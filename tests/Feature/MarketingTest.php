<?php

namespace Tests\Feature;

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
                'serieYoutube' => [['periodo', 'rotulo', 'views', 'ganho', 'audiencia', 'saldo']],
                'serieInstagram' => [['periodo', 'rotulo', 'views', 'ganho', 'audiencia', 'saldo']],
                'videosYoutube',
                'videosInstagram',
                'youtube' => ['saldoInscritos', 'inscritosPerdidos', 'engajamento'],
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
                    ['contentDetails' => ['videoId' => 'vid-short']],
                    ['contentDetails' => ['videoId' => 'vid-outro']],
                ],
            ]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'vid-top',
                        'snippet' => [
                            'title' => 'Como ler o DAS sem medo',
                            'description' => 'Aula completa',
                            'publishedAt' => now()->startOfMonth()->addDays(2)->toIso8601String(),
                        ],
                        'contentDetails' => ['duration' => 'PT12M4S'],
                        'statistics' => [
                            'viewCount' => '6800',
                            'likeCount' => '540',
                            'commentCount' => '86',
                        ],
                    ],
                    [
                        'id' => 'vid-short',
                        'snippet' => [
                            'title' => 'DAS em 30 segundos #shorts',
                            'description' => 'Corte rápido',
                            'publishedAt' => now()->startOfMonth()->addDays(5)->toIso8601String(),
                        ],
                        'contentDetails' => ['duration' => 'PT28S'],
                        'statistics' => [
                            'viewCount' => '4100',
                            'likeCount' => '320',
                            'commentCount' => '40',
                        ],
                    ],
                    [
                        'id' => 'vid-outro',
                        'snippet' => [
                            'title' => 'Bastidores HC',
                            'description' => 'Making of',
                            'publishedAt' => now()->startOfMonth()->addDays(10)->toIso8601String(),
                        ],
                        'contentDetails' => ['duration' => 'PT8M10S'],
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
            'views' => 12100,
            'likes' => 950,
            'seguidores' => 8200,
        ]);

        $this->assertDatabaseHas('marketing_conteudos', [
            'plataforma' => 'youtube',
            'periodo' => $periodo,
            'titulo' => 'Como ler o DAS sem medo',
            'formato' => 'video',
            'views' => 6800,
            'destaque' => 1,
        ]);

        $this->assertDatabaseHas('marketing_conteudos', [
            'plataforma' => 'youtube',
            'periodo' => $periodo,
            'titulo' => 'DAS em 30 segundos #shorts',
            'formato' => 'short',
            'views' => 4100,
            'destaque' => 0,
            'url' => 'https://www.youtube.com/shorts/vid-short',
        ]);
    }

    public function test_sync_instagram_grava_seguidores_e_destaque(): void
    {
        config([
            'hc.marketing.youtube_api_key' => null,
            'hc.marketing.youtube_channel_id' => null,
            'hc.marketing.instagram_access_token' => 'token-teste',
            'hc.marketing.instagram_user_id' => '17841400000000000',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000000?*' => Http::sequence()
                ->push(['followers_count' => 3765])
                ->push([
                    'followers_count' => 3765,
                    'media_count' => 120,
                    'username' => 'healthcarecontabilidade',
                ]),
            'https://graph.facebook.com/v21.0/17841400000000000/media*' => Http::response([
                'data' => [[
                    'id' => 'media-1',
                    'caption' => 'Checklist MEI em 60 segundos',
                    'media_type' => 'VIDEO',
                    'permalink' => 'https://instagram.com/p/abc',
                    'timestamp' => now()->startOfMonth()->addDays(3)->toIso8601String(),
                    'like_count' => 780,
                    'comments_count' => 64,
                ]],
            ]),
            'https://graph.facebook.com/v21.0/media-1/insights*' => Http::response([
                'data' => [
                    ['name' => 'plays', 'values' => [['value' => 5400]]],
                    ['name' => 'reach', 'values' => [['value' => 4200]]],
                ],
            ]),
        ]);

        $this->artisan('hc:sincronizar-marketing')->assertSuccessful();

        $periodo = now()->format('Y-m');

        $this->assertDatabaseHas('marketing_metricas', [
            'periodo' => $periodo,
            'plataforma' => 'instagram',
            'views' => 5400,
            'likes' => 780,
            'seguidores' => 3765,
            'inscritos_ou_alcance' => 4200,
        ]);

        $this->assertDatabaseHas('marketing_conteudos', [
            'plataforma' => 'instagram',
            'periodo' => $periodo,
            'titulo' => 'Checklist MEI em 60 segundos',
            'destaque' => 1,
        ]);
    }

    public function test_sync_instagram_resolve_ig_user_a_partir_do_page_id(): void
    {
        config([
            'hc.marketing.youtube_api_key' => null,
            'hc.marketing.youtube_channel_id' => null,
            'hc.marketing.instagram_access_token' => 'token-teste',
            'hc.marketing.instagram_user_id' => '111222333', // Page ID
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v21.0/111222333?*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => '(#100) Tried accessing nonexisting field (followers_count).',
                        'type' => 'OAuthException',
                        'code' => 100,
                    ],
                ], 400)
                ->push([
                    'instagram_business_account' => ['id' => '17841400000000000'],
                    'id' => '111222333',
                ]),
            'https://graph.facebook.com/v21.0/17841400000000000?*' => Http::response([
                'followers_count' => 2100,
                'media_count' => 10,
                'username' => 'healthcarecontabilidade',
            ]),
            'https://graph.facebook.com/v21.0/17841400000000000/media*' => Http::response([
                'data' => [],
            ]),
        ]);

        $this->artisan('hc:sincronizar-marketing')->assertSuccessful();

        $this->assertDatabaseHas('marketing_metricas', [
            'periodo' => now()->format('Y-m'),
            'plataforma' => 'instagram',
            'seguidores' => 2100,
        ]);
    }

    public function test_sync_instagram_descobre_conta_via_me_accounts(): void
    {
        config([
            'hc.marketing.youtube_api_key' => null,
            'hc.marketing.youtube_channel_id' => null,
            'hc.marketing.instagram_access_token' => 'token-teste',
            'hc.marketing.instagram_user_id' => '999999999', // ID inválido / tipo errado
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v21.0/999999999?*' => Http::sequence()
                ->push(['error' => ['message' => 'followers_count missing', 'code' => 100]], 400)
                ->push(['error' => ['message' => 'instagram_business_account missing', 'code' => 100]], 400),
            'https://graph.facebook.com/v21.0/me/accounts*' => Http::response([
                'data' => [[
                    'id' => 'page-1',
                    'name' => 'HC Contabilidade',
                    'instagram_business_account' => [
                        'id' => '17841405822304914',
                        'username' => 'healthcarecontabilidade',
                    ],
                ]],
            ]),
            'https://graph.facebook.com/v21.0/17841405822304914?*' => Http::response([
                'followers_count' => 1880,
                'media_count' => 40,
                'username' => 'healthcarecontabilidade',
            ]),
            'https://graph.facebook.com/v21.0/17841405822304914/media*' => Http::response([
                'data' => [],
            ]),
        ]);

        $this->artisan('hc:sincronizar-marketing')->assertSuccessful();

        $this->assertDatabaseHas('marketing_metricas', [
            'periodo' => now()->format('Y-m'),
            'plataforma' => 'instagram',
            'seguidores' => 1880,
        ]);
    }

    public function test_seeder_preenche_metricas_e_ideias(): void
    {
        $this->assertGreaterThanOrEqual(3, MarketingMetrica::query()->count());
        $this->assertGreaterThanOrEqual(1, MarketingIdeia::query()->count());
    }
}
