<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgendarScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_correto_dispara_schedule_run(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        $this->get('/interno/agendar/segredo-de-teste')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_tarefa_honorarios_responde_ok_e_agenda_comando(): void
    {
        config(['hc.schedule_token' => 'segredo-de-teste']);

        Artisan::partialMock()
            ->shouldReceive('call')
            ->once()
            ->with('hc:sincronizar-honorarios')
            ->andReturn(0);

        $this->get('/interno/agendar/segredo-de-teste?tarefa=honorarios')
            ->assertOk()
            ->assertSee('honorarios');
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
