<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\OmieApp;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copia apps Omie e o vínculo empresa↔cliente do automacao-2-hc para o Brain.
 *
 * Fonte: `omie_bases` + `companies.current_omie_base_id` / `omie_client_code`.
 * Os secrets no automacao estão criptografados com a APP_KEY dele — sem
 * `AUTOMACAO_APP_KEY` no Brain o app é criado, mas fica inativo até alguém
 * colar o secret ou configurar a chave.
 */
class SincronizarOmieVinculos extends Command
{
    protected $signature = 'hc:sincronizar-omie-vinculos';

    protected $description = 'Traz apps Omie e o vínculo empresa↔cliente do automacao-2-hc';

    private const SECRET_PENDENTE = 'aguardando-AUTOMACAO_APP_KEY';

    public function handle(): int
    {
        try {
            $origem = DB::connection('automacao');
            $bases = $this->bases($origem);
            $vinculos = $this->vinculos($origem);
        } catch (QueryException $erro) {
            $this->components->error('Não foi possível ler o banco do automacao-2-hc: '.$erro->getMessage());

            return self::FAILURE;
        }

        $appIdPorBase = $this->sincronizarApps($bases);
        [$ligadas, $limpas] = $this->sincronizarEmpresas($vinculos, $appIdPorBase);

        $this->components->info(
            count($appIdPorBase).' apps Omie, '.$ligadas.' empresas com vínculo e '.$limpas.' sem vínculo Omie.'
        );

        if (! filled(config('hc.omie.automacao_app_key'))) {
            $this->components->warn(
                'AUTOMACAO_APP_KEY não configurada: secrets não foram importados. Apps novos ficam inativos até a chave ou o cadastro manual do secret.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object{id: int, name: string, app_key: string, app_secret: string}>
     */
    private function bases(ConnectionInterface $origem): Collection
    {
        return $origem->table('omie_bases')
            ->orderBy('id')
            ->get(['id', 'name', 'app_key', 'app_secret']);
    }

    /**
     * @return Collection<int, object{id: int, current_omie_base_id: int, omie_client_code: string}>
     */
    private function vinculos(ConnectionInterface $origem): Collection
    {
        return $origem->table('companies')
            ->where('draft', false)
            ->whereNotNull('current_omie_base_id')
            ->whereNotNull('omie_client_code')
            ->where('omie_client_code', '!=', '')
            ->orderBy('id')
            ->get(['id', 'current_omie_base_id', 'omie_client_code']);
    }

    /**
     * @param  Collection<int, object{id: int, name: string, app_key: string, app_secret: string}>  $bases
     * @return array<int, int> automacao omie_bases.id => omie_apps.id
     */
    private function sincronizarApps(Collection $bases): array
    {
        $mapa = [];

        foreach ($bases as $base) {
            $secret = $this->descriptografarSecret((string) $base->app_secret);
            $app = OmieApp::query()
                ->where(function ($query) use ($base) {
                    $query->where('automacao_id', $base->id)
                        ->orWhere('app_key', $base->app_key);
                })
                ->first();

            $dados = [
                'automacao_id' => $base->id,
                'nome' => (string) $base->name,
                'app_key' => (string) $base->app_key,
            ];

            if ($secret !== null) {
                $dados['app_secret'] = $secret;
                $dados['ativo'] = true;
            } elseif ($app === null) {
                $dados['app_secret'] = self::SECRET_PENDENTE;
                $dados['ativo'] = false;
            }

            if ($app === null) {
                $app = OmieApp::query()->create($dados);
            } else {
                $app->fill($dados);
                $app->save();
            }

            $mapa[(int) $base->id] = $app->id;
        }

        return $mapa;
    }

    /**
     * @param  Collection<int, object{id: int, current_omie_base_id: int, omie_client_code: string}>  $vinculos
     * @param  array<int, int>  $appIdPorBase
     * @return array{0: int, 1: int}
     */
    private function sincronizarEmpresas(Collection $vinculos, array $appIdPorBase): array
    {
        $porAutomacao = $vinculos->keyBy(fn (object $v) => (int) $v->id);
        $empresas = Empresa::query()
            ->whereNotNull('automacao_id')
            ->get(['id', 'automacao_id', 'omie_app_id', 'omie_codigo_cliente']);

        $ligadas = 0;
        $limpas = 0;
        $lote = [];

        foreach ($empresas as $empresa) {
            $vinculo = $porAutomacao->get((int) $empresa->automacao_id);
            $appId = $vinculo !== null
                ? ($appIdPorBase[(int) $vinculo->current_omie_base_id] ?? null)
                : null;

            if ($vinculo !== null && $appId !== null && ctype_digit((string) $vinculo->omie_client_code)) {
                $omieAppId = $appId;
                $codigo = (int) $vinculo->omie_client_code;
                $ligadas++;
            } else {
                $omieAppId = null;
                $codigo = null;
                $limpas++;
            }

            if ($empresa->omie_app_id === $omieAppId && $empresa->omie_codigo_cliente === $codigo) {
                continue;
            }

            $lote[] = [
                'id' => $empresa->id,
                'omie_app_id' => $omieAppId,
                'omie_codigo_cliente' => $codigo,
            ];
        }

        DB::transaction(function () use ($lote) {
            foreach (array_chunk($lote, 500) as $pedaco) {
                foreach ($pedaco as $linha) {
                    Empresa::query()->whereKey($linha['id'])->update([
                        'omie_app_id' => $linha['omie_app_id'],
                        'omie_codigo_cliente' => $linha['omie_codigo_cliente'],
                    ]);
                }
            }
        });

        return [$ligadas, $limpas];
    }

    private function descriptografarSecret(string $cipher): ?string
    {
        $key = config('hc.omie.automacao_app_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $raw = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            return (new Encrypter($raw, (string) config('app.cipher')))->decryptString($cipher);
        } catch (DecryptException) {
            return null;
        }
    }
}
