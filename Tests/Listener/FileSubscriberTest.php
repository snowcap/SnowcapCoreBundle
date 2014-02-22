<?php

namespace Snowcap\CoreBundle\Tests\Listener;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\User;
use Symfony\Component\Filesystem\Filesystem;

use Snowcap\CoreBundle\Listener\FileSubscriber;
use Snowcap\CoreBundle\File\CondemnedFile;
use Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileSubscriberTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var array
     */
    private $classes = array(
        'Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\User',
        'Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel',
    );

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    private function buildEntityManager()
    {
        AnnotationRegistry::registerFile(__DIR__ . '/../../Doctrine/Mapping/File.php');

        $config = Setup::createAnnotationMetadataConfiguration(
            array(__DIR__ . '/Fixtures'),
            false,
            \sys_get_temp_dir(),
            null,
            false
        );
        $config->setAutoGenerateProxyClasses(true);

        $params = array(
            'driver' => 'pdo_sqlite',
            'memory' => true,
        );

        return EntityManager::create($params, $config);
    }

    private function createSchema()
    {
        $em = $this->em;
        $schema = array_map(function ($class) use ($em) {
            return $em->getClassMetadata($class);
        }, $this->classes);

        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema(array());
        $schemaTool->createSchema($schema);
    }

    protected function setUp()
    {
        $this->em = $this->buildEntityManager();
        $this->createSchema();
        $this->rootDir = sys_get_temp_dir() . '/' . uniqid();


        $this->em->getEventManager()->addEventSubscriber(new FileSubscriber($this->rootDir));

        parent::setUp();
    }

    public function testEntityInsertion()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush($novel);

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
    }

    public function testEntityInsertionWithFilename()
    {
        $user = $this->buildUser();
        $user->setCvFile($this->buildFile('test.txt'));
        $this->em->persist($user);
        $this->em->flush($user);

        $this->assertEquals('test_file.txt', $user->getOriginalFilename());
    }

    public function testEntityUpdateWithoutPreviousFile()
    {
        $novel = $this->buildNovel();
        $this->em->persist($novel);
        $this->em->flush($novel);
        $this->em->clear($novel);

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->flush($novel);

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
    }

    public function testEntityUpdateWithPreviousFile()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test1.txt'));
        $this->em->persist($novel);
        $this->em->flush($novel);
        $this->em->clear($novel);
        $oldAttachment = $novel->getAttachment();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test2.txt'));
        $this->em->flush($novel);

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    public function testEntityUpdateWithCondemnedFile()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush($novel);
        $this->em->clear($novel);
        $oldAttachment = $novel->getAttachment();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile(new CondemnedFile());
        $this->em->flush($novel);

        $this->assertNull($novel->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    public function testEntityRemoval()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush($novel);
        $this->em->clear($novel);
        $oldAttachment = $novel->getAttachment();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test2.txt'));
        $this->em->remove($novel);
        $this->em->flush($novel);

        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    private function buildFile($fileName)
    {
        $fs = new Filesystem();
        $targetPath = $this->rootDir . '/' . $fileName;
        $fileName = __DIR__ . '/Fixtures/files/test_file.txt';
        $fs->copy($fileName, $targetPath);

        return new UploadedFile($targetPath, $fileName, 'text/plain', filesize($fileName), null, true);
    }

    /**
     * @return Fixtures\Entity\Novel
     */
    private function buildNovel()
    {
        $novel = new Novel();
        $novel->setTitle('Dancing with the frogs');
        $novel->setSubtitle('An epic tale of man-frog love');

        return $novel;
    }

    /**
     * @return Fixtures\Entity\User
     */
    private function buildUser()
    {
        $user = new User();
        $user->setUserName('pvanliefland');

        return $user;
    }
}
