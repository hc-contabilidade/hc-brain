<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Acorda o Web free do Render e dispara o schedule (ou uma sync sob demanda).
 * Protegido por HC_SCHEDULE_TOKEN — sem o token certo responde 404.
 *
 * Syncs pesadas respondem na hora e rodam em segundo plano (evita 502 do
 * proxy do Render). O resultado fica em ?tarefa=status.
 *
 * ?tarefa=schedule   → php artisan schedule:run (padrão, para o cron-job.org)
 * ?tarefa=empresas   → hc:sincronizar-empresas
 * ?tarefa=vinculos   → hc:sincronizar-omie-vinculos
 * ?tarefa=honorarios → hc:sincronizar-honorarios
 * ?tarefa=omie       → hc:sincronizar-omie
 * ?tarefa=tudo       → empresas + vínculos + honorários
 * ?tarefa=status     → resultado da última sync em segundo plano
 * ?tarefa=ip         → IP de saída do Render (Trusted Sources no DigitalOcean)
 */
class AgendarController extends Controller
{
    private const STATUS_KEY = 'hc.interno.sync_status';

    private const RUNNING_KEY = 'hc.interno.sync_running';

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

        if ($tarefa === 'ip') {
            return $this->ipDeSaida();
        }

        if ($tarefa === 'status') {
            return $this->texto(Cache::get(self::STATUS_KEY, "nenhuma sync registrada ainda\n"));
        }

        $comandos = self::TAREFAS[$tarefa] ?? null;

        if ($comandos === null) {
            throw new NotFoundHttpException;
        }

        // Leve: responde com a saída na hora (cron externo).
        if ($tarefa === 'schedule') {
            Artisan::call('schedule:run');

            return $this->texto(
                "tarefa=schedule\n".(trim(Artisan::output()) ?: 'schedule:run ok')
            );
        }

        if (Cache::get(self::RUNNING_KEY)) {
            return $this->texto(
                "já existe uma sync em andamento\n".
                "abra ?tarefa=status para acompanhar\n"
            );
        }

        Cache::put(self::RUNNING_KEY, true, now()->addMinutes(30));
        Cache::put(
            self::STATUS_KEY,
            "tarefa={$tarefa}\nstatus=iniciando\ncomandos: ".implode(', ', $comandos)."\n",
            now()->addHours(6)
        );

        $comandosParaRodar = $comandos;
        $nomeTarefa = $tarefa;

        dispatch(function () use ($comandosParaRodar, $nomeTarefa) {
            ignore_user_abort(true);
            set_time_limit(300);

            $linhas = ["tarefa={$nomeTarefa}", 'status=rodando', 'inicio='.now()->toIso8601String()];

            try {
                foreach ($comandosParaRodar as $comando) {
                    $codigo = Artisan::call($comando);
                    $saida = trim(Artisan::output());
                    $linhas[] = "=== {$comando} (exit {$codigo}) ===";
                    $linhas[] = $saida !== '' ? $saida : '(sem saída)';
                    Cache::put(self::STATUS_KEY, implode("\n", $linhas)."\n", now()->addHours(6));
                }
                $linhas[] = 'status=ok';
                $linhas[] = 'fim='.now()->toIso8601String();
            } catch (Throwable $erro) {
                $linhas[] = 'status=erro';
                $linhas[] = 'ERRO: '.$erro->getMessage();
            }

            Cache::put(self::STATUS_KEY, implode("\n", $linhas)."\n", now()->addHours(6));
            Cache::forget(self::RUNNING_KEY);
        })->afterResponse();

        return $this->texto(
            "tarefa={$tarefa}\n".
            "status=aceita (rodando em segundo plano)\n".
            "aguarde 2–5 minutos e abra:\n".
            "?tarefa=status\n"
        );
    }

    private function ipDeSaida(): Response
    {
        try {
            $ip = trim((string) Http::connectTimeout(5)->timeout(10)->get('https://api.ipify.org')->body());
        } catch (Throwable $erro) {
            return $this->texto("ERRO ao descobrir IP: {$erro->getMessage()}", 500);
        }

        return $this->texto(
            "ip_saida={$ip}\n".
            "Adicione este IP (ou {$ip}/32) em Trusted Sources do banco Postgres no DigitalOcean.\n".
            "No plano free do Render o IP pode mudar; se voltar timeout, rode ?tarefa=ip de novo.\n"
        );
    }

    private function texto(string $corpo, int $status = 200): Response
    {
        return response(rtrim($corpo)."\n", $status)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
