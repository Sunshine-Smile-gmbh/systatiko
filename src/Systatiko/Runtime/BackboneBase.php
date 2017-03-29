<?php

namespace Systatiko\Runtime;

use Civis\Common\ArrayUtil;
use Civis\Common\File;
use Systatiko\Contract\AsynchronousEvent;
use Systatiko\Contract\AsynchronousEventHandler;
use Systatiko\Contract\BackboneContract;
use Systatiko\Contract\SynchronousEvent;
use Systatiko\Contract\SynchronousEventHandler;
use Systatiko\Exception\EventNotDefinedException;

abstract class BackboneBase implements BackboneContract
{

    /**
     * @var string
     */
    protected $context;

    /**
     * @var array
     */
    protected $configurationValueList;

    /**
     * @var AsynchronousEventHandler[]
     */
    protected $asynchronousHandlerList;

    /**
     * @var SynchronousEventHandler[]
     */
    protected $synchronousEventHandler;

    /**
     * @return string
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @param string $context
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * @return array
     */
    public function getConfigurationValueList()
    {
        return $this->configurationValueList;
    }

    /**
     * @param File $configFile
     */
    public function setConfigurationFile(File $configFile)
    {
        $this->setConfigurationValueList($configFile->loadAsJSONArray());
    }

    /**
     * @param array $configurationValueList
     */
    public function setConfigurationValueList(array $configurationValueList)
    {
        $this->configurationValueList = $configurationValueList;
    }

    /**
     * @param $componentName
     *
     * @return null|string
     */
    public function getComponentConfiguration(string $componentName)
    {
        return ArrayUtil::getFromArray($this->configurationValueList, $componentName);
    }

    /**
     * @param AsynchronousEvent $event
     */
    public function dispatchOutboundAsynchronousEvent(AsynchronousEvent $event)
    {
        foreach ($this->asynchronousHandlerList as $handler) {
            $handler->handleEvent($event);
        }
    }

    /**
     * @param SynchronousEvent $event
     */
    public function dispatchSynchronousEvent(SynchronousEvent $event)
    {
        foreach ($this->synchronousEventHandler as $handler) {
            $handler->handleEvent($event);
        }
    }

    /**
     * @param AsynchronousEvent $event
     *
     * @return void
     */
    public abstract function dispatchInboundAsynchronousEvent(AsynchronousEvent $event);

    /**
     * @param string $eventName
     * @param array $payload
     *
     * @return AsynchronousEvent
     *
     * @throws EventNotDefinedException
     */
    public abstract function newAsynchronousEvent(string $eventName, array $payload) : AsynchronousEvent;

}