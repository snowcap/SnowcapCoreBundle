<?php

namespace Snowcap\CoreBundle\Form\Type;

use Snowcap\CoreBundle\File\CondemnedFile;
use Snowcap\CoreBundle\Form\DataTransformer\FileDataTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Class FileType
 * @package Snowcap\CoreBundle\Form\Type
 */
class FileType extends AbstractType
{
    /**
     * @var string
     */
    private $uploadDir;

    /**
     * @return string
     */
    public function getName()
    {
        return 'snowcap_core_file';
    }

    /**
     * @return string
     */
    public function getParent()
    {
        return 'file';
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setRequired(array(
                'file_path',
            ))
            ->setDefaults(array(
                'compound' => true,
                'error_bubbling' => false,
                'data_class' => null,
                'delete_label' => null,
                'download_label' => null,
                'allow_delete' => true,
            ));
    }


    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array                                        $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $filePath = $options['file_path'];
        $uploadDir = $this->uploadDir;

        $builder
            ->add('file', 'file', array('error_bubbling' => true))
            ->add('delete', 'checkbox', array(
                'error_bubbling' => true,
            ))
            ->addViewTransformer(new FileDataTransformer())
            ->addEventListener(FormEvents::POST_SUBMIT, function(FormEvent $event) use($filePath, $uploadDir) {
                // We need to store the path to the file to delete in the Condemned file instance
                $data = $event->getData();
                if($data['file'] instanceof CondemnedFile) {
                    $parentForm = $event->getForm()->getParent();
                    $accessor = PropertyAccess::createPropertyAccessor();
                    $imagePath = $accessor->getValue($parentForm->getData(), $filePath);
                    $data['file']->setPath($uploadDir . '/' . $imagePath);
                }
            });
        ;
    }

    /**
     * @param \Symfony\Component\Form\FormView $view
     * @param \Symfony\Component\Form\FormInterface     $form
     * @param array                                     $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        if (array_key_exists('file_path', $options)) {
            $parentData = $form->getParent()->getData();
            try {
                $fileUrl = null;
                if ($parentData !== null) {
                    if(is_callable($options['file_path'])) {
                        $fileUrl = call_user_func($options['file_path'], $parentData);
                    } else {
                        $accessor = PropertyAccess::createPropertyAccessor();
                        $fileUrl = $accessor->getValue($parentData, $options['file_path']);
                    }
                }
            }
            catch(\Exception $e) {
                $fileUrl = null;
            }
            // set an "file_url" variable that will be available when rendering this field
            $view->vars['file_url'] = $fileUrl;
        }
        $view->vars['download_label'] = $options['download_label'];
        $view->vars['delete_label'] = $options['delete_label'];
        $view->vars['allow_delete'] = $options['allow_delete'];
    }

    /**
     * @param string $uploadDir
     */
    public function setUploadDir($uploadDir) {
        $this->uploadDir = $uploadDir;
    }
}