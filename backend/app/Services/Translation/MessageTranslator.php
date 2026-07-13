<?php

namespace App\Services\Translation;

use App\Contracts\TranslatorInterface;
use App\Enums\SenderType;
use App\Events\MessageTranslated;
use App\Models\Message;

class MessageTranslator
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('translation.enabled')
            && in_array(config('translation.method'), ['manual', 'auto'], true);
    }

    public function method(): ?string
    {
        return $this->enabled() ? config('translation.method') : null;
    }

    /** Whether the external AI can actually be called. */
    public function ready(): bool
    {
        return $this->enabled() && config('translation.endpoint') !== '';
    }

    public function counterpart(string $language): string
    {
        return $language === 'ms' ? 'en' : 'ms';
    }

    /**
     * Default translation target per the pair rule: visitor messages are
     * translated for agents (counterpart of the conversation language);
     * agent messages for the visitor (the conversation language). Bot and
     * system messages are skipped — FAQ answers are already
     * language-matched.
     */
    public function targetFor(Message $message): ?string
    {
        $conversationLanguage = $message->conversation->language;

        return match ($message->sender_type) {
            SenderType::Visitor => $this->counterpart($conversationLanguage),
            SenderType::Agent => $conversationLanguage,
            default => null,
        };
    }

    /**
     * Translate a message into $target (default: the pair rule), cache the
     * result on the message meta, and broadcast message.translated.
     * Idempotent per language.
     */
    public function translateMessage(Message $message, ?string $target = null): Message
    {
        $message->loadMissing('conversation');

        $target ??= $this->targetFor($message);

        if ($target === null || ! in_array($target, config('tdechat.languages'), true)) {
            return $message;
        }

        $existing = $message->meta['translations'] ?? [];

        if (! isset($existing[$target])) {
            $translation = $this->translator->translate(
                $message->body,
                $this->counterpart($target),
                $target,
            );

            $message->meta = array_merge($message->meta ?? [], [
                'translations' => array_merge($existing, [$target => $translation]),
            ]);
            $message->save();

            broadcast(new MessageTranslated($message));
        }

        return $message;
    }

    public function translateText(string $text, string $target): string
    {
        return $this->translator->translate($text, $this->counterpart($target), $target);
    }
}
