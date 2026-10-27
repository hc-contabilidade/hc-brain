<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca o último acesso de quem está logado. Só grava de minuto em minuto
 * para não escrever no banco a cada asset ou chamada de API.
 */
class RegistrarUltimoAcesso
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $usuario = $request->user();
        if ($usuario === null) {
            return $resposta;
        }

        $ultimo = $usuario->ultimo_acesso;
        // Menos de 60s desde a última marca: evita gravar em todo clique/API.
        if ($ultimo !== null && $ultimo->diffInSeconds(now()) < 60) {
            return $resposta;
        }

        $usuario->forceFill(['ultimo_acesso' => now()])->save();

        return $resposta;
    }
}
