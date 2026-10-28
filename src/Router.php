<?php

declare(strict_types=1);

namespace BrWay;

/**
 * Class Router
 * @package BrWay
 */
class Router extends Dispatch
{
    /**
     * Router constructor.
     *
     * @param string $projectUrl
     * @param null|string $separator
     */
    public function __construct(string $projectUrl, ?string $separator = ":")
    {
        parent::__construct($projectUrl, $separator);
    }

    /**
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    public function get(
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $this->addRoute("GET", $route, $handler, $name, $middleware);
    }

    /**
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    public function post(
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $this->addRoute("POST", $route, $handler, $name, $middleware);
    }

    /**
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    public function put(
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $this->addRoute("PUT", $route, $handler, $name, $middleware);
    }

    /**
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    public function patch(
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $this->addRoute("PATCH", $route, $handler, $name, $middleware);
    }

    /**
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    public function delete(
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $this->addRoute("DELETE", $route, $handler, $name, $middleware);
    }
}
