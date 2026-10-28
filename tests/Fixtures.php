<?php

namespace BrWay\Tests\Fixtures;

use BrWay\Router;

/**
 * Registra as chamadas de controllers e middlewares.
 */
class Log
{
    public static array $calls = [];
}

class Controller
{
    public function __construct(private Router $router)
    {
    }

    public function show(array $data): void
    {
        Log::$calls[] = ["show", $data];
    }

    public function other(array $data): void
    {
        Log::$calls[] = ["other", $data];
    }

    private function secret(): void
    {
        Log::$calls[] = ["secret"];
    }
}

class Allow
{
    public function handle(Router $router): bool
    {
        Log::$calls[] = "Allow";
        return true;
    }
}

class Auth
{
    public function handle(Router $router): bool
    {
        Log::$calls[] = "Auth";
        return true;
    }
}

class Deny
{
    public function handle(Router $router): bool
    {
        Log::$calls[] = "Deny";
        return false;
    }
}

class NoHandle
{
}
