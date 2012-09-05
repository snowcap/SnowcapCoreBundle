<?php

namespace Snowcap\CoreBundle\Listener;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\EventArgs;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

use Snowcap\CoreBundle\Doctrine\ORM\Event\PreFlushEventArgs;
use Snowcap\CoreBundle\File\CondemnedFile;

class FileSubscriber implements EventSubscriber
{
    private $config = array();

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var array
     */
    private $unlinkQueue = array();

    /**
     * @param string $rootDir
     */
    public function __construct($rootDir){
        $this->rootDir = $rootDir;
    }
    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array('prePersist', 'postPersist', 'postUpdate', 'preRemove', 'postRemove','loadClassMetadata','preFlush');
    }

    /**
     * @param \Doctrine\ORM\Event\LoadClassMetadataEventArgs $eventArgs
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs)
    {
        $reader = new \Doctrine\Common\Annotations\AnnotationReader();
        $meta = $eventArgs->getClassMetadata();
        foreach ($meta->getReflectionClass()->getProperties() as $property) {
            if ($meta->isMappedSuperclass && !$property->isPrivate() ||
                $meta->isInheritedField($property->name) ||
                isset($meta->associationMappings[$property->name]['inherited'])
            ) {
                continue;
            }
            if ($annotation = $reader->getPropertyAnnotation($property, 'Snowcap\\CoreBundle\\Doctrine\\Mapping\\File')) {
                $field = $property->getName();
                $this->config[$meta->getTableName()]['fields'][$field] = array(
                    'property' => $property,
                    'path' => $annotation->path,
                    'mappedBy' => $annotation->mappedBy,
                    'filename' => $annotation->filename,
                );
            }
        }
    }

    /**
     * @param \Snowcap\CoreBundle\Doctrine\ORM\Event\PreFlushEventArgs $ea
     */
    public function preFlush(PreFlushEventArgs $ea)
    {
        /** @var $unitOfWork \Doctrine\ORM\UnitOfWork */
        $unitOfWork = $ea->getEntityManager()->getUnitOfWork();

        $entityMaps = $unitOfWork->getIdentityMap();
        foreach($entityMaps as $entities) {
            foreach($entities as $entity) {
                foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
                    $propertyName = $file['property']->name;
                    $property = $entity->$propertyName;
                    if($property instanceof CondemnedFile) {
                        $this->preRemoveUpload($entity, $file);
                    }
                    else {
                        $this->preUpload($ea, $entity,$file);
                    }
                }
            }
        }
    }

    /**
     * @param $entity
     * @param \Doctrine\ORM\EntityManager $entityManager
     * @return array
     */
    private function getFiles($entity, \Doctrine\ORM\EntityManager $entityManager)
    {
        $classMetaData = $entityManager->getClassMetaData(get_class($entity));
        $tableName = $classMetaData->getTableName();

        if(array_key_exists($tableName, $this->config)) {
            return $this->config[$tableName]['fields'];
        } else {
            return array();
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     */
    public function prePersist(LifecycleEventArgs $ea)
    {
        $entity = $ea->getEntity();
        foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
            $this->preUpload($ea, $entity,$file);
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     */
    public function postPersist(LifecycleEventArgs $ea)
    {
        $entity = $ea->getEntity();
        foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
            $this->upload($ea, $entity,$file);
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     */
    public function postUpdate(LifecycleEventArgs $ea)
    {
        $entity = $ea->getEntity();
        foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
            $propertyName = $file['property']->name;
            $property = $entity->$propertyName;
            if($property instanceof CondemnedFile) {
                $this->removeUpload($entity, $file);
            }
            else {
                $this->upload($ea, $entity,$file);
            }
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     */
    public function preRemove(LifecycleEventArgs $ea){
        $entity = $ea->getEntity();
        foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
            $this->preRemoveUpload($entity,$file);
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     */
    public function postRemove(LifecycleEventArgs $ea)
    {
        $entity = $ea->getEntity();
        foreach($this->getFiles($entity,$ea->getEntityManager()) as $file) {
            $this->removeUpload($entity,$file);
        }
    }

    /**
     * @param $ea
     * @param $fileEntity
     * @param array $file
     */
    private function preUpload(EventArgs $ea, $fileEntity, array $file)
    {
        $propertyName = $file['property']->name;
        if (isset($fileEntity->$propertyName) && null !== $fileEntity->$propertyName) {
            $getter = "get" . ucfirst(strtolower($file['mappedBy']));
            $setter = "set" . ucfirst(strtolower($file['mappedBy']));
            $oldValue = $fileEntity->$getter();
            $newValue = $file['path'] . '/' . uniqid() . '.' . $fileEntity->$propertyName->guessExtension();
            $fileEntity->$setter($newValue);

            if ($file['filename'] !== null) {
                $setter = "set" . ucfirst(strtolower($file['filename']));
                $fileEntity->$setter($fileEntity->$propertyName->getClientOriginalName());

            }
            /** @var $entityManager \Doctrine\ORM\EntityManager */
            $entityManager = $ea->getEntityManager();
            $entityManager->getUnitOfWork()->propertyChanged($fileEntity, $file['mappedBy'], $oldValue, $newValue);
        }
    }

    /**
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $ea
     * @param $fileEntity
     * @param array $file
     */
    private function upload(LifecycleEventArgs $ea, $fileEntity, array $file)
    {
        $propertyName = $file['property']->name;
        if (!isset($fileEntity->$propertyName) || null === $fileEntity->$propertyName) {
            return;
        }

        $getter = "get" . ucfirst(strtolower($file['mappedBy']));
        $filename = basename($fileEntity->$getter());
        $path = dirname($fileEntity->$getter());

        $fileEntity->$propertyName->move($this->getUploadRootDir() . $path, $filename);


        // Remove previous file
        $unitOfWork = $ea->getEntityManager()->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($fileEntity);
        if(array_key_exists($file['mappedBy'],$changeSet)) {
            $oldvalue = $changeSet[$file['mappedBy']][0];
            if($oldvalue !== '' && $oldvalue !== NULL) {
                @unlink($this->getUploadRootDir($fileEntity) . '/' . $oldvalue);
            }
        }

        $fileEntity->$propertyName = null;
    }

    /**
     * @param $fileEntity
     * @param array $file
     */
    private function removeUpload($fileEntity, array $file)
    {
        if (isset($this->unlinkQueue[spl_object_hash($fileEntity)]) && is_file($this->unlinkQueue[spl_object_hash($fileEntity)])) {
            unlink($this->unlinkQueue[spl_object_hash($fileEntity)]);
        }
    }

    /**
     * @param $fileEntity
     * @param array $file
     */
    private function preRemoveUpload($fileEntity, array $file)
    {
        if ($file['path'] !== "") {
            $getter = "get" . ucfirst(strtolower($file['mappedBy']));
            $filePath = $fileEntity->$getter();
            if($filePath !== "") {
                $this->unlinkQueue[spl_object_hash($fileEntity)]= $this->getUploadRootDir() . $filePath;
            }

            $setter = "set" . ucfirst(strtolower($file['mappedBy']));
            $fileEntity->$setter(null);
        }
    }

    /**
     * @return string
     */
    private function getUploadRootDir()
    {
        // the absolute directory path where uploaded documents should be saved
        return $this->rootDir . '/../web/';
    }
}