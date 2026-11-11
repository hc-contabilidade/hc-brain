<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O automacao-2-hc é a fonte das empresas. Aqui o banco dele é um SQLite em
 * memória com só as colunas que o comando lê — as mesmas que o usuário de
 * leitura tem permissão de ver em produção.
 */
class SincronizarEmpresasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'hc.completar_cnpj_planilha' => false,
        ]);
        DB::purge('automacao');

        $schema = Schema::connection('automacao');

        $schema->create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('draft')->default(false);
            $table->string('code');
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('document_number')->nullable();
            $table->string('document_type')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->string('metier')->nullable();
            $table->text('segment_type')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->softDeletes();
        });

        $schema->create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });

        $schema->create('company_partner', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('partner_id');
            $table->boolean('is_administrator')->default(false);
            $table->string('status')->nullable();
            $table->softDeletes();
        });

        $schema->create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->softDeletes();
        });

        $schema->create('partner_contact', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('contact_id');
            $table->boolean('is_primary_contact')->default(false);
            $table->softDeletes();
        });
    }

    /** @param  array<string, mixed>  $dados */
    private function company(array $dados): int
    {
        return DB::connection('automacao')->table('companies')->insertGetId([
            'code' => (string) fake()->unique()->numberBetween(100, 999),
            'name' => 'CLINICA VIDA SERVICOS MEDICOS LTDA',
            ...$dados,
        ]);
    }

    /** @param  list<array{email?: string, phone_number?: string, is_primary_contact?: bool}>  $contatos */
    private function socio(int $companyId, string $nome, bool $administrador, array $contatos = []): void
    {
        $origem = DB::connection('automacao');
        $partnerId = $origem->table('partners')->insertGetId(['name' => $nome]);
        $origem->table('company_partner')->insert([
            'company_id' => $companyId, 'partner_id' => $partnerId, 'is_administrator' => $administrador,
        ]);

        foreach ($contatos as $contato) {
            $contactId = $origem->table('contacts')->insertGetId([
                'email' => $contato['email'] ?? null, 'phone_number' => $contato['phone_number'] ?? null,
            ]);
            $origem->table('partner_contact')->insert([
                'partner_id' => $partnerId, 'contact_id' => $contactId,
                'is_primary_contact' => $contato['is_primary_contact'] ?? false,
            ]);
        }
    }

    public function test_traz_a_empresa_com_status_setor_e_o_socio_administrador(): void
    {
        $companyId = $this->company([
            'code' => '25',
            'trade_name' => 'Clínica Vida', 'status' => 'IN_OPENING',
            'document_number' => '36.359.902/0001-66', 'document_type' => 'CNPJ',
            'metier' => 'Cardiologia', 'contract_start_date' => '2025-03-10',
        ]);
        $this->socio($companyId, 'Paulo Sócio', false, [['email' => 'paulo@vida.com.br']]);
        $this->socio($companyId, 'Ana Administradora', true, [
            ['email' => 'ana.secundario@vida.com.br', 'phone_number' => '(11) 3333-0000'],
            ['email' => 'ana@vida.com.br', 'is_primary_contact' => true],
        ]);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $empresa = Empresa::with('socio')->sole();
        $this->assertSame('clinica-vida', $empresa->id);
        $this->assertSame($companyId, $empresa->automacao_id);
        $this->assertSame('25', $empresa->codigo);
        $this->assertSame('36359902000166', $empresa->documento);
        $this->assertSame('CNPJ', $empresa->documento_tipo);
        $this->assertSame('Clínica Vida', $empresa->nome);
        $this->assertSame('Cardiologia', $empresa->setor);
        $this->assertSame('Em abertura', $empresa->status);
        $this->assertSame('blue', $empresa->status_tag);

        $this->assertSame('Ana Administradora', $empresa->socio->nome);
        $this->assertSame('Sócio administrador', $empresa->socio->cargo);
        $this->assertSame('ana@vida.com.br', $empresa->socio->email);
        $this->assertSame('(11) 3333-0000', $empresa->socio->telefone);
        $this->assertSame('Cliente desde mar/2025', $empresa->socio->desde);
    }

    public function test_sem_ramo_de_atuacao_o_setor_vem_do_primeiro_segmento(): void
    {
        $this->company(['segment_type' => '["Holding","Pessoa Jurídica (PJ)"]']);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $this->assertSame('Holding', Empresa::sole()->setor);
    }

    public function test_rodar_de_novo_atualiza_sem_duplicar_nem_trocar_o_endereco(): void
    {
        $companyId = $this->company(['trade_name' => 'Clínica Vida']);
        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        DB::connection('automacao')->table('companies')->where('id', $companyId)
            ->update(['trade_name' => 'Vida Saúde', 'status' => 'INACTIVE']);
        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $empresa = Empresa::sole();
        $this->assertSame('clinica-vida', $empresa->id);
        $this->assertSame('Vida Saúde', $empresa->nome);
        $this->assertSame('Inativo', $empresa->status);
        $this->assertSame('gray', $empresa->status_tag);
    }

    public function test_rascunho_fica_de_fora_e_excluida_vem_marcada(): void
    {
        $this->company(['trade_name' => 'Ainda em cadastro', 'draft' => true]);
        $this->company(['trade_name' => 'Saiu da HC', 'deleted_at' => now()]);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $empresa = Empresa::sole();
        $this->assertSame('Saiu da HC', $empresa->nome);
        $this->assertSame('Excluída', $empresa->status);
        $this->assertSame('gray', $empresa->status_tag);
    }

    public function test_nao_mexe_nas_empresas_cadastradas_no_hc_brain_e_desempata_o_nome(): void
    {
        $this->seed(UsuarioSeeder::class);
        $manual = Empresa::create(['id' => 'clinica-vida', 'nome' => 'Clínica Vida', 'setor' => 'Saúde', 'status' => 'Ativo', 'status_tag' => 'green']);
        $manual->socio()->create(['nome' => 'Dona', 'cargo' => 'Sócia', 'email' => 'dona@vida.com.br', 'telefone' => '1', 'participacao' => '100%', 'desde' => 'sempre']);
        $this->company(['trade_name' => 'Clínica Vida', 'code' => '321']);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $this->assertSame('Clínica Vida', $manual->fresh()->nome);
        $sincronizada = Empresa::whereNotNull('automacao_id')->sole();
        $this->assertSame('clinica-vida-2', $sincronizada->id);
        $this->assertSame('Clínica Vida (321)', $sincronizada->nome);

        // Empresa sem sócio no automacao-2-hc ainda precisa abrir na lista, que lê `socio->nome`.
        $this->actingAs(User::firstOrFail())
            ->getJson('/api/empresas')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Sócio não cadastrado']);
    }

    public function test_sem_colunas_de_documento_ainda_grava_codigo_e_status(): void
    {
        Schema::connection('automacao')->table('companies', function (Blueprint $table) {
            $table->dropColumn(['document_number', 'document_type']);
        });

        $this->company([
            'code' => '25',
            'trade_name' => 'Clínica Vida',
            'status' => 'INACTIVE',
        ]);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $empresa = Empresa::sole();
        $this->assertSame('25', $empresa->codigo);
        $this->assertNull($empresa->documento);
        $this->assertSame('Inativo', $empresa->status);
        $this->assertSame('gray', $empresa->status_tag);
    }

    public function test_sem_colunas_de_documento_nao_apaga_cnpj_gravado(): void
    {
        Schema::connection('automacao')->table('companies', function (Blueprint $table) {
            $table->dropColumn(['document_number', 'document_type']);
        });

        $companyId = $this->company([
            'code' => '25',
            'trade_name' => 'Clínica Vida',
            'status' => 'ACTIVE',
        ]);
        Empresa::query()->create([
            'id' => 'clinica-vida',
            'automacao_id' => $companyId,
            'codigo' => '25',
            'nome' => 'Clínica Vida',
            'documento' => '36359902000166',
            'documento_tipo' => 'CNPJ',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-empresas')->assertSuccessful();

        $empresa = Empresa::query()->find('clinica-vida');
        $this->assertSame('36359902000166', $empresa->documento);
        $this->assertSame('CNPJ', $empresa->documento_tipo);
        $this->assertSame('Ativo', $empresa->status);
    }

    public function test_falha_sem_gravar_nada_quando_o_banco_da_automacao_nao_responde(): void
    {
        Schema::connection('automacao')->drop('company_partner');
        $this->company(['trade_name' => 'Clínica Vida']);

        $this->artisan('hc:sincronizar-empresas')->assertFailed();

        $this->assertDatabaseCount('empresas', 0);
    }
}
