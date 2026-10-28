<?php

namespace BrWay\Tests;

use BrWay\Tests\Fixtures\Log;

class SpoofingTest extends TestCase
{
    private function routes(TestRouter $router): TestRouter
    {
        foreach (["get", "post", "put", "patch", "delete"] as $verb) {
            $router->$verb("/user/{id}", fn(array $data) => Log::$calls[] = [strtoupper($verb), $data]);
        }
        return $router;
    }

    public function testPostData(): void
    {
        $this->routes($this->request("POST", "/user/1", ["name" => "Ana"]))->dispatch();
        $this->assertSame([["POST", ["name" => "Ana", "id" => "1"]]], Log::$calls);
    }

    public function testMethodSpoofing(): void
    {
        foreach (["PUT", "patch", "Delete"] as $method) {
            $this->routes($this->request("POST", "/user/1", ["_method" => $method, "name" => "Ana"]))->dispatch();
        }

        $this->assertSame([
            ["PUT", ["name" => "Ana", "id" => "1"]],
            ["PATCH", ["name" => "Ana", "id" => "1"]],
            ["DELETE", ["name" => "Ana", "id" => "1"]]
        ], Log::$calls);
    }

    public function testInvalidSpoofingKeepsPost(): void
    {
        foreach (["GET", "OPTIONS", ["PUT"]] as $method) {
            $this->routes($this->request("POST", "/user/1", ["_method" => $method]))->dispatch();
        }

        $this->assertSame(array_fill(0, 3, ["POST", ["id" => "1"]]), Log::$calls);
    }

    public function testSpoofingOnlyFromPost(): void
    {
        $_POST = ["_method" => "DELETE"];
        $router = $this->routes($this->request("GET", "/user/1", ["_method" => "DELETE"]));
        $router->dispatch();

        $this->assertSame([["GET", ["id" => "1"]]], Log::$calls);
    }

    public function testFormBody(): void
    {
        $this->routes($this->request("PUT", "/user/1", body: "name=Ana&_method=PUT"))->dispatch();
        $this->assertSame([["PUT", ["name" => "Ana", "id" => "1"]]], Log::$calls);
    }

    public function testJsonBody(): void
    {
        $json = '{"name":"Ana","tags":["a","b"]}';
        $this->routes($this->request("PATCH", "/user/1", body: $json, contentType: "application/json"))->dispatch();
        $this->routes($this->request("POST", "/user/1", body: $json, contentType: "application/json; charset=utf-8"))->dispatch();
        $this->routes($this->request("PUT", "/user/1", body: "{invalid", contentType: "application/json"))->dispatch();

        $this->assertSame([
            ["PATCH", ["name" => "Ana", "tags" => ["a", "b"], "id" => "1"]],
            ["POST", ["name" => "Ana", "tags" => ["a", "b"], "id" => "1"]],
            ["PUT", ["id" => "1"]]
        ], Log::$calls);
    }

    public function testBodyCannotOverrideRouteParams(): void
    {
        $this->routes($this->request("POST", "/user/1", ["id" => "999", "_method" => "DELETE"]))->dispatch();
        $this->assertSame([["DELETE", ["id" => "1"]]], Log::$calls);
    }

    public static function jsonTypes(): array
    {
        return [
            ["application/json", true],
            ["Application/JSON; charset=utf-8", true],
            ["application/vnd.api+json", true],
            ["text/plain; x=json", false],
            ["text/json-ish", false],
            ["+json", false]
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jsonTypes')]
    public function testOnlyJsonMediaTypesAreDecoded(string $type, bool $json): void
    {
        $this->routes($this->request("PUT", "/user/1", body: '{"name":"Ana"}', contentType: $type))->dispatch();
        $expected = ($json ? ["name" => "Ana", "id" => "1"] : ["{\"name\":\"Ana\"}" => "", "id" => "1"]);

        $this->assertSame([["PUT", $expected]], Log::$calls);
    }

    public function testBodyOverTheLimitIsDiscarded(): void
    {
        TestRouter::$limit = 10;
        $this->routes($this->request("PUT", "/user/1", body: "name=Ana"))->dispatch();
        $this->routes($this->request("PUT", "/user/1", body: "name=Ana&x=12345"))->dispatch();
        $this->routes($this->request("POST", "/user/1", body: '{"_method":"DELETE"}', contentType: "application/json"))->dispatch();

        $this->assertSame([
            ["PUT", ["name" => "Ana", "id" => "1"]],
            ["PUT", ["id" => "1"]],
            ["POST", ["id" => "1"]]
        ], Log::$calls);
    }
}
