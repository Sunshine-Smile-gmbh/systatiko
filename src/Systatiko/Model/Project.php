<?php

namespace Systatiko\Model;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Systatiko\Annotation\FacadeExposition;
use Systatiko\Annotation\Factory;
use Systatiko\Reader\PHPClass;
use Systatiko\Reader\PHPMethod;

class Project implements LoggerAwareInterface
{

    const ERROR_NO_FACTORY_FOR_FACADE = "There is no factory defined for '%s'. Include @Factory annotation in exposed class.";

    const ERROR_NO_FACTORY_FOR_CONFIGURATION = "Could not assign Configuration in class '%s' There is no factory defined for '%s'.";

    const ERROR_NO_FACTORY_FOR_EVENT = "Could not assign Event in class '%s' There is no factory defined for '%s'.";

    const ERROR_CONFIGURATION_DOES_NOT_IMPLEMENT = "The class '%s' does not implement ComponentConfiguration interface";

    const WARNING_MORE_THAN_ONE_CONFIG_FOR_FACTORY = "Class '%s' and '%s' define configuration for namespace '%s'. Using '%s'";

    const CONFIGURATION_INTERFACE = 'Systatiko\Contract\ComponentConfiguration';

    /**
     * @var PHPClass[]
     */
    protected $phpClassList;

    /**
     * @var ProjectClass[]
     */
    protected $projectClassList;

    /**
     * @var BackboneModel
     */
    protected $backboneModel;

    /**
     * @var ComponentFactory[]
     */
    protected $componentFactoryList;

    /**
     * @var ComponentFacade[]
     */
    protected $componentFacadeList;

    /**
     * @var ComponentConfigurationModel[]
     */
    protected $componentConfigurationList;

    /**
     * @var ComponentEvent[]
     */
    protected $componentEventList;

    /**
     * @var LoggerInterface
     */
    protected $loggerInterface;

    /**
     * @var int
     */
    protected $errorCount;

    /**
     * @var int
     */
    protected $warningCount;

    /**
     * Project constructor.
     */
    public function __construct()
    {
        $this->errorCount = 0;
        $this->warningCount = 0;
        $this->phpClassList = [];
        $this->componentFactoryList = [];
        $this->componentFacadeList = [];
        $this->componentConfigurationList = [];
        $this->componentEventList = [];
    }

    /**
     * @param array $phpClassList
     */
    public function addPHPClassList(array $phpClassList)
    {
        $this->phpClassList = array_merge($this->phpClassList, $phpClassList);
    }

    /**
     * @param string $backboneExtendsName
     */
    public function analyze(string $backboneExtendsName)
    {
        $backboneExtendsBaseClass = $this->getPHPClassByName($backboneExtendsName);
        $this->backboneModel = new BackboneModel($backboneExtendsBaseClass);

        foreach ($this->phpClassList as $phpClass) {
            $this->analyzePHPClass($phpClass);
        }

        $this->update();
    }

    /**
     * @return PHPMethod[]
     */
    public function getGlobalExposeMethodList()
    {
        return $this->backboneModel->getExposeList();
    }

    protected function update()
    {
        $this->logDebug("updating model");

        foreach ($this->componentConfigurationList as $configurationModel) {
            $this->updateComponentConfiguration($configurationModel);
        }

        foreach ($this->componentFactoryList as $componentFactory) {
            $componentFactory->update($this);
        }

        foreach ($this->componentEventList as $componentEvent) {
            $this->updateComponentEvent($componentEvent);
        }

        foreach ($this->componentFacadeList as $componentFacade) {
            $componentFacade->update($this);
        }

    }

