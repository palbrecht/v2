<?php
namespace Vivo\Module\InstallManager;

use Vivo\Storage\StorageInterface;
use Vivo\Module\Exception;
use Vivo\Storage\StorageUtil;
use Vivo\Storage\Factory as StorageFactory;

/**
 * InstallManager
 */
class InstallManager implements InstallManagerInterface
{
    /**
     * Name of the file containing the JSON data describing the module
     * @var string
     */
    const MODULE_DESCRIPTOR     = 'vivo_module.json';

    /**
     * Module storage
     * @var StorageInterface
     */
    protected $storage;

    /**
     * Paths in storage, where modules can be found
     * @var array
     */
    protected $modulePaths  = array();

    /**
     * Default path in storage, where new modules are installed
     * @var string
     */
    protected $defaultInstallPath;

    /**
     * Storage utilities
     * @var StorageUtil
     */
    protected $storageUtil;

    /**
     * Storage Factory
     * @var StorageFactory
     */
    protected $storageFactory;

    /**
     * Cached storage instances
     * @var array
     */
    protected $storageInstances      = array();

    /**
     * Constructor
     * @param \Vivo\Storage\StorageInterface $storage
     * @param array $modulePaths
     * @param string $defaultInstallPath
     * @param \Vivo\Storage\StorageUtil $storageUtil
     * @param \Vivo\Storage\Factory $storageFactory
     * @throws \Vivo\Module\Exception\InvalidArgumentException
     */
    public function __construct(StorageInterface $storage,
                                array $modulePaths,
                                $defaultInstallPath,
                                StorageUtil $storageUtil,
                                StorageFactory $storageFactory)
    {
        if (!in_array($defaultInstallPath, $modulePaths)) {
            throw new Exception\InvalidArgumentException(
                sprintf("%s: The default install path '%s' is not a module path.", __METHOD__, $defaultInstallPath));
        }
        $this->storage              = $storage;
        $this->modulePaths          = $modulePaths;
        $this->defaultInstallPath   = $defaultInstallPath;
        $this->storageUtil          = $storageUtil;
        $this->storageFactory       = $storageFactory;
    }

    /**
     * Adds a module into storage
     * @param string $moduleUrl
     * @param bool $force
     * @param string|null $installPath
     * @throws \Vivo\Module\Exception\InvalidArgumentException
     * @return mixed
     */
    public function addModule($moduleUrl, $force = false, $installPath = null)
    {
        //Check that $installPath is among the module paths
        if ($installPath) {
            if (!in_array($installPath, $this->modulePaths)) {
                throw new Exception\InvalidArgumentException(
                    sprintf("%s: Install path '%S' is not a valid module path in storage.", __METHOD__, $installPath));
            }
        } else {
            $installPath    = $this->defaultInstallPath;
        }
        $sourceStorage  = $this->getSourceStorage($moduleUrl);
        //Get the module name (i.e. the module namespace), which will be used as installation path
        $pathInSourceStorage    = $this->getModulePathInSourceStorage($moduleUrl);
        $moduleDescriptorPath   = $sourceStorage->buildStoragePath(
                                    array($pathInSourceStorage, self::MODULE_DESCRIPTOR), true);
        $moduleDescJson         = $this->getJsonContent($sourceStorage, $moduleDescriptorPath);
        if (!$moduleDescJson) {
            throw new Exception\DescriptorException(
                sprintf("%s: Cannot read module descriptor from '%s' for module URL '%s'",
                    __METHOD__, self::MODULE_DESCRIPTOR, $moduleUrl));
        }
        if (!isset($moduleDescJson['namespace'])) {
            throw new Exception\DescriptorException(
                sprintf("%s: 'namespace' field missing in module descriptor for module URL '%s'", __METHOD__, $moduleUrl));
        }
        $moduleName = $moduleDescJson['namespace'];
        //Check that the module name has not been added yet
        if ($this->moduleExists($moduleName)) {
            throw new Exception\InvalidArgumentException(
                sprintf("%s: Module '%s' (%s) already exists in module storage", __METHOD__, $moduleName, $moduleUrl));
        }
        //TODO - Read module dependencies on other vmodules and check they have been added (otherwise throw exception)
        //TODO - Read module dependencies on libraries, throw an exception, if unsatisfied
        //Copy the module source to the module storage
        $pathInTargetStorage    = $this->storage->buildStoragePath(array($installPath, $moduleName), true);
        $this->storageUtil->copy($sourceStorage, $pathInSourceStorage, $this->storage, $pathInTargetStorage);
    }

