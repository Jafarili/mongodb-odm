<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataFactory;
use Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;
use Doctrine\ODM\MongoDB\PersistentCollection\DefaultPersistentCollectionFactory;
use Doctrine\ODM\MongoDB\PersistentCollection\DefaultPersistentCollectionGenerator;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionFactory;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionGenerator;
use Doctrine\ODM\MongoDB\Repository\DefaultGridFSRepository;
use Doctrine\ODM\MongoDB\Repository\DefaultRepositoryFactory;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Doctrine\ODM\MongoDB\Repository\GridFSRepository;
use Doctrine\ODM\MongoDB\Repository\RepositoryFactory;
use InvalidArgumentException;
use ProxyManager\Configuration as ProxyManagerConfiguration;
use ProxyManager\Factory\LazyLoadingGhostFactory;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ReflectionClass;
use function trim;

/**
 * Configuration class for the DocumentManager. When setting up your DocumentManager
 * you can optionally specify an instance of this class as the second argument.
 * If you do not pass a configuration object, a blank one will be created for you.
 *
 *     <?php
 *
 *     $config = new Configuration();
 *     $dm = DocumentManager::create(new Connection(), $config);
 */
class Configuration
{
    /**
     * Never autogenerate a proxy/hydrator/persistent collection and rely that
     * it was generated by some process before deployment. Copied from
     * \Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public const AUTOGENERATE_NEVER = 0;

    /**
     * Always generates a new proxy/hydrator/persistent collection in every request.
     *
     * This is only sane during development.
     * Copied from \Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public const AUTOGENERATE_ALWAYS = 1;

    /**
     * Autogenerate the proxy/hydrator/persistent collection class when the file does not exist.
     *
     * This strategy causes a file exists call whenever any proxy/hydrator is used the
     * first time in a request. Copied from \Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public const AUTOGENERATE_FILE_NOT_EXISTS = 2;

    /**
     * Generate the proxy/hydrator/persistent collection classes using eval().
     *
     * This strategy is only sane for development.
     * Copied from \Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public const AUTOGENERATE_EVAL = 3;

    /**
     * Array of attributes for this configuration instance.
     *
     * @var array
     */
    private $attributes = [];

    /** @var ProxyManagerConfiguration|null */
    private $proxyManagerConfiguration;

    /** @var int */
    private $autoGenerateProxyClasses = self::AUTOGENERATE_EVAL;

    public function __construct()
    {
        $this->proxyManagerConfiguration = new ProxyManagerConfiguration();
        $this->setAutoGenerateProxyClasses(self::AUTOGENERATE_FILE_NOT_EXISTS);
    }

    /**
     * Adds a namespace under a certain alias.
     */
    public function addDocumentNamespace(string $alias, string $namespace) : void
    {
        $this->attributes['documentNamespaces'][$alias] = $namespace;
    }

    /**
     * Resolves a registered namespace alias to the full namespace.
     *
     * @throws MongoDBException
     */
    public function getDocumentNamespace(string $documentNamespaceAlias) : string
    {
        if (! isset($this->attributes['documentNamespaces'][$documentNamespaceAlias])) {
            throw MongoDBException::unknownDocumentNamespace($documentNamespaceAlias);
        }

        return trim($this->attributes['documentNamespaces'][$documentNamespaceAlias], '\\');
    }

    /**
     * Retrieves the list of registered document namespace aliases.
     */
    public function getDocumentNamespaces() : array
    {
        return $this->attributes['documentNamespaces'];
    }

    /**
     * Set the document alias map
     */
    public function setDocumentNamespaces(array $documentNamespaces) : void
    {
        $this->attributes['documentNamespaces'] = $documentNamespaces;
    }

    /**
     * Sets the cache driver implementation that is used for metadata caching.
     *
     * @todo Force parameter to be a Closure to ensure lazy evaluation
     *       (as soon as a metadata cache is in effect, the driver never needs to initialize).
     */
    public function setMetadataDriverImpl(MappingDriver $driverImpl) : void
    {
        $this->attributes['metadataDriverImpl'] = $driverImpl;
    }

    /**
     * Add a new default annotation driver with a correctly configured annotation reader.
     */
    public function newDefaultAnnotationDriver(array $paths = []) : AnnotationDriver
    {
        $reader = new AnnotationReader();

        return new AnnotationDriver($reader, $paths);
    }

    /**
     * Gets the cache driver implementation that is used for the mapping metadata.
     */
    public function getMetadataDriverImpl() : ?MappingDriver
    {
        return $this->attributes['metadataDriverImpl'] ?? null;
    }

    public function getMetadataCacheImpl() : ?Cache
    {
        return $this->attributes['metadataCacheImpl'] ?? null;
    }

