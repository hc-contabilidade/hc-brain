<?php

use App\Http\Middleware\RegistrarUltimoAcesso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Confia nos proxies do Render para carregar CSS e assets em HTTPS
        $middleware->trustProxies(at: '*');
        // Quem navega logado atualiza o "último acesso" da tela de Usuários.
        $middleware->web(append: [
            RegistrarUltimoAcesso::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
