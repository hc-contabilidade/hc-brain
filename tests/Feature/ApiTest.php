<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\Empresa;
use App\Models\OmieApp;
use App\Models\Pasta;
use App\Models\Reuniao;
use App\Models\User;
use App\Support\ChaveDeEmpresa;
use App\Support\GoogleAgenda;
use Database\Seeders\ImportEmpresasSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A API vive dentro da sessão do navegador (routes/web.php), então ela responde
 * com os mesmos dados que as telas em TypeScript consomem.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hc.completar_cnpj_planilha' => false,
            'hc.completar_pela_automacao' => false,
        ]);

        $this->seed();
    }

    private function autenticado(): static
    {
        return $this->actingAs(User::firstOrFail());
    }

    public function test_api_exige_sessao(): void
    {
        $this->getJson('/api/empresas')->assertUnauthorized();
    }

    public function test_contadores_alimentam_o_menu(): void
    {
        $this->autenticado()
            ->getJson('/api/contadores')
            ->assertOk()
            ->assertJson(['empresas' => 5, 'reunioes' => 10, 'usuarios' => 7]);
    }

    public function test_lista_de_empresas(): void
    {
        $this->autenticado()
            ->getJson('/api/empresas')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'nome', 'setor', 'documento', 'documentoTipo', 'statusTag', 'socio', 'totalReunioes']],
            ]);
    }

    /** A lista traz só os totais e a reunião mais recente, não as fontes e reuniões inteiras. */
    public function test_lista_de_empresas_traz_totais_e_a_ultima_reuniao(): void
    {
        $ultima = Reuniao::where('empresa_id', 'empresaX')->orderByDesc('data')->orderByDesc('id')->firstOrFail();

        $empresas = $this->autenticado()->getJson('/api/empresas')->assertOk()->json('data');
        $empresaX = collect($empresas)->firstWhere('id', 'empresaX');

        $this->assertSame(4, $empresaX['totalFontes']);
        $this->assertSame(3, $empresaX['totalReunioes']);
        $this->assertSame(['tipo' => $ultima->tipo, 'data' => $ultima->data->format('d/m/Y')], $empresaX['ultimaReuniao']);
        $this->assertArrayNotHasKey('fontes', $empresaX);
    }

    public function test_detalhe_da_empresa(): void
    {
        $this->autenticado()
            ->getJson('/api/empresas/empresaX')
            ->assertOk()
            ->assertJsonPath('empresa.nome', 'Empresa X')
            ->assertJsonPath('empresa.socio.nome', 'Flávia Silva')
            ->assertJsonStructure([
                'empresa', 'reunioes', 'treinamentos',
                'financeiro' => ['regime', 'meses', 'primeiro', 'atual', 'total', 'receitaMensal', 'transacoes'],
            ]);
    }

    public function test_empresa_inexistente_responde_404(): void
    {
        $this->autenticado()->getJson('/api/empresas/nada')->assertNotFound();
    }

    public function test_reunioes_trazem_a_empresa_e_o_socio(): void
    {
        $this->autenticado()
            ->getJson('/api/reunioes')
            ->assertOk()
            ->assertJsonCount(10, 'reunioes')
            ->assertJsonStructure(['reunioes' => [['id', 'empresa', 'socio', 'tipo', 'data', 'dataOrd', 'status']], 'empresas'])
            ->assertJsonPath('reunioes.0.socio', fn (?string $socio) => $socio !== null);
    }

    public function test_documentos_vem_com_as_pastas(): void
    {
        $this->autenticado()
            ->getJson('/api/documentos')
            ->assertOk()
            ->assertJsonCount(5, 'documentos')
            ->assertJsonCount(5, 'pastas')
            // A contagem de cada pasta é contada, não guardada em coluna.
            ->assertJsonPath('pastas.0.total', 3);
    }

    public function test_treinamentos_vem_com_o_historico(): void
    {
        $this->autenticado()
            ->getJson('/api/treinamentos')
            ->assertOk()
            ->assertJsonCount(19, 'conteudos')
            ->assertJsonCount(0, 'historico');
    }

    public function test_projetos_trazem_prazo_e_progresso(): void
    {
        $this->autenticado()
            ->getJson('/api/projetos')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'nome', 'status', 'prioridade', 'progresso', 'prazo', 'diasRestantes']],
            ])
            ->assertJsonPath('data.0.empresa', 'Empresa X');
    }

    public function test_processos_trazem_as_etapas_na_ordem(): void
    {
        $this->autenticado()
            ->getJson('/api/processos')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.nome', 'Cadastro e atualização de clientes')
            ->assertJsonPath('data.0.etapas.0', 'Receber os dados e documentos do cliente');
    }

    /** O Início mostra contagens reais; nenhum número da tela é digitado. */
    public function test_inicio_traz_os_numeros_da_base_e_a_atividade(): void
    {
        $this->autenticado()
            ->getJson('/api/inicio')
            ->assertOk()
            ->assertJson([
                'contadores' => [
                    'empresas' => 5,
                    'reunioes' => 10,
                    'usuarios' => 7,
                    'documentos' => 5,
                    'treinamentos' => 19,
                    'projetos' => 4,
                    'processos' => 6,
                ],
            ])
            ->assertJsonStructure(['proximasReunioes', 'atividade'])
            ->assertJsonStructure(['atividade' => [['titulo', 'detalhe', 'etiqueta', 'quando', 'destino']]]);
    }

    public function test_inicio_separa_proximas_reunioes_do_historico_recente(): void
    {
        $hoje = now(GoogleAgenda::FUSO)->startOfDay();

        $futura = Reuniao::query()->create([
            'titulo' => 'Alinhamento futuro HC',
            'tipo' => 'Alinhamento',
            'data' => $hoje->copy()->addYear()->toDateString(),
            'horario' => '10:00',
            'responsavel' => 'Beatriz Andrade',
            'participantes' => [],
            'decisoes' => [],
            'proximos_passos' => [],
            'status' => 'Agendada',
            'resumo' => 'Não deve aparecer na atividade recente.',
        ]);

        $concluidaFutura = Reuniao::query()->create([
            'titulo' => 'Concluída ainda na data',
            'tipo' => 'Alinhamento',
            'data' => $hoje->copy()->addDays(2)->toDateString(),
            'horario' => '11:00',
            'responsavel' => 'Beatriz Andrade',
            'participantes' => [],
            'decisoes' => [],
            'proximos_passos' => [],
            'status' => 'Concluída',
            'resumo' => 'Já concluída — fora de Próximas reuniões.',
        ]);

        $concluidaHoje = Reuniao::query()->create([
            'titulo' => 'Alinhamento com Matheus',
            'tipo' => 'Alinhamento',
            'data' => $hoje->toDateString(),
            'horario' => '08:00',
            'responsavel' => 'Beatriz Andrade',
            'participantes' => [],
            'decisoes' => [],
            'proximos_passos' => [],
            'status' => 'Concluída',
            'resumo' => 'Concluída hoje de manhã.',
        ]);

        Reuniao::query()->create([
            'titulo' => 'Alinhamento passado HC',
            'tipo' => 'Alinhamento',
            'data' => $hoje->copy()->subDays(3)->toDateString(),
            'horario' => '15:00',
            'responsavel' => 'Beatriz Andrade',
            'participantes' => [],
            'decisoes' => [],
            'proximos_passos' => [],
            'status' => 'Concluída',
            'resumo' => 'Pertence ao histórico recente.',
        ]);

        $resposta = $this->autenticado()
            ->getJson('/api/inicio')
            ->assertOk();

        $proximas = collect($resposta->json('proximasReunioes'));
        $atividade = collect($resposta->json('atividade'));

        $this->assertTrue(
            $proximas->contains(fn (array $item) => str_contains($item['caminho'] ?? '', 'reuniao='.$futura->id)),
            'A reunião futura deve aparecer em Próximas reuniões.',
        );
        $this->assertFalse(
            $proximas->contains(fn (array $item) => str_contains($item['caminho'] ?? '', 'reuniao='.$concluidaFutura->id)),
            'Reunião concluída não deve aparecer em Próximas reuniões.',
        );
        $this->assertFalse(
            $proximas->contains(fn (array $item) => str_contains($item['caminho'] ?? '', 'reuniao='.$concluidaHoje->id)),
            'Reunião concluída de hoje não deve aparecer em Próximas reuniões.',
        );
        $this->assertFalse(
            $atividade->contains(fn (array $item) => str_contains($item['titulo'], 'Alinhamento futuro HC')),
            'A reunião futura não deve aparecer na atividade recente.',
        );
        $this->assertTrue(
            $atividade->contains(fn (array $item) => str_contains((string) ($item['detalhe'] ?? ''), 'Pertence ao histórico recente.')),
            'A reunião passada deve aparecer na atividade recente.',
        );
        $this->assertTrue(
            $atividade->contains(fn (array $item) => str_contains($item['caminho'] ?? '', 'reuniao='.$concluidaHoje->id)),
            'A reunião concluída de hoje deve aparecer na atividade recente.',
        );
    }

    public function test_comercial_responde_por_periodo(): void
    {
        $this->autenticado()
            ->getJson('/api/comercial/2026-08')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-08')
            ->assertJsonStructure(['leads', 'closed', 'revenue', 'monthly', 'origins', 'losses', 'team']);
    }

    public function test_origens_do_comercial_somam_os_leads_do_periodo(): void
    {
        foreach (['2026-07', '2026-08', '2026-09'] as $periodo) {
            $resposta = $this->autenticado()
                ->getJson("/api/comercial/{$periodo}")
                ->assertOk()
                ->assertJsonPath('periodo', $periodo);

            $somaOrigens = collect($resposta->json('origins'))->sum(fn (array $origem) => $origem[1]);
            $this->assertSame(
                $resposta->json('leads'),
                $somaOrigens,
                "Origens de {$periodo} devem somar os leads do período.",
            );
        }
    }

    /** O período vem de um <select>, então um valor desconhecido cai no mais recente. */
    public function test_periodo_comercial_desconhecido_cai_no_mais_recente(): void
    {
        $this->autenticado()
            ->getJson('/api/comercial/1999-01')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-09');
    }

    public function test_carteira_financeira_lista_somente_empresa_integrada_ao_omie(): void
    {
        $this->autenticado()
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(0);

        $empresa = Empresa::query()->where('nome', 'Empresa X')->firstOrFail();
        $app = OmieApp::query()->create([
            'nome' => 'HC Contábil',
            'app_key' => 'chave-teste',
            'app_secret' => 'segredo-teste',
            'ativo' => true,
        ]);
        $empresa->update([
            'omie_app_id' => $app->id,
            'omie_codigo_cliente' => 424242,
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'empresaX')
            ->assertJsonPath('0.nome', 'Empresa X')
            ->assertJsonPath('0.total', 142600)
            ->assertJsonMissing(['id' => 'empresaY']);
    }

    public function test_lista_do_financeiro_mostra_ativa_e_inativa_e_acompanha_a_troca(): void
    {
        Carbon::setTestNow('2026-11-10');

        $ruegg = Empresa::query()->create([
            'id' => 'ruegg',
            'automacao_id' => 1,
            'codigo' => '1',
            'nome' => 'RUEGG ODONTOLOGIA LTDA',
            'documento' => '33152744000180',
            'documento_tipo' => 'CNPJ',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        $ruegg->financeiro()->create([
            'regime' => 'MEI',
            'desde' => '2024-01-01',
            'primeiro' => 1000,
            'atual' => 1500,
            'total' => 8000,
            'saldo' => 0,
            'receber' => 0,
            'despesas' => 0,
            'margem' => 0,
        ]);
        Empresa::query()->create([
            'id' => 'margem',
            'automacao_id' => 3,
            'codigo' => '3',
            'nome' => 'MARGEM PINTURAS E REFORMAS EM GERAL LTDA',
            'documento' => '18045084000145',
            'documento_tipo' => 'CNPJ',
            'setor' => 'Construção',
            'status' => 'Inativo',
            'status_tag' => 'gray',
        ]);
        Empresa::query()->create([
            'id' => 'sieg',
            'automacao_id' => 1389,
            'nome' => 'SIEG ASSISTENCIA MEDICA LTDA (1389)',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $lista = $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonMissing(['id' => 'empresaX'])
            ->json();
        $porId = collect($lista)->keyBy('id');

        $this->assertSame('1', $porId['ruegg']['codigo']);
        $this->assertSame('Ativo', $porId['ruegg']['status']);
        $this->assertSame('33152744000180', $porId['ruegg']['documento']);
        $this->assertSame('CNPJ', $porId['ruegg']['documentoTipo']);
        $this->assertSame('MEI', $porId['ruegg']['regime']);
        $this->assertSame(34, $porId['ruegg']['meses']);
        $this->assertSame(1000, $porId['ruegg']['primeiro']);
        $this->assertSame(1500, $porId['ruegg']['atual']);
        $this->assertSame(8000, $porId['ruegg']['total']);
        $this->assertSame('3', $porId['margem']['codigo']);
        $this->assertNull($porId['margem']['regime']);
        $this->assertNull($porId['margem']['meses']);
        $this->assertNull($porId['margem']['primeiro']);
        $this->assertSame('Inativo', $porId['margem']['status']);
        $this->assertSame('1389', $porId['sieg']['codigo']);
        $this->assertNull($porId['sieg']['documento']);

        Empresa::query()->whereKey('ruegg')->update(['status' => 'Inativo', 'status_tag' => 'gray']);

        $atualizada = collect($this->autenticado()->getJson('/api/financeiro/empresas')->assertOk()->json())->keyBy('id');
        $this->assertSame('Inativo', $atualizada['ruegg']['status']);
        $this->assertSame('gray', $atualizada['ruegg']['statusTag']);
    }

    public function test_lista_do_financeiro_usa_o_cnpj_da_planilha_pelo_codigo(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);

        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                return [
                    'porCodigo' => [
                        '8801' => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '8801', 'nome' => 'EMPRESA 8801 DA PLANILHA LTDA'],
                    ],
                    'porNome' => [
                        ChaveDeEmpresa::nome('Clínica Exemplo LTDA') => ['documento' => '33444555000172', 'tipo' => 'CNPJ', 'codigo' => '8802', 'nome' => 'CLÍNICA EXEMPLO LTDA'],
                        ChaveDeEmpresa::nome('A&E OLIVEIRA GESTAO PATRIMONIAL LTDA') => ['documento' => '55666777000190', 'tipo' => 'CNPJ', 'codigo' => '1489', 'nome' => 'A&E OLIVEIRA GESTAO PATRIMONIAL LTDA'],
                        ChaveDeEmpresa::nome('PESSOA EXEMPLO') => ['documento' => '12345678901', 'tipo' => 'CPF', 'codigo' => '1500', 'nome' => 'PESSOA EXEMPLO'],
                    ],
                ];
            }
        });

        Empresa::query()->create([
            'id' => 'financeiro-com-codigo',
            'automacao_id' => 8801,
            'codigo' => '8801',
            'nome' => 'Empresa do financeiro',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        Empresa::query()->create([
            'id' => 'financeiro-pelo-nome',
            'automacao_id' => 8802,
            'nome' => 'Clínica Exemplo LTDA',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        Empresa::query()->create([
            'id' => 'financeiro-sem-ltda',
            'automacao_id' => 1489,
            'nome' => 'A&E OLIVEIRA GESTAO PATRIMONIAL',
            'setor' => 'Serviços',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        Empresa::query()->create([
            'id' => 'financeiro-com-prefixo',
            'automacao_id' => 1500,
            'nome' => '27.109.976 PESSOA EXEMPLO',
            'setor' => 'Serviços',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'financeiro-com-codigo',
                'nome' => 'EMPRESA 8801 DA PLANILHA LTDA',
                'codigo' => '8801',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ])
            ->assertJsonFragment([
                'id' => 'financeiro-pelo-nome',
                'nome' => 'CLÍNICA EXEMPLO LTDA',
                'codigo' => '8802',
                'documento' => '33444555000172',
                'documentoTipo' => 'CNPJ',
            ])
            ->assertJsonFragment([
                'id' => 'financeiro-sem-ltda',
                'nome' => 'A&E OLIVEIRA GESTAO PATRIMONIAL LTDA',
                'codigo' => '1489',
                'documento' => '55666777000190',
                'documentoTipo' => 'CNPJ',
            ])
            ->assertJsonFragment([
                'id' => 'financeiro-com-prefixo',
                'nome' => 'PESSOA EXEMPLO',
                'codigo' => '1500',
                'documento' => '12345678901',
                'documentoTipo' => 'CPF',
            ]);
    }

    public function test_lista_do_financeiro_completa_cnpj_pelo_codigo_da_empresa(): void
    {
        Empresa::query()->create([
            'id' => 'planilha-aaf',
            'codigo' => '1402',
            'nome' => 'AAF REPRESENTACOES COMERCIAIS LTDA',
            'documento' => '11222333000181',
            'documento_tipo' => 'CNPJ',
            'setor' => 'Comércio',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        Empresa::query()->create([
            'id' => 'automacao-aaf',
            'automacao_id' => 501,
            'codigo' => '1402',
            'nome' => 'AAF Comercial',
            'setor' => 'Comércio',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'automacao-aaf',
                'codigo' => '1402',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_completa_codigo_e_cnpj_pelo_nome_da_empresa(): void
    {
        Empresa::query()->create([
            'id' => 'planilha-abcl',
            'codigo' => '669',
            'nome' => 'ABCL SERVICOS MEDICOS LTDA',
            'documento' => '33444555000172',
            'documento_tipo' => 'CNPJ',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        Empresa::query()->create([
            'id' => 'automacao-abcl',
            'automacao_id' => 502,
            'nome' => 'ABCL Serviços Médicos LTDA',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'automacao-abcl',
                'codigo' => '669',
                'documento' => '33444555000172',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_completa_codigo_e_cnpj_pela_automacao(): void
    {
        config([
            'hc.completar_pela_automacao' => true,
            'database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('automacao');
        Schema::connection('automacao')->create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('draft')->default(false);
            $table->string('code');
            $table->string('document_number')->nullable();
            $table->string('document_type')->nullable();
        });
        $id = DB::connection('automacao')->table('companies')->insertGetId([
            'draft' => false,
            'code' => '4401',
            'document_number' => '11.222.333/0001-81',
            'document_type' => 'CNPJ',
        ]);
        Empresa::query()->create([
            'id' => 'sem-codigo-local',
            'automacao_id' => $id,
            'nome' => 'Empresa sem codigo local',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'sem-codigo-local',
                'codigo' => '4401',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_usa_o_codigo_da_automacao_para_achar_o_cnpj_da_planilha(): void
    {
        config([
            'hc.completar_pela_automacao' => true,
            'hc.completar_cnpj_planilha' => true,
            'database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('automacao');
        Schema::connection('automacao')->create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('draft')->default(false);
            $table->string('code');
        });
        $id = DB::connection('automacao')->table('companies')->insertGetId([
            'draft' => false,
            'code' => '8801',
        ]);
        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                return [
                    'porCodigo' => [
                        '8801' => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '8801', 'nome' => 'EMPRESA 8801 DA PLANILHA LTDA'],
                    ],
                    'porNome' => [],
                    'porRaiz' => [],
                ];
            }
        });
        Empresa::query()->create([
            'id' => 'so-na-automacao',
            'automacao_id' => $id,
            'nome' => 'Nome fantasia diferente',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'so-na-automacao',
                'nome' => 'EMPRESA 8801 DA PLANILHA LTDA',
                'codigo' => '8801',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_acha_cnpj_quando_o_nome_fantasia_cabe_na_razao_social(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);
        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                $chave = ChaveDeEmpresa::nome('AISSAR BURATO ASSISTENCIA MEDICA LTDA');

                return [
                    'porCodigo' => [],
                    'porNome' => [
                        $chave => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '1514', 'nome' => 'AISSAR BURATO ASSISTENCIA MEDICA LTDA'],
                    ],
                    'porRaiz' => [],
                ];
            }
        });
        Empresa::query()->create([
            'id' => 'nome-curto',
            'automacao_id' => 1514,
            'nome' => 'AISSAR BURATO',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'nome-curto',
                'nome' => 'AISSAR BURATO ASSISTENCIA MEDICA LTDA',
                'codigo' => '1514',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_troca_o_numero_sequencial_pelo_codigo_da_planilha(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);
        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                $chave = ChaveDeEmpresa::nome('AAF REPRESENTACOES COMERCIAIS LTDA');

                return [
                    'porCodigo' => [
                        '2' => ['documento' => '99888777000166', 'tipo' => 'CNPJ', 'codigo' => '2', 'nome' => 'OUTRA EMPRESA DE CODIGO 2'],
                    ],
                    'porNome' => [
                        $chave => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '1402', 'nome' => 'AAF REPRESENTACOES COMERCIAIS LTDA'],
                    ],
                    'porRaiz' => [],
                ];
            }
        });
        Empresa::query()->create([
            'id' => 'codigo-sequencial',
            'automacao_id' => 50,
            'codigo' => '2',
            'nome' => 'AAF REPRESENTACOES COMERCIAIS LTDA',
            'setor' => 'Comércio',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'codigo-sequencial',
                'nome' => 'AAF REPRESENTACOES COMERCIAIS LTDA',
                'codigo' => '1402',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_lista_do_financeiro_nao_chuta_cnpj_quando_o_nome_curto_cabe_em_duas_empresas(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);
        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                return [
                    'porCodigo' => [],
                    'porNome' => [
                        'CLINICA VIDA SAUDE' => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '10', 'nome' => 'CLINICA VIDA SAUDE LTDA'],
                        'CLINICA VIDA ODONTO' => ['documento' => '33444555000172', 'tipo' => 'CNPJ', 'codigo' => '11', 'nome' => 'CLINICA VIDA ODONTO LTDA'],
                    ],
                    'porRaiz' => [],
                ];
            }
        });
        Empresa::query()->create([
            'id' => 'nome-ambiguo',
            'automacao_id' => 77,
            'nome' => 'CLINICA VIDA',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'nome-ambiguo',
                'nome' => 'CLINICA VIDA',
                'codigo' => null,
                'documento' => null,
            ]);
    }

    public function test_lista_do_financeiro_acha_o_cnpj_pela_raiz_no_comeco_do_nome(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);
        $this->app->instance(ImportEmpresasSeeder::class, new class extends ImportEmpresasSeeder
        {
            public function mapaDeDocumentos(?string $caminho = null): array
            {
                return [
                    'porCodigo' => [],
                    'porNome' => [],
                    'porRaiz' => [
                        '11222333' => ['documento' => '11222333000181', 'tipo' => 'CNPJ', 'codigo' => '900', 'nome' => 'RAZAO SOCIAL DIFERENTE LTDA'],
                    ],
                ];
            }
        });
        Empresa::query()->create([
            'id' => 'pela-raiz',
            'automacao_id' => 900,
            'nome' => '11.222.333 PESSOA SEM RAZAO',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'pela-raiz',
                'nome' => 'RAZAO SOCIAL DIFERENTE LTDA',
                'codigo' => '900',
                'documento' => '11222333000181',
                'documentoTipo' => 'CNPJ',
            ]);
    }

    public function test_mapa_le_codigo_e_cnpj_do_xlsx(): void
    {
        config(['hc.completar_cnpj_planilha' => true]);
        $caminho = storage_path('framework/testing/empresas-mini.xlsx');
        $this->gravarPlanilhaMinima($caminho);

        try {
            $mapa = app(ImportEmpresasSeeder::class)->mapaDeDocumentos($caminho);
        } finally {
            @unlink($caminho);
        }

        $this->assertSame('4401', $mapa['porCodigo']['4401']['codigo']);
        $this->assertSame('11222333000181', $mapa['porCodigo']['4401']['documento']);
        $this->assertSame('CNPJ', $mapa['porCodigo']['4401']['tipo']);
        $this->assertArrayHasKey(ChaveDeEmpresa::nome('AISSAR BURATO ASSISTENCIA MEDICA LTDA'), $mapa['porNome']);
        $this->assertSame('11222333000181', $mapa['porRaiz']['11222333']['documento']);
    }

    private function gravarPlanilhaMinima(string $caminho): void
    {
        if (! is_dir(dirname($caminho))) {
            mkdir(dirname($caminho), 0777, true);
        }

        $zip = new \ZipArchive;
        $zip->open($caminho, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Empresas" sheetId="1" r:id="rId1"/></sheets></workbook>
            XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>
            XML);
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>11222333000181</t></si><si><t>AISSAR BURATO ASSISTENCIA MEDICA LTDA</t></si></sst>
            XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2"><v>4401</v></c><c r="B2" t="s"><v>0</v></c><c r="C2" t="s"><v>1</v></c></row></sheetData></worksheet>
            XML);
        $zip->close();
    }

    public function test_indice_de_pesquisa_vem_da_base_ao_vivo(): void
    {
        $documento = Documento::query()->where('nome', 'Proposta comercial')->firstOrFail();

        $resposta = $this->autenticado()
            ->getJson('/api/pesquisa')
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'Documento',
                'title' => $documento->nomeDoArquivo(),
                'demonstrativo' => true,
            ]);

        $this->assertNotEmpty($resposta->json());
        $this->assertContains('Documento', collect($resposta->json())->pluck('type')->all());
        $this->assertContains('Pessoa', collect($resposta->json())->pluck('type')->all());

        $proposta = collect($resposta->json())->firstWhere('title', 'Proposta comercial.pdf');
        $this->assertIsArray($proposta);
        $this->assertStringContainsString('Demonstrativo', (string) ($proposta['meta'] ?? ''));
        $this->assertStringContainsString('exemplo', (string) ($proposta['text'] ?? ''));
    }

    public function test_documento_criado_entra_na_pesquisa_geral(): void
    {
        Storage::fake(config('hc.documentos.disco'));

        $pasta = Pasta::firstOrFail();
        Documento::create([
            'pasta_id' => $pasta->id,
            'nome' => 'Lista de Usuários',
            'extensao' => 'pdf',
            'arquivo' => 'documentos/'.$pasta->id.'/lista.pdf',
            'conteudo' => '%PDF-1.4',
            'tamanho' => 8,
            'mime' => 'application/pdf',
            'enviado_por' => User::firstOrFail()->id,
        ]);

        $this->autenticado()
            ->getJson('/api/pesquisa')
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'Documento',
                'title' => 'Lista de Usuários.pdf',
            ]);
    }

    public function test_cadastro_de_usuario_entra_na_base(): void
    {
        $this->autenticado()
            ->postJson('/api/usuarios', [
                'nome' => 'Joana Prado',
                'email' => 'joana.prado@healthcare.com.br',
                'perfil' => 'Colaborador',
                'area' => 'Projetos',
                'status' => 'Ativo',
                'senha' => 'segredo123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Joana Prado')
            ->assertJsonPath('data.iniciais', 'JP');

        $this->assertDatabaseHas('users', ['email' => 'joana.prado@healthcare.com.br']);

        $this->autenticado()
            ->getJson('/api/contadores')
            ->assertJsonPath('usuarios', 8);
    }

    public function test_cadastro_recusa_email_repetido(): void
    {
        $this->autenticado()
            ->postJson('/api/usuarios', [
                'nome' => 'Outra Beatriz',
                'email' => 'beatriz@healthcare.com.br',
                'perfil' => 'Colaborador',
                'area' => 'Projetos',
                'status' => 'Ativo',
                'senha' => 'segredo123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_sofia_sugere_perguntas(): void
    {
        $this->autenticado()
            ->getJson('/api/sofia/sugestoes')
            ->assertOk()
            ->assertJsonCount(6)
            ->assertJsonStructure([['icon', 'text']]);
    }
}
