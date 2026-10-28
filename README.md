# BrWay

[![Maintainer](https://img.shields.io/badge/maintainer-@kevind3v-blue.svg?style=flat-square)](https://github.com/kevind3v)
[![Source Code](https://img.shields.io/badge/source-brbunny/brway-blue.svg?style=flat-square)](https://github.com/kevind3v/brway)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/brbunny/brway.svg?style=flat-square)](https://packagist.org/packages/brbunny/brway)
[![Latest Version](https://img.shields.io/github/release/kevind3v/brway.svg?style=flat-square)](https://github.com/kevind3v/brway/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/brbunny/brway.svg?style=flat-square)](https://packagist.org/packages/brbunny/brway)

Roteador para PHP 8.2+ simples e sem dependências. Tem os verbos RESTful (GET, POST, PUT, PATCH e DELETE),
grupos, rotas nomeadas, middlewares e form spoofing, para aplicações MVC e APIs.

> Fork mantido do [CoffeeCode Router](https://github.com/robsonvleite/router), de Robson V. Leite,
> atualizado para o PHP 8.x e com correções de segurança. Veja [Migrando do CoffeeCode Router](#migrando-do-coffeecode-router).

## Instalação

```bash
composer require brbunny/brway
```

## Servidor

Todo endereço que não for um arquivo real precisa chegar ao `index.php`. As configs padrão de Apache e
Nginx já fazem isso.

#### Apache (.htaccess)

```apacheconfig
RewriteEngine On
Options All -Indexes

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

#### Nginx

```nginx
location / {
    try_files $uri /index.php?$query_string;
}

# projeto numa subpasta
location /loja/ {
    try_files $uri /loja/index.php?$query_string;
}
```

Sem `$uri/` no `try_files`: com ele, o Nginx responde 405 a PUT, PATCH e DELETE no endereço raiz (`/`)
antes de chegar ao PHP.

O router lê o caminho da URL original (`REQUEST_URI`), sem a pasta do projeto. Ele descobre a pasta assim:

1. pelo caminho do `projectUrl` (`new Router("https://site.com/loja")` → `/loja`);
2. pela pasta do `index.php`, quando o `projectUrl` não bate com a URL (ex.: `projectUrl` de desenvolvimento
   em produção, ou proxy que remove um prefixo). Se as duas baterem, vale a mais longa.

O parâmetro `?route=` das configs antigas (`index.php?route=/$1`) só é usado no CLI e quando nenhuma das
pastas bate. Em `/blog?route=/admin`, quem vale é `/blog`; `/index.php?route=/admin` e `/index.php/admin` não
abrem `/admin`. Assim o servidor e o router leem o mesmo caminho, e um bloqueio feito no servidor (ex.: `deny`
em `/admin`) não pode ser contornado.

Com a config antiga do Apache, uma URL com espaço (`%20`) recebe 403 do próprio Apache antes de chegar ao
PHP. Por isso a config acima é a recomendada.

## Rotas

```php
use BrWay\Router;

$router = new Router("https://www.seudominio.com");

// controllers no namespace App\Controllers
$router->namespace("App\Controllers");

$router->get("/", "Web:home");
$router->get("/user/{id}", "User:show");
$router->post("/user", "User:store");
$router->put("/user/{id}", "User:update");
$router->patch("/user/{id}/photo", "User:photo");
$router->delete("/user/{id}", [User::class, "destroy"]); // array também funciona

// grupo: /admin, /admin/user/{id}
$router->group("admin", function () {
    $this->namespace("App\Admin");
    $this->get("/", "Dash:home");
    $this->get("/user/{id}", "Dash:user");
});

// grupo de erros: 400, 404, 405 e 501
$router->group("error", function () {
    $this->get("/{errcode}", "Web:error");
});

$router->dispatch();

if ($router->error()) {
    $router->redirect("/error/{$router->error()}");
}
```

- **Rota mais específica vence**: `/user/new` ganha de `/user/{id}`, em qualquer ordem de registro. Se as duas
  têm o mesmo número de parâmetros, vence a registrada primeiro.
- **O caminho é comparado literalmente**: o `.` de `/feed.xml` é um ponto, não "qualquer caractere".
- **Parâmetros** (`{id}`) casam com um segmento inteiro, sem `/` e sem caracteres de controle (`%00`, `%0A`).
- `namespace(null)` limpa o namespace.
- Requisições HEAD usam as rotas GET.

### Grupos

As rotas registradas dentro da função ficam no grupo. Dentro dela, `$this` é o router. No fim, o grupo, os
middlewares e o namespace voltam ao que eram antes:

```php
$router->group("admin", function () {
    $this->get("/", "Admin:home");               // /admin
    $this->get("/user/{id}", "Admin:user");      // /admin/user/{id}

    // grupo dentro de grupo: /admin/report/{year}
    $this->group("report", function () {
        $this->get("/{year}", "Report:year");
    });
});

$router->get("/contato", "Web:contact");         // fora do grupo
```

Se preferir receber o router no parâmetro (útil para o autocomplete do editor), também funciona:
`function (Router $router) { $router->get(...); }`. Uma `static function` ou um método (`$this->rotas(...)`)
recebem o router só pelo parâmetro.

O formato sem função também continua valendo: `$router->group("admin")` seguido das rotas. Nele, o grupo vale
até o próximo `group()`, e `group(null)` volta para fora do grupo. Um grupo com função fecha o grupo aberto sem
função antes dele, então os dois formatos podem ser usados no mesmo arquivo.

### Controller

O controller recebe o router no construtor, e o método recebe os dados da rota:

```php
namespace App\Controllers;

use BrWay\Router;

class User
{
    public function __construct(private Router $router)
    {
    }

    public function show(array $data): void
    {
        echo "Usuário " . htmlspecialchars($data["id"]);
    }
}
```

Handlers em string (`"User:show"`) ou array (`[User::class, "show"]`) sempre apontam para um controller e
um método público. Um nome de função como `"phpinfo:x"` não é executado. Para usar outro separador:
`new Router($url, "@")` e `"User@show"`.

### Callable

```php
$router->get("/hello/{name}", function (array $data, Router $router) {
    echo "Olá, " . htmlspecialchars($data["name"]);
});
```

## Dados da requisição

`$data` junta os parâmetros da rota com o corpo da requisição:

- **POST**: `$_POST`, ou o JSON do corpo quando `Content-Type: application/json` (ou `application/*+json`).
- **PUT, PATCH e DELETE**: o corpo em `x-www-form-urlencoded` ou JSON.
- **Os parâmetros da rota vencem**: um campo `id` no corpo não substitui o `{id}` da URL.
- **Limite de tamanho**: o corpo é lido até o `post_max_size` do PHP (8 MB por padrão). Acima disso, os dados
  vêm vazios, como o PHP já faz no POST.
- **Os valores vêm do usuário, sem tratamento**: valide antes de usar e escape ao imprimir
  (`htmlspecialchars`), como faria com `$_GET` e `$_POST`.

Depois do `dispatch()`, `$router->data()` devolve os mesmos dados e `$router->current()` devolve a rota
encontrada (nome, grupo, namespace, handler, middlewares, dados).

### Form spoofing

Formulários HTML só enviam GET e POST. Para chegar a uma rota PUT, PATCH ou DELETE, envie um POST com o
campo `_method`, em maiúsculas ou minúsculas:

```html
<form action="/user/5" method="POST">
    <input type="hidden" name="_method" value="DELETE"/>
    <button>Remover</button>
</form>
```

`_method` só vale num POST. Num GET, ou com outro valor, ele é ignorado.

O spoofing não protege contra CSRF: assim como num POST comum, outro site consegue enviar esse formulário.
Use um token CSRF nos formulários que alteram dados.

## Rotas nomeadas

```php
$router->group("blog");
$router->get("/{category}/page/{page}", "Blog:list", "blog.list");

$router->route("blog.list", ["category" => "php", "page" => 2]);
// https://www.seudominio.com/blog/php/page/2

$router->route("blog.list", ["category" => "php", "page" => 2, "q" => "rotas php"]);
// https://www.seudominio.com/blog/php/page/2?q=rotas+php

$router->redirect("blog.list", ["category" => "php", "page" => 1]);
```

- Os valores são codificados (`rawurlencode`): `"../admin"` vira `..%2Fadmin` e não altera a URL.
- Valores que não são parâmetros da rota vão para a query string.
- `route()` devolve `null` se o nome não existe.
- `redirect()` aceita um nome de rota, uma URL completa ou um caminho do projeto (`"/login"`). **Não passe dados
  do usuário** (ex.: `$_GET["back"]`): uma URL completa é seguida como está, o que abre um redirect para
  qualquer site.

## Middlewares

Um middleware é uma classe com `handle(Router $router): bool`. Se devolver `false`, a rota não roda:
o `dispatch()` devolve `false` e o `error()` fica `null`, porque a resposta é responsabilidade do middleware.

```php
namespace App\Http;

use BrWay\Router;

class Auth
{
    public function handle(Router $router): bool
    {
        if (empty($_SESSION["user"])) {
            $router->redirect("/login");
        }
        return true;
    }
}
```

```php
// na rota (um ou vários, em ordem)
$router->get("/perfil", "User:profile", middleware: Auth::class);
$router->get("/painel", "User:panel", "user.panel", [Auth::class, Verified::class]);

// no grupo (3º parâmetro): roda antes dos middlewares de cada rota do grupo
$router->group("admin", function () {
    $this->get("/", "Admin:home");                                // Auth
    $this->post("/user", "Admin:store", middleware: Csrf::class); // Auth, depois Csrf

    $this->group("finance", function () {
        $this->get("/", "Finance:home");                          // Auth, depois Admin
    }, Admin::class);
}, Auth::class);

// formato sem função: o middleware é o 2º parâmetro e vale até o próximo group()
$router->group("admin", Auth::class);
$router->get("/", "Admin:home");                                  // Auth
```

Num grupo dentro de grupo, os middlewares somam: primeiro os do grupo de fora, depois os do de dentro e por
último os da rota.

## Erros

| Código | Constante | Quando |
|---|---|---|
| 404 | `Router::NOT_FOUND` | Nenhuma rota para o caminho |
| 405 | `Router::METHOD_NOT_ALLOWED` | O caminho existe, mas em outro verbo |
| 501 | `Router::NOT_IMPLEMENTED` | Nenhuma rota registrada, ou controller, método ou middleware inexistente |

`Router::BAD_REQUEST` (400) continua disponível para uso próprio.

## Migrando do CoffeeCode Router

1. Troque `use CoffeeCode\Router\Router;` por `use BrWay\Router;`. É preciso PHP 8.2+.
2. **O caminho vem da URL original**, não mais do `?route=`. A config antiga do servidor continua funcionando,
   mas a nova (veja [Servidor](#servidor)) aceita espaço, `&`, `#` e `+` no caminho e é a padrão do Nginx.
   Depois de atualizar, abra algumas páginas: se tudo der 404, o `projectUrl` não bate com a pasta do site.
   Links com o `index.php` escrito (`/index.php?route=/contato`, `/index.php/contato`) não abrem mais a rota.
3. **O middleware do grupo agora soma com o da rota.** Antes, uma rota com middleware próprio ignorava o do
   grupo (um grupo `admin` com `Auth` perdia o `Auth` nessas rotas). Agora roda o do grupo e depois o da rota.
4. **A rota mais específica vence.** Antes valia a última registrada que casasse com o caminho.
5. **Códigos de erro:** um caminho que existe em outro verbo dá 405 (antes, 501 ou 404). Controller ou método
   inexistente dá 501 (antes, 400 e 405). Middleware sem `handle()` dá 501 (antes, 405).
6. `route()` codifica os valores. Quem passava `"a/b"` para gerar dois segmentos agora recebe `a%2Fb`.
7. O caminho da rota é literal: `.` e outros caracteres especiais de regex não funcionam mais como regex.
8. Os parâmetros vêm do caminho requisitado e não incluem campos do corpo com o mesmo nome.
9. Um handler em string nunca é chamado como função.
10. `namespace()` e `group()` devolvem `static`, e `redirect()` devolve `never`.
11. O corpo de PUT, PATCH e DELETE é limitado ao `post_max_size`, e só `application/json` e `+json` são lidos
    como JSON.

## Testes

```bash
composer install
composer test
```

## Créditos

- [Robson V. Leite](https://github.com/robsonvleite): autor do CoffeeCode Router original
- [Kevin Siqueira](https://github.com/kevind3v): BrWay

## Licença

MIT. Veja o [LICENSE](LICENSE).
