<?php

/**
 * @see       https://github.com/laminas/laminas-mvc-middleware for the canonical source repository
 * @copyright https://github.com/laminas/laminas-mvc-middleware/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-mvc-middleware/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Laminas\Mvc\Middleware;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\Psr7Bridge\Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class MiddlewareListener extends AbstractListenerAggregate
{
    /** @var HandlerFromPipeSpecFactory */
    private $pipeSpecFactory;

    public function __construct(HandlerFromPipeSpecFactory $pipeSpecFactory)
    {
        $this->pipeSpecFactory = $pipeSpecFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(MvcEvent::EVENT_DISPATCH, [$this, 'onDispatch'], 1);
    }

    /**
     * Listen to the "dispatch" event
     *
     * @return mixed
     */
    public function onDispatch(MvcEvent $event)
    {
        if (null !== $event->getResult()) {
            return null;
        }

        $routeMatch = $event->getRouteMatch();
        if ($routeMatch === null || $routeMatch->getParam('controller') !== PipeSpec::class) {
            return null;
        }

        $middleware = $routeMatch->getParam('middleware', null);

        $request = $event->getRequest();
        /** @var Application $application */
        $application    = $event->getApplication();
        $response       = $application->getResponse();
        $serviceManager = $application->getServiceManager();

        try {
            $pipe = $this->pipeSpecFactory->createFromMiddlewareParam($serviceManager, $middleware);
        } catch (InvalidMiddlewareException $invalidMiddlewareException) {
            $return = $this->marshalInvalidMiddleware(
                Application::ERROR_MIDDLEWARE_CANNOT_DISPATCH,
                $invalidMiddlewareException->toMiddlewareName(),
                $event,
                $application,
                $invalidMiddlewareException
            );
            $event->setResult($return);
            return $return;
        }

        try {
            $return = (new MiddlewareController(
                $pipe,
                $application->getServiceManager()->get('EventManager'),
                $event
            ))->dispatch($request, $response);
        } catch (Throwable $exception) {
            $event->setName(MvcEvent::EVENT_DISPATCH_ERROR);
            $event->setError(Application::ERROR_EXCEPTION);
            $event->setParam('exception', $exception);

            $events  = $application->getEventManager();
            $results = $events->triggerEvent($event);
            $return  = $results->last();
            if (! $return) {
                $return = $event->getResult();
            }
        }

        $event->setError('');

        if (! $return instanceof ResponseInterface) {
            $event->setResult($return);
            return $return;
        }
        $response = Psr7Response::toLaminas($return);
        $event->setResult($response);
        return $response;
    }

    /**
     * Marshal a middleware not callable exception event
     *
     * @return mixed
     */
    protected function marshalInvalidMiddleware(
        string $type,
        string $middlewareName,
        MvcEvent $event,
        Application $application,
        ?Throwable $exception = null
    ) {
        $event->setName(MvcEvent::EVENT_DISPATCH_ERROR);
        $event->setError($type);
        $event->setController($middlewareName);
        $event->setControllerClass('Middleware not callable: ' . $middlewareName);
        if ($exception !== null) {
            $event->setParam('exception', $exception);
        }

        $events  = $application->getEventManager();
        $results = $events->triggerEvent($event);
        $return  = $results->last();
        if (! $return) {
            $return = $event->getResult();
        }
        return $return;
    }
}
