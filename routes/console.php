<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A cada 15 min: combina com o cron-job.org do plano free do Render
// (o Web dorme; o hit externo acorda e o schedule:run dispara estas tarefas).
Schedule::command('hc:sincronizar-empresas')->everyFifteenMinutes()->withoutOverlapping(30);
// O próprio comando evita duas leituras ao mesmo tempo, inclusive com o botão
// "Sincronizar agora", e sua trava expira em minutos, não em um dia.
Schedule::command('hc:sincronizar-omie-vinculos')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('hc:sincronizar-honorarios')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('hc:sincronizar-agendas')->everyFiveMinutes()->withoutOverlapping();
// Omie via API é pesado: fica de hora em hora (só no minuto 0).
Schedule::command('hc:sincronizar-omie')->hourly()->withoutOverlapping(120);
