<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\OmieApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A carteira consolidada do Financeiro só lista empresas com Omie
 * (app + código de cliente), nunca as fictícias do seeder.
 */
class FinanceiroCarteiraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_empresas_ficticias_do_seeder_nao_aparecem_na_carteira(): void
    {
        $this->actingAs(User::firstOrFail())
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(0)
            ->assertExactJson([]);
    }

    public function test_empresa_com_omie_e_financeiro_aparece_na_carteira(): void
    {
        $app = OmieApp::query()->create([
            'nome' => 'HC Contábil',
            'app_key' => 'chave-teste',
            'app_secret' => 'segredo-teste',
            'ativo' => true,
        ]);
        $empresa = Empresa::query()->create([
            'id' => 'cliente-real',
            'automacao_id' => 42,
            'omie_app_id' => $app->id,
            'omie_codigo_cliente' => 1001,
            'nome' => 'Cliente Real LTDA',
            'setor' => 'Serviços',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);
        $empresa->socio()->create([
            'nome' => 'Ana Real',
            'cargo' => 'Sócia',
            'email' => 'ana@cliente.real',
            'telefone' => '(11) 90000-0000',
            'participacao' => '100%',
            'desde' => 'Cliente desde jan/2026',
        ]);
        $empresa->financeiro()->create([
            'regime' => 'Simples Nacional',
            'desde' => '2025-01-15',
            'primeiro' => 1000,
            'atual' => 2000,
            'total' => 5000,
            'saldo' => 1500,
            'receber' => 2000,
            'despesas' => 500,
            'margem' => 75,
        ]);

        $this->actingAs(User::firstOrFail())
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'cliente-real')
            ->assertJsonPath('0.nome', 'Cliente Real LTDA')
            ->assertJsonPath('0.regime', 'Simples Nacional');
    }

    public function test_empresa_ficticia_mesmo_com_financeiro_manual_nao_aparece(): void
    {
        $empresa = Empresa::query()->findOrFail('empresaX');
        $empresa->financeiro()->create([
            'regime' => 'Simples Nacional',
            'desde' => '2025-01-15',
            'primeiro' => 4500,
            'atual' => 8700,
            'total' => 142600,
            'saldo' => 18450,
            'receber' => 8700,
            'despesas' => 3150,
            'margem' => 63,
        ]);

        $this->actingAs(User::firstOrFail())
            ->getJson('/api/financeiro')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
