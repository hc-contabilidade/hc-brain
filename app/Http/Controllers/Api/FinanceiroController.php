<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FinanceiroController extends Controller
{
    /**
     * Carteira do financeiro geral. Empresas de rascunho (Empresa X e as
     * demais do seeder) têm honorários fictícios e ficam de fora: só entra
     * quem já está vinculado a um app Omie e a um código de cliente.
     */
    public function index(): JsonResponse
    {
        $carteira = Empresa::with(['socio', 'financeiro'])
            ->whereNotNull('omie_app_id')
            ->whereNotNull('omie_codigo_cliente')
            ->whereHas('financeiro')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'nome' => $empresa->nome,
                'setor' => $empresa->setor,
                'socio' => $empresa->socio->nome,
                'regime' => $empresa->financeiro->regime,
                'desde' => $empresa->financeiro->desde->format('Y-m-d'),
                'meses' => $empresa->financeiro->mesesDeVida(),
                'primeiro' => $empresa->financeiro->primeiro,
                'atual' => $empresa->financeiro->atual,
                'total' => $empresa->financeiro->total,
            ]);

        return response()->json($carteira);
    }

    /**
     * Lista de baixo do financeiro, no formato da automação: código, cliente
     * e documento. Entram ativas e inativas. O status é o da última
     * sincronização, então uma ativa que vira inativa muda aqui sozinha.
     */
    public function empresas(): JsonResponse
    {
        $cadastros = Empresa::query()
            ->where(function ($query) {
                $query->whereNotNull('codigo')->orWhereNotNull('documento');
            })
            ->get(['id', 'nome', 'codigo', 'documento', 'documento_tipo']);

        $empresas = Empresa::query()
            ->whereNotNull('automacao_id')
            ->with('financeiro:id,empresa_id,regime,desde,primeiro,atual,total')
            ->get(['id', 'codigo', 'nome', 'documento', 'documento_tipo', 'status', 'status_tag'])
            ->map(function (Empresa $empresa) use ($cadastros) {
                $this->completarCodigoEDocumento($empresa, $cadastros);

                return $empresa;
            })
            ->sortBy(fn (Empresa $empresa) => sprintf('%010d-%s', (int) $empresa->codigo, $empresa->nome))
            ->values()
            ->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'codigo' => $empresa->codigo,
                'nome' => $empresa->nome,
                'documento' => $empresa->documento,
                'documentoTipo' => $empresa->documento_tipo,
                'status' => $empresa->status,
                'statusTag' => $empresa->status_tag,
                'regime' => $empresa->financeiro?->regime,
                'meses' => $empresa->financeiro?->mesesDeVida(),
                'primeiro' => $empresa->financeiro?->primeiro,
                'atual' => $empresa->financeiro?->atual,
                'total' => $empresa->financeiro?->total,
            ]);

        return response()->json($empresas);
    }

    /**
     * O código e o CNPJ nascem na lista de empresas (planilha ou automação).
     * A cópia que entrou pelo automacao às vezes fica sem um dos dois: o
     * código some do nome fantasia e o CNPJ fica na outra linha, a da razão
     * social. Aqui os dois se encontram de novo, pelo código e, se ele
     * também faltar, pelo nome.
     *
     * @param  Collection<int, Empresa>  $cadastros
     */
    private function completarCodigoEDocumento(Empresa $empresa, Collection $cadastros): void
    {
        $codigo = $this->codigoNormalizado($empresa->codigo) ?? $this->codigoNoNome($empresa->nome);
        $doador = $this->doador($empresa, $cadastros, $codigo);

        if ($codigo === null && $doador !== null) {
            $codigo = $this->codigoNormalizado($doador->codigo);
        }

        if (blank($empresa->documento) && $doador !== null && filled($doador->documento)) {
            $empresa->documento = $doador->documento;
            $empresa->documento_tipo = $doador->documento_tipo;
        }

        $empresa->codigo = $codigo;
    }

    /**
     * @param  Collection<int, Empresa>  $cadastros
     */
    private function doador(Empresa $empresa, Collection $cadastros, ?string $codigo): ?Empresa
    {
        $doador = null;

        if ($codigo !== null) {
            foreach ($cadastros as $candidato) {
                if ($candidato->id === $empresa->id || $this->codigoNormalizado($candidato->codigo) !== $codigo) {
                    continue;
                }

                if (blank($candidato->documento)) {
                    continue;
                }

                $mesmoNome = $this->nomeChave($candidato->nome) === $this->nomeChave($empresa->nome);

                if ($doador === null || $mesmoNome) {
                    $doador = $candidato;
                }

                if ($mesmoNome) {
                    return $candidato;
                }
            }
        }

        if ($doador !== null) {
            return $doador;
        }

        $peloNome = $cadastros->first(function (Empresa $candidato) use ($empresa) {
            return $candidato->id !== $empresa->id
                && $this->nomeChave($candidato->nome) === $this->nomeChave($empresa->nome)
                && (filled($candidato->codigo) || filled($candidato->documento));
        });

        return $peloNome instanceof Empresa ? $peloNome : null;
    }

    private function codigoNormalizado(mixed $codigo): ?string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return null;
        }

        if (preg_match('/^\d+\.0+$/', $codigo) === 1) {
            return (string) (int) $codigo;
        }

        return $codigo;
    }

    /**
     * Empresas sincronizadas antes da coluna `codigo` ainda carregam o número
     * no fim do nome, no formato "RAZÃO (1389)".
     */
    private function codigoNoNome(string $nome): ?string
    {
        if (preg_match('/\((\d+)\)\s*$/u', $nome, $encontrado) !== 1) {
            return null;
        }

        return $encontrado[1];
    }

    /** Mesma empresa escrita com acento, caixa ou "(código)" no fim continua batendo. */
    private function nomeChave(string $nome): string
    {
        $semCodigo = preg_replace('/\s*\(\d+\)\s*$/u', '', $nome) ?? $nome;

        return Str::of($semCodigo)->ascii()->upper()->squish()->toString();
    }
}
