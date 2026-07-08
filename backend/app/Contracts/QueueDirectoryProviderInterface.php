<?php

namespace App\Contracts;

/**
 * Providers that expose a directory of routing queues for admin
 * configuration (e.g. mapping inboxes/teams to MiCC Open Media queues).
 */
interface QueueDirectoryProviderInterface
{
    /**
     * @return list<array{id: string|null, name: string|null}>
     */
    public function listQueues(): array;
}
