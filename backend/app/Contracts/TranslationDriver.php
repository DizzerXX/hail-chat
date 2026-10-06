<?php

namespace App\Contracts;

/**
 * A single translation backend. Drivers speak to one kind of service and know
 * nothing about caching — CachingTranslator wraps whichever driver is bound.
 */
interface TranslationDriver
{
    /**
     * Translate text between the supported languages (ms/en).
     *
     * @throws \App\Exceptions\TranslationException
     */
    public function translate(string $text, string $source, string $target): string;
}
