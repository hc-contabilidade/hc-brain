<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Acorda o Web free do Render e dispara o schedule (ou uma sync sob demanda).
 * Protegido por HC_SCHEDULE_TOKEN — sem o token certo responde 404.
 *
 * ?tarefa=schedule   → php artisan schedule:run (padrão, para o cron-job.org)
 * ?tarefa=empresas   → hc:sincronizar-empresas
 * ?tarefa=vinculos   → hc:sincronizar-omie-vinculos
 * ?tarefa=honorarios → hc:sincronizar-honorarios
 * ?tarefa=omie       → hc:sincronizar-omie
 * ?tarefa=tudo       → empresas + vínculos + honorários
 */
class AgendarController extends Controller
{
    /** @var array<string, list<string>> */
    private const TAREFAS = [
        'schedule' => ['schedule:run'],
        'empresas' => ['hc:sincronizar-empresas'],
        'vinculos' => ['hc:sincronizar-omie-vinculos'],
        'honorarios' => ['hc:sincronizar-honorarios'],
        'omie' => ['hc:sincronizar-omie'],
        'tudo' => [
            'hc:sincronizar-empresas',
            'hc:sincronizar-omie-vinculos',
            'hc:sincronizar-honorarios',
        ],
    ];

    public function __invoke(Request $request, string $token): Response
    {
        $esperado = (string) config('hc.schedule_token');

        if ($esperado === '' || ! hash_equals($esperado, $token)) {
            throw new NotFoundHttpException;
        }

        $tarefa = (string) $request->query('tarefa', 'schedule');
        $comandos = self::TAREFAS[$tarefa] ?? null;

        if ($comandos === null) {
            throw new NotFoundHttpException;
        }

        // O cron externo usa o padrão: só o que está "na hora" no schedule.
        if ($tarefa === 'schedule') {
            Artisan::call('schedule:run');

            return response(Artisan::output() ?: "schedule:run ok\n", 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        // Sync pesada: responde já e continua no mesmo request (plano free
        // não tem Shell nem fila). Abra a URL no navegador e espere 1–3 min.
        $comandosParaRodar = $comandos;
        dispatch(function () use ($comandosParaRodar) {
            foreach ($comandosParaRodar as $comando) {
                Artisan::call($comando);
            }
        })->afterResponse();

        return response(
            "ok: {$tarefa} iniciada em segundo plano\n".
            'comandos: '.implode(', ', $comandos)."\n".
            "aguarde 1–3 minutos e atualize o Financeiro geral\n",
            200
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
