<?php

namespace Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Snowcap\CoreBundle\Doctrine\Mapping as SnowcapORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity
 * @ORM\Table
 */
class LeafletTranslation {
    /**
     * @var integer
     *
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string")
     */
    private $title;

    /**
     * @var \Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet
     *
     * @ORM\ManyToOne(targetEntity="Leaflet", inversedBy="translations")
     * @ORM\JoinColumn
     */
    private $leaflet;

    /**
     * @var string
     *
     * @ORM\Column(name="attachment", type="string", length=255, nullable=true)
     */
    protected $attachment;

    /**
     * @var \Symfony\Component\HttpFoundation\File\File
     *
     * @SnowcapORM\File(path="uploads/attachments", mappedBy="attachment")
     */
    protected $attachmentFile;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param \Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet $leaflet
     */
    public function setLeaflet(Leaflet $leaflet)
    {
        $this->leaflet = $leaflet;
    }

    /**
     * @return \Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet
     */
    public function getLeaflet()
    {
        return $this->leaflet;
    }

    /**
     * @param string $attachment
     */
    public function setAttachment($attachment)
    {
        $this->attachment = $attachment;
    }

    /**
     * @return string
     */
    public function getAttachment()
    {
        return $this->attachment;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\File\File $attachmentFile
     */
    public function setAttachmentFile(File $attachmentFile)
    {
        $this->attachmentFile = $attachmentFile;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\File\File
     */
    public function getAttachmentFile()
    {
        return $this->attachmentFile;
    }
}