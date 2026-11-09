<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AgendarScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_correto_dispara_schedule_run(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        $this->get('/interno/agendar/segredo-de-teste')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('tarefa=schedule');
    }

    public function test_tarefa_honorarios_roda_comando_e_mostra_saida(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('hc:sincronizar-honorarios')
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Honorários: 1 empresa(s) atualizada(s), 0 sem cobrança no automacao.\n");

        $this->get('/interno/agendar/segredo-de-teste?tarefa=honorarios')
            ->assertOk()
            ->assertSee('hc:sincronizar-honorarios')
            ->assertSee('Honorários:');

        Mockery::close();
    }

    public function test_tarefa_ip_mostra_ip_de_saida(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        Http::fake([
            'api.ipify.org' => Http::response('203.0.113.10', 200),
        ]);

        $this->get('/interno/agendar/segredo-de-teste?tarefa=ip')
            ->assertOk()
            ->assertSee('ip_saida=203.0.113.10');
    }

    public function test_tarefa_desconhecida_responde_404(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        $this->get('/interno/agendar/segredo-de-teste?tarefa=nao-existe')->assertNotFound();
    }

    public function test_token_errado_responde_404(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        $this->get('/interno/agendar/token-errado')->assertNotFound();
    }

    public function test_sem_token_configurado_responde_404(): void
    {
        config(['hc.schedule_token' => null]);

        $this->get('/interno/agendar/qualquer')->assertNotFound();
    }
}
