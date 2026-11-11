<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use App\Support\Telas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O menu lateral é montado a partir de App\Support\Telas, então percorrer esse
 * mapa garante que nenhum item do menu aponte para uma rota que não existe.
 */
class TelasTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function rotasDoMenu(): array
    {
        return collect(Telas::MENU)
            ->mapWithKeys(fn (array $tela) => [$tela['id'] => [$tela['rota']]])
            ->all();
    }

    #[DataProvider('rotasDoMenu')]
    public function test_tela_do_menu_abre_para_quem_tem_sessao(string $rota): void
    {
        $this->seed();

        $this->actingAs(User::firstOrFail())->get($rota)->assertOk();
    }

    #[DataProvider('rotasDoMenu')]
    public function test_tela_do_menu_exige_sessao(string $rota): void
    {
        $this->get($rota)->assertRedirect('/login');
    }

    public function test_detalhe_do_cliente_abre_pela_url(): void
    {
        $this->seed();

        $this->actingAs(User::firstOrFail())
            ->get('/clientes/empresaX')
            ->assertOk()
            ->assertSee('Empresa X');
    }

    public function test_detalhe_do_cliente_mostra_o_cnpj_no_cabecalho(): void
    {
        $this->seed();
        $this->withoutVite();

        $this->actingAs(User::firstOrFail())
            ->get('/clientes/empresaX')
            ->assertOk()
            ->assertSee('CNPJ');

        Empresa::query()->whereKey('empresaX')->update([
            'documento' => '11222333000181',
            'documento_tipo' => 'CNPJ',
        ]);

        $this->actingAs(User::firstOrFail())
            ->get('/clientes/empresaX')
            ->assertOk()
            ->assertSee('11.222.333/0001-81');

        $empresa = Empresa::query()->findOrFail('empresaX');
        $empresa->documento = '12345678901';
        $this->assertSame('123.456.789-01', $empresa->documentoFormatado());

        $empresa->documento = null;
        $this->assertSame('—', $empresa->documentoFormatado());
    }

    public function test_cliente_inexistente_responde_404(): void
    {
        $this->seed();

        $this->actingAs(User::firstOrFail())
            ->get('/clientes/empresa-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Empresa não encontrada');
    }

    /**
     * A rota não recebe id: o perfil é o de quem está logado. Por isso o
     * teste entra como alguém que não é o primeiro usuário da base e cobra
     * que seja esse cadastro, e só esse, a aparecer na tela.
     */
    public function test_perfil_mostra_os_dados_de_quem_esta_logado(): void
    {
        $this->seed();

        $usuario = User::where('email', 'ana.souza@healthcare.com.br')->firstOrFail();

        $this->actingAs($usuario)
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Ana Souza')
            ->assertSee('ana.souza@healthcare.com.br')
            ->assertSee('Colaborador')
            ->assertSee('Projetos')
            ->assertSee('Inativo')
            ->assertDontSee('beatriz@healthcare.com.br');
    }

    public function test_perfil_diz_quando_nunca_houve_acesso(): void
    {
        $this->seed();

        $usuario = User::firstOrFail();
        $usuario->update(['ultimo_acesso' => null]);

        $this->actingAs($usuario)
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Nunca acessou');
    }

    public function test_perfil_exige_sessao(): void
    {
        $this->get('/perfil')->assertRedirect('/login');
    }
}
