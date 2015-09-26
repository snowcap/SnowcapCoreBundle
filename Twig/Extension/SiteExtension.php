<?php

namespace Snowcap\CoreBundle\Twig\Extension;

class SiteExtension extends \Twig_Extension
{
    /**
     * @var array
     */
    private $titleParts = array('prepend' => array(), 'append' => array());

    /**
     * @var string
     */
    private $metaDescription;

    /**
     * @var array
     */
    private $metaKeywords = array();

    /**
     * @return array
     */
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('prepend_page_title', array($this, 'prependPageTitle')),
            new \Twig_SimpleFunction('append_page_title', array($this, 'appendPageTitle')),
            new \Twig_SimpleFunction('page_title', array($this, 'getPageTitle')),
            new \Twig_SimpleFunction('meta_description', array($this, 'getMetaDescription')),
            new \Twig_SimpleFunction('set_meta_description', array($this, 'setMetaDescription')),
            new \Twig_SimpleFunction('meta_keywords', array($this, 'getMetaKeywords')),
            new \Twig_SimpleFunction('add_meta_keywords', array($this, 'addMetaKeywords')),
        );
    }

    /**
     * @param string $baseTitle
     * @param string $seperator
     * @return string
     */
    public function getPageTitle($baseTitle, $seperator = ' - ')
    {
        $parts = array_merge(
            $this->titleParts['prepend'],
            array($baseTitle),
            $this->titleParts['append']
        );

        return implode($seperator, $parts);
    }

    /**
     * @param string $defaultDescription
     * @return string
     */
    public function getMetaDescription($defaultDescription)
    {
        return $this->metaDescription ?: $defaultDescription;
    }

    /**
     * @param string $description
     */
    public function setMetaDescription($description)
    {
        $this->metaDescription = $description;
    }

    /**
     * @param array $defaultKeywords
     * @return string
     */
    public function getMetaKeywords(array $defaultKeywords)
    {
        $merged = array_merge($defaultKeywords, $this->metaKeywords);
        $exploded = array();
        foreach($merged as $item) {
            $exploded = array_merge($exploded, explode(',', $item));
        }
        $trimmed = array_map('trim', $exploded);

        return implode(',', array_unique($trimmed));
    }

    /**
     * @param array $keywords
     */
    public function addMetaKeywords(array $keywords)
    {
        $this->metaKeywords = array_merge($this->metaKeywords, $keywords);
    }

    /**
     * @param string $prepend
     */
    public function prependPageTitle($prepend)
    {
        array_unshift($this->titleParts['prepend'], $prepend);
    }

    /**
     * @param string $prepend
     */
    public function appendPageTitle($append)
    {
        array_push($this->titleParts['append'], $append);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'snowcap_core_site';
    }
}