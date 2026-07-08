<?php

namespace App\Services\WorkItems;

use App\Contracts\WorkItemProviderInterface;
use App\Enums\ChannelType;
use App\Models\Inbox;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

class WorkItemProviderResolver
{
    public function __construct(private Container $container)
    {
    }

    public function resolve(Inbox $inbox): WorkItemProviderInterface
    {
        return match ($inbox->channel_type) {
            ChannelType::Standalone => $this->container->make(StandaloneProvider::class),
            ChannelType::MitelOpenMedia => throw new RuntimeException(
                'The Mitel Open Media provider is not available yet (Phase 3).'
            ),
        };
    }
}
