<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Processo;
use App\Models\Projeto;
use App\Models\Reuniao;
use App\Models\Treinamento;
use stdClass;
use Throwable;

/**
 * Consultas que a Sofia faz na mesma base das outras telas. O modelo não
 * recebe a carteira inteira: ele pede o que precisa e lê o JSON de volta.
 */
final class SofiaTools
{
    public const LIMITE_BUSCA = 10;

    public const LIMITE_LISTA = 20;

    public const LIMITE_TREINAMENTO = 6;

    /**
     * @return list<array{type: string, function: array<string, mixed>}>
     */
    public static function definicoes(): array
    {
        return [
            self::funcao(
                'contar',
                'Devolve os totais atuais da base HC: empresas, reuniões, documentos, usuários, fontes, treinamentos, projetos e processos.',
                [],
            ),
            self::funcao(
                'buscar_empresas',
                'Busca empresas da carteira pelo nome. Use antes de detalhar quando a pessoa citar um cliente. Não lista a carteira inteira.',
                [
                    'nome' => ['type' => 'string', 'description' => 'Trecho do nome da empresa ou cliente.'],
                ],
                ['nome'],
            ),
            self::funcao(
                'detalhar_empresa',
                'Detalha uma empresa: status, sócio, fontes, última reunião e treinamentos vinculados.',
                [
                    'empresa_id' => ['type' => 'string', 'description' => 'Id da empresa, se já conhecido.'],
                    'nome' => ['type' => 'string', 'description' => 'Nome da empresa, se o id ainda não for conhecido.'],
                ],
            ),
            self::funcao(
                'listar_reunioes',
                'Lista reuniões da base, com filtros opcionais de tipo, status ou empresa.',
                [
                    'tipo' => ['type' => 'string', 'enum' => Reuniao::TIPOS, 'description' => 'Tipo da reunião.'],
                    'status' => ['type' => 'string', 'enum' => Reuniao::STATUS, 'description' => 'Status da reunião.'],
                    'empresa' => ['type' => 'string', 'description' => 'Id ou nome da empresa.'],
                ],
            ),
            self::funcao(
                'listar_projetos',
                'Lista projetos da carteira da HC, com filtro opcional de status ou termo.',
                [
                    'status' => ['type' => 'string', 'enum' => Projeto::STATUS, 'description' => 'Status do projeto.'],
                    'termo' => ['type' => 'string', 'description' => 'Trecho do nome ou da descrição.'],
                ],
            ),
            self::funcao(
                'listar_processos',
                'Lista processos internos documentados, com filtro opcional por termo.',
                [
                    'termo' => ['type' => 'string', 'description' => 'Trecho do nome, da área ou da descrição.'],
                ],
            ),
            self::funcao(
                'buscar_treinamentos',
                'Busca conteúdos de capacitação (cursos, trilhas, manuais, fluxogramas).',
                [
                    'termo' => ['type' => 'string', 'description' => 'Tema, título ou processo.'],
                    'empresa' => ['type' => 'string', 'description' => 'Id ou nome da empresa para conteúdos vinculados a ela.'],
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     */
    public static function executar(string $nome, array $argumentos): string
    {
        try {
            $resultado = match ($nome) {
                'contar' => self::contar(),
                'buscar_empresas' => self::buscarEmpresas($argumentos),
                'detalhar_empresa' => self::detalharEmpresa($argumentos),
                'listar_reunioes' => self::listarReunioes($argumentos),
                'listar_projetos' => self::listarProjetos($argumentos),
                'listar_processos' => self::listarProcessos($argumentos),
                'buscar_treinamentos' => self::buscarTreinamentos($argumentos),
                default => ['erro' => 'ferramenta não encontrada'],
            };
        } catch (Throwable $excecao) {
            report($excecao);

            $resultado = ['erro' => 'falha ao consultar a base'];
        }

        return json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"erro":"falha ao serializar"}';
    }

    /**
     * @param  array<string, array<string, mixed>>  $propriedades
     * @param  list<string>  $obrigatorios
     * @return array{type: string, function: array<string, mixed>}
     */
    private static function funcao(string $nome, string $descricao, array $propriedades, array $obrigatorios = []): array
    {
        $parametros = [
            'type' => 'object',
            'properties' => $propriedades === [] ? new stdClass : $propriedades,
        ];

        if ($obrigatorios !== []) {
            $parametros['required'] = $obrigatorios;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $nome,
                'description' => $descricao,
                'parameters' => $parametros,
            ],
        ];
    }

    /** @return array<string, int> */
    private static function contar(): array
    {
        return Contadores::doInicio();
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function buscarEmpresas(array $argumentos): array
    {
        $nome = self::texto($argumentos, 'nome');

        if ($nome === '') {
            return ['erro' => 'Informe um trecho do nome da empresa.'];
        }

        $empresas = Empresa::query()
            ->where('nome', 'like', self::contem($nome))
            ->orderBy('nome')
            ->limit(self::LIMITE_BUSCA)
            ->get(['id', 'nome', 'setor', 'status']);

        return [
            'total' => $empresas->count(),
            'empresas' => $empresas->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'nome' => $empresa->nome,
                'setor' => $empresa->setor,
                'status' => $empresa->status,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function detalharEmpresa(array $argumentos): array
    {
        $empresa = self::encontrarEmpresa(
            self::texto($argumentos, 'empresa_id'),
            self::texto($argumentos, 'nome'),
        );

        if ($empresa === null) {
            return ['erro' => 'Empresa não encontrada na base.'];
        }

        $empresa->load(['socio', 'fontes', 'treinamentos', 'ultimaReuniao']);
        $socio = $empresa->socio;
        $ultima = $empresa->ultimaReuniao;

        return [
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'setor' => $empresa->setor,
            'status' => $empresa->status,
            'socio' => $socio === null ? null : [
                'nome' => $socio->nome,
                'cargo' => $socio->cargo,
                'email' => $socio->email,
                'participacao' => $socio->participacao,
            ],
            'fontes' => $empresa->fontes->map(fn ($fonte) => $fonte->nome)->all(),
            'ultima_reuniao' => $ultima === null ? null : [
                'tipo' => $ultima->tipo,
                'data' => $ultima->data?->format('d/m/Y'),
                'status' => $ultima->status,
            ],
            'treinamentos' => $empresa->treinamentos
                ->take(self::LIMITE_TREINAMENTO)
                ->map(fn (Treinamento $item) => [
                    'titulo' => $item->titulo,
                    'tipo' => $item->tipo,
                ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function listarReunioes(array $argumentos): array
    {
        $consulta = Reuniao::query()->with('empresa')->orderByDesc('data')->orderByDesc('id');

        $tipo = self::texto($argumentos, 'tipo');
        if ($tipo !== '' && in_array($tipo, Reuniao::TIPOS, true)) {
            $consulta->where('tipo', $tipo);
        }

        $status = self::texto($argumentos, 'status');
        if ($status !== '' && in_array($status, Reuniao::STATUS, true)) {
            $consulta->where('status', $status);
        }

        $empresaFiltro = self::texto($argumentos, 'empresa');
        if ($empresaFiltro !== '') {
            $empresa = self::encontrarEmpresa($empresaFiltro, $empresaFiltro);
            if ($empresa === null) {
                return ['erro' => 'Empresa não encontrada na base.', 'reunioes' => []];
            }
            $consulta->where('empresa_id', $empresa->id);
        }

        $reunioes = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'total' => $reunioes->count(),
            'reunioes' => $reunioes->map(fn (Reuniao $reuniao) => [
                'tipo' => $reuniao->tipo,
                'data' => $reuniao->data?->format('d/m/Y'),
                'status' => $reuniao->status,
                'empresa' => $reuniao->nomeDaEmpresa(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function listarProjetos(array $argumentos): array
    {
        $consulta = Projeto::query()->orderBy('ordem');

        $status = self::texto($argumentos, 'status');
        if ($status !== '' && in_array($status, Projeto::STATUS, true)) {
            $consulta->where('status', $status);
        }

        $termo = self::texto($argumentos, 'termo');
        if ($termo !== '') {
            $consulta->where(function ($q) use ($termo): void {
                $q->where('nome', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo));
            });
        }

        $projetos = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'total' => $projetos->count(),
            'projetos' => $projetos->map(fn (Projeto $projeto) => [
                'id' => $projeto->id,
                'nome' => $projeto->nome,
                'status' => $projeto->status,
                'progresso' => $projeto->progresso,
                'responsavel' => $projeto->responsavel,
                'prazo' => $projeto->prazo?->format('d/m/Y'),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function listarProcessos(array $argumentos): array
    {
        $consulta = Processo::query()->orderBy('ordem');
        $termo = self::texto($argumentos, 'termo');

        if ($termo !== '') {
            $consulta->where(function ($q) use ($termo): void {
                $q->where('nome', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo))
                    ->orWhere('area', 'like', self::contem($termo));
            });
        }

        $processos = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'total' => $processos->count(),
            'processos' => $processos->map(fn (Processo $processo) => [
                'id' => $processo->id,
                'nome' => $processo->nome,
                'area' => $processo->area,
                'responsavel' => $processo->responsavel,
                'etapas' => is_array($processo->etapas) ? count($processo->etapas) : 0,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function buscarTreinamentos(array $argumentos): array
    {
        $empresaFiltro = self::texto($argumentos, 'empresa');
        if ($empresaFiltro !== '') {
            $empresa = self::encontrarEmpresa($empresaFiltro, $empresaFiltro);
            if ($empresa === null) {
                return ['erro' => 'Empresa não encontrada na base.', 'treinamentos' => []];
            }

            return [
                'empresa' => $empresa->nome,
                'treinamentos' => $empresa->treinamentos
                    ->take(self::LIMITE_TREINAMENTO)
                    ->map(fn (Treinamento $item) => self::resumoTreinamento($item))
                    ->all(),
            ];
        }

        $termo = self::texto($argumentos, 'termo');
        $consulta = Treinamento::query()->orderBy('ordem');

        if ($termo !== '') {
            $consulta->where(function ($q) use ($termo): void {
                $q->where('titulo', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo))
                    ->orWhere('categoria', 'like', self::contem($termo))
                    ->orWhere('tipo', 'like', self::contem($termo))
                    ->orWhere('processo', 'like', self::contem($termo));
            });
        }

        $encontrados = $consulta->limit(self::LIMITE_TREINAMENTO)->get();

        return [
            'total' => $encontrados->count(),
            'treinamentos' => $encontrados->map(fn (Treinamento $item) => self::resumoTreinamento($item))->all(),
        ];
    }

    /** @return array{titulo: string, tipo: string, categoria: string} */
    private static function resumoTreinamento(Treinamento $item): array
    {
        return [
            'titulo' => $item->titulo,
            'tipo' => $item->tipo,
            'categoria' => $item->categoria,
        ];
    }

    private static function encontrarEmpresa(string $id, string $nome): ?Empresa
    {
        if ($id !== '') {
            $porId = Empresa::find($id);
            if ($porId !== null) {
                return $porId;
            }
        }

        $busca = $nome !== '' ? $nome : $id;
        if ($busca === '') {
            return null;
        }

        return Empresa::query()
            ->where('nome', 'like', self::contem($busca))
            ->orderBy('nome')
            ->first();
    }

    /** @param  array<string, mixed>  $argumentos */
    private static function texto(array $argumentos, string $chave): string
    {
        $valor = $argumentos[$chave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }

    private static function contem(string $valor): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor).'%';
    }
}
