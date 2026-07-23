<?php

namespace App\Console\Commands;

use App\Jobs\IdleSweepJob;
use Illuminate\Console\Command;

class IdleSweepCommand extends Command
{
    protected $signature = 'chat:idle-sweep';

    protected $description = 'Run the idle sweep once (warn/close silent customers, nudge idle agents)';

    public function handle(): int
    {
        IdleSweepJob::dispatchSync();
        $this->info('Idle sweep complete.');

        return self::SUCCESS;
    }
}