    /**
     * Returns if a module exists in the storage
     * @param string $moduleName
     * @return boolean
     */
    public function moduleExists($moduleName)
    {
        //TODO - refactor to a cheaper implementation, getModules() always scans whole storage
        $modules    = $this->getModules();
        $moduleExists   = array_key_exists($moduleName, $modules);
        return $moduleExists;
    }

    /**
     * Returns an array with information about modules added to the storage
     * @return array
     */
    public function getModules()
    {
        $modules    = array();
        foreach ($this->modulePaths as $moduleBasePath) {
            $scan   = $this->storage->scan($moduleBasePath);
            foreach ($scan as $item) {
                $modulePath     = $this->storage->buildStoragePath(array($moduleBasePath, $item), true);
                $moduleFilePath = $this->storage->buildStoragePath(array($moduleBasePath, $item, 'Module.php'), true);
                $moduleJsonPath = $this->storage->buildStoragePath(array($moduleBasePath, $item, self::MODULE_DESCRIPTOR), true);
                if ($this->storage->isObject($moduleFilePath)) {
                    $moduleJson = $this->getJsonContent($this->storage, $moduleJsonPath);
                    if ((!isset($moduleJson['namespace'])) || ($item != $moduleJson['namespace'])) {
                        throw new Exception\DescriptorException(
                            sprintf("%s: Namespace in the module descriptor does not match module name '%s'.",
                                    __METHOD__, $item));
                    }
                    $moduleDesc = array(
                        'namespace'     => $item,
                        'storage_path'  => $modulePath,
                    );
                    //Version
                    if (isset($moduleJson['version'])) {
                        $moduleDesc['version']  = $moduleJson['version'];
                    } else {
                        $moduleDesc['version']  = null;
                    }
                    //Type
                    if (isset($moduleJson['type'])) {
                        $moduleDesc['type']  = $moduleJson['type'];
                    } else {
                        $moduleDesc['type']  = null;
                    }
                    $modules[$item] = $moduleDesc;
                }
            }
        }
        return $modules;
    }

    /**
     * Returns storage which will be used to access the module source
     * @param string $moduleUrl
     * @return StorageInterface
     */
    protected function getSourceStorage($moduleUrl)
    {
        if (!array_key_exists($moduleUrl, $this->storageInstances)) {
            //TODO - configure for specific storage implementation based on the $moduleUrl
            $streamSpec     = 'file://';
            $root           = substr($moduleUrl, strlen($streamSpec));
            $storageConfig  = array(
                'class'     => 'Vivo\Storage\LocalFileSystemStorage',
                'options'   => array(
                    'root'      => $root,
                ),
            );
            $this->storageInstances[$moduleUrl] = $this->storageFactory->create($storageConfig);
        }
        return $this->storageInstances[$moduleUrl];
    }

    /**
     * Returns an array with values from a json file
     * If the file cannot be found in storage, returns null
     * @param \Vivo\Storage\StorageInterface $storage
     * @param string $path
     * @return array|null
     */
    protected function getJsonContent(StorageInterface $storage, $path)
    {
        if ($storage->isObject($path)) {
            $data   = $storage->get($path);
            $jsonContent    = json_decode($data, true);
        } else {
            $jsonContent    = null;
        }
        return $jsonContent;
    }

    /**
     * Returns the path to module in the source storage
     * @param string $moduleUrl
     * @return string
     */
    protected function getModulePathInSourceStorage($moduleUrl)
    {
        $sourceStorage  = $this->getSourceStorage($moduleUrl);
        //TODO - implement based on the $moduleUrl
        //For file system storage the module is always at the root of the storage
        $modulePath = $sourceStorage->getStoragePathSeparator();
        return $modulePath;
    }
}