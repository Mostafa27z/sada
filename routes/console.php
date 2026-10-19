<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sada:sync-all')->dailyAt('00:00');
Schedule::command('sada:fetch-industry-news')->dailyAt('20:00')->timezone('Asia/Riyadh');
Schedule::command('sada:sync-google-reviews --limit=20')->dailyAt('02:00')->timezone('Asia/Riyadh');


