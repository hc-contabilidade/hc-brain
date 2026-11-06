<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
 *
 * No plano free não há Shell: a sync roda neste request e a saída (ou o erro)
 * volta no corpo da resposta para dá para ver no navegador.
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

        set_time_limit(300);

        $linhas = ["tarefa={$tarefa}"];

        try {
            foreach ($comandos as $comando) {
                $codigo = Artisan::call($comando);
                $saida = trim(Artisan::output());
                $linhas[] = "=== {$comando} (exit {$codigo}) ===";
                $linhas[] = $saida !== '' ? $saida : '(sem saída)';
            }
        } catch (Throwable $erro) {
            $linhas[] = 'ERRO: '.$erro->getMessage();

            return response(implode("\n", $linhas)."\n", 500)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response(implode("\n", $linhas)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
