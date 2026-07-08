<?php

namespace App\Console\Commands;

use App\Exceptions\MitelApiException;
use App\Services\WorkItems\Mitel\MitelTokenProvider;
use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Console\Command;

class MitelCheckCommand extends Command
{
    protected $signature = 'mitel:check';

    protected $description = 'Verify MiCC Open Media connectivity: config sanity, token acquisition, and a live queue listing';

    public function handle(MitelTokenProvider $tokens, MitelOpenMediaProvider $provider): int
    {
        $secret = (string) config('mitel.client_secret');

        $this->table(['Setting', 'Value'], [
            ['enabled', config('mitel.enabled') ? 'yes' : 'no'],
            ['base_url', config('mitel.base_url') ?: '(not set)'],
            ['auth_url', config('mitel.base_url') ? $tokens->authUrl() : '(derived from base_url)'],
            ['client_id', config('mitel.client_id') ?: '(not set)'],
            ['client_secret', $secret !== '' ? substr($secret, 0, 4).'…('.strlen($secret).' chars)' : '(not set)'],
            ['tenant', config('mitel.tenant') ?? '(not set)'],
            ['verify_ssl', config('mitel.verify_ssl') ? 'yes' : 'no'],
            ['polling_interval', config('mitel.polling_interval').'s'],
        ]);

        if (! config('mitel.base_url') || ! config('mitel.client_id') || $secret === '') {
            $this->error('MITEL_BASE_URL, MITEL_CLIENT_ID and MITEL_CLIENT_SECRET must be set before checking connectivity.');

            return self::FAILURE;
        }

        $this->info('Requesting access token (client credentials)…');

        try {
            $tokens->forget();
            $tokens->getToken();
        } catch (MitelApiException $exception) {
            $this->error($exception->getMessage());
            $this->line('Hint: the client_id must be registered as a trusted server in the Authorization Server clients.config.');

            return self::FAILURE;
        }

        $this->info('Token acquired. Fetching queue directory from MiCC…');

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
