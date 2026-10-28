<?php

/*
 * Os controllers e middlewares do exemplo imprimem antes de redirecionar: o buffer
 * segura a saída para o header("Location") ainda funcionar.
 */
ob_start();

require dirname(__DIR__, 2) . "/vendor/autoload.php";
require __DIR__ . "/Test/Coffee.php";
require __DIR__ . "/Test/Name.php";

/*
 * Middleware example classes
 */
require __DIR__ . "/Http/Middlewares.php";
require __DIR__ . "/Http/Guest.php";
require __DIR__ . "/Http/User.php";
require __DIR__ . "/Http/Group.php";

use BrWay\Router;
use Http\Middlewares as Middleware;

/*
 * URL do exemplo. O Host enviado pelo cliente só é aceito se for localhost (qualquer porta),
 * para o exemplo rodar local sem permitir redirect para outro site. Em produção, use a URL fixa.
 */
$host = (preg_match("~^(localhost|127\.0\.0\.1)(:\d+)?$~", $_SERVER["HTTP_HOST"] ?? "") ? $_SERVER["HTTP_HOST"] : "localhost");
define("BASE", "http://{$host}" . rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/"));
$router = new Router(BASE);

/**
 * routes
 */
$router->namespace("Test");

$router->get("/", "Coffee:home");
$router->get("/edit/{id}", "Coffee:edit", middleware: Middleware::GUEST);
$router->post("/edit/{id}", "Coffee:edit");
$router->get("/logado", "Coffee:logged", middleware: [\Http\Guest::class, \Http\User::class]);
$router->get("/negado", "Coffee:denied", "coffe.denied", Middleware::USER);

/**
 * group by routes and namespace
 */
$router->group("admin", function () {
    $this->get("/", "Coffee:admin");
    $this->get("/user/{id}", "Coffee:admin");
    $this->get("/user/{id}/profile", "Coffee:admin", middleware: \Http\Guest::class);
    $this->get("/user/{id}/profile/{photo}", "Coffee:admin");
}, \Http\Group::class);

/**
 * named routes and middlewares
 */
$router->group("name", function () {
    $this->get("/", "Name:home", "name.home");
    $this->get("/hello", "Name:hello", "name.hello", \Http\Guest::class);
    $this->get("/params/{category}/page/{page}", "name:params", "name.params");
    $this->get("/redirect", "Name:redirect", "name.redirect", Middleware::GUEST);
    $this->get("/redirect/{category}/{page}", "name:redirect", "name.redirect.params");
});

/**
 * call route and group middleware
 */
$router->group("call", function () {
    $show = function ($data, Router $route) {
        echo "<pre>", htmlspecialchars(print_r([$data, $route->current()], true)), "</pre>";

        echo "<a href='{$route->home()}' title='voltar'>voltar</a>";
    };

    $this->get("/", $show);
    $this->get("/{app}/", $show);
}, Middleware::GUEST);

/**
 * Group Error
 */
$router->group("error", function () {
    $this->get("/{errcode}", "Coffee:notFound");
});

/**
 * execute
 */
$router->dispatch();

if ($router->error()) {
    //var_dump($router->error());
    $router->redirect("/error/{$router->error()}");
}