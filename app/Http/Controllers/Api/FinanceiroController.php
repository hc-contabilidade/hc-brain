<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\ChaveDeEmpresa;
use Database\Seeders\ImportEmpresasSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FinanceiroController extends Controller
{
    /**
     * Carteira do financeiro geral. Empresas de rascunho (Empresa X e as
     * demais do seeder) têm honorários fictícios e ficam de fora: só entra
     * quem já está vinculado a um app Omie e a um código de cliente.
     */
    public function index(): JsonResponse
    {
        $planilha = app(ImportEmpresasSeeder::class)->mapaDeDocumentos();
        $indice = ChaveDeEmpresa::indice($planilha);

        $carteira = Empresa::with(['socio', 'financeiro'])
            ->whereNotNull('omie_app_id')
            ->whereNotNull('omie_codigo_cliente')
            ->whereHas('financeiro')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'nome' => $this->nomeExibido($empresa, $planilha, $indice),
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
        [$porCodigo, $porNome] = $this->indiceDeCadastros($cadastros);
        $planilha = app(ImportEmpresasSeeder::class)->mapaDeDocumentos();
        $indice = ChaveDeEmpresa::indice($planilha);
        $automacao = $this->dadosDaAutomacao();

        $empresas = Empresa::query()
            ->whereNotNull('automacao_id')
            ->with('financeiro:id,empresa_id,regime,desde,primeiro,atual,total')
            ->get(['id', 'automacao_id', 'codigo', 'nome', 'documento', 'documento_tipo', 'status', 'status_tag'])
            ->map(function (Empresa $empresa) use ($porCodigo, $porNome, $planilha, $indice, $automacao) {
                $this->completarCodigoEDocumento($empresa, $porCodigo, $porNome, $planilha, $indice, $automacao);

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
     * Uma passada só. Sem isso a lista comparava cada empresa com a carteira
     * inteira e o financeiro passava do tempo de resposta.
     *
     * @param  Collection<int, Empresa>  $cadastros
     * @return array{0: array<string, list<Empresa>>, 1: array<string, Empresa>}
     */
    private function indiceDeCadastros(Collection $cadastros): array
    {
        $porCodigo = [];
        $porNome = [];

        foreach ($cadastros as $cadastro) {
            $codigo = $this->codigoNormalizado($cadastro->codigo);

            if ($codigo !== null && filled($cadastro->documento)) {
                $porCodigo[$codigo][] = $cadastro;
            }

            $nome = $this->nomeChave($cadastro->nome);

            if ($nome === '') {
                continue;
            }

            $porNome[$nome] = $this->cadastroMaisCompleto($porNome[$nome] ?? null, $cadastro);
        }

        return [$porCodigo, $porNome];
    }

    private function cadastroMaisCompleto(?Empresa $atual, Empresa $cadastro): Empresa
    {
        if (! $atual instanceof Empresa) {
            return $cadastro;
        }

        $pontos = fn (Empresa $empresa): int => (filled($empresa->codigo) ? 1 : 0) + (filled($empresa->documento) ? 2 : 0);

        return $pontos($cadastro) > $pontos($atual) ? $cadastro : $atual;
    }

    /**
     * O código e o CNPJ nascem na lista de empresas (planilha ou automação).
     * A cópia que entrou pelo automacao às vezes fica sem um dos dois: o
     * código some do nome fantasia e o CNPJ fica na outra linha, a da razão
     * social. Aqui os dois se encontram de novo, pelo código e, se ele
     * também faltar, pelo nome.
     *
     * @param  array<string, list<Empresa>>  $porCodigo
     * @param  array<string, Empresa>  $porNome
     * @param  array{porCodigo?: array<string, array<string, mixed>>, porNome?: array<string, array<string, mixed>>, porRaiz?: array<string, array<string, mixed>>}  $planilha
     * @param  array<string, list<array{0: string, 1: array<string, mixed>}>>  $indice
     * @param  array<int, array{codigo: ?string, documento: ?string, tipo: ?string}>  $automacao
     */
    private function completarCodigoEDocumento(Empresa $empresa, array $porCodigo, array $porNome, array $planilha, array $indice, array $automacao): void
    {
        $nomeOriginal = $empresa->nome;
        $codigo = $this->codigoNormalizado($empresa->codigo) ?? $this->codigoNoNome($empresa->nome);
        $doador = $this->doador($empresa, $porCodigo, $porNome, $codigo);

        if ($codigo === null && $doador !== null) {
            $codigo = $this->codigoNormalizado($doador->codigo);
        }

        if (blank($empresa->documento) && $doador !== null && filled($doador->documento)) {
            $empresa->documento = $doador->documento;
            $empresa->documento_tipo = $doador->documento_tipo;
        }

        $daAutomacao = filled($empresa->automacao_id) ? ($automacao[(int) $empresa->automacao_id] ?? null) : null;

        if (is_array($daAutomacao)) {
            if ($codigo === null) {
                $codigo = $this->codigoNormalizado($daAutomacao['codigo'] ?? null);
            }

            if (blank($empresa->documento) && filled($daAutomacao['documento'] ?? null)) {
                $empresa->documento = $daAutomacao['documento'];
                $empresa->documento_tipo = $this->tipoDoDocumento($daAutomacao['documento'], $daAutomacao['tipo'] ?? null);
            }
        }

        $daPlanilha = ChaveDeEmpresa::linha($planilha, $nomeOriginal, $codigo, $indice);

        if (is_array($daPlanilha)) {
            if (filled($daPlanilha['nome'] ?? null)) {
                $empresa->nome = $daPlanilha['nome'];
            }

            if (blank($empresa->documento) && filled($daPlanilha['documento'] ?? null)) {
                $empresa->documento = $daPlanilha['documento'];
                $empresa->documento_tipo = $daPlanilha['tipo'] ?? null;
            }

            if ($codigo === null) {
                $codigo = $this->codigoNormalizado($daPlanilha['codigo'] ?? null);
            }
        }

        if (blank($empresa->documento)) {
            $noNome = $this->documentoNoNome($nomeOriginal);

            if ($noNome !== null) {
                $empresa->documento = $noNome;
                $empresa->documento_tipo = $this->tipoDoDocumento($noNome, null);
            }
        }

        $empresa->codigo = $codigo;
    }

    /**
     * Código e CNPJ ainda não copiados para o Brain. Uma leitura só, guardada
     * por dez minutos: a lista não pode esperar a próxima sincronização.
     *
     * @return array<int, array{codigo: ?string, documento: ?string, tipo: ?string}>
     */
    private function dadosDaAutomacao(): array
    {
        if (! config('hc.completar_pela_automacao')) {
            return [];
        }

        if (app()->runningUnitTests()) {
            return $this->lerDadosDaAutomacao() ?? [];
        }

        $guardado = Cache::get('hc.automacao.codigo-documento.v1');

        if (is_array($guardado)) {
            return $guardado;
        }

        $dados = $this->lerDadosDaAutomacao();

        if ($dados === null) {
            return [];
        }

        Cache::put('hc.automacao.codigo-documento.v1', $dados, 600);

        return $dados;
    }

    /**
     * Null quando a automação não respondeu, para não guardar esse vazio
     * por dez minutos e esconder o código que voltaria na hora seguinte.
     *
     * @return array<int, array{codigo: ?string, documento: ?string, tipo: ?string}>|null
     */
    private function lerDadosDaAutomacao(): ?array
    {
        try {
            $origem = DB::connection('automacao');
            $empresas = $origem->table('companies')
                ->where('draft', false)
                ->get(['id', 'code']);
        } catch (\Throwable) {
            return null;
        }

        $documentos = $this->documentosDaAutomacao($origem);
        $mapa = [];

        foreach ($empresas as $empresa) {
            $documento = $documentos[(int) $empresa->id] ?? null;
            $codigo = trim((string) $empresa->code);
            $mapa[(int) $empresa->id] = [
                'codigo' => $codigo === '' ? null : $codigo,
                'documento' => $documento['documento'] ?? null,
                'tipo' => $documento['tipo'] ?? null,
            ];
        }

        return $mapa;
    }

    /**
     * @return array<int, array{documento: ?string, tipo: ?string}>
     */
    private function documentosDaAutomacao(ConnectionInterface $origem): array
    {
        foreach ([['id', 'document_number', 'document_type'], ['id', 'document_number']] as $colunas) {
            try {
                return $origem->table('companies')
                    ->where('draft', false)
                    ->get($colunas)
                    ->mapWithKeys(function (object $empresa) {
                        $documento = preg_replace('/\D+/', '', (string) ($empresa->document_number ?? '')) ?? '';

                        return [(int) $empresa->id => [
                            'documento' => $documento === '' ? null : $documento,
                            'tipo' => $this->tipoDoDocumento($documento, $empresa->document_type ?? null),
                        ]];
                    })
                    ->all();
            } catch (QueryException) {
                $this->limparConsultaFalhada($origem);
            }
        }

        return [];
    }

    private function limparConsultaFalhada(ConnectionInterface $origem): void
    {
        try {
            if ($origem->transactionLevel() > 0) {
                $origem->rollBack();

                return;
            }

            $origem->unprepared('ROLLBACK');
        } catch (\Throwable) {
            // Não havia transação aberta: a próxima leitura segue.
        }
    }

    private function tipoDoDocumento(?string $documento, mixed $tipo): ?string
    {
        $tipo = strtoupper(trim((string) $tipo));

        if (in_array($tipo, ['CNPJ', 'CPF'], true)) {
            return $tipo;
        }

        return match (strlen((string) $documento)) {
            14 => 'CNPJ',
            11 => 'CPF',
            default => null,
        };
    }

    /** CNPJ ou CPF escrito no próprio nome, quando a coluna de documento não veio. */
    private function documentoNoNome(string $nome): ?string
    {
        if (preg_match('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $nome, $encontrado) === 1) {
            return preg_replace('/\D+/', '', $encontrado[0]) ?: null;
        }

        if (preg_match('/\d{3}\.\d{3}\.\d{3}-\d{2}/', $nome, $encontrado) === 1) {
            return preg_replace('/\D+/', '', $encontrado[0]) ?: null;
        }

        return null;
    }

    /**
     * @param  array{porCodigo?: array<string, array<string, mixed>>, porNome?: array<string, array<string, mixed>>, porRaiz?: array<string, array<string, mixed>>}  $planilha
     * @param  array<string, list<array{0: string, 1: array<string, mixed>}>>  $indice
     */
    private function nomeExibido(Empresa $empresa, array $planilha, array $indice): string
    {
        $codigo = $this->codigoNormalizado($empresa->codigo) ?? $this->codigoNoNome($empresa->nome);
        $linha = ChaveDeEmpresa::linha($planilha, $empresa->nome, $codigo, $indice);
        $nome = is_array($linha) ? ($linha['nome'] ?? null) : null;

        return filled($nome) ? $nome : $empresa->nome;
    }

    /**
     * @param  array<string, list<Empresa>>  $porCodigo
     * @param  array<string, Empresa>  $porNome
     */
    private function doador(Empresa $empresa, array $porCodigo, array $porNome, ?string $codigo): ?Empresa
    {
        if ($codigo !== null) {
            $doador = null;
            $nome = $this->nomeChave($empresa->nome);

            foreach ($porCodigo[$codigo] ?? [] as $candidato) {
                if ($candidato->id === $empresa->id) {
                    continue;
                }

                $doador = $candidato;

                if ($this->nomeChave($candidato->nome) === $nome) {
                    return $candidato;
                }
            }

            if ($doador !== null) {
                return $doador;
            }
        }

        $peloNome = $porNome[$this->nomeChave($empresa->nome)] ?? null;

        if ($peloNome instanceof Empresa && $peloNome->id !== $empresa->id) {
            return $peloNome;
        }

        return null;
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

    /** Mesma empresa com acento, LTDA ou um número na frente continua batendo. */
    private function nomeChave(string $nome): string
    {
        return ChaveDeEmpresa::nome($nome);
    }
}
