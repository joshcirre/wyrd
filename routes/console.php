<?php

declare(strict_types=1);

use App\Console\Commands\EnsureActiveQuestion;
use Illuminate\Support\Facades\Schedule;

Schedule::command(EnsureActiveQuestion::class)->everyMinute();
