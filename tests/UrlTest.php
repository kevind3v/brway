<?php

namespace BrWay\Tests;

class UrlTest extends TestCase
{
    private function router(): TestRouter
    {
        $router = $this->request("GET", "/");
        $router->get("/", fn() => null, "home");
        $router->group("blog");
        $router->get("/{category}/page/{page}", fn() => null, "blog.page");
        return $router;
    }

    public function testNamedRoutes(): void
    {
        $router = $this->router();

        $this->assertSame("https://www.site.com", $router->home());
        $this->assertSame("https://www.site.com", $router->route("home"));
        $this->assertSame(
            "https://www.site.com/blog/php/page/2",
            $router->route("blog.page", ["category" => "php", "page" => 2])
        );
        $this->assertNull($router->route("missing"));
    }

    public function testExtraValuesGoToTheQueryString(): void
    {
        $this->assertSame(
            "https://www.site.com/blog/php/page/2?q=most+search&order=new",
            $this->router()->route("blog.page", ["category" => "php", "page" => 2, "q" => "most search", "order" => "new"])
        );
    }

    public function testParamsAreEncoded(): void
    {
        $this->assertSame(
            "https://www.site.com/blog/..%2Fadmin%3Fx%3D1/page/a%20b",
            $this->router()->route("blog.page", ["category" => "../admin?x=1", "page" => "a b"])
        );
    }
}
