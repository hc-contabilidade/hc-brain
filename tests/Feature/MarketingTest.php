<?php

namespace Tests\Feature;

use App\Models\MarketingConteudo;
use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonPath('youtube.views', 9100)
            ->assertJsonPath('youtube.inscritosNovos', 145)
            ->assertJsonPath('youtube.variacaoInscritos', 100)
            ->assertJsonPath('instagram.views', 12800)
            ->assertJsonPath('instagram.seguidores', 3680)
            ->assertJsonPath('destaqueYoutube.titulo', 'Como ler o DAS sem medo')
            ->assertJsonPath('destaqueYoutube.ganhoInscritos', 118)
            ->assertJsonPath('canais.youtube', 'https://youtube.com/@healthcarecontabilidade/videos')
            ->assertJsonPath('canais.instagram', 'https://instagram.com/healthcarecontabilidade/')
            ->assertJsonStructure([
                'serieYoutube' => [['rotulo', 'views', 'ganho', 'audiencia']],
                'serieInstagram' => [['rotulo', 'views', 'ganho', 'audiencia']],
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

    public function test_comando_sync_marketing_responde_ok_sem_credenciais(): void
    {
        config([
            'hc.marketing.youtube_api_key' => null,
            'hc.marketing.youtube_channel_id' => null,
            'hc.marketing.instagram_access_token' => null,
            'hc.marketing.instagram_user_id' => null,
        ]);

        $this->artisan('hc:sincronizar-marketing')
            ->assertSuccessful();
    }

    public function test_sync_youtube_grava_metricas_e_destaque_do_canal(): void
    {
        config([
            'hc.marketing.youtube_api_key' => 'chave-teste',
            'hc.marketing.youtube_channel_id' => 'UC5Evg8Q8ZMf1fm91vHPJ-mw',
            'hc.marketing.instagram_access_token' => null,
            'hc.marketing.instagram_user_id' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'statistics' => ['subscriberCount' => '8200', 'viewCount' => '100000'],
                    'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-uploads']],
                ]],
            ]),
            'https://www.googleapis.com/youtube/v3/playlistItems*' => Http::response([
                'items' => [
                    ['contentDetails' => ['videoId' => 'vid-top']],
                    ['contentDetails' => ['videoId' => 'vid-outro']],
                ],
            ]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'vid-top',
                        'snippet' => [
                            'title' => 'Como ler o DAS sem medo',
                            'publishedAt' => now()->startOfMonth()->addDays(2)->toIso8601String(),
                        ],
                        'statistics' => [
                            'viewCount' => '6800',
                            'likeCount' => '540',
                            'commentCount' => '86',
                        ],
                    ],
                    [
                        'id' => 'vid-outro',
                        'snippet' => [
                            'title' => 'Bastidores HC',
                            'publishedAt' => now()->startOfMonth()->addDays(10)->toIso8601String(),
                        ],
                        'statistics' => [
                            'viewCount' => '1200',
                            'likeCount' => '90',
                            'commentCount' => '12',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('hc:sincronizar-marketing')->assertSuccessful();

        $periodo = now()->format('Y-m');

        $this->assertDatabaseHas('marketing_metricas', [
            'periodo' => $periodo,
            'plataforma' => 'youtube',
            'views' => 8000,
            'likes' => 630,
            'seguidores' => 8200,
        ]);

        $this->assertDatabaseHas('marketing_conteudos', [
            'plataforma' => 'youtube',
            'periodo' => $periodo,
            'titulo' => 'Como ler o DAS sem medo',
            'views' => 6800,
            'destaque' => 1,
        ]);

        $this->assertTrue(MarketingConteudo::query()->where('titulo', 'Como ler o DAS sem medo')->exists());
    }

    public function test_seeder_preenche_metricas_e_ideias(): void
    {
        $this->assertGreaterThanOrEqual(3, MarketingMetrica::query()->count());
        $this->assertGreaterThanOrEqual(1, MarketingIdeia::query()->count());
    }
}
