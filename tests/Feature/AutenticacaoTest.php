<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RedefinirSenha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AutenticacaoTest extends TestCase
{
    use RefreshDatabase;

    private function beatriz(): User
    {
        $this->seed();

        return User::where('email', 'beatriz@healthcare.com.br')->firstOrFail();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_destino_pretendido_e_retomado_apos_o_login(): void
    {
        $this->beatriz();

        $this->get('/reunioes')->assertRedirect('/login');

        $this->post('/login', [
            'email' => 'beatriz@healthcare.com.br',
            'password' => self::SENHA,
        ])->assertRedirect('/reunioes');

        $this->assertAuthenticated();
    }

    public function test_senha_incorreta_nao_abre_a_sessao(): void
    {
        $this->beatriz();

        $this->from('/login')
            ->post('/login', [
                'email' => 'beatriz@healthcare.com.br',
                'password' => 'senha-errada',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_registra_o_ultimo_acesso(): void
    {
        $beatriz = $this->beatriz();

        $this->post('/login', [
            'email' => $beatriz->email,
            'password' => self::SENHA,
        ]);

        $this->assertTrue($beatriz->refresh()->ultimo_acesso->isSameMinute(now()));
    }

    public function test_navegar_logado_atualiza_o_ultimo_acesso(): void
    {
        $beatriz = $this->beatriz();
        $beatriz->forceFill(['ultimo_acesso' => now()->subHours(2)])->save();

        $this->actingAs($beatriz)->get('/')->assertOk();

        $this->assertTrue($beatriz->refresh()->ultimo_acesso->isSameMinute(now()));
    }

    public function test_ultimo_acesso_nao_grava_a_cada_request_no_mesmo_minuto(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $beatriz = $this->beatriz();
        $marcado = now();
        $beatriz->forceFill(['ultimo_acesso' => $marcado])->save();

        $this->travel(20)->seconds();
        $this->actingAs($beatriz)->get('/')->assertOk();
        $this->actingAs($beatriz)->get('/reunioes')->assertOk();

        $this->assertTrue($beatriz->refresh()->ultimo_acesso->equalTo($marcado));

        $this->travel(2)->minutes();
        $this->actingAs($beatriz)->get('/')->assertOk();

        $this->assertTrue($beatriz->refresh()->ultimo_acesso->equalTo(now()));
    }

    /** Sem limite, a tela de login vira um alvo aberto para força bruta. */
    public function test_tentativas_seguidas_de_senha_sao_barradas(): void
    {
        $this->beatriz();

        foreach (range(1, 10) as $ignorado) {
            $this->post('/login', [
                'email' => 'beatriz@healthcare.com.br',
                'password' => 'senha-errada',
            ]);
        }

        $this->post('/login', [
            'email' => 'beatriz@healthcare.com.br',
            'password' => self::SENHA,
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_lembrar_acesso_guarda_o_email_no_cookie(): void
    {
        $this->beatriz();

        $this->post('/login', [
            'email' => 'beatriz@healthcare.com.br',
            'password' => self::SENHA,
            'remember' => 'on',
        ])->assertCookie('hc_brain_email', 'beatriz@healthcare.com.br');
    }

    public function test_sair_encerra_a_sessao(): void
    {
        $this->actingAs($this->beatriz())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_pedido_de_recuperacao_envia_o_link(): void
    {
        $beatriz = $this->beatriz();
        Notification::fake();

        $this->post('/esqueci-a-senha', ['email' => $beatriz->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($beatriz, RedefinirSenha::class);
    }

    /** A tela não pode virar uma lista de quem tem acesso ao HC Brain. */
    public function test_email_desconhecido_recebe_a_mesma_resposta(): void
    {
        $this->beatriz();
        Notification::fake();

        $this->post('/esqueci-a-senha', ['email' => 'ninguem@healthcare.com.br'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_token_valido_troca_a_senha(): void
    {
        $beatriz = $this->beatriz();
        $token = Password::createToken($beatriz);

        $this->post('/redefinir-senha', [
            'token' => $token,
            'email' => $beatriz->email,
            'password' => 'nova-senha-forte',
            'password_confirmation' => 'nova-senha-forte',
        ])->assertRedirect('/login');

        $this->post('/login', [
            'email' => $beatriz->email,
            'password' => 'nova-senha-forte',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_token_invalido_nao_troca_a_senha(): void
    {
        $beatriz = $this->beatriz();

        $this->from('/redefinir-senha/qualquer-coisa')
            ->post('/redefinir-senha', [
                'token' => 'token-que-nunca-existiu',
                'email' => $beatriz->email,
                'password' => 'nova-senha-forte',
                'password_confirmation' => 'nova-senha-forte',
            ])
            ->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => $beatriz->email,
            'password' => self::SENHA,
        ]);

        $this->assertAuthenticated();
    }
}
