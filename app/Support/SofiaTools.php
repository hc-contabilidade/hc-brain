<?php

namespace App\Support;

use App\Models\Documento;
use App\Models\Empresa;
use App\Models\Processo;
use App\Models\Projeto;
use App\Models\ResultadoPesquisa;
use App\Models\Reuniao;
use App\Models\Socio;
use App\Models\Treinamento;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Consultas e ações que a Sofia faz na mesma base das outras telas. O modelo
 * não recebe a carteira inteira: ele pede o que precisa, lê o JSON e, quando
 * pedirem, cria/edita/apaga empresa ou cria projeto e processo. Reuniões
 * ficam só na consulta — a Sofia não agenda.
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
                'pesquisar',
                'Busca ampla no índice interno, em documentos e em empresas. Use no modo Pesquisar ou quando a pessoa não souber o cadastro exato.',
                [
                    'termo' => ['type' => 'string', 'description' => 'O que procurar: nome, tema, documento, pessoa ou processo.'],
                ],
                ['termo'],
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
                'Detalha uma empresa: status, sócio, fontes, última reunião, treinamentos, financeiro e o caminho da tela.',
                [
                    'empresa_id' => ['type' => 'string', 'description' => 'Id da empresa, se já conhecido.'],
                    'nome' => ['type' => 'string', 'description' => 'Nome da empresa, se o id ainda não for conhecido.'],
                ],
            ),
            self::funcao(
                'listar_reunioes',
                'Lista reuniões. Para hoje/amanhã/esta semana/semana que vem, passe data. Para as que ainda vão acontecer, passe proximas=true. Sem filtro devolve as mais recentes.',
                [
                    'data' => ['type' => 'string', 'description' => 'hoje, amanhã, ontem, esta semana, semana que vem, próxima segunda, YYYY-MM-DD ou dd/mm/aaaa.'],
                    'de' => ['type' => 'string', 'description' => 'Início do período, inclusive. Mesmos formatos de data.'],
                    'ate' => ['type' => 'string', 'description' => 'Fim do período, inclusive. Mesmos formatos de data.'],
                    'proximas' => ['type' => 'boolean', 'description' => 'Só as reuniões de hoje em diante, da mais cedo para a mais tarde.'],
                    'minhas' => ['type' => 'boolean', 'description' => 'Só as reuniões em que quem pergunta é responsável.'],
                    'tipo' => ['type' => 'string', 'enum' => Reuniao::TIPOS, 'description' => 'Tipo da reunião.'],
                    'status' => ['type' => 'string', 'enum' => Reuniao::STATUS, 'description' => 'Status da reunião.'],
                    'empresa' => ['type' => 'string', 'description' => 'Id ou nome da empresa.'],
                    'responsavel' => ['type' => 'string', 'description' => 'Nome de quem conduz a reunião.'],
                ],
            ),
            self::funcao(
                'listar_projetos',
                'Lista projetos da carteira da HC, com filtro opcional de status, termo ou responsável.',
                [
                    'status' => ['type' => 'string', 'enum' => Projeto::STATUS, 'description' => 'Status do projeto.'],
                    'termo' => ['type' => 'string', 'description' => 'Trecho do nome ou da descrição.'],
                    'minhas' => ['type' => 'boolean', 'description' => 'Só os projetos em que quem pergunta é responsável.'],
                ],
            ),
            self::funcao(
                'listar_processos',
                'Lista processos internos documentados, com as etapas na ordem de execução.',
                [
                    'termo' => ['type' => 'string', 'description' => 'Trecho do nome, da área ou da descrição.'],
                ],
            ),
            self::funcao(
                'buscar_documentos',
                'Busca arquivos da base de documentos pelo nome ou pela pasta.',
                [
                    'termo' => ['type' => 'string', 'description' => 'Trecho do nome do arquivo.'],
                    'pasta' => ['type' => 'string', 'description' => 'Nome da pasta (Comercial, Projetos, Financeiro…).'],
                ],
            ),
            self::funcao(
                'buscar_pessoas',
                'Busca sócios de clientes e pessoas da equipe pelo nome.',
                [
                    'nome' => ['type' => 'string', 'description' => 'Trecho do nome da pessoa.'],
                ],
                ['nome'],
            ),
            self::funcao(
                'buscar_treinamentos',
                'Busca conteúdos de capacitação (cursos, trilhas, manuais, fluxogramas).',
                [
                    'termo' => ['type' => 'string', 'description' => 'Tema, título ou processo.'],
                    'empresa' => ['type' => 'string', 'description' => 'Id ou nome da empresa para conteúdos vinculados a ela.'],
                ],
            ),
            self::funcao(
                'criar_empresa',
                'Cadastra uma empresa na carteira do HC Brain. Use quando pedirem para criar/cadastrar um cliente ou empresa.',
                [
                    'nome' => ['type' => 'string', 'description' => 'Nome da empresa ou cliente.'],
                    'setor' => ['type' => 'string', 'description' => 'Ramo de atuação. Se omitido, fica "Não informado".'],
                    'status' => ['type' => 'string', 'description' => 'Status do cadastro. Padrão: Ativo.'],
                    'socio_nome' => ['type' => 'string', 'description' => 'Nome do sócio responsável, se informado.'],
                ],
                ['nome'],
            ),
            self::funcao(
                'editar_empresa',
                'Atualiza dados de uma empresa já cadastrada (nome, setor, status, sócio). Use quando pedirem para editar/alterar um cliente.',
                [
                    'empresa_id' => ['type' => 'string', 'description' => 'Id da empresa, se já conhecido.'],
                    'nome' => ['type' => 'string', 'description' => 'Nome atual da empresa, se o id ainda não for conhecido.'],
                    'novo_nome' => ['type' => 'string', 'description' => 'Novo nome, se for renomear.'],
                    'setor' => ['type' => 'string', 'description' => 'Novo setor.'],
                    'status' => ['type' => 'string', 'description' => 'Novo status (ex.: Cliente ativo, Em negociação).'],
                    'socio_nome' => ['type' => 'string', 'description' => 'Novo nome do sócio responsável.'],
                ],
            ),
            self::funcao(
                'apagar_empresa',
                'Remove uma empresa da carteira. Só execute com confirmar=true depois de ter certeza do registro certo.',
                [
                    'empresa_id' => ['type' => 'string', 'description' => 'Id da empresa, se já conhecido.'],
                    'nome' => ['type' => 'string', 'description' => 'Nome da empresa, se o id ainda não for conhecido.'],
                    'confirmar' => ['type' => 'boolean', 'description' => 'Deve ser true para realmente apagar.'],
                ],
                ['confirmar'],
            ),
            self::funcao(
                'criar_projeto',
                'Cadastra um projeto na base. Use quando pedirem para criar um projeto.',
                [
                    'nome' => ['type' => 'string', 'description' => 'Nome do projeto.'],
                    'descricao' => ['type' => 'string', 'description' => 'Descrição curta do objetivo.'],
                    'responsavel' => ['type' => 'string', 'description' => 'Quem conduz. Se omitido, usa quem está perguntando.'],
                    'area' => ['type' => 'string', 'description' => 'Área (Comercial, Operações…). Padrão: a área de quem pergunta, ou Geral.'],
                    'status' => ['type' => 'string', 'enum' => Projeto::STATUS, 'description' => 'Padrão: Planejado.'],
                    'prioridade' => ['type' => 'string', 'enum' => Projeto::PRIORIDADES, 'description' => 'Padrão: Média.'],
                    'inicio' => ['type' => 'string', 'description' => 'Data de início. Padrão: hoje.'],
                    'prazo' => ['type' => 'string', 'description' => 'Prazo. Padrão: 30 dias após o início.'],
                    'empresa' => ['type' => 'string', 'description' => 'Id ou nome da empresa vinculada, se houver.'],
                ],
                ['nome', 'descricao'],
            ),
            self::funcao(
                'criar_processo',
                'Documenta um processo interno com etapas. Use quando pedirem para criar/cadastrar um processo.',
                [
                    'nome' => ['type' => 'string', 'description' => 'Nome do processo.'],
                    'descricao' => ['type' => 'string', 'description' => 'O que o processo cobre.'],
                    'etapas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Etapas na ordem de execução (pelo menos uma).'],
                    'responsavel' => ['type' => 'string', 'description' => 'Quem é dono do processo. Se omitido, usa quem está perguntando.'],
                    'area' => ['type' => 'string', 'description' => 'Área. Padrão: a área de quem pergunta, ou Geral.'],
                    'frequencia' => ['type' => 'string', 'description' => 'Com que frequência roda. Padrão: Sob demanda.'],
                ],
                ['nome', 'descricao', 'etapas'],
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
                'pesquisar' => self::pesquisar($argumentos),
                'buscar_empresas' => self::buscarEmpresas($argumentos),
                'detalhar_empresa' => self::detalharEmpresa($argumentos),
                'listar_reunioes' => self::listarReunioes($argumentos),
                'listar_projetos' => self::listarProjetos($argumentos),
                'listar_processos' => self::listarProcessos($argumentos),
                'buscar_documentos' => self::buscarDocumentos($argumentos),
                'buscar_pessoas' => self::buscarPessoas($argumentos),
                'buscar_treinamentos' => self::buscarTreinamentos($argumentos),
                'criar_empresa' => self::criarEmpresa($argumentos),
                'editar_empresa' => self::editarEmpresa($argumentos),
                'apagar_empresa' => self::apagarEmpresa($argumentos),
                'criar_projeto' => self::criarProjeto($argumentos),
                'criar_processo' => self::criarProcesso($argumentos),
                default => ['erro' => 'ferramenta não encontrada'],
            };
        } catch (Throwable $excecao) {
            report($excecao);

            $resultado = ['erro' => 'falha ao executar na base'];
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
    private static function pesquisar(array $argumentos): array
    {
        $termo = self::texto($argumentos, 'termo');
        if ($termo === '') {
            return ['erro' => 'Informe o que procurar.'];
        }

        $como = self::contem($termo);

        $indice = ResultadoPesquisa::query()
            ->where(function (Builder $consulta) use ($como): void {
                $consulta->where('titulo', 'like', $como)
                    ->orWhere('texto', 'like', $como)
                    ->orWhere('meta', 'like', $como)
                    ->orWhere('area', 'like', $como);
            })
            ->orderBy('ordem')
            ->limit(self::LIMITE_BUSCA)
            ->get();

        $empresas = Empresa::query()
            ->where('nome', 'like', $como)
            ->orderBy('nome')
            ->limit(5)
            ->get(['id', 'nome', 'status']);

        $documentos = Documento::query()
            ->with('pasta')
            ->where('nome', 'like', $como)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return [
            'caminho' => Telas::caminho('pesquisa'),
            'indice' => $indice->map(fn (ResultadoPesquisa $item) => [
                'tipo' => $item->tipo,
                'area' => $item->area,
                'titulo' => $item->titulo,
                'texto' => self::trecho($item->texto, 280),
                'meta' => $item->meta,
            ])->all(),
            'empresas' => $empresas->map(fn (Empresa $empresa) => [
                'nome' => $empresa->nome,
                'status' => $empresa->status,
                'caminho' => Telas::caminho('cliente', ['id' => $empresa->id]),
            ])->all(),
            'documentos' => $documentos->map(fn (Documento $documento) => self::resumoDocumento($documento))->all(),
        ];
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
                'caminho' => Telas::caminho('cliente', ['id' => $empresa->id]),
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

        $empresa->load(['socio', 'fontes', 'treinamentos', 'ultimaReuniao', 'financeiro']);
        $socio = $empresa->socio;
        $ultima = $empresa->ultimaReuniao;
        $financeiro = $empresa->financeiro;

        return [
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'setor' => $empresa->setor,
            'status' => $empresa->status,
            'caminho' => Telas::caminho('cliente', ['id' => $empresa->id]),
            'caminho_financeiro' => Telas::caminho('financeiro'),
            'socio' => $socio === null ? null : [
                'nome' => $socio->nome,
                'cargo' => $socio->cargo,
                'email' => $socio->email,
                'telefone' => $socio->telefone,
                'participacao' => $socio->participacao,
            ],
            'fontes' => $empresa->fontes->map(fn ($fonte) => $fonte->nome)->all(),
            'ultima_reuniao' => $ultima === null ? null : [
                'tipo' => $ultima->tipo,
                'data' => $ultima->data?->format('d/m/Y'),
                'status' => $ultima->status,
            ],
            'financeiro' => $financeiro === null ? null : [
                'regime' => $financeiro->regime,
                'desde' => $financeiro->desde?->format('d/m/Y'),
                'meses' => $financeiro->mesesDeVida(),
                'honorario_atual' => $financeiro->atual,
                'honorario_primeiro' => $financeiro->primeiro,
                'total' => $financeiro->total,
                'saldo' => $financeiro->saldo,
                'receber' => $financeiro->receber,
                'despesas' => $financeiro->despesas,
                'margem' => $financeiro->margem,
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
        $consulta = Reuniao::query()->with('empresa');
        $hoje = now(GoogleAgenda::FUSO)->toDateString();
        $ordemDoDia = false;
        $proximas = self::booleano($argumentos, 'proximas');
        $quando = null;

        $data = self::texto($argumentos, 'data');
        if ($data !== '') {
            $quando = self::interpretarQuando($data);
            if ($quando === null) {
                return self::erroDeData('Data inválida. Use hoje, amanhã, ontem, esta semana, semana que vem, próxima segunda, YYYY-MM-DD ou dd/mm/aaaa.');
            }

            if (($quando['tipo'] ?? '') === 'proximas') {
                $proximas = true;
            } elseif (($quando['tipo'] ?? '') === 'dia') {
                $consulta->whereDate('data', $quando['iso']);
                $ordemDoDia = true;
            } else {
                $consulta->whereDate('data', '>=', $quando['de']);
                $consulta->whereDate('data', '<=', $quando['ate']);
            }
        } else {
            $de = self::texto($argumentos, 'de');
            if ($de !== '') {
                $isoDe = self::dataDoArgumento($de);
                if ($isoDe === null) {
                    return self::erroDeData('Data inicial inválida. Use hoje, amanhã, ontem, YYYY-MM-DD ou dd/mm/aaaa.');
                }
                $consulta->whereDate('data', '>=', $isoDe);
            }

            $ate = self::texto($argumentos, 'ate');
            if ($ate !== '') {
                $isoAte = self::dataDoArgumento($ate);
                if ($isoAte === null) {
                    return self::erroDeData('Data final inválida. Use hoje, amanhã, ontem, YYYY-MM-DD ou dd/mm/aaaa.');
                }
                $consulta->whereDate('data', '<=', $isoAte);
            }
        }

        if ($proximas && $data === '') {
            $consulta->whereDate('data', '>=', $hoje);
        } elseif ($proximas && ($quando['tipo'] ?? '') === 'proximas') {
            $consulta->whereDate('data', '>=', $hoje);
        }

        $tipo = self::texto($argumentos, 'tipo');
        if ($tipo !== '' && in_array($tipo, Reuniao::TIPOS, true)) {
            $consulta->where('tipo', $tipo);
        }

        $status = self::texto($argumentos, 'status');
        if ($status !== '' && in_array($status, Reuniao::STATUS, true)) {
            $consulta->where('status', $status);
        } elseif ($proximas) {
            $consulta->where('status', '!=', 'Cancelada');
        }

        $empresaFiltro = self::texto($argumentos, 'empresa');
        if ($empresaFiltro !== '') {
            $empresa = self::encontrarEmpresa($empresaFiltro, $empresaFiltro);
            if ($empresa === null) {
                return ['erro' => 'Empresa não encontrada na base.', 'reunioes' => []];
            }
            $consulta->where('empresa_id', $empresa->id);
        }

        $responsavel = self::texto($argumentos, 'responsavel');
        if (self::booleano($argumentos, 'minhas')) {
            $nome = SofiaContexto::nome();
            if ($nome === '') {
                return ['erro' => 'Não sei quem está perguntando para filtrar as suas reuniões.', 'reunioes' => []];
            }
            $consulta->where('responsavel', 'like', self::contem($nome));
        } elseif ($responsavel !== '') {
            $consulta->where('responsavel', 'like', self::contem($responsavel));
        }

        if ($ordemDoDia) {
            $consulta->orderBy('horario')->orderBy('id');
        } elseif ($proximas || $data !== '') {
            $consulta->orderBy('data')->orderBy('horario')->orderBy('id');
        } else {
            $consulta->orderByDesc('data')->orderByDesc('id');
        }

        $total = (clone $consulta)->count();
        $reunioes = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'hoje' => now(GoogleAgenda::FUSO)->format('d/m/Y'),
            'caminho' => Telas::caminho('reunioes'),
            'total' => $total,
            'mostradas' => $reunioes->count(),
            'reunioes' => $reunioes->map(fn (Reuniao $reuniao) => [
                'id' => $reuniao->id,
                'titulo' => $reuniao->titulo,
                'tipo' => $reuniao->tipo,
                'data' => $reuniao->data?->format('d/m/Y'),
                'horario' => $reuniao->horario,
                'status' => $reuniao->status,
                'empresa' => $reuniao->nomeDaEmpresa(),
                'responsavel' => $reuniao->responsavel,
                'participantes' => $reuniao->participantes,
                'resumo' => self::trecho($reuniao->resumo),
                'decisoes' => self::lista($reuniao->decisoes),
                'proximos_passos' => self::lista($reuniao->proximos_passos),
                // Meet abre a videochamada; caminho leva ao card dessa reunião.
                'link_meet' => $reuniao->link_meet,
                'caminho' => Telas::caminho('reunioes').'?reuniao='.$reuniao->id,
                'caminho_rotulo' => 'Abrir card no HC Brain',
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function listarProjetos(array $argumentos): array
    {
        $consulta = Projeto::query()->with('empresa')->orderBy('ordem');
        $hoje = today();

        $status = self::texto($argumentos, 'status');
        if ($status !== '' && in_array($status, Projeto::STATUS, true)) {
            $consulta->where('status', $status);
        }

        $termo = self::texto($argumentos, 'termo');
        if ($termo !== '') {
            $consulta->where(function (Builder $q) use ($termo): void {
                $q->where('nome', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo));
            });
        }

        if (self::booleano($argumentos, 'minhas')) {
            $nome = SofiaContexto::nome();
            if ($nome === '') {
                return ['erro' => 'Não sei quem está perguntando para filtrar os seus projetos.', 'projetos' => []];
            }
            $consulta->where('responsavel', 'like', self::contem($nome));
        }

        $total = (clone $consulta)->count();
        $projetos = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'caminho' => Telas::caminho('projetos'),
            'total' => $total,
            'mostradas' => $projetos->count(),
            'projetos' => $projetos->map(fn (Projeto $projeto) => [
                'id' => $projeto->id,
                'nome' => $projeto->nome,
                'descricao' => self::trecho($projeto->descricao),
                'status' => $projeto->status,
                'progresso' => $projeto->progresso,
                'responsavel' => $projeto->responsavel,
                'prazo' => $projeto->prazo?->format('d/m/Y'),
                'dias_restantes' => $projeto->prazo === null ? null : (int) $hoje->diffInDays($projeto->prazo, false),
                'empresa' => $projeto->empresa?->nome,
                'caminho' => Telas::caminho('projetos'),
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
            $consulta->where(function (Builder $q) use ($termo): void {
                $q->where('nome', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo))
                    ->orWhere('area', 'like', self::contem($termo));
            });
        }

        $total = (clone $consulta)->count();
        $processos = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'caminho' => Telas::caminho('processos'),
            'total' => $total,
            'mostradas' => $processos->count(),
            'processos' => $processos->map(fn (Processo $processo) => [
                'id' => $processo->id,
                'nome' => $processo->nome,
                'descricao' => self::trecho($processo->descricao),
                'area' => $processo->area,
                'responsavel' => $processo->responsavel,
                'etapas' => is_array($processo->etapas) ? array_values($processo->etapas) : [],
                'caminho' => Telas::caminho('processos'),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function buscarDocumentos(array $argumentos): array
    {
        $consulta = Documento::query()->with(['pasta', 'enviadoPor'])->orderByDesc('updated_at');
        $termo = self::texto($argumentos, 'termo');
        $pasta = self::texto($argumentos, 'pasta');

        if ($termo !== '') {
            $consulta->where('nome', 'like', self::contem($termo));
        }

        if ($pasta !== '') {
            $consulta->whereHas('pasta', function (Builder $q) use ($pasta): void {
                $q->where('nome', 'like', self::contem($pasta));
            });
        }

        if ($termo === '' && $pasta === '') {
            return ['erro' => 'Informe um trecho do nome ou a pasta do documento.'];
        }

        $total = (clone $consulta)->count();
        $documentos = $consulta->limit(self::LIMITE_LISTA)->get();

        return [
            'caminho' => Telas::caminho('documentos'),
            'total' => $total,
            'mostradas' => $documentos->count(),
            'documentos' => $documentos->map(fn (Documento $documento) => self::resumoDocumento($documento))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function buscarPessoas(array $argumentos): array
    {
        $nome = self::texto($argumentos, 'nome');
        if ($nome === '') {
            return ['erro' => 'Informe um trecho do nome da pessoa.'];
        }

        $como = self::contem($nome);

        $socios = Socio::query()
            ->with('empresa')
            ->where('nome', 'like', $como)
            ->orderBy('nome')
            ->limit(self::LIMITE_BUSCA)
            ->get();

        $equipe = User::query()
            ->where('name', 'like', $como)
            ->orderBy('name')
            ->limit(self::LIMITE_BUSCA)
            ->get(['id', 'name', 'perfil', 'area', 'email']);

        return [
            'socios' => $socios->map(fn (Socio $socio) => [
                'nome' => $socio->nome,
                'cargo' => $socio->cargo,
                'email' => $socio->email,
                'empresa' => $socio->empresa?->nome,
                'caminho' => $socio->empresa_id
                    ? Telas::caminho('cliente', ['id' => $socio->empresa_id])
                    : Telas::caminho('clientes'),
            ])->all(),
            'equipe' => $equipe->map(fn (User $usuario) => [
                'nome' => $usuario->name,
                'perfil' => $usuario->perfil,
                'area' => $usuario->area,
                'email' => $usuario->email,
                'caminho' => Telas::caminho('usuarios'),
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
                'caminho' => Telas::caminho('treinamentos'),
                'treinamentos' => $empresa->treinamentos
                    ->take(self::LIMITE_TREINAMENTO)
                    ->map(fn (Treinamento $item) => self::resumoTreinamento($item))
                    ->all(),
            ];
        }

        $termo = self::texto($argumentos, 'termo');
        $consulta = Treinamento::query()->orderBy('ordem');

        if ($termo !== '') {
            $consulta->where(function (Builder $q) use ($termo): void {
                $q->where('titulo', 'like', self::contem($termo))
                    ->orWhere('descricao', 'like', self::contem($termo))
                    ->orWhere('categoria', 'like', self::contem($termo))
                    ->orWhere('tipo', 'like', self::contem($termo))
                    ->orWhere('processo', 'like', self::contem($termo));
            });
        }

        $encontrados = $consulta->limit(self::LIMITE_TREINAMENTO)->get();

        return [
            'caminho' => Telas::caminho('treinamentos'),
            'total' => $encontrados->count(),
            'treinamentos' => $encontrados->map(fn (Treinamento $item) => self::resumoTreinamento($item))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function criarEmpresa(array $argumentos): array
    {
        $nome = self::texto($argumentos, 'nome');
        if ($nome === '') {
            return ['erro' => 'Informe o nome da empresa.'];
        }

        $setor = self::texto($argumentos, 'setor') ?: 'Não informado';
        $status = self::texto($argumentos, 'status') ?: 'Ativo';
        $tag = in_array($status, ['Ativo', 'ativo'], true) ? 'green' : 'gray';
        $socioNome = self::texto($argumentos, 'socio_nome') ?: 'Sócio não cadastrado';

        $empresa = DB::transaction(function () use ($nome, $setor, $status, $tag, $socioNome): Empresa {
            $empresa = Empresa::create([
                'id' => Chave::apartirDe($nome, 'empresas'),
                'nome' => $nome,
                'setor' => $setor,
                'status' => $status,
                'status_tag' => $tag,
            ]);

            Socio::create([
                'empresa_id' => $empresa->id,
                'nome' => $socioNome,
                'cargo' => 'Não informado',
                'email' => 'Não informado',
                'telefone' => 'Não informado',
                'participacao' => 'Não informado',
                'desde' => 'Não informado',
            ]);

            return $empresa;
        });

        return [
            'criado' => true,
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'setor' => $empresa->setor,
            'status' => $empresa->status,
            'caminho' => Telas::caminho('cliente', ['id' => $empresa->id]),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function editarEmpresa(array $argumentos): array
    {
        $empresa = self::encontrarEmpresa(
            self::texto($argumentos, 'empresa_id'),
            self::texto($argumentos, 'nome'),
        );

        if ($empresa === null) {
            return ['erro' => 'Empresa não encontrada na base.'];
        }

        $novoNome = self::texto($argumentos, 'novo_nome');
        $setor = self::texto($argumentos, 'setor');
        $status = self::texto($argumentos, 'status');
        $socioNome = self::texto($argumentos, 'socio_nome');

        if ($novoNome === '' && $setor === '' && $status === '' && $socioNome === '') {
            return ['erro' => 'Informe o que alterar: novo_nome, setor, status ou socio_nome.'];
        }

        DB::transaction(function () use ($empresa, $novoNome, $setor, $status, $socioNome): void {
            $dados = [];
            if ($novoNome !== '') {
                $dados['nome'] = $novoNome;
            }
            if ($setor !== '') {
                $dados['setor'] = $setor;
            }
            if ($status !== '') {
                $dados['status'] = $status;
                $dados['status_tag'] = self::tagDoStatus($status);
            }
            if ($dados !== []) {
                $empresa->update($dados);
            }

            if ($socioNome !== '') {
                $socio = $empresa->socio;
                if ($socio === null) {
                    Socio::create([
                        'empresa_id' => $empresa->id,
                        'nome' => $socioNome,
                        'cargo' => 'Não informado',
                        'email' => 'Não informado',
                        'telefone' => 'Não informado',
                        'participacao' => 'Não informado',
                        'desde' => 'Não informado',
                    ]);
                } else {
                    $socio->update(['nome' => $socioNome]);
                }
            }
        });

        $empresa->refresh()->load('socio');

        return [
            'editado' => true,
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'setor' => $empresa->setor,
            'status' => $empresa->status,
            'socio' => $empresa->socio?->nome,
            'caminho' => Telas::caminho('cliente', ['id' => $empresa->id]),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function apagarEmpresa(array $argumentos): array
    {
        if (! self::booleano($argumentos, 'confirmar')) {
            return ['erro' => 'Para apagar, confirme com confirmar=true e o id ou nome exato da empresa.'];
        }

        $empresa = self::encontrarEmpresa(
            self::texto($argumentos, 'empresa_id'),
            self::texto($argumentos, 'nome'),
        );

        if ($empresa === null) {
            return ['erro' => 'Empresa não encontrada na base.'];
        }

        $id = $empresa->id;
        $nome = $empresa->nome;
        $empresa->delete();

        return [
            'apagado' => true,
            'id' => $id,
            'nome' => $nome,
            'caminho' => Telas::caminho('clientes'),
        ];
    }

    private static function tagDoStatus(string $status): string
    {
        $normalizado = mb_strtolower($status);

        return match (true) {
            str_contains($normalizado, 'ativo') => 'blue',
            str_contains($normalizado, 'negocia') => 'green',
            str_contains($normalizado, 'acompanhar') => 'yellow',
            str_contains($normalizado, 'novo') => 'purple',
            str_contains($normalizado, 'exclu'), str_contains($normalizado, 'inativ') => 'gray',
            default => in_array('gray', Empresa::TAGS, true) ? 'gray' : Empresa::TAGS[0],
        };
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function criarProjeto(array $argumentos): array
    {
        $nome = self::texto($argumentos, 'nome');
        $descricao = self::texto($argumentos, 'descricao');
        if ($nome === '' || $descricao === '') {
            return ['erro' => 'Informe nome e descrição do projeto.'];
        }

        $status = self::texto($argumentos, 'status') ?: 'Planejado';
        if (! in_array($status, Projeto::STATUS, true)) {
            return ['erro' => 'Status inválido.', 'status' => Projeto::STATUS];
        }

        $prioridade = self::texto($argumentos, 'prioridade') ?: 'Média';
        if (! in_array($prioridade, Projeto::PRIORIDADES, true)) {
            return ['erro' => 'Prioridade inválida.', 'prioridades' => Projeto::PRIORIDADES];
        }

        $inicioIso = self::dataDoArgumento(self::texto($argumentos, 'inicio') ?: 'hoje');
        if ($inicioIso === null) {
            return ['erro' => 'Data de início inválida.'];
        }

        $prazoTexto = self::texto($argumentos, 'prazo');
        $prazoIso = $prazoTexto === ''
            ? Carbon::parse($inicioIso, GoogleAgenda::FUSO)->addDays(30)->toDateString()
            : self::dataDoArgumento($prazoTexto);
        if ($prazoIso === null || $prazoIso < $inicioIso) {
            return ['erro' => 'Prazo inválido: use uma data igual ou posterior ao início.'];
        }

        $empresaId = null;
        $empresaRef = self::texto($argumentos, 'empresa');
        if ($empresaRef !== '') {
            $empresa = self::encontrarEmpresa($empresaRef, $empresaRef);
            if ($empresa === null) {
                return ['erro' => 'Empresa não encontrada na carteira.'];
            }
            $empresaId = $empresa->id;
        }

        $projeto = Projeto::create([
            'id' => Chave::apartirDe($nome, 'projetos'),
            'nome' => $nome,
            'descricao' => $descricao,
            'status' => $status,
            'prioridade' => $prioridade,
            'responsavel' => self::texto($argumentos, 'responsavel') ?: (SofiaContexto::nome() ?: 'Equipe HC'),
            'area' => self::texto($argumentos, 'area') ?: (SofiaContexto::area() ?: 'Geral'),
            'progresso' => 0,
            'inicio' => $inicioIso,
            'prazo' => $prazoIso,
            'empresa_id' => $empresaId,
            'ordem' => (int) Projeto::max('ordem') + 1,
        ]);

        return [
            'criado' => true,
            'id' => $projeto->id,
            'nome' => $projeto->nome,
            'status' => $projeto->status,
            'prioridade' => $projeto->prioridade,
            'caminho' => Telas::caminho('projetos'),
        ];
    }

    /**
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private static function criarProcesso(array $argumentos): array
    {
        $nome = self::texto($argumentos, 'nome');
        $descricao = self::texto($argumentos, 'descricao');
        $etapas = self::lista($argumentos['etapas'] ?? null);

        if ($nome === '' || $descricao === '') {
            return ['erro' => 'Informe nome e descrição do processo.'];
        }

        if ($etapas === []) {
            return ['erro' => 'Descreva ao menos uma etapa do processo.'];
        }

        $processo = Processo::create([
            'id' => Chave::apartirDe($nome, 'processos'),
            'nome' => $nome,
            'descricao' => $descricao,
            'area' => self::texto($argumentos, 'area') ?: (SofiaContexto::area() ?: 'Geral'),
            'responsavel' => self::texto($argumentos, 'responsavel') ?: (SofiaContexto::nome() ?: 'Equipe HC'),
            'frequencia' => self::texto($argumentos, 'frequencia') ?: 'Sob demanda',
            'etapas' => $etapas,
            'atualizado_em' => today()->format('d/m/Y'),
            'ordem' => (int) Processo::max('ordem') + 1,
        ]);

        return [
            'criado' => true,
            'id' => $processo->id,
            'nome' => $processo->nome,
            'area' => $processo->area,
            'etapas' => $processo->etapas,
            'caminho' => Telas::caminho('processos'),
        ];
    }

    /** @return array{titulo: string, tipo: string, categoria: string, descricao: ?string, nivel: string, caminho: string} */
    private static function resumoTreinamento(Treinamento $item): array
    {
        return [
            'titulo' => $item->titulo,
            'tipo' => $item->tipo,
            'categoria' => $item->categoria,
            'descricao' => self::trecho($item->descricao),
            'nivel' => $item->nivel,
            'caminho' => Telas::caminho('treinamentos'),
        ];
    }

    /** @return array<string, mixed> */
    private static function resumoDocumento(Documento $documento): array
    {
        return [
            'nome' => $documento->nomeDoArquivo(),
            'pasta' => $documento->pasta?->nome,
            'enviado_por' => $documento->enviadoPor?->name,
            'caminho' => Telas::caminho('documentos'),
            'arquivo' => route('documentos.arquivo', $documento, false),
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

    /** @return array{erro: string, hoje: string, reunioes: array<empty>} */
    private static function erroDeData(string $erro): array
    {
        return [
            'erro' => $erro,
            'hoje' => now(GoogleAgenda::FUSO)->format('d/m/Y'),
            'reunioes' => [],
        ];
    }

    /**
     * @return array{tipo: 'dia', iso: string}|array{tipo: 'periodo', de: string, ate: string}|array{tipo: 'proximas'}|null
     */
    private static function interpretarQuando(string $valor): ?array
    {
        $normalizado = self::normalizarData($valor);

        if (in_array($normalizado, ['proximas', 'proxima', 'a seguir', 'que vem'], true)) {
            return ['tipo' => 'proximas'];
        }

        if (in_array($normalizado, ['esta semana', 'essa semana'], true)) {
            $inicio = now(GoogleAgenda::FUSO)->startOfWeek(CarbonInterface::MONDAY);

            return [
                'tipo' => 'periodo',
                'de' => $inicio->toDateString(),
                'ate' => $inicio->copy()->endOfWeek(CarbonInterface::SUNDAY)->toDateString(),
            ];
        }

        if (in_array($normalizado, ['semana que vem', 'proxima semana', 'a proxima semana'], true)) {
            $inicio = now(GoogleAgenda::FUSO)->startOfWeek(CarbonInterface::MONDAY)->addWeek();

            return [
                'tipo' => 'periodo',
                'de' => $inicio->toDateString(),
                'ate' => $inicio->copy()->endOfWeek(CarbonInterface::SUNDAY)->toDateString(),
            ];
        }

        if (in_array($normalizado, ['este mes', 'esse mes', 'neste mes'], true)) {
            $agora = now(GoogleAgenda::FUSO);

            return [
                'tipo' => 'periodo',
                'de' => $agora->copy()->startOfMonth()->toDateString(),
                'ate' => $agora->copy()->endOfMonth()->toDateString(),
            ];
        }

        if (in_array($normalizado, ['mes que vem', 'proximo mes'], true)) {
            $inicio = now(GoogleAgenda::FUSO)->addMonthNoOverflow()->startOfMonth();

            return [
                'tipo' => 'periodo',
                'de' => $inicio->toDateString(),
                'ate' => $inicio->copy()->endOfMonth()->toDateString(),
            ];
        }

        $diaDaSemana = self::proximoDiaDaSemana($normalizado);
        if ($diaDaSemana !== null) {
            return ['tipo' => 'dia', 'iso' => $diaDaSemana];
        }

        $iso = self::dataDoArgumento($valor);

        return $iso === null ? null : ['tipo' => 'dia', 'iso' => $iso];
    }

    private static function proximoDiaDaSemana(string $normalizado): ?string
    {
        $dias = [
            'segunda' => CarbonInterface::MONDAY,
            'terca' => CarbonInterface::TUESDAY,
            'quarta' => CarbonInterface::WEDNESDAY,
            'quinta' => CarbonInterface::THURSDAY,
            'sexta' => CarbonInterface::FRIDAY,
            'sabado' => CarbonInterface::SATURDAY,
            'domingo' => CarbonInterface::SUNDAY,
        ];

        foreach ($dias as $nome => $indice) {
            if (preg_match('/^(proxim[oa]s?\s+)?'.$nome.'(-feira)?$/', $normalizado) === 1) {
                return now(GoogleAgenda::FUSO)->next($indice)->toDateString();
            }
        }

        return null;
    }

    /** Aceita hoje/amanhã/ontem, YYYY-MM-DD ou dd/mm/aaaa no fuso de Brasília. */
    private static function dataDoArgumento(string $valor): ?string
    {
        $normalizado = self::normalizarData($valor);

        if (in_array($normalizado, ['hoje', 'today'], true)) {
            return now(GoogleAgenda::FUSO)->toDateString();
        }

        if (in_array($normalizado, ['amanha', 'tomorrow'], true)) {
            return now(GoogleAgenda::FUSO)->addDay()->toDateString();
        }

        if (in_array($normalizado, ['ontem', 'yesterday'], true)) {
            return now(GoogleAgenda::FUSO)->subDay()->toDateString();
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) === 1) {
            return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])
                ? $valor
                : null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor, $partes) === 1) {
            $dia = (int) $partes[1];
            $mes = (int) $partes[2];
            $ano = (int) $partes[3];

            return checkdate($mes, $dia, $ano)
                ? sprintf('%04d-%02d-%02d', $ano, $mes, $dia)
                : null;
        }

        return null;
    }

    private static function normalizarData(string $valor): string
    {
        $minusculo = mb_strtolower(trim($valor));
        $semAcento = strtr($minusculo, ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/\s+/', ' ', $semAcento) ?? $semAcento);
    }

    /** @param  array<string, mixed>  $argumentos */
    private static function texto(array $argumentos, string $chave): string
    {
        $valor = $argumentos[$chave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }

    /** @param  array<string, mixed>  $argumentos */
    private static function booleano(array $argumentos, string $chave): bool
    {
        $valor = $argumentos[$chave] ?? false;

        if (is_bool($valor)) {
            return $valor;
        }

        if (is_string($valor)) {
            return in_array(mb_strtolower(trim($valor)), ['1', 'true', 'sim', 'yes'], true);
        }

        return (bool) $valor;
    }

    private static function trecho(?string $texto, int $limite = 360): ?string
    {
        if ($texto === null) {
            return null;
        }

        $limpo = trim($texto);
        if ($limpo === '') {
            return null;
        }

        if (mb_strlen($limpo) <= $limite) {
            return $limpo;
        }

        return mb_substr($limpo, 0, $limite - 1).'…';
    }

    /**
     * @return list<string>
     */
    private static function lista(mixed $itens): array
    {
        if (! is_array($itens)) {
            return [];
        }

        $linhas = [];
        foreach (array_slice(array_values($itens), 0, 8) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $linhas[] = trim($item);
            }
        }

        return $linhas;
    }

    private static function contem(string $valor): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor).'%';
    }
}
