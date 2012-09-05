<?php

namespace Snowcap\CoreBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\Util\PropertyPath;
use Symfony\Component\Form\Exception\MissingOptionsException;

use Snowcap\CoreBundle\Form\DataTransformer\ImageDataTransformer;
use Snowcap\CoreBundle\File\CondemnedFile;

class ImageType extends AbstractType {

    private $uploadDir;

    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    function getName()
    {
        return 'snowcap_core_image';
    }

    /**
     * @param array $options
     * @return null|string
     */
    public function getParent(array $options)
    {
        return 'form';
    }

    /**
     * @param array $options
     * @return array
     */
    public function getDefaultOptions(array $options)
    {
        return array(
            'web_path' => null,
        );
    }

    /**
     * @param \Symfony\Component\Form\FormBuilder $builder
     * @param array $options
     * @throws \Symfony\Component\Form\Exception\MissingOptionsException
     */
    public function buildForm(FormBuilder $builder, array $options)
    {
        if(!isset($options['web_path'])) {
            throw new MissingOptionsException('The "web_path" option is mandatory', array('web_path'));
        }
        $webPath = $options['web_path'];
        $uploadDir = $this->uploadDir;

        $builder
            ->add('file', 'file', array('error_bubbling' => true))
            ->add('delete', 'checkbox', array('error_bubbling' => true))
            ->appendClientTransformer(new ImageDataTransformer($this->uploadDir))
            ->addEventListener(\Symfony\Component\Form\FormEvents::POST_BIND, function($event) use($webPath, $uploadDir) {
                $parentForm = $event->getForm()->getParent();
                $propertyPath = new PropertyPath($webPath);
                $imagePath = $propertyPath->getValue($parentForm->getData());

                $data = $event->getData();
                if($data['file'] instanceof CondemnedFile) {
                    $data['file']->setPath($uploadDir . $imagePath);
                }
            })
            ->setAttribute('web_path', $options['web_path'] ?: null)
        ;
    }

    /**
     * @param \Symfony\Component\Form\FormView $view
     * @param \Symfony\Component\Form\FormInterface $form
     */
    public function buildView(FormView $view, FormInterface $form)
    {
        $vars = $view->getParent()->getVars();
        $parentValue = $vars['value'];
        if(!empty($parentValue)) {
            $propertyPath = new PropertyPath($form->getAttribute('web_path'));
            $view->set('image_src', $propertyPath->getValue($parentValue));
        }
    }

    /**
     * @param string $rootDir
     */
    public function setRootDir($rootDir) {
        $this->uploadDir = $rootDir . '/../web/';
    }
}