    public function setMetadataCacheImpl(Cache $cacheImpl) : void
    {
        $this->attributes['metadataCacheImpl'] = $cacheImpl;
    }

    /**
     * Sets the directory where Doctrine generates any necessary proxy class files.
     */
    public function setProxyDir(string $dir) : void
    {
        $this->getProxyManagerConfiguration()->setProxiesTargetDir($dir);

        // Recreate proxy generator to ensure its path was updated
        if ($this->autoGenerateProxyClasses !== self::AUTOGENERATE_FILE_NOT_EXISTS) {
            return;
        }

        $this->setAutoGenerateProxyClasses($this->autoGenerateProxyClasses);
    }

    /**
     * Gets the directory where Doctrine generates any necessary proxy class files.
     */
    public function getProxyDir() : ?string
    {
        return $this->getProxyManagerConfiguration()->getProxiesTargetDir();
    }

    /**
     * Gets an int flag that indicates whether proxy classes should always be regenerated
     * during each script execution.
     */
    public function getAutoGenerateProxyClasses() : int
    {
        return $this->autoGenerateProxyClasses;
    }

    /**
     * Sets an int flag that indicates whether proxy classes should always be regenerated
     * during each script execution.
     *
     * @throws InvalidArgumentException If an invalid mode was given.
     */
    public function setAutoGenerateProxyClasses(int $mode) : void
    {
        $this->autoGenerateProxyClasses = $mode;
        $proxyManagerConfig             = $this->getProxyManagerConfiguration();

        switch ($mode) {
            case self::AUTOGENERATE_FILE_NOT_EXISTS:
                $proxyManagerConfig->setGeneratorStrategy(new FileWriterGeneratorStrategy(
                    new FileLocator($proxyManagerConfig->getProxiesTargetDir())
                ));

                break;
            case self::AUTOGENERATE_EVAL:
                $proxyManagerConfig->setGeneratorStrategy(new EvaluatingGeneratorStrategy());

                break;
            default:
                throw new InvalidArgumentException('Invalid proxy generation strategy given - only AUTOGENERATE_FILE_NOT_EXISTS and AUTOGENERATE_EVAL are supported.');
        }
    }

    public function getProxyNamespace() : ?string
    {
        return $this->getProxyManagerConfiguration()->getProxiesNamespace();
    }

    public function setProxyNamespace(string $ns) : void
    {
        $this->getProxyManagerConfiguration()->setProxiesNamespace($ns);
    }

    public function setHydratorDir(string $dir) : void
    {
        $this->attributes['hydratorDir'] = $dir;
    }

    public function getHydratorDir() : ?string
    {
        return $this->attributes['hydratorDir'] ?? null;
    }

    /**
     * Gets an int flag that indicates whether hydrator classes should always be regenerated
     * during each script execution.
     */
    public function getAutoGenerateHydratorClasses() : int
    {
        return $this->attributes['autoGenerateHydratorClasses'] ?? self::AUTOGENERATE_ALWAYS;
    }

    /**
     * Sets an int flag that indicates whether hydrator classes should always be regenerated
     * during each script execution.
     */
    public function setAutoGenerateHydratorClasses(int $mode) : void
    {
        $this->attributes['autoGenerateHydratorClasses'] = $mode;
    }

    public function getHydratorNamespace() : ?string
    {
        return $this->attributes['hydratorNamespace'] ?? null;
    }

    public function setHydratorNamespace(string $ns) : void
    {
        $this->attributes['hydratorNamespace'] = $ns;
    }

    public function setPersistentCollectionDir(string $dir) : void
    {
        $this->attributes['persistentCollectionDir'] = $dir;
    }

    public function getPersistentCollectionDir() : ?string
    {
        return $this->attributes['persistentCollectionDir'] ?? null;
    }

    /**
     * Gets a integer flag that indicates how and when persistent collection
     * classes should be generated.
     */
    public function getAutoGeneratePersistentCollectionClasses() : int
    {
        return $this->attributes['autoGeneratePersistentCollectionClasses'] ?? self::AUTOGENERATE_ALWAYS;
    }

    /**
     * Sets a integer flag that indicates how and when persistent collection
     * classes should be generated.
     */
    public function setAutoGeneratePersistentCollectionClasses(int $mode) : void
    {
        $this->attributes['autoGeneratePersistentCollectionClasses'] = $mode;
    }

    public function getPersistentCollectionNamespace() : ?string
    {
        return $this->attributes['persistentCollectionNamespace'] ?? null;
    }

    public function setPersistentCollectionNamespace(string $ns) : void
    {
        $this->attributes['persistentCollectionNamespace'] = $ns;
    }

    /**
     * Sets the default DB to use for all Documents that do not specify
     * a database.
     */
    public function setDefaultDB(string $defaultDB) : void
    {
        $this->attributes['defaultDB'] = $defaultDB;
    }

