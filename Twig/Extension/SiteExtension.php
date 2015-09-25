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
            'prepend_page_title' => new \Twig_SimpleFunction($this, 'prependPageTitle'),
            'append_page_title' => new \Twig_SimpleFunction($this, 'appendPageTitle'),
            'page_title' => new \Twig_SimpleFunction($this, 'getPageTitle'),
            'meta_description' => new \Twig_SimpleFunction($this, 'getMetaDescription'),
            'set_meta_description' => new \Twig_SimpleFunction($this, 'setMetaDescription'),
            'meta_keywords' => new \Twig_SimpleFunction($this, 'getMetaKeywords'),
            'add_meta_keywords' => new \Twig_SimpleFunction($this, 'addMetaKeywords'),
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