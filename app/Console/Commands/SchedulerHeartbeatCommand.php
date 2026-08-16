<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the current timestamp to storage/app/scheduler-heartbeat.txt every
 * minute. A fresh timestamp proves the server cron is running `schedule:run`
 * (the scheduler is otherwise silent — its output goes to /dev/null). Cheap
 * uptime monitor for the scheduler itself.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Stamp a heartbeat file so we can confirm the cron is running schedule:run';

    public function handle(): int
    {
        Storage::disk('local')->put('scheduler-heartbeat.txt', now()->toIso8601String().' ('.now('Europe/Madrid')->format('Y-m-d H:i:s').' Madrid)');

        return self::SUCCESS;
    }
}
