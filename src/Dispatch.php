<?php

declare(strict_types=1);

namespace BrWay;

/**
 * Class Dispatch
 * @package BrWay
 */
abstract class Dispatch
{
    use RouterTrait;

    /** @var string URL base do projeto, sem barra no final */
    protected string $projectUrl;

    /** @var string verbo da requisição (já com o spoofing aplicado) */
    protected string $httpMethod;

    /** @var string caminho requisitado, ex.: "/admin/user/5" ("" na raiz) */
    protected string $path;

    /** @var array|null rota encontrada pelo dispatch() */
    protected ?array $route = null;

    /** @var array rotas por verbo: [verbo => [regex => rota]] */
    protected array $routes = [];

    /** @var string separador de "Controller:method" */
    protected string $separator;

    /** @var string|null namespace dos controllers das próximas rotas */
    protected ?string $namespace = null;

    /** @var string|null grupo (prefixo) das próximas rotas */
    protected ?string $group = null;

    /** @var array middlewares do grupo atual */
    protected array $middleware = [];

    /** @var int quantas funções de group() estão rodando (0 = fora de qualquer grupo com função) */
    private int $depth = 0;

    /** @var array|null dados da requisição; depois do dispatch(), os da rota encontrada */
    protected ?array $data = null;

    /** @var int|null */
    protected ?int $error = null;

    /** @const int Bad Request */
    public const BAD_REQUEST = 400;

    /** @const int Not Found: nenhuma rota para o caminho */
    public const NOT_FOUND = 404;

    /** @const int Method Not Allowed: o caminho existe, mas em outro verbo */
    public const METHOD_NOT_ALLOWED = 405;

    /** @const int Not Implemented: sem rotas, ou controller/método/middleware inexistente */
    public const NOT_IMPLEMENTED = 501;

    /**
     * Dispatch constructor.
     *
     * @param string $projectUrl
     * @param null|string $separator
     */
    public function __construct(string $projectUrl, ?string $separator = ":")
    {
        $this->projectUrl = rtrim($projectUrl, "/");
        $this->separator = ($separator ?: ":");
        $this->path = $this->requestPath();
        $this->httpMethod = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
        $this->formSpoofing();
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->routes;
    }

    /**
     * URL de uma rota nomeada. Valores que não são parâmetros da rota vão para a query string.
     *
     * @param string $name
     * @param array|null $data
     * @return string|null
     */
    public function route(string $name, ?array $data = null): ?string
    {
        foreach ($this->routes as $http_verb) {
            foreach ($http_verb as $route_item) {
                if ($route_item["name"] !== null && $route_item["name"] === $name) {
                    return $this->treat($route_item, $data);
                }
            }
        }
        return null;
    }

    /**
     * @param null|string $namespace
     * @return static
     */
    public function namespace(?string $namespace): static
    {
        $this->namespace = ($namespace ? ucwords(trim($namespace, "\\")) : null);
        return $this;
    }

    /**
     * Com $routes, as rotas registradas na função ficam no grupo. Dentro de outra função de grupo, ele soma
     * com o grupo de fora e, no fim, o grupo e os middlewares voltam ao que eram. Fora, ele fecha o grupo
     * aberto sem função e, no fim, nenhum grupo fica aberto. O namespace sempre volta ao que era.
     * Sem $routes, o grupo vale até o próximo group().
     *
     * @param null|string $group
     * @param \Closure|array|string|null $routes função com as rotas do grupo, ou os middlewares (formato sem função)
     * @param array|string|null $middleware rodam antes dos middlewares de cada rota do grupo
     * @return static
     */
    public function group(
        ?string $group,
        \Closure|array|string|null $routes = null,
        array|string|null $middleware = null
    ): static {
        $group = trim((string)$group, "/");

        if (!$routes instanceof \Closure) {
            $this->group = ($group ?: null);
            $this->middleware = (array)($routes ?? $middleware);
            return $this;
        }

        if ($this->depth === 0) {
            $this->group = null;
            $this->middleware = [];
        }

        $previous = [$this->group, $this->middleware, $this->namespace];

        $this->group = (implode("/", array_filter([$this->group, $group])) ?: null);
        $this->middleware = array_values(array_unique(array_merge($this->middleware, (array)$middleware)));

        $this->depth++;
        try {
            $this->bind($routes)($this);
        } finally {
            $this->depth--;
            [$this->group, $this->middleware, $this->namespace] = $previous;
        }
        return $this;
    }

    /**
     * Dentro da função do grupo, $this é o router, mesmo quando ela foi criada dentro de uma classe.
     * Funções static e métodos ($obj->metodo(...)) não são alterados: usam o router recebido no parâmetro.
     *
     * @param \Closure $routes
     * @return \Closure
     */
    private function bind(\Closure $routes): \Closure
    {
        $function = new \ReflectionFunction($routes);
        if ($function->isStatic() || !str_starts_with($function->getName(), "{closure")) {
            return $routes;
        }
        return ($routes->bindTo($this) ?? $routes);
    }

    /**
     * @return null|array
     */
    public function data(): ?array
    {
        return $this->data;
    }

    /**
     * @return object
     */
    public function current(): object
    {
        return (object)array_merge(
            [
                "namespace" => $this->namespace,
                "group" => $this->group,
                "path" => $this->path
            ],
            $this->route ?? []
        );
    }

    /**
     * @return string
     */
    public function home(): string
    {
        return $this->projectUrl;
    }

    /**
     * Redireciona para uma rota nomeada, uma URL completa ou um caminho do projeto.
     * Não passe dados do usuário em $route: uma URL completa é seguida como está.
     *
     * @param string $route
     * @param array|null $data
     */
    public function redirect(string $route, ?array $data = null): never
    {
        if ($name = $this->route($route, $data)) {
            header("Location: {$name}");
            exit;
        }

        if (filter_var($route, FILTER_VALIDATE_URL)) {
            header("Location: {$route}");
            exit;
        }

        $route = "/" . ltrim($route, "/");
        header("Location: {$this->projectUrl}{$route}");
        exit;
    }

    /**
     * @return null|int
     */
    public function error(): ?int
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function dispatch(): bool
    {
        $this->route = null;
        $this->error = null;

        if (empty($this->routes)) {
            $this->error = self::NOT_IMPLEMENTED;
            return false;
        }

        $routes = $this->routes[$this->httpMethod] ?? [];
        if ($this->httpMethod === "HEAD" && empty($routes)) {
            $routes = $this->routes["GET"] ?? [];
        }

        $this->route = $this->match($routes);
        if (!$this->route) {
            $this->error = ($this->matchesAnotherMethod() ? self::METHOD_NOT_ALLOWED : self::NOT_FOUND);
            return false;
        }

        $this->data = $this->route["data"];
        return $this->execute();
    }

    /**
     * @return bool
     */
    private function matchesAnotherMethod(): bool
    {
        foreach ($this->routes as $routes) {
            if ($this->match($routes)) {
                return true;
            }
        }
        return false;
    }
}
