<?php

namespace App\Contracts;

interface TranslatorInterface
{
    /**
     * Translate text between the supported languages (ms/en).
     *
     * @throws \App\Exceptions\TranslationException
     */
    public function translate(string $text, string $source, string $target): string;
}