    /**
     * Gets the default DB to use for all Documents that do not specify a database.
     */
    public function getDefaultDB() : ?string
    {
        return $this->attributes['defaultDB'] ?? null;
    }

    public function setClassMetadataFactoryName(string $cmfName) : void
    {
        $this->attributes['classMetadataFactoryName'] = $cmfName;
    }

    public function getClassMetadataFactoryName() : string
    {
        if (! isset($this->attributes['classMetadataFactoryName'])) {
            $this->attributes['classMetadataFactoryName'] = ClassMetadataFactory::class;
        }
        return $this->attributes['classMetadataFactoryName'];
    }

    public function getDefaultCommitOptions() : array
    {
        return $this->attributes['defaultCommitOptions'] ?? ['w' => 1];
    }

    public function setDefaultCommitOptions(array $defaultCommitOptions) : void
    {
        $this->attributes['defaultCommitOptions'] = $defaultCommitOptions;
    }

    /**
     * Add a filter to the list of possible filters.
     */
    public function addFilter(string $name, string $className, array $parameters = []) : void
    {
        $this->attributes['filters'][$name] = [
            'class' => $className,
            'parameters' => $parameters,
        ];
    }

    public function getFilterClassName(string $name) : ?string
    {
        return isset($this->attributes['filters'][$name])
            ? $this->attributes['filters'][$name]['class']
            : null;
    }

    public function getFilterParameters(string $name) : ?array
    {
        return isset($this->attributes['filters'][$name])
            ? $this->attributes['filters'][$name]['parameters']
            : null;
    }

    /**
     * @throws MongoDBException If not is a ObjectRepository.
     */
    public function setDefaultDocumentRepositoryClassName(string $className) : void
    {
        $reflectionClass = new ReflectionClass($className);

        if (! $reflectionClass->implementsInterface(ObjectRepository::class)) {
            throw MongoDBException::invalidDocumentRepository($className);
        }

        $this->attributes['defaultDocumentRepositoryClassName'] = $className;
    }

    public function getDefaultDocumentRepositoryClassName() : string
    {
        return $this->attributes['defaultDocumentRepositoryClassName'] ?? DocumentRepository::class;
    }

    /**
     * @throws MongoDBException If the class does not implement the GridFSRepository interface.
     */
    public function setDefaultGridFSRepositoryClassName(string $className) : void
    {
        $reflectionClass = new ReflectionClass($className);

        if (! $reflectionClass->implementsInterface(GridFSRepository::class)) {
            throw MongoDBException::invalidGridFSRepository($className);
        }

        $this->attributes['defaultGridFSRepositoryClassName'] = $className;
    }

    public function getDefaultGridFSRepositoryClassName() : string
    {
        return $this->attributes['defaultGridFSRepositoryClassName'] ?? DefaultGridFSRepository::class;
    }

    public function setRepositoryFactory(RepositoryFactory $repositoryFactory) : void
    {
        $this->attributes['repositoryFactory'] = $repositoryFactory;
    }

    public function getRepositoryFactory() : RepositoryFactory
    {
        return $this->attributes['repositoryFactory'] ?? new DefaultRepositoryFactory();
    }

    public function setPersistentCollectionFactory(PersistentCollectionFactory $persistentCollectionFactory) : void
    {
        $this->attributes['persistentCollectionFactory'] = $persistentCollectionFactory;
    }

    public function getPersistentCollectionFactory() : PersistentCollectionFactory
    {
        if (! isset($this->attributes['persistentCollectionFactory'])) {
            $this->attributes['persistentCollectionFactory'] = new DefaultPersistentCollectionFactory();
        }
        return $this->attributes['persistentCollectionFactory'];
    }

    public function setPersistentCollectionGenerator(PersistentCollectionGenerator $persistentCollectionGenerator) : void
    {
        $this->attributes['persistentCollectionGenerator'] = $persistentCollectionGenerator;
    }

    public function getPersistentCollectionGenerator() : PersistentCollectionGenerator
    {
        if (! isset($this->attributes['persistentCollectionGenerator'])) {
            $this->attributes['persistentCollectionGenerator'] = new DefaultPersistentCollectionGenerator(
                $this->getPersistentCollectionDir(),
                $this->getPersistentCollectionNamespace()
            );
        }
        return $this->attributes['persistentCollectionGenerator'];
    }

    public function buildGhostObjectFactory() : LazyLoadingGhostFactory
    {
        return new LazyLoadingGhostFactory(clone $this->getProxyManagerConfiguration());
    }

    public function getProxyManagerConfiguration() : ProxyManagerConfiguration
    {
        return $this->proxyManagerConfiguration;
    }
}
