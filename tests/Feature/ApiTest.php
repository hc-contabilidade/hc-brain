<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\Empresa;
use App\Models\OmieApp;
use App\Models\Pasta;
use App\Models\Reuniao;
use App\Models\User;
use App\Support\GoogleAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'data' => [['id', 'nome', 'setor', 'statusTag', 'socio', 'totalReunioes']],
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

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.codigo', '1')
            ->assertJsonPath('0.status', 'Ativo')
            ->assertJsonPath('0.documento', '33152744000180')
            ->assertJsonPath('0.documentoTipo', 'CNPJ')
            ->assertJsonPath('0.regime', 'MEI')
            ->assertJsonPath('1.codigo', '3')
            ->assertJsonPath('1.regime', null)
            ->assertJsonPath('1.status', 'Inativo')
            ->assertJsonMissing(['id' => 'empresaX']);

        Empresa::query()->whereKey('ruegg')->update(['status' => 'Inativo', 'status_tag' => 'gray']);

        $this->autenticado()
            ->getJson('/api/financeiro/empresas')
            ->assertOk()
            ->assertJsonPath('0.id', 'ruegg')
            ->assertJsonPath('0.status', 'Inativo')
            ->assertJsonPath('0.statusTag', 'gray');
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
