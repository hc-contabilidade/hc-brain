<?php

namespace Tests\Feature;

use App\Models\IndicadorComercial;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O comando agrega o CRM do automacao (leads/reuniões/propostas)
 * em indicadores_comerciais por mês.
 */
class SincronizarComercialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.automacao' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('automacao');

        $schema = Schema::connection('automacao');

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $schema->create('commercial_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('stage');
            $table->string('type')->nullable();
            $table->string('origin')->nullable();
            $table->string('source')->nullable();
            $table->string('channel')->nullable();
            $table->string('loss_reason')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->boolean('is_rebote')->default(false);
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('commercial_meetings', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_done')->default(false);
            $table->boolean('is_cancelled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('commercial_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_agrega_leads_vendas_e_reunioes_do_mes(): void
    {
        $origem = DB::connection('automacao');
        $userId = $origem->table('users')->insertGetId(['name' => 'Sthephany Dias']);

        $origem->table('commercial_leads')->insert([
            [
                'name' => 'Clínica Alpha',
                'stage' => 'fechado',
                'type' => 'abertura',
                'origin' => 'organico',
                'source' => 'indicacao',
                'channel' => 'whatsapp',
                'loss_reason' => null,
                'assigned_to' => $userId,
                'is_rebote' => false,
                'entered_at' => '2026-10-05 10:00:00',
                'closed_at' => '2026-10-13 00:00:00',
                'created_at' => '2026-10-05 10:00:00',
                'updated_at' => '2026-10-13 00:00:00',
                'deleted_at' => null,
            ],
            [
                'name' => 'Clínica Beta',
                'stage' => 'fechado',
                'type' => 'transferencia',
                'origin' => 'organico',
                'source' => 'indicacao',
                'channel' => 'whatsapp',
                'loss_reason' => null,
                'assigned_to' => $userId,
                'is_rebote' => false,
                'entered_at' => '2026-09-20 10:00:00',
                'closed_at' => '2026-10-09 00:00:00',
                'created_at' => '2026-09-20 10:00:00',
                'updated_at' => '2026-10-09 00:00:00',
                'deleted_at' => null,
            ],
            [
                'name' => 'Lead Novo',
                'stage' => 'novo',
                'type' => 'abertura',
                'origin' => 'pago',
                'source' => 'redes_sociais',
                'channel' => 'instagram',
                'loss_reason' => null,
                'assigned_to' => $userId,
                'is_rebote' => false,
                'entered_at' => '2026-10-18 10:00:00',
                'closed_at' => null,
                'created_at' => '2026-10-18 10:00:00',
                'updated_at' => '2026-10-18 10:00:00',
                'deleted_at' => null,
            ],
            [
                'name' => 'Lead Perdido',
                'stage' => 'perdido',
                'type' => 'abertura',
                'origin' => 'organico',
                'source' => 'indicacao',
                'channel' => 'whatsapp',
                'loss_reason' => 'SEM INTERESSE',
                'assigned_to' => $userId,
                'is_rebote' => false,
                'entered_at' => '2026-10-08 10:00:00',
                'closed_at' => '2026-10-20 00:00:00',
                'created_at' => '2026-10-08 10:00:00',
                'updated_at' => '2026-10-20 00:00:00',
                'deleted_at' => null,
            ],
        ]);

        $origem->table('commercial_meetings')->insert([
            [
                'scheduled_at' => '2026-10-12 14:00:00',
                'status' => 'agendada',
                'is_done' => false,
                'is_cancelled' => false,
                'created_at' => '2026-10-01 00:00:00',
                'updated_at' => '2026-10-01 00:00:00',
                'deleted_at' => null,
            ],
            [
                'scheduled_at' => '2026-10-15 14:00:00',
                'status' => 'realizada',
                'is_done' => true,
                'is_cancelled' => false,
                'created_at' => '2026-10-01 00:00:00',
                'updated_at' => '2026-10-15 00:00:00',
                'deleted_at' => null,
            ],
        ]);

        $origem->table('commercial_proposals')->insert([
            'status' => 'enviada',
            'created_at' => '2026-10-11 00:00:00',
            'updated_at' => '2026-10-11 00:00:00',
            'deleted_at' => null,
        ]);

        $this->artisan('hc:sincronizar-comercial')->assertSuccessful();

        $outubro = IndicadorComercial::query()->findOrFail('2026-10');

        $this->assertSame(3, $outubro->leads);
        $this->assertSame(1, $outubro->qualified);
        $this->assertSame(2, $outubro->closed);
        $this->assertSame(1, $outubro->abertura);
        $this->assertSame(1, $outubro->transferencia);
        $this->assertSame(2, $outubro->meetings);
        $this->assertSame(1, $outubro->meetings_done);
        $this->assertSame(1, $outubro->proposals);
        $this->assertSame(0, $outubro->revenue);
        $this->assertNotEmpty($outubro->origins);
        $this->assertNotEmpty($outubro->sources);
        $this->assertNotEmpty($outubro->funnel);
        $this->assertSame('SEM INTERESSE', $outubro->losses[0][0]);
        $this->assertSame('Sthephany Dias', $outubro->team[0][0]);
    }

    public function test_falha_quando_automacao_nao_responde(): void
    {
        config(['database.connections.automacao' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nao_existe',
            'username' => 'x',
            'password' => 'y',
        ]]);
        DB::purge('automacao');

        $this->artisan('hc:sincronizar-comercial')->assertFailed();
    }
}
