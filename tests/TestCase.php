<?php

namespace BrWay\Tests;

use BrWay\Router;

require_once __DIR__ . '/Fixtures.php';

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SERVER["CONTENT_TYPE"], $_SERVER["REQUEST_URI"]);
        TestRouter::$body = "";
        TestRouter::$limit = 0;
        Fixtures\Log::$calls = [];
    }

    /**
     * Simula uma requisição e devolve o router dela.
     */
    protected function request(
        string $method,
        string $path,
        array $post = [],
        string $body = "",
        ?string $contentType = null
    ): TestRouter {
        $_SERVER["REQUEST_METHOD"] = $method;
        $_GET = ["route" => $path];
        $_POST = $post;
        TestRouter::$body = $body;
        if ($contentType) {
            $_SERVER["CONTENT_TYPE"] = $contentType;
        }

        return new TestRouter("https://www.site.com/");
    }
}

/**
 * Router com o corpo da requisição (php://input) controlado pelo teste.
 */
class TestRouter extends Router
{
    public static string $body = "";

    public static int $limit = 0;

    protected function input(int $limit): string
    {
        return self::$body;
    }

    protected function bodyLimit(): int
    {
        return self::$limit;
    }
}
