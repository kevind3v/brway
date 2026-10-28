<?php

namespace BrWay\Tests;

use BrWay\Router;
use BrWay\Tests\Fixtures\Allow;
use BrWay\Tests\Fixtures\Auth;
use BrWay\Tests\Fixtures\Deny;
use BrWay\Tests\Fixtures\Log;
use BrWay\Tests\Fixtures\NoHandle;

class MiddlewareTest extends TestCase
{
    public function testRouteMiddlewares(): void
    {
        $router = $this->request("GET", "/");
        $router->get("/", fn() => Log::$calls[] = "route", middleware: [Allow::class, Auth::class]);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Allow", "Auth", "route"], Log::$calls);
    }

    public function testGroupMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $router = $this->request("GET", "/admin/users");
        $router->group("admin", Auth::class);
        $router->get("/users", fn() => Log::$calls[] = "route", middleware: Allow::class);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Auth", "Allow", "route"], Log::$calls);
    }

    public function testGroupMiddlewareDoesNotLeakToTheNextGroup(): void
    {
        $router = $this->request("GET", "/site");
        $router->group("admin", Deny::class);
        $router->get("/", fn() => Log::$calls[] = "admin");
        $router->group("site");
        $router->get("/", fn() => Log::$calls[] = "site");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["site"], Log::$calls);
    }

    public function testDenyStopsTheRoute(): void
    {
        $router = $this->request("GET", "/");
        $router->get("/", fn() => Log::$calls[] = "route", middleware: [Deny::class, Allow::class]);

        $this->assertFalse($router->dispatch());
        $this->assertNull($router->error());
        $this->assertSame(["Deny"], Log::$calls);
    }

    public function testInvalidMiddlewares(): void
    {
        foreach (["Missing\\Middleware", NoHandle::class] as $middleware) {
            $router = $this->request("GET", "/");
            $router->get("/", fn() => Log::$calls[] = "route", middleware: $middleware);

            $this->assertFalse($router->dispatch());
            $this->assertSame(Router::NOT_IMPLEMENTED, $router->error());
        }
        $this->assertSame([], Log::$calls);
    }

    public function testHeadAndSpoofingRunTheSameMiddlewares(): void
    {
        $router = $this->request("HEAD", "/admin");
        $router->group("admin", Deny::class);
        $router->get("/", fn() => Log::$calls[] = "route");
        $this->assertFalse($router->dispatch());

        $router = $this->request("POST", "/admin", ["_method" => "DELETE"]);
        $router->group("admin", Deny::class);
        $router->delete("/", fn() => Log::$calls[] = "route");
        $this->assertFalse($router->dispatch());

        $this->assertSame(["Deny", "Deny"], Log::$calls);
    }
}
