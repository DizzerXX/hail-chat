<?php

namespace App\Jobs;

use App\Exceptions\TranslationException;
use App\Models\Message;
use App\Services\Translation\MessageTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-mode background translation — chat delivery never waits on the AI,
 * and AI failures only cost the translation, never the message.
 */
class TranslateMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Message $message)
    {
    }

    public function handle(MessageTranslator $translator): void
    {
        if (! $translator->ready()) {
            return;
        }

        try {
            $translator->translateMessage($this->message);
        } catch (TranslationException $exception) {
            Log::warning('Auto-translation skipped.', [
                'message_id' => $this->message->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
