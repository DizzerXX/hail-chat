<?php

namespace App\Services;

use App\Models\ChatSettings;
use App\Models\Inbox;

/**
 * Resolves the pre-chat form for an inbox: the global ChatSettings values,
 * with any keys the inbox overrides in channel_config.prechat on top.
 *
 * An inbox stores only the keys it actually changes, so a global edit still
 * reaches every inbox that has not overridden that particular key. No inbox,
 * or no override, means the global form — which is what the widget gets when
 * an older embed calls /app-config without naming its inbox.
 */
class PreChatForm
{
    /** @return array<string, mixed> */
    public function for(?Inbox $inbox): array
    {
        $settings = ChatSettings::current();

        $global = collect(ChatSettings::FIELD_CONFIG)
            ->mapWithKeys(fn (string $key) => [$key => $settings->{$key}])
            ->put('widget_name_label', $settings->widget_name_label)
            ->all();

        $override = $inbox?->channel_config['prechat'] ?? [];

        return array_merge($global, is_array($override) ? $override : []);
    }

    /** @return list<array<string, mixed>> */
    public function customFieldsFor(?Inbox $inbox): array
    {
        return $this->for($inbox)['custom_fields'] ?? [];
    }
}
