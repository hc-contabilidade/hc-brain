<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\ImportEmpresasSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A planilha é montada em um storage temporário com as mesmas abas e colunas
 * da `empresas.xlsx` de verdade.
 */
class ImportEmpresasSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/hc-import-'.uniqid();
        File::ensureDirectoryExists("{$this->storage}/app");
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $empresas  [código, razão social, status]
     * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>  $socios  [código, razão social, sócio, categoria, administrador, e-mail, telefone]
     */
    private function planilha(array $empresas, array $socios = []): void
    {
        $planilha = new Spreadsheet;

        $abaEmpresas = $planilha->getActiveSheet()->setTitle('Empresas');
        $abaEmpresas->fromArray(['Código', 'CNPJ', 'Razão Social', 'Status', 'Regime Tributário']);
        foreach ($empresas as $indice => [$codigo, $razaoSocial, $status]) {
            $abaEmpresas->fromArray([$codigo, '00000000000100', $razaoSocial, $status, 'Simples Nacional'], null, 'A'.($indice + 2));
        }

        $abaSocios = $planilha->createSheet()->setTitle('Sócios');
        $abaSocios->fromArray(['Código', 'CNPJ', 'Razão Social', 'Sócio', 'CPF', 'Categoria', 'Administrador', 'E-mail', 'Telefone']);
        foreach ($socios as $indice => [$codigo, $razaoSocial, $nome, $categoria, $administrador, $email, $telefone]) {
            $abaSocios->fromArray([$codigo, '00000000000100', $razaoSocial, $nome, '00000000000', $categoria, $administrador, $email, $telefone], null, 'A'.($indice + 2));
        }

        (new Xlsx($planilha))->save("{$this->storage}/app/empresas.xlsx");
    }

    public function test_importa_a_empresa_com_a_etiqueta_do_status_e_o_socio_administrador(): void
    {
        $this->planilha(
            [[952, 'CLINICA VIDA LTDA', 'Bloqueado']],
            [
                [952, 'CLINICA VIDA LTDA', 'PAULO SOCIO', 'Sócio Não Participante', 'Não', 'paulo@vida.com.br', '-'],
                [952, 'CLINICA VIDA LTDA', 'ANA ADMINISTRADORA', 'Sócio', 'Sim', 'ana@vida.com.br', '(11) 3333-0000'],
            ],
        );

        $this->seed(ImportEmpresasSeeder::class);

        $empresa = Empresa::with('socio')->sole();
        $this->assertSame('clinica-vida-ltda', $empresa->id);
        $this->assertSame('CLINICA VIDA LTDA', $empresa->nome);
        $this->assertSame('Bloqueado', $empresa->status);
        $this->assertSame('red', $empresa->status_tag);
        $this->assertSame('ANA ADMINISTRADORA', $empresa->socio->nome);
        $this->assertSame('Sócio administrador', $empresa->socio->cargo);
        $this->assertSame('ana@vida.com.br', $empresa->socio->email);
        $this->assertSame('(11) 3333-0000', $empresa->socio->telefone);
    }

    public function test_deixa_rascunho_de_fora_e_desempata_a_mesma_razao_social_pelo_codigo(): void
    {
        $this->planilha([
            [10, 'Rascunho de Empresa', 'Ativo'],
            [775, 'GAIA SERVICOS MEDICOS LTDA', 'Ativo'],
            [775, 'GAIA SERVICOS MEDICOS LTDA', 'Ativo'],
            [776, 'GAIA SERVICOS MEDICOS LTDA', 'Inativo'],
        ]);

        $this->seed(ImportEmpresasSeeder::class);

        $this->assertSame(
            ['GAIA SERVICOS MEDICOS LTDA', 'GAIA SERVICOS MEDICOS LTDA (776)'],
            Empresa::orderBy('nome')->pluck('nome')->all(),
        );
        $this->assertSame('gray', Empresa::firstWhere('nome', 'GAIA SERVICOS MEDICOS LTDA (776)')->status_tag);
    }

    public function test_empresa_que_reaproveita_o_codigo_de_outra_nao_herda_o_socio_dela(): void
    {
        $this->seed(UsuarioSeeder::class);
        $this->planilha(
            [[100, 'AMATRUDA MARUM LTDA', 'Ativo'], [100, 'Empresa 1', 'Ativo']],
            [[100, 'AMATRUDA MARUM LTDA', 'JOAO AMATRUDA', 'Sócio', 'Sim', '-', '-']],
        );

        $this->seed(ImportEmpresasSeeder::class);

        $this->assertSame('JOAO AMATRUDA', Empresa::firstWhere('nome', 'AMATRUDA MARUM LTDA')->socio->nome);
        $this->assertSame('Sócio não cadastrado', Empresa::firstWhere('nome', 'Empresa 1')->socio->nome);

        // A lista de clientes lê `socio->nome` de toda empresa importada.
        $this->actingAs(User::firstOrFail())
            ->getJson('/api/empresas')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_rodar_de_novo_nao_duplica_nem_mexe_na_empresa_que_ja_esta_na_carteira(): void
    {
        $cadastrada = Empresa::create(['id' => 'clinica-vida', 'nome' => 'CLINICA VIDA LTDA', 'setor' => 'Saúde', 'status' => 'Ativo', 'status_tag' => 'green']);
        $this->planilha([[952, 'CLINICA VIDA LTDA', 'Inativo'], [953, 'CLINICA VIDA LTDA', 'Ativo'], [954, 'NOVA LTDA', 'Ativo']]);

        $this->seed(ImportEmpresasSeeder::class);
        $this->seed(ImportEmpresasSeeder::class);

        $this->assertSame(
            ['CLINICA VIDA LTDA', 'CLINICA VIDA LTDA (953)', 'NOVA LTDA'],
            Empresa::orderBy('nome')->pluck('nome')->all(),
        );
        $this->assertSame('Ativo', $cadastrada->fresh()->status);
        $this->assertDatabaseCount('socios', 2);
    }
}
