<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hc:sincronizar-empresas')->hourly()->withoutOverlapping();
// O próprio comando evita duas leituras ao mesmo tempo, inclusive com o botão
// "Sincronizar agora", e sua trava expira em minutos, não em um dia.
Schedule::command('hc:sincronizar-agendas')->everyFiveMinutes();
