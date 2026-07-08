<?php

namespace App\Console\Commands;

use App\Jobs\PollOpenMediaWorkItems;
use Illuminate\Console\Command;

class MitelPollCommand extends Command
{
    protected $signature = 'mitel:poll';

    protected $description = 'Run a single MiCC Open Media work-item poll pass synchronously';

    public function handle(): int
    {
        dispatch_sync(new PollOpenMediaWorkItems());

        $this->info('Poll pass completed.');

        return self::SUCCESS;
    }
}
