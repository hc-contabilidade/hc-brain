<?php

namespace Tests\Feature;

use App\Models\Reuniao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonCount(5, 'historico');
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
                    'projetos' => 6,
                    'processos' => 6,
                ],
            ])
            ->assertJsonStructure(['atividade' => [['titulo', 'detalhe', 'etiqueta', 'quando', 'destino']]]);
    }

    public function test_comercial_responde_por_periodo(): void
    {
        $this->autenticado()
            ->getJson('/api/comercial/2026-08')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-08')
            ->assertJsonStructure(['leads', 'closed', 'revenue', 'monthly', 'origins', 'losses', 'team']);
    }

    /** O período vem de um <select>, então um valor desconhecido cai no mais recente. */
    public function test_periodo_comercial_desconhecido_cai_no_mais_recente(): void
    {
        $this->autenticado()
            ->getJson('/api/comercial/1999-01')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-09');
    }

    public function test_carteira_financeira(): void
    {
        $this->autenticado()
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonStructure([['nome', 'setor', 'socio', 'regime', 'meses', 'primeiro', 'atual', 'total']]);
    }

    public function test_indice_de_pesquisa(): void
    {
        $this->autenticado()
            ->getJson('/api/pesquisa')
            ->assertOk()
            ->assertJsonCount(16);
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
