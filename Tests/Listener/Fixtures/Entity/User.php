<?php

namespace Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Snowcap\CoreBundle\Doctrine\Mapping as SnowcapORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity
 * @ORM\Table
 */
class User {
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
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $userName;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $cv;

    /**
     * @var \Symfony\Component\HttpFoundation\File\File
     *
     * @SnowcapORM\File(path="uploads/cvs", mappedBy="cv", filename="originalFilename")
     */
    private $cvFile;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $originalFilename;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $cv
     */
    public function setCv($cv)
    {
        $this->cv = $cv;
    }

    /**
     * @return string
     */
    public function getCv()
    {
        return $this->cv;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\File\File $cvFile
     */
    public function setCvFile($cvFile)
    {
        $this->cvFile = $cvFile;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\File\File
     */
    public function getCvFile()
    {
        return $this->cvFile;
    }

    /**
     * @param string $userName
     */
    public function setUserName($userName)
    {
        $this->userName = $userName;
    }

    /**
     * @return string
     */
    public function getUserName()
    {
        return $this->userName;
    }

    /**
     * @return mixed
     */
    public function getOriginalFilename()
    {
        return $this->originalFilename;
    }
}