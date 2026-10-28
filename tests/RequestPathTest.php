<?php

namespace BrWay\Tests;

use BrWay\Router;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestPathTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER["REQUEST_URI"], $_SERVER["SCRIPT_NAME"]);
    }

    private function path(string $projectUrl, ?string $uri, string $script, ?string $route = null): string
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = $uri;
        $_SERVER["SCRIPT_NAME"] = $script;
        $_GET = ($route !== null ? ["route" => $route] : []);
        if ($uri === null) {
            unset($_SERVER["REQUEST_URI"]);
        }

        return (new Router($projectUrl))->current()->path;
    }

    public static function requests(): array
    {
        return [
            "raiz" => ["https://site.com", "/", "/index.php", null, ""],
            "rota" => ["https://site.com", "/user/5", "/index.php", null, "/user/5"],
            "barra final e query" => ["https://site.com/", "/user/5/?x=1", "/index.php", null, "/user/5"],
            "espaço" => ["https://site.com", "/search/a%20b", "/index.php", null, "/search/a b"],
            "mais é literal" => ["https://site.com", "/search/a+b", "/index.php", null, "/search/a+b"],
            "e comercial" => ["https://site.com", "/search/a%26b", "/index.php", null, "/search/a&b"],
            "cerquilha" => ["https://site.com", "/search/a%23b", "/index.php", null, "/search/a#b"],
            "acento" => ["https://site.com", "/search/caf%C3%A9", "/index.php", null, "/search/café"],
            "?route= não troca a rota" => ["https://site.com", "/foo?route=/user/9", "/index.php", "/user/9", "/foo"],
            "rewrite antigo com ?route=" => ["https://site.com", "/user/5", "/index.php", "/user/5", "/user/5"],
            "subpasta" => ["https://site.com/loja", "/loja/user/5", "/loja/index.php", null, "/user/5"],
            "subpasta raiz" => ["https://site.com/loja", "/loja", "/loja/index.php", null, ""],
            "prefixo parecido" => ["https://site.com/app", "/apple", "/index.php", null, "/apple"],
            "projectUrl de dev em produção" => ["https://localhost/projeto", "/user/5", "/index.php", null, "/user/5"],
            "projectUrl na raiz, site em subpasta" => ["https://site.com", "/loja/user/5", "/loja/index.php", null, "/user/5"],
            "index.php em public/" => ["https://site.com", "/user/5", "/public/index.php", null, "/user/5"],
            "proxy que tira o prefixo" => ["https://site.com/app", "/user/5", "/app/index.php", null, "/user/5"],
            "proxy, rewrite antigo" => ["https://site.com/app", "/user/5", "/app/index.php", "/user/5", "/user/5"],
            "index.php?route= sem barra" => ["https://site.com", "/index.php?route=admin", "/index.php", "admin", ""],
            "index.php?route= não abre a rota" => ["https://site.com", "/index.php?route=/admin", "/index.php", "/admin", ""],
            "?route= fora do index.php é ignorado" => ["https://site.com", "/?route=user/5", "/index.php", "user/5", ""],
            "index.php direto" =>["https://site.com", "/index.php", "/index.php", null, ""],
            "index.php/caminho não abre a rota" => ["https://site.com/loja", "/loja/index.php/admin", "/loja/index.php", null, "/index.php/admin"],
            "pasta com espaço" => ["https://site.com/minha%20pasta", "/minha%20pasta/user/5", "/minha pasta/index.php", null, "/user/5"],
            "URL absoluta na requisição" => ["https://site.com", "https://site.com/user/5", "/index.php", null, "/user/5"],
            "barra dupla não vira rota" => ["https://site.com", "//user/5", "/index.php", null, "//user/5"],
            "ponto-ponto não é resolvido" => ["https://site.com", "/x/../admin", "/index.php", null, "/x/../admin"],
            "CLI usa ?route=" => ["https://site.com", null, "vendor/bin/phpunit", "/user/5", "/user/5"],
            "CLI sem nada" => ["https://site.com", null, "vendor/bin/phpunit", null, ""]
        ];
    }

    #[DataProvider('requests')]
    public function testPath(string $projectUrl, ?string $uri, string $script, ?string $route, string $expected): void
    {
        $this->assertSame($expected, $this->path($projectUrl, $uri, $script, $route));
    }

    public function testRouteParamsWithSpecialChars(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/search/x%26role%3Dadmin%20%2B1";
        $_SERVER["SCRIPT_NAME"] = "/index.php";

        $router = new Router("https://site.com");
        $router->get("/search/{q}", fn() => null);
        $router->get("/admin", fn() => null);

        $this->assertTrue($router->dispatch());
        $this->assertSame(["q" => "x&role=admin +1"], $router->data());
        $this->assertArrayNotHasKey("role", $_GET);
    }

    public function testRouteQueryParamDoesNotOpenAnotherRoute(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/blog?route=/admin";
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $_GET = ["route" => "/admin"];

        $router = new Router("https://site.com");
        $router->get("/blog", fn() => null, "blog");
        $router->get("/admin", fn() => null, "admin");

        $this->assertTrue($router->dispatch());
        $this->assertSame("blog", $router->current()->name);
    }
}
