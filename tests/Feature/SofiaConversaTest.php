<?php

namespace Tests\Feature;

use App\Models\SofiaConversa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SofiaConversaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        config()->set('hc.sofia.chave', null);
        Cache::flush();
    }

    private function autenticado(?User $usuario = null): static
    {
        return $this->actingAs($usuario ?? User::firstOrFail());
    }

    public function test_lista_de_conversas_exige_sessao(): void
    {
        $this->getJson('/api/sofia/conversas')->assertUnauthorized();
    }

    public function test_lista_de_conversas_comeca_vazia(): void
    {
        $this->autenticado()
            ->getJson('/api/sofia/conversas')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_pergunta_grava_o_chat_de_quem_perguntou(): void
    {
        config()->set('hc.sofia.chave', 'chave-de-teste');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Há uma reunião hoje.']]],
            ]),
        ]);

        $id = '3f1c0a6e-2b4d-4c8a-9e11-7a2b0c4d5e6f';

        $this->autenticado()
            ->postJson('/api/sofia/perguntar', [
                'pergunta' => 'Tem reunião hoje?',
                'conversa_id' => $id,
                'modo' => 'base',
            ])
            ->assertOk()
            ->assertJsonPath('resposta', 'Há uma reunião hoje.')
            ->assertJsonPath('conversa.id', $id)
            ->assertJsonPath('conversa.mensagens.0.texto', 'Tem reunião hoje?')
            ->assertJsonPath('conversa.mensagens.1.texto', 'Há uma reunião hoje.');

        $this->autenticado()
            ->getJson('/api/sofia/conversas')
            ->assertOk()
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.titulo', 'Tem reunião hoje?');
    }

    public function test_nao_mostra_chat_de_outra_pessoa(): void
    {
        $beatriz = User::query()->where('email', 'beatriz@healthcare.com.br')->firstOrFail();
        $carlos = User::query()->where('email', 'carlos.mendes@healthcare.com.br')->firstOrFail();

        $conversa = SofiaConversa::create([
            'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'user_id' => $beatriz->id,
            'titulo' => 'Chat da Beatriz',
            'modo' => 'search',
        ]);

        $this->autenticado($carlos)
            ->getJson('/api/sofia/conversas')
            ->assertOk()
            ->assertExactJson([]);

        $this->autenticado($carlos)
            ->deleteJson("/api/sofia/conversas/{$conversa->id}")
            ->assertNotFound();

        $this->assertModelExists($conversa);
    }

    public function test_importa_chats_que_estavam_no_navegador(): void
    {
        $id = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';

        $this->autenticado()
            ->postJson('/api/sofia/conversas/importar', [
                'modo' => 'search',
                'conversas' => [[
                    'id' => $id,
                    'titulo' => 'Reuniões de hoje',
                    'atualizadoEm' => 1_700_000_000_000,
                    'mensagens' => [
                        ['autor' => 'user', 'texto' => 'O que tem hoje?'],
                        ['autor' => 'bot', 'texto' => 'Uma reunião às 9h.'],
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.mensagens.1.texto', 'Uma reunião às 9h.');
    }

    public function test_prompt_traz_quem_pergunta_e_o_modo(): void
    {
        config()->set('hc.sofia.chave', 'chave-de-teste');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ]),
        ]);

        $this->autenticado()
            ->postJson('/api/sofia/perguntar', [
                'pergunta' => 'Oi',
                'modo' => 'base',
            ])
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            $system = collect($request->data()['messages'] ?? [])->firstWhere('role', 'system');
            $conteudo = is_array($system) ? (string) ($system['content'] ?? '') : '';

            return str_contains($conteudo, 'Beatriz')
                && str_contains($conteudo, 'Modo Base HC');
        });
    }
}
