<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\OmieApp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O automacao-2-hc guarda apps em omie_bases e o vínculo na company.
 * O comando replica isso no Brain sem depender da API Omie.
 */
class SincronizarOmieVinculosTest extends TestCase
{
    use RefreshDatabase;

    private string $chaveAutomacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chaveAutomacao = base64_encode(random_bytes(32));
        config([
            'database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'hc.omie.automacao_app_key' => 'base64:'.$this->chaveAutomacao,
        ]);
        DB::purge('automacao');

        $schema = Schema::connection('automacao');

        $schema->create('omie_bases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('app_key');
            $table->text('app_secret');
        });

        $schema->create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('draft')->default(false);
            $table->unsignedBigInteger('current_omie_base_id')->nullable();
            $table->string('omie_client_code')->nullable();
        });
    }

    private function cifrar(string $plain): string
    {
        return (new Encrypter(base64_decode($this->chaveAutomacao), config('app.cipher')))
            ->encryptString($plain);
    }

    public function test_traz_apps_e_liga_empresa_pelo_automacao_id(): void
    {
        $baseId = DB::connection('automacao')->table('omie_bases')->insertGetId([
            'name' => 'HC MORUMBI',
            'app_key' => '1850677815987',
            'app_secret' => $this->cifrar('segredo-morumbi'),
        ]);
        $companyId = DB::connection('automacao')->table('companies')->insertGetId([
            'current_omie_base_id' => $baseId,
            'omie_client_code' => '1677893107',
        ]);

        Empresa::query()->create([
            'id' => 'rp-fitness',
            'automacao_id' => $companyId,
            'nome' => 'RP FITNESS',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-omie-vinculos')->assertSuccessful();

        $app = OmieApp::query()->sole();
        $this->assertSame($baseId, $app->automacao_id);
        $this->assertSame('HC MORUMBI', $app->nome);
        $this->assertSame('1850677815987', $app->app_key);
        $this->assertSame('segredo-morumbi', $app->app_secret);
        $this->assertTrue($app->ativo);

        $empresa = Empresa::query()->sole();
        $this->assertSame($app->id, $empresa->omie_app_id);
        $this->assertSame(1677893107, $empresa->omie_codigo_cliente);
    }

    public function test_sem_chave_cria_app_inativo_e_ainda_liga_a_empresa(): void
    {
        config(['hc.omie.automacao_app_key' => null]);

        $baseId = DB::connection('automacao')->table('omie_bases')->insertGetId([
            'name' => 'HC CLINICAS',
            'app_key' => '3611878054785',
            'app_secret' => 'cipher-qualquer',
        ]);
        $companyId = DB::connection('automacao')->table('companies')->insertGetId([
            'current_omie_base_id' => $baseId,
            'omie_client_code' => '815719361',
        ]);

        Empresa::query()->create([
            'id' => 'abn-care',
            'automacao_id' => $companyId,
            'nome' => 'ABN CARE',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-omie-vinculos')->assertSuccessful();

        $app = OmieApp::query()->sole();
        $this->assertFalse($app->ativo);
        $this->assertSame('aguardando-AUTOMACAO_APP_KEY', $app->app_secret);

        $empresa = Empresa::query()->sole();
        $this->assertSame($app->id, $empresa->omie_app_id);
        $this->assertSame(815719361, $empresa->omie_codigo_cliente);
    }

    public function test_empresa_sem_vinculo_no_automacao_limpa_omie(): void
    {
        $app = OmieApp::query()->create([
            'automacao_id' => 1,
            'nome' => 'App',
            'app_key' => 'k1',
            'app_secret' => 's1',
            'ativo' => true,
        ]);

        Empresa::query()->create([
            'id' => 'sem-omie',
            'automacao_id' => 99,
            'omie_app_id' => $app->id,
            'omie_codigo_cliente' => 123,
            'nome' => 'Sem Omie',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        DB::connection('automacao')->table('omie_bases')->insert([
            'id' => 1,
            'name' => 'App',
            'app_key' => 'k1',
            'app_secret' => $this->cifrar('s1'),
        ]);

        $this->artisan('hc:sincronizar-omie-vinculos')->assertSuccessful();

        $empresa = Empresa::query()->sole();
        $this->assertNull($empresa->omie_app_id);
        $this->assertNull($empresa->omie_codigo_cliente);
    }

    public function test_falha_quando_o_banco_da_automacao_nao_responde(): void
    {
        Schema::connection('automacao')->drop('omie_bases');

        $this->artisan('hc:sincronizar-omie-vinculos')->assertFailed();
    }
}
