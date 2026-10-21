<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportarColaboradoresTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arquivo = storage_path('app/testing-colaboradores.xlsx');
        config(['hc.senha_semeada' => 'senha-importada']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->arquivo)) {
            unlink($this->arquivo);
        }

        parent::tearDown();
    }

    public function test_importa_colaboradores_da_planilha_como_usuarios(): void
    {
        $this->gravarPlanilha([
            ['Nome', 'Email', 'Função', 'Departamento'],
            ['Agustina Delvalle', 'agustina@example.net', 'Administrador', '—'],
            ['Atendimento 0', 'atendimento0@healthcarecontabilidade.com.br', 'Funcionário', 'Atendimento'],
        ]);

        $this->artisan('hc:importar-colaboradores', ['--arquivo' => $this->arquivo])
            ->assertSuccessful();

        $admin = User::where('email', 'agustina@example.net')->sole();
        $this->assertSame('Agustina Delvalle', $admin->name);
        $this->assertSame('Administrador', $admin->perfil);
        $this->assertSame('Operações', $admin->area);
        $this->assertTrue(password_verify('senha-importada', $admin->password));

        $colaborador = User::where('email', 'atendimento0@healthcarecontabilidade.com.br')->sole();
        $this->assertSame('Colaborador', $colaborador->perfil);
        $this->assertSame('Atendimento', $colaborador->area);
    }

    public function test_rodar_de_novo_atualiza_cadastro_sem_trocar_a_senha(): void
    {
        User::create([
            'name' => 'Nome antigo',
            'email' => 'atendimento0@healthcarecontabilidade.com.br',
            'password' => 'senha-que-fica',
            'perfil' => 'Gestor',
            'area' => 'Comercial',
            'status' => 'Inativo',
        ]);

        $this->gravarPlanilha([
            ['Nome', 'Email', 'Função', 'Departamento'],
            ['Atendimento 0', 'atendimento0@healthcarecontabilidade.com.br', 'Funcionário', 'Atendimento'],
        ]);

        $this->artisan('hc:importar-colaboradores', ['--arquivo' => $this->arquivo])
            ->assertSuccessful();

        $usuario = User::where('email', 'atendimento0@healthcarecontabilidade.com.br')->sole();
        $this->assertSame('Atendimento 0', $usuario->name);
        $this->assertSame('Colaborador', $usuario->perfil);
        $this->assertSame('Atendimento', $usuario->area);
        $this->assertSame('Ativo', $usuario->status);
        $this->assertTrue(password_verify('senha-que-fica', $usuario->password));
    }

    public function test_falha_quando_o_arquivo_nao_existe(): void
    {
        $this->artisan('hc:importar-colaboradores', ['--arquivo' => storage_path('app/nao-existe.xlsx')])
            ->assertFailed();
    }

    /** @param  list<list<string>>  $linhas */
    private function gravarPlanilha(array $linhas): void
    {
        $planilha = new Spreadsheet;
        $planilha->getActiveSheet()->fromArray($linhas);
        (new Xlsx($planilha))->save($this->arquivo);
    }
}
