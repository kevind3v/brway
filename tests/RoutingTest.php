<?php

namespace BrWay\Tests;

use BrWay\Router;
use BrWay\Tests\Fixtures\Controller;
use BrWay\Tests\Fixtures\Log;

class RoutingTest extends TestCase
{
    public function testRootAndStaticRoutes(): void
    {
        foreach (["/" => "root", "/about" => "about", "/about/" => "about"] as $path => $expected) {
            $router = $this->request("GET", $path);
            $router->get("/", fn() => Log::$calls[] = "root");
            $router->get("/about", fn() => Log::$calls[] = "about");
            $this->assertTrue($router->dispatch());
            $this->assertSame($expected, end(Log::$calls));
        }
    }

    public function testParamsComeFromTheMatchedPath(): void
    {
        $router = $this->request("GET", "/user/5/profile/photo.jpg");
        $router->get("/edit/{id}", fn() => null);
        $router->get("/user/{id}/profile/{photo}", fn(array $data) => Log::$calls[] = $data);

        $this->assertTrue($router->dispatch());
        $this->assertSame([["id" => "5", "photo" => "photo.jpg"]], Log::$calls);
        $this->assertSame(["id" => "5", "photo" => "photo.jpg"], $router->data());
    }

    public function testGroupNameInsideTheParamIsKept(): void
    {
        $router = $this->request("GET", "/name/username");
        $router->group("name");
        $router->get("/{user}", fn(array $data) => Log::$calls[] = $data);

        $this->assertTrue($router->dispatch());
        $this->assertSame([["user" => "username"]], Log::$calls);
    }

    public function testRouteWithoutLeadingSlashInsideGroup(): void
    {
        $router = $this->request("GET", "/admin/user");
        $router->group("admin");
        $router->get("user", fn() => Log::$calls[] = "user");

        $this->assertTrue($router->dispatch());
    }

    public function testMostSpecificRouteWinsInAnyOrder(): void
    {
        $router = $this->request("GET", "/user/new");
        $router->get("/user/{id}", fn() => Log::$calls[] = "id");
        $router->get("/user/new", fn() => Log::$calls[] = "new");
        $router->dispatch();

        $router = $this->request("GET", "/user/new");
        $router->get("/user/new", fn() => Log::$calls[] = "new");
        $router->get("/user/{id}", fn() => Log::$calls[] = "id");
        $router->dispatch();

        $this->assertSame(["new", "new"], Log::$calls);
    }

    public function testRouteIsMatchedLiterally(): void
    {
        foreach (["/fileXjson", "/file.json\n", "/file.json/extra", "/x/file.json"] as $path) {
            $router = $this->request("GET", $path);
            $router->get("/file.json", fn() => Log::$calls[] = $path);
            $this->assertFalse($router->dispatch(), $path);
            $this->assertSame(Router::NOT_FOUND, $router->error());
        }

        $router = $this->request("GET", "/a~b");
        $router->get("/a~b", fn() => Log::$calls[] = "tilde");
        $this->assertTrue($router->dispatch());
    }

    public function testRouteParamRejectsControlChars(): void
    {
        foreach (["/admin\n", "/file.php\0.jpg"] as $path) {
            $router = $this->request("GET", $path);
            $router->get("/{page}", fn() => Log::$calls[] = "page");
            $this->assertFalse($router->dispatch());
        }
        $this->assertSame([], Log::$calls);
    }

    public function testErrors(): void
    {
        $router = $this->request("GET", "/");
        $this->assertFalse($router->dispatch());
        $this->assertSame(Router::NOT_IMPLEMENTED, $router->error());

        $router = $this->request("GET", "/missing");
        $router->get("/", fn() => null);
        $this->assertFalse($router->dispatch());
        $this->assertSame(Router::NOT_FOUND, $router->error());

        $router = $this->request("DELETE", "/");
        $router->get("/", fn() => null);
        $this->assertFalse($router->dispatch());
        $this->assertSame(Router::METHOD_NOT_ALLOWED, $router->error());
    }

    public function testErrorIsClearedOnTheNextDispatch(): void
    {
        $router = $this->request("GET", "/");
        $this->assertFalse($router->dispatch());

        $router->get("/", fn() => null);
        $this->assertTrue($router->dispatch());
        $this->assertNull($router->error());
    }

    public function testHeadUsesGetRoutes(): void
    {
        $router = $this->request("HEAD", "/");
        $router->get("/", fn() => Log::$calls[] = "get");

        $this->assertTrue($router->dispatch());
        $this->assertSame(["get"], Log::$calls);
    }

    public function testControllerHandlers(): void
    {
        $router = $this->request("GET", "/user/7");
        $router->namespace("BrWay\\Tests\\Fixtures");
        $router->get("/user/{id}", "Controller:show");
        $this->assertTrue($router->dispatch());

        $router = $this->request("GET", "/user/8");
        $router->get("/user/{id}", [Controller::class, "other"]);
        $this->assertTrue($router->dispatch());

        $this->assertSame([["show", ["id" => "7"]], ["other", ["id" => "8"]]], Log::$calls);
    }

    public function testCustomSeparator(): void
    {
        $this->request("GET", "/");
        $router = new TestRouter("https://www.site.com", "@");
        $router->namespace("BrWay\\Tests\\Fixtures");
        $router->get("/", "Controller@show");

        $this->assertTrue($router->dispatch());
    }

    public function testMissingHandlersReturnNotImplemented(): void
    {
        $handlers = [
            "Missing:show",
            "Controller:missing",
            "Controller:secret",
            "Controller",
            [Controller::class],
            ["", "show"]
        ];

        foreach ($handlers as $handler) {
            $router = $this->request("GET", "/");
            $router->namespace("BrWay\\Tests\\Fixtures");
            $router->get("/", $handler);

            $this->assertFalse($router->dispatch());
            $this->assertSame(Router::NOT_IMPLEMENTED, $router->error());
        }
        $this->assertSame([], Log::$calls);
    }

    public function testCurrentShowsTheMatchedRoute(): void
    {
        $router = $this->request("GET", "/admin/5");
        $router->namespace("Dash")->group("admin");
        $router->get("/{id}", fn() => null, "admin.user");
        $router->namespace("Web")->group("site");
        $router->get("/", fn() => null);
        $router->dispatch();

        $current = $router->current();
        $this->assertSame("Dash", $current->namespace);
        $this->assertSame("admin", $current->group);
        $this->assertSame("/admin/5", $current->path);
        $this->assertSame("admin.user", $current->name);
        $this->assertSame(["id" => "5"], $current->data);
    }

    public function testNoNoticesWithoutRequestMethod(): void
    {
        unset($_SERVER["REQUEST_METHOD"]);
        $router = new Router("https://www.site.com");

        $this->assertNull($router->route("none"));
        $this->assertSame([], $router->__debugInfo());
    }
}