    /**
     * @param ComponentConfigurationModel $componentConfiguration
     */
    protected function updateComponentConfiguration(ComponentConfigurationModel $componentConfiguration)
    {
        $namespace = $componentConfiguration->getNamespace();
        $className = $componentConfiguration->getClassName();

        $factory = $this->getResponsibleComponentFactory($namespace);
        if ($factory === null) {

            $error = sprintf(self::ERROR_NO_FACTORY_FOR_CONFIGURATION, $className, $namespace);
            $this->logError($error);
            return;
        }

        $class = $componentConfiguration->getProjectClass();
        if (!$class->implementsInterface(self::CONFIGURATION_INTERFACE)) {
            $error = sprintf(self::ERROR_CONFIGURATION_DOES_NOT_IMPLEMENT, $className);
            $this->logError($error);
        }

        $existing = $factory->getComponentConfigurationModel();
        if ($existing !== null) {
            $existingClass = $existing->getClassName();

            $warning = sprintf(self::WARNING_MORE_THAN_ONE_CONFIG_FOR_FACTORY, $existingClass, $className, $namespace, $existingClass);
            $this->logWarning($warning);
        }

        $factory->setComponentConfigurationModel($componentConfiguration);

    }

    /**
     * @param ComponentEvent $componentEvent
     */
    protected function updateComponentEvent(ComponentEvent $componentEvent)
    {
        $namespace = $componentEvent->getNamespace();
        $className = $componentEvent->getEventClassName();

        $factory = $this->getResponsibleComponentFactory($namespace);
        if ($factory === null) {
            $error = sprintf(self::ERROR_NO_FACTORY_FOR_EVENT, $className, $namespace);
            $this->logError($error);
            return;
        }
        $factory->addComponentEvent($componentEvent);
    }

    /**
     * @param PHPClass $phpClass
     */
    protected function analyzePHPClass(PHPClass $phpClass)
    {
        $projectClass = new ProjectClass($this, $phpClass);

        $this->logDebug("Analyzing " . $phpClass->getClassName());

        $this->handleFactoryAnnotation($projectClass);

        $this->handleFacadeAnnotation($projectClass);

        $this->handleComponentConfiguration($projectClass);

        $this->handleEventAnnotation($projectClass);

    }

    /**
     * @param ProjectClass $projectClass
     */
    protected function handleFactoryAnnotation(ProjectClass $projectClass)
    {
        $factoryAnnotation = $projectClass->getComponentFactoryAnnotation();
        if ($factoryAnnotation === null) {
            return;
        }
        $componentFactory = $this->getOrCreateResponsibleComponentFactory($factoryAnnotation);
        $componentFactory->addFactoryMethod($factoryAnnotation, $projectClass);
    }

    /**
     * @param ProjectClass $projectClass
     */
    protected function handleFacadeAnnotation(ProjectClass $projectClass)
    {
        $facadeAnnotation = $projectClass->getComponentFacadeAnnotation();
        if ($facadeAnnotation === null) {
            return;
        }
        $componentFactory = $this->getResponsibleComponentFactory($facadeAnnotation->getNamespace());
        if ($componentFactory === null) {
            $error = sprintf(self::ERROR_NO_FACTORY_FOR_FACADE, $facadeAnnotation->getNamespace());
            $this->logError($error);
            return;
        }

        $componentFacade = $this->getOrCreateResponsibleComponentFacade($facadeAnnotation, $componentFactory);

        $componentFactory->setComponentFacade($componentFacade);
        $componentFacade->addFacadeMethodList($facadeAnnotation, $projectClass);
    }

    /**
     * @param ProjectClass $projectClass
     */
    protected function handleComponentConfiguration(ProjectClass $projectClass)
    {
        $configurationAnnotation = $projectClass->getComponentConfigurationAnnotation();
        if ($configurationAnnotation === null) {
            return;
        }

        $componentConfiguration = new ComponentConfigurationModel();
        $componentConfiguration->setProjectClass($projectClass);
        $componentConfiguration->setNamespace($configurationAnnotation->getNamespace());

        $this->componentConfigurationList[] = $componentConfiguration;
    }

    /**
     * @param ProjectClass $projectClass
     */
    protected function handleEventAnnotation(ProjectClass $projectClass)
    {
        $eventAnnotation = $projectClass->getEventAnnotation();

        if ($eventAnnotation === null) {
            return;
        }

        $this->componentEventList[] = new ComponentEvent($projectClass, $eventAnnotation);
    }

    /**
     * @param Factory $factory
     *
     * @return ComponentFactory
     */
    protected function getOrCreateResponsibleComponentFactory(Factory $factory) : ComponentFactory
    {
        $componentFactory = $this->getResponsibleComponentFactory($factory->getNamespace());
        if ($componentFactory !== null) {
            return $componentFactory;
        }
        $componentFactory = new ComponentFactory($this, $factory);
        $this->componentFactoryList[] = $componentFactory;
        return $componentFactory;
    }

