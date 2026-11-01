<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rotina diária das mensalidades (cobranças do Asaas, suspensões). O servidor precisa rodar o agendador
// a cada minuto: `php artisan schedule:run` (cron, ou Agendador de Tarefas do Windows).
Schedule::command('locgestor:rotina-diaria')->dailyAt('06:00');
