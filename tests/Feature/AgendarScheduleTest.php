<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
