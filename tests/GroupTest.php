<?php

namespace BrWay\Tests;

use BrWay\Router;
use BrWay\Tests\Fixtures\Allow;
use BrWay\Tests\Fixtures\Auth;
use BrWay\Tests\Fixtures\Deny;
use BrWay\Tests\Fixtures\Log;

class GroupTest extends TestCase
{
    public function testThisIsTheRouterInARoutesFile(): void
    {
        $router = $this->request("GET", "/admin/user/5");
        $router->group("admin", require __DIR__ . "/routes.php", Auth::class);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Auth", ["id" => "5"]], Log::$calls);
    }

    public function testRoutesAfterTheGroupAreOutsideIt(): void
    {
        $router = $this->request("GET", "/contact");
        $router->group("admin", function (Router $router) {
            $router->get("/", fn() => Log::$calls[] = "admin");
        }, Deny::class);
        $router->get("/contact", fn() => Log::$calls[] = "contact", "contact");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["contact"], Log::$calls);
        $this->assertNull($router->current()->group);
        $this->assertSame([], $router->current()->middlewares);
    }

    public function testNestedGroups(): void
    {
        $router = $this->request("GET", "/admin/user/5");
        $router->group("admin", function (Router $router) {
            $router->group("user", function (Router $router) {
                $router->get("/{id}", fn() => Log::$calls[] = "route", "admin.user", Allow::class);
            });
            $router->get("/", fn() => Log::$calls[] = "admin");
        }, Auth::class);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Auth", "Allow", "route"], Log::$calls);
        $this->assertSame("admin/user", $router->current()->group);
        $this->assertSame("https://www.site.com/admin/user/7", $router->route("admin.user", ["id" => 7]));
    }

    public function testInnerGroupGoesBackToTheOuterGroup(): void
    {
        $router = $this->request("GET", "/admin/settings");
        $router->group("admin", function (Router $router) {
            $router->group("user", function (Router $router) {
                $router->get("/settings", fn() => Log::$calls[] = "user");
            }, Deny::class);
            $router->get("/settings", fn() => Log::$calls[] = "admin");
        }, Auth::class);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Auth", "admin"], Log::$calls);
    }

    public function testNamespaceIsRestored(): void
    {
        $router = $this->request("GET", "/");
        $router->namespace("App");
        $router->group("admin", function (Router $router) {
            $router->namespace("Admin");
            $router->get("/", "Dash:home");
        });
        $router->get("/", "Web:home");

        $router->dispatch();
        $this->assertSame("App\\Web", $router->current()->handler);
    }

    public function testStateIsRestoredAfterAnException(): void
    {
        $router = $this->request("GET", "/");
        try {
            $router->group("admin", function () {
                throw new \RuntimeException();
            }, Deny::class);
        } catch (\RuntimeException) {
        }
        $router->get("/", fn() => Log::$calls[] = "home");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["home"], Log::$calls);
    }

    public function testStaticClosureReceivesTheRouter(): void
    {
        $router = $this->request("GET", "/api/ping");
        $router->group("api", static function (Router $router) {
            $router->get("/ping", fn() => Log::$calls[] = "pong");
        });

        $this->assertTrue($router->dispatch());
        $this->assertSame(["pong"], Log::$calls);
    }

    public function testThisIsTheRouterInAClosureCreatedInAClass(): void
    {
        $router = $this->request("GET", "/admin");
        $router->group("admin", function () {
            $this->get("/", fn() => Log::$calls[] = "admin");
        });

        $this->assertTrue($router->dispatch());
        $this->assertSame(["admin"], Log::$calls);
    }

    public function testMethodReceivesTheRouter(): void
    {
        $router = $this->request("GET", "/admin");
        $router->group("admin", $this->adminRoutes(...));

        $this->assertTrue($router->dispatch());
        $this->assertSame(["admin"], Log::$calls);
    }

    public function adminRoutes(Router $router): void
    {
        $router->get("/", fn() => Log::$calls[] = "admin");
    }

    public function testClosesTheOpenGroupWithoutFunction(): void
    {
        $router = $this->request("GET", "/error/404");
        $router->group("call", Deny::class);
        $router->get("/", fn() => Log::$calls[] = "call");
        $router->group("error", function () {
            $this->get("/{errcode}", fn() => Log::$calls[] = "error");
        });
        $router->get("/contact", fn() => Log::$calls[] = "contact", "contact");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["error"], Log::$calls);
        $this->assertSame("https://www.site.com/contact", $router->route("contact"));
    }

    public function testOldFormStillWorks(): void
    {
        $router = $this->request("GET", "/admin");
        $router->group("admin", middleware: Auth::class);
        $router->get("/", fn() => Log::$calls[] = "admin");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["Auth", "admin"], Log::$calls);
    }
}
