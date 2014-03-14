<?php

namespace Snowcap\CoreBundle\Tests\Listener;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet;
use Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\LeafletTranslation;
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
        'Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet',
        'Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\LeafletTranslation',
    );

    private $eventSubscriber;

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

    /**
     * Create the database for the fixture entities
     *
     */
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

    /**
     * Build an entity manager, create the database schema and prepare the test filesystem
     *
     */
    protected function setUp()
    {
        $this->em = $this->buildEntityManager();
        $this->createSchema();
        $this->rootDir = sys_get_temp_dir() . '/' . uniqid();

        $this->eventSubscriber = new FileSubscriber($this->rootDir);
        $this->em->getEventManager()->addEventSubscriber($this->eventSubscriber);

        parent::setUp();
    }

    /**
     * Reset everything: clear the entity manager and replace the current file subscriber by a fresh new one
     *
     */
    protected function reset()
    {
        $this->em->clear();
        $this->em->getEventManager()->removeEventSubscriber($this->eventSubscriber);
        $this->eventSubscriber = new FileSubscriber($this->rootDir);
        $this->em->getEventManager()->addEventSubscriber($this->eventSubscriber);
    }

    /**
     * Test a simple insert with a file
     *
     */
    public function testEntityInsertion()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush();

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
    }

    /**
     * Test the preservation of the original filename if the "filename" option is used
     *
     */
    public function testEntityInsertionWithFilename()
    {
        $user = $this->buildUser();
        $user->setCvFile($this->buildFile('test.txt'));
        $this->em->persist($user);
        $this->em->flush();

        $this->assertEquals('test_file.txt', $user->getOriginalFilename());
    }

    /**
     * Test a simple update, no previous file
     *
     */
    public function testEntityUpdateWithoutPreviousFile()
    {
        $novel = $this->buildNovel();
        $this->em->persist($novel);
        $this->em->flush();

        $this->reset();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->flush();

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
    }

    /**
     * Test the update of an entity that had a previous file
     *
     */
    public function testEntityUpdateWithPreviousFile()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test1.txt'));
        $this->em->persist($novel);
        $this->em->flush();
        $oldAttachment = $novel->getAttachment();

        $this->reset();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test2.txt'));
        $this->em->flush();

        $this->assertNotNull($novel->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $novel->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    /**
     * Test an update with a condemned file (used to implement the "delete" checkbox in forms)
     *
     */
    public function testEntityUpdateWithCondemnedFile()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush();
        $oldAttachment = $novel->getAttachment();

        $this->reset();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile(new CondemnedFile());
        $this->em->flush();

        $this->assertNull($novel->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    /**
     * Test an entity deletion
     *
     */
    public function testEntityRemoval()
    {
        $novel = $this->buildNovel();
        $novel->setAttachmentFile($this->buildFile('test.txt'));
        $this->em->persist($novel);
        $this->em->flush();
        $oldAttachment = $novel->getAttachment();

        $this->reset();

        $novel = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Novel')
            ->find($novel->getId());
        $novel->setAttachmentFile($this->buildFile('test2.txt'));
        $this->em->remove($novel);
        $this->em->flush();

        $this->assertNull($novel->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    /**
     * Test an insert taking place not in the "main" entity, but in a association of the main entity
     *
     */
    public function testInsertInCollection()
    {
        $leaflet = $this->buildLeaflet();
        $translation = $leaflet->getTranslations()->first();
        $translation->setAttachmentFile($this->buildFile('test.txt'));

        $this->em->persist($leaflet);
        $this->em->flush();

        $this->assertNotNull($translation->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $translation->getAttachment());
    }

    /**
     * Test an update taking place not in the "main" entity, but in a association of the main entity (simple case,
     * no previous file)
     *
     */
    public function testUpdateInCollectionWithNoPreviousFile()
    {
        $leaflet = $this->buildLeaflet();
        $this->em->persist($leaflet);
        $this->em->flush();

        $this->reset();

        $leaflet = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet')
            ->find($leaflet->getId());
        $translation = $leaflet->getTranslations()->first();
        $translation->setAttachmentFile($this->buildFile('test1.txt'));
        $this->em->flush();

        $this->assertNotNull($translation->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $translation->getAttachment());
    }

    /**
     * Test an update taking place not in the "main" entity, but in a association of the main entity (alternate case,
     * with a previous file)
     *
     */
    public function testUpdateInCollectionWithPreviousFile()
    {
        $leaflet = $this->buildLeaflet();
        $translation = $leaflet->getTranslations()->first();
        $translation->setAttachmentFile($this->buildFile('test1.txt'));
        $this->em->persist($leaflet);
        $this->em->flush();
        $oldAttachment = $translation->getAttachment();

        $this->reset();

        $leaflet = $this->em
            ->getRepository('Snowcap\CoreBundle\Tests\Listener\Fixtures\Entity\Leaflet')
            ->find($leaflet->getId());
        $translation = $leaflet->getTranslations()->first();
        $translation->setAttachmentFile($this->buildFile('test2.txt'));
        $this->em->flush();

        $this->assertNotNull($translation->getAttachment());
        $this->assertFileExists($this->rootDir . '/' . $translation->getAttachment());
        $this->assertFileNotExists($this->rootDir . '/' . $oldAttachment);
    }

    /**
     * Build a Symfony2 UploadedFile object
     *
     * @param string $fileName
     * @return UploadedFile
     */
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

    /**
     * @return Leaflet
     */
    private function buildLeaflet()
    {
        $leaflet = new Leaflet();
        $leaflet->setCode('plop');

        $leafletTranslation = new LeafletTranslation();
        $leafletTranslation->setLeaflet($leaflet);
        $leafletTranslation->setTitle('hop hop');

        $leaflet->addTranslation($leafletTranslation);

        return $leaflet;
    }
}
