<?php

namespace App\Console\Commands;

use App\Exceptions\MitelApiException;
use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Console\Command;

class MitelCheckCommand extends Command
{
    protected $signature = 'mitel:check';

    protected $description = 'Verify MiCC Open Media connectivity: config sanity plus a live queue listing';

    public function handle(MitelOpenMediaProvider $provider): int
    {
        $token = (string) config('mitel.token');

        $this->table(['Setting', 'Value'], [
            ['enabled', config('mitel.enabled') ? 'yes' : 'no'],
            ['base_url', config('mitel.base_url') ?: '(not set)'],
            ['token', $token !== '' ? substr($token, 0, 4).'…('.strlen($token).' chars)' : '(not set)'],
            ['tenant', config('mitel.tenant') ?? '(not set)'],
            ['verify_ssl', config('mitel.verify_ssl') ? 'yes' : 'no'],
            ['polling_interval', config('mitel.polling_interval').'s'],
        ]);

        if (! config('mitel.base_url') || $token === '') {
            $this->error('MITEL_BASE_URL and MITEL_TOKEN must be set before checking connectivity.');

            return self::FAILURE;
        }

        $this->info('Fetching queue directory from MiCC…');

        try {
            $queues = $provider->listQueues();
        } catch (MitelApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($queues === []) {
            $this->warn('Connected, but MiCC returned no Open Media queues.');

            return self::SUCCESS;
        }

        $this->table(['Queue Id', 'Name'], array_map(
            fn (array $queue) => [$queue['id'] ?? '-', $queue['name'] ?? '-'],
            $queues,
        ));

        $this->info('MiCC Open Media connectivity OK.');

        return self::SUCCESS;
    }
}
