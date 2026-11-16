<?php

namespace Tests\Feature;

use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_tela_marketing_abre(): void
    {
        $this->actingAs(User::firstOrFail())
            ->get('/marketing')
            ->assertOk();
    }

    public function test_resumo_do_periodo_traz_kpis_e_ideias(): void
    {
        $this->actingAs(User::firstOrFail())
            ->getJson('/api/marketing/2026-10')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-10')
            ->assertJsonPath('youtube.views', 5100)
            ->assertJsonPath('instagram.views', 11200)
            ->assertJsonStructure([
                'serieYoutube',
                'serieInstagram',
                'ideias' => [['id', 'titulo', 'plataforma', 'status']],
            ]);
    }

    public function test_cria_atualiza_e_exclui_ideia(): void
    {
        $usuario = User::firstOrFail();

        $criada = $this->actingAs($usuario)
            ->postJson('/api/marketing/ideias', [
                'titulo' => 'Vídeo teste HC',
                'descricao' => 'Rascunho de teste',
                'plataforma' => 'youtube',
                'status' => 'rascunho',
                'prioridade' => 'alta',
                'responsavel' => 'Beatriz',
            ])
            ->assertCreated()
            ->assertJsonPath('titulo', 'Vídeo teste HC')
            ->json();

        $this->actingAs($usuario)
            ->putJson('/api/marketing/ideias/'.$criada['id'], [
                'titulo' => 'Vídeo teste HC',
                'descricao' => 'Aprovado',
                'plataforma' => 'youtube',
                'status' => 'aprovada',
                'prioridade' => 'alta',
                'responsavel' => 'Beatriz',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'aprovada');

        $this->actingAs($usuario)
            ->deleteJson('/api/marketing/ideias/'.$criada['id'])
            ->assertNoContent();

        $this->assertDatabaseMissing('marketing_ideias', ['id' => $criada['id']]);
    }

    public function test_comando_sync_marketing_responde_ok_no_stub(): void
    {
        $this->artisan('hc:sincronizar-marketing')
            ->assertSuccessful();
    }

    public function test_seeder_preenche_metricas_e_ideias(): void
    {
        $this->assertGreaterThanOrEqual(3, MarketingMetrica::query()->count());
        $this->assertGreaterThanOrEqual(1, MarketingIdeia::query()->count());
    }
}
