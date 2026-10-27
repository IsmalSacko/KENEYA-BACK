<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Rappels SMS des rendez-vous (veille et peu avant).
Schedule::command('sante:rappels-rdv')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sante:rappels-maternite')->dailyAt('08:00')->withoutOverlapping();
