<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Acorda o Web free do Render e dispara o schedule do Laravel.
 * Protegido por HC_SCHEDULE_TOKEN — sem o token certo responde 404.
 */
class AgendarController extends Controller
{
    public function __invoke(string $token): Response
    {
        $esperado = (string) config('hc.schedule_token');

        if ($esperado === '' || ! hash_equals($esperado, $token)) {
            throw new NotFoundHttpException;
        }

        Artisan::call('schedule:run');

        return response(Artisan::output() ?: "schedule:run ok\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
