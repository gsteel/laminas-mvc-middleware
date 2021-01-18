<?php

/**
 * @see       https://github.com/laminas/laminas-mvc-middleware for the canonical source repository
 * @copyright https://github.com/laminas/laminas-mvc-middleware/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-mvc-middleware/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace LaminasTest\Mvc\Middleware\Integration;

use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Mvc\SendResponseListener;
use LaminasTest\Mvc\Middleware\Integration\TestAsset\NoopSendResponseListener;

trait ApplicationTrait
{
    /** @var Application */
    protected $application;

    /**
     * Fail test with exception message if mvc error event is triggered.
     *
     * @var bool
     */
    protected $failOnErrorEvents = true;

    protected function setUpApplication(array $extraConfig = []): Application
    {
        $extraConfig['service_manager']['services'][SendResponseListener::class] = new NoopSendResponseListener();
        $config            = [
            'modules'                 => [
                'Laminas\Router',
                'Laminas\Mvc\Middleware',
            ],
            'module_listener_options' => [
                'config_cache_enabled' => false,
                'extra_config'         => $extraConfig,
            ],
        ];
        $this->application = Application::init($config);

        //setup verbose error listeners
        $errorListener = function (MvcEvent $event) {
            if (! $this->failOnErrorEvents) {
                return;
            }
            $exception = $event->getParam('exception');
            $exception = $exception ?: $event->getError();
            $this->fail((string) $exception);
        };
        $this->application
            ->getEventManager()
            ->attach(MvcEvent::EVENT_DISPATCH_ERROR, $errorListener, -10000);
        $this->application
            ->getEventManager()
            ->attach(MvcEvent::EVENT_RENDER_ERROR, $errorListener, -10000);
        return $this->application;
    }

    protected function tearDownApplication(): void
    {
        $this->application       = null;
        $this->failOnErrorEvents = true;
    }
}
