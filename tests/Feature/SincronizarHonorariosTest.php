<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Os honorários vêm da tabela charges do automacao-2-hc.
 * O comando monta financeiro, receita mensal e movimentações no Brain.
 */
class SincronizarHonorariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('automacao');

        $schema = Schema::connection('automacao');

        $schema->create('companies', function (Blueprint $table) {
            $table->id();
            $table->date('contract_start_date')->nullable();
        });

        $schema->create('omie_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $schema->create('charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('omie_category_id')->nullable();
            $table->decimal('value', 12, 2);
            $table->decimal('paid_value', 12, 2)->default(0);
            $table->date('competence_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('status');
            $table->softDeletes();
        });

        $schema->create('company_monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('competence_date');
            $table->unsignedBigInteger('tax_regime_type_id')->nullable();
        });

        $schema->create('tax_regime_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function test_traz_primeiro_atual_total_e_movimentacoes_da_empresa(): void
    {
        $origem = DB::connection('automacao');
        $companyId = $origem->table('companies')->insertGetId([
            'contract_start_date' => '2025-01-15',
        ]);
        $regimeId = $origem->table('tax_regime_types')->insertGetId(['name' => 'Lucro Presumido']);
        $origem->table('company_monthly_summaries')->insert([
            'company_id' => $companyId,
            'competence_date' => '2026-10-01',
            'tax_regime_type_id' => $regimeId,
        ]);

        $catHonorario = $origem->table('omie_categories')->insertGetId([
            'name' => '1 - RDiF - Honorários Contábeis',
        ]);
        $catOutro = $origem->table('omie_categories')->insertGetId([
            'name' => '8 - RDiV - Serviços contábeis - Abertura de empresa',
        ]);

        $origem->table('charges')->insert([
            [
                'company_id' => $companyId,
                'omie_category_id' => $catHonorario,
                'value' => 350,
                'paid_value' => 350,
                'competence_date' => '2025-03-31',
                'due_date' => '2025-04-10',
                'payment_date' => '2025-04-05',
                'status' => 'RECEBIDO',
            ],
            [
                'company_id' => $companyId,
                'omie_category_id' => $catHonorario,
                'value' => 500,
                'paid_value' => 500,
                'competence_date' => '2026-10-31',
                'due_date' => '2026-11-10',
                'payment_date' => '2026-11-01',
                'status' => 'RECEBIDO',
            ],
            [
                'company_id' => $companyId,
                'omie_category_id' => $catHonorario,
                'value' => 500,
                'paid_value' => 0,
                'competence_date' => '2026-11-30',
                'due_date' => '2026-12-10',
                'payment_date' => null,
                'status' => 'AVENCER',
            ],
            [
                'company_id' => $companyId,
                'omie_category_id' => $catOutro,
                'value' => 1200,
                'paid_value' => 1200,
                'competence_date' => '2025-02-28',
                'due_date' => '2025-03-10',
                'payment_date' => '2025-03-01',
                'status' => 'RECEBIDO',
            ],
        ]);

        Empresa::query()->create([
            'id' => 'clinica-teste',
            'automacao_id' => $companyId,
            'nome' => 'Clínica Teste',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-honorarios')->assertSuccessful();

        $empresa = Empresa::query()->with(['financeiro', 'receitasMensais', 'transacoes'])->sole();

        $this->assertSame('Lucro Presumido', $empresa->financeiro->regime);
        $this->assertSame('2025-01-15', $empresa->financeiro->desde->format('Y-m-d'));
        $this->assertSame(350, (int) $empresa->financeiro->primeiro);
        $this->assertSame(500, (int) $empresa->financeiro->atual);
        $this->assertSame(2050, (int) $empresa->financeiro->total); // 350+500+1200
        $this->assertSame(500, (int) $empresa->financeiro->receber);
        $this->assertGreaterThan(0, $empresa->receitasMensais->count());
        $this->assertGreaterThan(0, $empresa->transacoes->count());
        $this->assertTrue(
            $empresa->transacoes->contains(fn ($t) => $t->descricao === 'Honorário mensal' && $t->status === 'Recebido')
        );
    }

    public function test_empresa_sem_cobranca_nao_cria_financeiro(): void
    {
        $companyId = DB::connection('automacao')->table('companies')->insertGetId([
            'contract_start_date' => '2025-01-15',
        ]);

        Empresa::query()->create([
            'id' => 'sem-cobranca',
            'automacao_id' => $companyId,
            'nome' => 'Sem Cobrança',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-honorarios')->assertSuccessful();

        $this->assertNull(Empresa::query()->sole()->financeiro);
    }

    public function test_falha_quando_o_banco_da_automacao_nao_responde(): void
    {
        Schema::connection('automacao')->drop('charges');

        Empresa::query()->create([
            'id' => 'qualquer',
            'automacao_id' => 1,
            'nome' => 'Qualquer',
            'setor' => 'Saúde',
            'status' => 'Ativo',
            'status_tag' => 'green',
        ]);

        $this->artisan('hc:sincronizar-honorarios')->assertFailed();
    }
}
