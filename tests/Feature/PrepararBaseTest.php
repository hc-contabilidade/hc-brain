<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O comando que o contêiner roda a cada inicialização. Ele precisa ser seguro
 * de repetir: um restart do servidor não pode apagar nem desfazer nada.
 */
class PrepararBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_a_base_vazia(): void
    {
        $this->artisan('hc:preparar')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'beatriz@healthcare.com.br']);
        $this->assertDatabaseCount('empresas', 5);
    }

    public function test_nao_semeia_de_novo_quando_ja_ha_gente(): void
    {
        $this->seed();
        $empresas = Empresa::count();

        $this->artisan('hc:preparar')->assertSuccessful();

        $this->assertDatabaseCount('empresas', $empresas);
    }

    public function test_semeia_marketing_quando_a_base_ja_existe_mas_falta_o_rascunho(): void
    {
        User::create([
            'name' => 'Já Existe',
            'email' => 'ja-existe@healthcare.com.br',
            'password' => 'senha-de-teste',
            'perfil' => 'Administrador',
            'area' => 'Gestão',
            'status' => 'Ativo',
        ]);

        $this->assertDatabaseCount('marketing_metricas', 0);

        $this->artisan('hc:preparar')->assertSuccessful();

        $this->assertGreaterThan(0, MarketingMetrica::query()->count());
        $this->assertGreaterThan(0, MarketingIdeia::query()->count());
    }

    public function test_nao_resemeia_marketing_quando_ja_ha_metricas(): void
    {
        $this->seed();
        $metricas = MarketingMetrica::query()->count();
        $ideias = MarketingIdeia::query()->count();

        MarketingIdeia::query()->create([
            'titulo' => 'Ideia que não deve sumir no restart',
            'descricao' => 'Proteção contra wipe no hc:preparar.',
            'plataforma' => 'youtube',
            'status' => 'rascunho',
            'responsavel' => 'Beatriz',
            'prioridade' => 'media',
            'ordem' => 99,
        ]);

        $this->artisan('hc:preparar')->assertSuccessful();

        $this->assertDatabaseCount('marketing_metricas', $metricas);
        $this->assertDatabaseCount('marketing_ideias', $ideias + 1);
        $this->assertDatabaseHas('marketing_ideias', [
            'titulo' => 'Ideia que não deve sumir no restart',
        ]);
    }

    public function test_cria_o_acesso_inicial_definido_no_ambiente(): void
    {
        config([
            'hc.acesso_inicial.email' => 'dona@healthcare.com.br',
            'hc.acesso_inicial.senha' => 'uma-senha-boa',
            'hc.acesso_inicial.nome' => 'Dona do Ambiente',
        ]);

        $this->artisan('hc:preparar')->assertSuccessful();

        $usuario = User::where('email', 'dona@healthcare.com.br')->firstOrFail();

        $this->assertSame('Dona do Ambiente', $usuario->name);
        $this->assertSame('Administrador', $usuario->perfil);
        $this->assertSame('Ativo', $usuario->status);
        $this->assertTrue(Hash::check('uma-senha-boa', $usuario->password));
    }

    /** Trocar a variável é como se recupera a entrada sem terminal nem e-mail. */
    public function test_senha_nova_no_ambiente_substitui_a_antiga(): void
    {
        $this->seed();
        config([
            'hc.acesso_inicial.email' => 'beatriz@healthcare.com.br',
            'hc.acesso_inicial.senha' => 'a-senha-de-agora',
        ]);

        $this->artisan('hc:preparar')->assertSuccessful();

        $usuario = User::where('email', 'beatriz@healthcare.com.br')->firstOrFail();

        $this->assertSame('Beatriz', $usuario->name);
        $this->assertTrue(Hash::check('a-senha-de-agora', $usuario->password));
    }

    public function test_senha_curta_no_ambiente_falha(): void
    {
        config([
            'hc.acesso_inicial.email' => 'dona@healthcare.com.br',
            'hc.acesso_inicial.senha' => 'curta',
        ]);

        $this->artisan('hc:preparar')->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'dona@healthcare.com.br']);
    }

    public function test_sem_variaveis_nenhum_acesso_extra_e_criado(): void
    {
        config(['hc.acesso_inicial.email' => null, 'hc.acesso_inicial.senha' => null]);

        $this->artisan('hc:preparar')->assertSuccessful();

        $this->assertSame(7, User::count());
    }
}