    /**
     * @param string $namespace
     *
     * @return ComponentFactory|null
     */
    protected function getResponsibleComponentFactory(string $namespace)
    {
        foreach ($this->componentFactoryList as $componentFactory) {
            if ($componentFactory->isResponsible($namespace)) {
                return $componentFactory;
            }
        }
        return null;
    }

    /**
     * @param string $eventClassName
     *
     * @return ComponentEvent|null
     */
    public function getComponentEventByName(string $eventClassName)
    {
        foreach ($this->componentEventList as $componentEvent) {
            if ($componentEvent->getEventClassName() === $eventClassName) {
                return $componentEvent;
            }
        }
        return null;
    }

    /**
     * @param FacadeExposition $facadeExposition
     * @param ComponentFactory $componentFactory
     *
     * @return ComponentFacade
     */
    protected function getOrCreateResponsibleComponentFacade(FacadeExposition $facadeExposition, ComponentFactory $componentFactory) : ComponentFacade
    {
        $componentFacade = $this->getResponsibleComponentFacade($facadeExposition->getNamespace());
        if ($componentFacade !== null) {
            return $componentFacade;
        }

        $componentFacade = new ComponentFacade($this, $facadeExposition, $componentFactory);
        $this->componentFacadeList[] = $componentFacade;
        return $componentFacade;

    }

    /**
     * @param $namespace
     *
     * @return ComponentFacade|null
     */
    protected function getResponsibleComponentFacade(string $namespace)
    {
        foreach ($this->componentFacadeList as $componentFacade) {
            if ($componentFacade->isResponsible($namespace)) {
                return $componentFacade;
            }
        }
        return null;
    }

    /**
     * @param string $className
     *
     * @return null|string
     */
    public function getBackboneAccessor(string $className)
    {
        foreach ($this->componentFacadeList as $componentFacade) {
            $accessor = $componentFacade->getBackboneAccessor($className);
            if ($accessor !== null) {
                return $accessor;
            }
        }
        return null;
    }

    /**
     * @param string $className
     *
     * @return ProjectClass|null
     */
    public function getProjectClassByName(string $className)
    {
        foreach ($this->projectClassList as $projectClass) {
            if ($projectClass->getClassName() === $className) {
                return $projectClass;
            }
        }
        return null;
    }

    /**
     * @param string $className
     *
     * @return PHPClass|null
     */
    public function getPHPClassByName(string $className)
    {
        foreach ($this->phpClassList as $phpClass) {
            if ($phpClass->getClassName() === $className) {
                return $phpClass;
            }
        }
        return null;
    }

    /**
     * @return ComponentFactory[]
     */
    public function getComponentFactoryList()
    {
        return $this->componentFactoryList;
    }

    /**
     * @return ComponentFacade[]
     */
    public function getComponentFacadeList()
    {
        return $this->componentFacadeList;
    }

    /**
     * @return ComponentEvent[]
     */
    public function getComponentEventList()
    {
        return $this->componentEventList;
    }

    /**
     * @param string $message
     */
    public function logWarning(string $message)
    {
        $this->warningCount++;
        if ($this->loggerInterface === null) {
            return;
        }
        $this->loggerInterface->warning($message);
    }

    /**
     * @param string $message
     */
    public function logError(string $message)
    {
        $this->errorCount++;
        if ($this->loggerInterface === null) {
            return;
        }
        $this->loggerInterface->error($message);
    }

    /**
     * @param string $message
     */
    public function logInfo(string $message)
    {
        if ($this->loggerInterface === null) {
            return;
        }
        $this->loggerInterface->info($message);
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message)
    {
        if ($this->loggerInterface === null) {
            return;
        }
        $this->loggerInterface->debug($message);
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->loggerInterface = $logger;
        return null;
    }

    /**
     * @return int
     */
    public function getErrorCount()
    {
        return $this->errorCount;
    }

    /**
     * @return int
     */
    public function getWarningCount()
    {
        return $this->warningCount;
    }

}