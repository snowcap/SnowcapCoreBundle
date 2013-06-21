<?php

namespace Snowcap\CoreBundle\Test;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


abstract class IntegrationTestCase extends WebTestCase implements ContainerAwareInterface
{
    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected $container;

    /**
     * Before instantiation, boot kernel and generate test schema
     */
    public static function setUpBeforeClass()
    {
        static::$kernel = static::createKernel();
        static::$kernel->boot();
        static::generateSchema();
    }

    /**
     * Generate database test schema
     *
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    private static function generateSchema() {
        $em = static::getEntityManager();
        $metaData = $em->getMetadataFactory()->getAllMetadata();

        if (!empty($metaData)) {
            // Create SchemaTool
            $tool = new SchemaTool($em);
            try {
                $tool->dropDatabase();
            } catch(\Exception $e) {
                // the database should not exist, lets create it
            }
            $tool->createSchema($metaData);
        } else {
            throw new SchemaException('No Metadata Classes to process.');
        }
    }

    /**
     * @return \Doctrine\Common\Persistence\ObjectManager
     */
    protected static function getEntityManager()
    {
        return static::$kernel->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Before each test, set the container on the current instance
     *
     */
    public function setUp()
    {
        $this->setContainer(static::$kernel->getContainer());
    }

    /**
     * Unset the container on the current instance after each test
     *
     */
    public function tearDown()
    {
        // Shutdown the kernel.
        $this->setContainer(null);
    }

    /**
     * Finally, shut the kernel down
     *
     */
    public static function tearDownAfterClass()
    {
        static::$kernel->shutDown();
    }

    /**
     * Load all the fixtures in the provided directory
     *
     * @param string $fixturesDirectory
     * @throws \InvalidArgumentException
     */
    protected function loadFixtures($fixturesDirectory)
    {
        $loader = new Loader();
        $loader->loadFromDirectory($fixturesDirectory);
        $purger = new ORMPurger(static::getEntityManager());
        $executor = new ORMExecutor(static::getEntityManager(), $purger);
        $executor->execute($loader->getFixtures());
    }

    /**
     * Load a single fixture
     *
     * @param $fixture
     */
    protected function loadFixture($fixture)
    {
        $loader = new Loader();
        $loader->addFixture($fixture);
        $purger = new ORMPurger(static::getEntityManager());
        $executor = new ORMExecutor(static::getEntityManager(), $purger);
        $executor->execute($loader->getFixtures());
    }

    /**
     * Sets the Container.
     *
     * @param ContainerInterface $container A ContainerInterface instance
     *
     * @api
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }
}
