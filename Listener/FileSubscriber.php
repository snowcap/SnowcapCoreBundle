<?php

namespace Snowcap\CoreBundle\Listener;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Snowcap\CoreBundle\Util\String;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

use Snowcap\CoreBundle\File\CondemnedFile;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * This class is a Doctrine EventSubscriber that listens to Doctrine events and handle file uploads as well as
 * file deletions performed on entities
 *
 * @package Snowcap\CoreBundle\Listener
 */
class FileSubscriber implements EventSubscriber
{
    /**
     * @var array
     */
    private $config = array();

    /**
     * @var array
     */
    private $unlinkQueue = array();

    /**
     * @var array
     */
    private $uploadEntities = array();

    /**
     * @var array
     */
    private $condemnedEntities = array();

    /**
     * @var string
     */
    private $uploadDir;

    /**
     * @param string $uploadDir
     */
    public function __construct($uploadDir)
    {
        $this->uploadDir = $uploadDir;
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            Events::preFlush,
            Events::onFlush,
            Events::postFlush
        );
    }

    /**
     * Before flush, we need to update the config array with all the properties of entities about to be
     * inserted, updated or removed and which correspond to a SnowcapCoreBundle File annotation
     *
     * @param \Doctrine\ORM\Event\PreFlushEventArgs $ea
     * @throws \Doctrine\Common\Annotations\AnnotationException
     */
    public function preFlush(PreFlushEventArgs $ea)
    {
        if (!empty($this->config)) {
            return;
        }

        $entityManager = $ea->getEntityManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $scheduledEntities = array_merge(
            $unitOfWork->getScheduledEntityInsertions(),
            $unitOfWork->getScheduledEntityUpdates(),
            $unitOfWork->getScheduledEntityDeletions()
        );
        $scheduledEntityClassNames = array_unique(array_map(function ($entity) {
            return get_class($entity);
        }, $scheduledEntities));

        $reader = new AnnotationReader();

        // Loop over all the class names of entities about to be inserted, updated or removed
        foreach ($scheduledEntityClassNames as $className) {
            if (isset($this->config[$className])) { // Skip this entity if we already have a config for it
                return;
            }

            $meta = $entityManager->getClassMetadata($className);

            // Loop over all of the properties of the class
            foreach ($meta->getReflectionClass()->getProperties() as $property) {

                // Skip if mappedSuperclass, private property or some other special case
                if ($meta->isMappedSuperclass && !$property->isPrivate() ||
                    $meta->isInheritedField($property->name) ||
                    isset($meta->associationMappings[$property->name]['inherited'])
                ) {
                    continue;
                }

                // Check if the property corresponds to the SnowcapCoreBundle File annotation
                $annotationClass = 'Snowcap\\CoreBundle\\Doctrine\\Mapping\\File';
                if ($annotation = $reader->getPropertyAnnotation($property, $annotationClass)) {
                    $property->setAccessible(true);
                    $field = $property->getName();

                    // Validation
                    if (null === $annotation->mappedBy) {
                        throw AnnotationException::requiredError(
                            'mappedBy',
                            'SnowcapCore\File',
                            $meta->getReflectionClass()->getName(),
                            'another class property to map onto'
                        );
                    }
                    if (null === $annotation->path && null === $annotation->pathCallback) {
                        throw AnnotationException::syntaxError(
                            sprintf(
                                'Annotation @%s declared on %s expects "path" or "pathCallback". One of them should not be null.',
                                'SnowcapCore\File',
                                $meta->getReflectionClass()->getName()
                            )
                        );
                    }
                    if (!$meta->hasField($annotation->mappedBy)) {
                        throw AnnotationException::syntaxError(
                            sprintf(
                                'The entity "%s" has no field named "%s", but it is documented in the annotation @%s',
                                $meta->getReflectionClass()->getName(),
                                $annotation->mappedBy,
                                'SnowcapCore\File'
                            )
                        );
                    }

                    $this->config[$className]['fields'][$field] = array(
                        'file' => $property,
                        'path' => $annotation->path,
                        'mappedBy' => $annotation->mappedBy,
                        'filename' => $annotation->filename,
                        'meta' => $meta,
                        'nameCallback' => $annotation->nameCallback,
                        'pathCallback' => $annotation->pathCallback,
                    );
                }
            }
        }
    }

    /**
     * When flushing, we check all the entities about to be inserted, updated or removed and we perform pre-upload
     * or pre-remove upload operations
     *
     * @param \Doctrine\ORM\Event\OnFlushEventArgs $ea
     */
    public function onFlush(OnFlushEventArgs $ea)
    {
        $entityManager = $ea->getEntityManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        // First, check all entities in identity map - if they have a file object they need to be processed
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $fileEntity) {
                foreach ($this->getFileProperties($fileEntity, $entityManager) as $fileConfig) {
                    if (
                        $fileConfig['file']->getValue($fileEntity) instanceof File &&
                        !$unitOfWork->isScheduledForUpdate($fileEntity) &&
                        !$unitOfWork->isScheduledForDelete($fileEntity)
                    ) {
                        $unitOfWork->scheduleForUpdate($fileEntity);
                    }
                }
            }
        }

        // Then, let's deal with entities schedules for insertion
        foreach ($unitOfWork->getScheduledEntityInsertions() as $fileEntity) {
            if ($unitOfWork->isScheduledForDelete($fileEntity)) {
                break;
            }

            foreach ($this->getFileProperties($fileEntity, $entityManager) as $fileConfig) {
                $propertyValue = $fileConfig['file']->getValue($fileEntity);
                if ($propertyValue instanceof File) {
                    $this->preUpload($ea, $fileEntity, $fileConfig);
                }
            }
        }
        // Then, let's deal with entities schedules for updates
        foreach ($unitOfWork->getScheduledEntityUpdates() as $fileEntity) {
            if ($unitOfWork->isScheduledForDelete($fileEntity)) {
                break;
            }

            foreach ($this->getFileProperties($fileEntity, $entityManager) as $fileConfig) {
                $propertyValue = $fileConfig['file']->getValue($fileEntity);
                if ($propertyValue instanceof CondemnedFile) {
                    $this->preRemoveUpload($ea, $fileEntity, $fileConfig);
                } elseif ($propertyValue instanceof File) {
                    $this->preUpload($ea, $fileEntity, $fileConfig);
                }
            }
        }
        // Then, let's deal with entities schedules for deletions
        foreach ($unitOfWork->getScheduledEntityDeletions() as $fileEntity) {
            foreach ($this->getFileProperties($fileEntity, $entityManager) as $fileConfig) {
                $mappedByValue = $fileConfig['meta']->getFieldValue($fileEntity, $fileConfig['mappedBy']);
                if (null !== $mappedByValue) {
                    $this->preRemoveUpload($ea, $fileEntity, $fileConfig);
                }
            }
        }
    }

    /**
     * After the flush, we loop over entities that need "upload" or "condemn" operations
     *
     * @param \Doctrine\ORM\Event\PostFlushEventArgs $ea
     */
    public function postFlush(PostFlushEventArgs $ea)
    {
        foreach ($this->uploadEntities as $uploadEntity) {
            foreach ($this->getFileProperties($uploadEntity, $ea->getEntityManager()) as $fileConfig) {
                $this->upload($ea, $uploadEntity, $fileConfig);
            }
        }
        foreach ($this->condemnedEntities as $uploadEntity) {
            foreach ($this->getFileProperties($uploadEntity, $ea->getEntityManager()) as $fileConfig) {
                $this->removeUpload($uploadEntity, $fileConfig);
            }
        }

        $this->uploadEntities = array();
        $this->condemnedEntities = array();
    }

    /**
     * Return all properties of the provided entity that correspond to a SnowcapCoreBundle File annotation
     *
     * @param $entity
     * @param \Doctrine\ORM\EntityManager $em
     * @return array
     */
    private function getFileProperties($entity, EntityManager $em)
    {
        $classMetaData = $em->getClassMetaData(get_class($entity));
        $className = $classMetaData->getName();

        if (array_key_exists($className, $this->config)) {
            return $this->config[$className]['fields'];
        }

        return array();
    }

    /**
     * To prepare the upload, we need to generate a file name and optionally, store the original file name
     *
     * @param \Doctrine\ORM\Event\OnFlushEventArgs $ea
     * @param object $fileEntity
     * @param array $fileConfig
     */
    private function preUpload(OnFlushEventArgs $ea, $fileEntity, array $fileConfig)
    {
        // Update the mapped property on the entity with the generated file name
        $newMappedValue = $this->generateFileName($fileEntity, $fileConfig);
        $this->changePropertyVaue($ea, $fileConfig, $fileEntity, 'mappedBy', $newMappedValue);

        // The "filename" property allows us to store the original file name on another entity property
        if ($fileConfig['filename'] !== null) {
            $file = $fileConfig['file']->getValue($fileEntity);
            $newFilename = $file->getClientOriginalName();
            $this->changePropertyVaue($ea, $fileConfig, $fileEntity, 'filename', $newFilename);
        }

        $this->uploadEntities[] = $fileEntity;
    }

    /**
     * Perform the upload operation itself
     *
     * @param PostFlushEventArgs $ea
     * @param object $fileEntity
     * @param array $fileConfig
     */
    private function upload(PostFlushEventArgs $ea, $fileEntity, array $fileConfig)
    {
        // Move uploaded file
        $file = $fileConfig['file']->getValue($fileEntity);
        $mappedValue = $fileConfig['meta']->getFieldValue($fileEntity, $fileConfig['mappedBy']);
        $filename = basename($mappedValue);
        $path = dirname($mappedValue);
        $file->move($this->uploadDir . '/' . $path, $filename);

        // Remove previous file
        $unitOfWork = $ea->getEntityManager()->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($fileEntity);
        if (array_key_exists($fileConfig['mappedBy'], $changeSet)) {
            $oldValue = $changeSet[$fileConfig['mappedBy']][0];
            if (null !== $oldValue) {
                @unlink($this->uploadDir . '/' . $oldValue);
            }
        }

        $fileConfig['file']->setValue($fileEntity, null);
    }

    /**
     * Before the "condemn" operation, we clear the "mappedBy" and if needed, the "filename" values
     *
     * @param \Doctrine\ORM\Event\OnFlushEventArgs $ea
     * @param object $fileEntity
     * @param array $fileConfig
     */
    private function preRemoveUpload(OnFlushEventArgs $ea, $fileEntity, array $fileConfig)
    {
        // Clear the "mappedBy" property
        $oldMappedByValue = $this->changePropertyVaue($ea, $fileConfig, $fileEntity, 'mappedBy', null);

        // The "filename" property needs to be cleared as well
        if ($fileConfig['filename'] !== null) {
            $this->changePropertyVaue($ea, $fileConfig, $fileEntity, 'filename', null);
        }

        $this->unlinkQueue[spl_object_hash($fileEntity)] = $this->uploadDir . '/' . $oldMappedByValue;
        $this->condemnedEntities[] = $fileEntity;
    }

    /**
     * The "condemn" operation itself
     *
     * @param object $fileEntity
     * @param array $fileConfig
     */
    private function removeUpload($fileEntity, array $fileConfig)
    {
        if (
            isset($this->unlinkQueue[spl_object_hash($fileEntity)]) &&
            is_file($this->unlinkQueue[spl_object_hash($fileEntity)])
        ) {
            unlink($this->unlinkQueue[spl_object_hash($fileEntity)]);
        }
        $fileConfig['file']->setValue($fileEntity, null);
    }

    /**
     * Generate the filename for the entity, either randomly or by using name and path callbacks
     *
     * @param object $fileEntity
     * @param array $fileConfig
     * @return string
     */
    private function generateFileName($fileEntity, array $fileConfig)
    {
        $path = $fileConfig['path'];
        if (null !== $fileConfig['pathCallback']) {
            $accessor = PropertyAccess::createPropertyAccessor();
            $path = $accessor->getValue($fileEntity, $fileConfig['pathCallback']);
        }
        $path .= '/';
        $ext = '.' . $fileConfig['file']->getValue($fileEntity)->guessExtension();

        if ($fileConfig['nameCallback'] !== null) {
            $accessor = PropertyAccess::createPropertyAccessor();
            $filename = $accessor->getValue($fileEntity, $fileConfig['nameCallback']);
            $filename = String::slugify($filename);

            // If a file with the same name already exists, append increment until a unique one is found
            $i = 0;
            do {
                $testFile = $filename . (0 === $i ? '' : '-' . $i);
                $i++;
            } while (file_exists($path . $testFile . $ext));

            $filename = $testFile;
        } else {
            $filename = uniqid();
        }

        return $path . $filename . $ext;
    }

    /**
     * Change a property value and marked it as changed in the unit of work
     * 
     * @param \Doctrine\Common\EventArgs $ea
     * @param array $fileConfig
     * @param object $fileEntity
     * @param string $configKey
     * @param string $newValue
     * @return string
     */
    private function changePropertyVaue($ea, $fileConfig, $fileEntity, $configKey, $newValue)
    {
        $oldValue = $fileConfig['meta']->getFieldValue($fileEntity, $fileConfig[$configKey]);
        $fileConfig['meta']->setFieldValue($fileEntity, $fileConfig[$configKey], $newValue);
        $ea
            ->getEntityManager()
            ->getUnitOfWork()
            ->propertyChanged($fileEntity, $fileConfig[$configKey], $oldValue, $newValue);

        return $oldValue;
    }
}