<?php

declare(strict_types=1);

namespace BrWay;

/**
 * Trait RouterTrait
 * @package BrWay
 */
trait RouterTrait
{
    /** @var string parâmetro da rota, ex.: {id} */
    private string $placeholder = "~\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*\}~";

    /**
     * @param string $method
     * @param string $route
     * @param callable|array|string $handler
     * @param string|null $name
     * @param array|string|null $middleware
     */
    protected function addRoute(
        string $method,
        string $route,
        callable|array|string $handler,
        ?string $name = null,
        array|string|null $middleware = null
    ): void {
        $route = rtrim(($this->group ? "/{$this->group}" : "") . "/" . trim($route, "/"), "/");

        $regex = "";
        $params = [];
        $parts = preg_split($this->placeholder, $route, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2) {
                $params[] = $part;
                $regex .= "([^/\\x00-\\x1F\\x7F]+)";
                continue;
            }
            $regex .= preg_quote($part, "~");
        }

        $this->routes[$method][$regex] = [
            "route" => $route,
            "name" => $name,
            "method" => $method,
            "namespace" => $this->namespace,
            "group" => $this->group,
            "middlewares" => array_values(array_unique(array_merge($this->middleware, (array)$middleware))),
            "handler" => $this->handler($handler, $this->namespace),
            "action" => $this->action($handler),
            "params" => $params,
            "data" => null
        ];
    }

    /**
     * Rota que casa com o caminho. Entre várias, vence a com menos parâmetros (a mais específica)
     * e, no empate, a registrada primeiro.
     *
     * @param array $routes
     * @return array|null
     */
    private function match(array $routes): ?array
    {
        $match = null;
        foreach ($routes as $regex => $route) {
            if (!preg_match("~^{$regex}$~D", $this->path, $found)) {
                continue;
            }

            if ($match === null || count($route["params"]) < count($match["params"])) {
                $values = array_map("strval", array_slice($found, 1));
                $route["data"] = array_merge($this->data ?? [], array_combine($route["params"], $values));
                $match = $route;
            }
        }
        return $match;
    }

    /**
     * Caminho requisitado, lido da URL original (REQUEST_URI) sem a pasta do projeto. A pasta é a do
     * projectUrl ou a do index.php (SCRIPT_NAME), a mais longa que bater. O parâmetro "route" só é usado
     * sem REQUEST_URI (CLI) ou quando nenhuma das pastas bate. "/index.php/admin" e "/index.php?route=/admin"
     * não abrem a rota /admin: o servidor e o router precisam ler o mesmo caminho, senão um bloqueio feito
     * no servidor (ex.: deny em /admin) é contornado.
     *
     * @return string
     */
    protected function requestPath(): string
    {
        $route = $_GET["route"] ?? null;
        $route = (is_string($route) ? rtrim("/" . ltrim($route, "/"), "/") : null);

        $uri = $_SERVER["REQUEST_URI"] ?? null;
        if (!is_string($uri) || $uri === "") {
            return ($route ?? "");
        }

        $uri = preg_replace("~^[a-z][a-z0-9+.-]*://[^/]*~i", "", explode("?", $uri, 2)[0]);
        $path = rawurldecode($uri);

        $script = str_replace("\\", "/", (string)($_SERVER["SCRIPT_NAME"] ?? ""));
        $folder = dirname($script);
        $bases = [
            rtrim(rawurldecode((string)parse_url($this->projectUrl, PHP_URL_PATH)), "/"),
            (in_array($folder, ["/", "."], true) ? "" : rtrim($folder, "/"))
        ];

        $base = null;
        foreach ($bases as $candidate) {
            if (
                ($path === $candidate || str_starts_with($path, "{$candidate}/"))
                && ($base === null || strlen($candidate) > strlen($base))
            ) {
                $base = $candidate;
            }
        }

        if ($base === null) {
            return ($route ?? rtrim($path, "/"));
        }

        $path = rtrim(substr($path, strlen($base)), "/");
        return ($script !== "" && $path === "/" . basename($script) ? "" : $path);
    }

    /**
     * Dados da requisição e httpMethod form spoofing (_method só é aceito num POST).
     */
    protected function formSpoofing(): void
    {
        $this->data = [];

        if ($this->httpMethod === "POST") {
            $this->data = ($_POST ?: $this->body());

            $spoofing = $this->data["_method"] ?? null;
            if (is_string($spoofing) && in_array(strtoupper($spoofing), ["PUT", "PATCH", "DELETE"], true)) {
                $this->httpMethod = strtoupper($spoofing);
            }

            unset($this->data["_method"]);
            return;
        }

        if (in_array($this->httpMethod, ["PUT", "PATCH", "DELETE"], true)) {
            $this->data = $this->body();
            unset($this->data["_method"]);
        }
    }

    /**
     * Corpo da requisição em JSON (application/json ou tipos "+json") ou x-www-form-urlencoded.
     * Acima do post_max_size, os dados vêm vazios, como o PHP faz no POST.
     *
     * @return array
     */
    private function body(): array
    {
        $limit = $this->bodyLimit();
        $input = $this->input($limit);
        if ($input === "" || ($limit > 0 && strlen($input) > $limit)) {
            return [];
        }

        $type = strtolower(trim(explode(";", (string)($_SERVER["CONTENT_TYPE"] ?? ""))[0]));
        if ($type === "application/json" || (str_ends_with($type, "+json") && str_contains($type, "/"))) {
            $json = json_decode($input, true);
            return (is_array($json) ? $json : []);
        }

        parse_str($input, $data);
        return $data;
    }

    /**
     * Lê no máximo $limit + 1 bytes: o suficiente para saber que passou do limite (0 = sem limite).
     *
     * @param int $limit
     * @return string
     */
    protected function input(int $limit): string
    {
        return (string)file_get_contents("php://input", false, null, 0, ($limit > 0 ? $limit + 1 : null));
    }

    /**
     * @return int bytes (0 = sem limite)
     */
    protected function bodyLimit(): int
    {
        return max(0, ini_parse_quantity((string)ini_get("post_max_size")));
    }

    /**
     * @return bool
     */
    private function execute(): bool
    {
        if (!$this->middleware()) {
            return false;
        }

        $handler = $this->route["handler"];
        $data = ($this->route["data"] ?? []);

        if (!is_string($handler)) {
            if (!is_callable($handler)) {
                $this->error = self::NOT_IMPLEMENTED;
                return false;
            }

            $handler($data, $this);
            return true;
        }

        $method = $this->route["action"];
        if (!is_string($method) || !class_exists($handler)) {
            $this->error = self::NOT_IMPLEMENTED;
            return false;
        }

        $controller = new $handler($this);
        if (!is_callable([$controller, $method])) {
            $this->error = self::NOT_IMPLEMENTED;
            return false;
        }

        $controller->$method($data);
        return true;
    }

    /**
     * Roda os middlewares do grupo e depois os da rota. Para no primeiro que devolver false.
     *
     * @return bool
     */
    private function middleware(): bool
    {
        foreach ($this->route["middlewares"] as $middleware) {
            if (!is_string($middleware) || !class_exists($middleware)) {
                $this->error = self::NOT_IMPLEMENTED;
                return false;
            }

            $newMiddleware = new $middleware();
            if (!is_callable([$newMiddleware, "handle"])) {
                $this->error = self::NOT_IMPLEMENTED;
                return false;
            }

            if (!$newMiddleware->handle($this)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strings ("Controller:method") e arrays ([Controller::class, "method"]) apontam para um controller,
     * nunca para uma função: "phpinfo:x" procura a classe phpinfo. Closures, objetos invocáveis e
     * [$objeto, "method"] são chamados direto.
     *
     * @param callable|array|string $handler
     * @param string|null $namespace
     * @return callable|string
     */
    private function handler(callable|array|string $handler, ?string $namespace): callable|string
    {
        if (is_string($handler)) {
            $class = explode($this->separator, $handler)[0];
            return ($namespace ? "{$namespace}\\{$class}" : $class);
        }

        if (is_array($handler) && !is_object($handler[0] ?? null)) {
            return (is_string($handler[0] ?? null) ? $handler[0] : "");
        }

        return $handler;
    }

    /**
     * @param callable|array|string $handler
     * @return string|null
     */
    private function action(callable|array|string $handler): ?string
    {
        if (is_string($handler)) {
            return (explode($this->separator, $handler)[1] ?? null);
        }

        if (is_array($handler) && !is_object($handler[0] ?? null)) {
            return (is_string($handler[1] ?? null) ? $handler[1] : null);
        }

        return null;
    }

    /**
     * @param array $route_item
     * @param array|null $data
     * @return string
     */
    private function treat(array $route_item, ?array $data = null): string
    {
        $data = ($data ?? []);
        $route = preg_replace_callback(
            $this->placeholder,
            function (array $key) use (&$data): string {
                if (!array_key_exists($key[1], $data)) {
                    return $key[0];
                }

                $value = rawurlencode((string)$data[$key[1]]);
                unset($data[$key[1]]);
                return $value;
            },
            $route_item["route"]
        );

        $query = (!empty($data) ? "?" . http_build_query($data) : "");
        return "{$this->projectUrl}{$route}{$query}";
    }
}
