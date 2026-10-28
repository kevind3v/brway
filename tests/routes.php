<?php

/*
 * Rotas de um arquivo comum, fora de classe: dentro da função do grupo, $this é o router.
 */

use BrWay\Tests\Fixtures\Log;

return function () {
    $this->get("/", fn() => Log::$calls[] = "home");
    $this->get("/user/{id}", fn($data) => Log::$calls[] = $data);
};
