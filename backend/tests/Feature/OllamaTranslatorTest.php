<?php

namespace Tests\Feature;

use App\Contracts\TranslationDriver;
use App\Exceptions\TranslationException;
use App\Services\Translation\HttpTranslator;
use App\Services\Translation\OllamaTranslator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'translation.driver' => 'ollama',
            'translation.url' => 'http://ollama.test:11434',
            'translation.model' => 'seallm:test',
        ]);
    }

    private function reply(string $content): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    private function translator(): OllamaTranslator
    {
        return new OllamaTranslator();
    }

    public function test_the_configured_driver_is_the_one_that_gets_resolved(): void
    {
        $this->assertInstanceOf(OllamaTranslator::class, app(TranslationDriver::class));

        config(['translation.driver' => 'http']);
        $this->assertInstanceOf(HttpTranslator::class, app(TranslationDriver::class));
    }

    public function test_it_posts_a_chat_completion_and_returns_the_content(): void
    {
        $this->reply('Good morning, I need help');

        $this->assertSame(
            'Good morning, I need help',
            $this->translator()->translate('Selamat pagi, saya perlukan bantuan', 'ms', 'en'),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://ollama.test:11434/v1/chat/completions'
                && $body['model'] === 'seallm:test'
                && $body['temperature'] === 0
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][1]['content'], 'into English')
                && str_contains($body['messages'][1]['content'], 'Selamat pagi, saya perlukan bantuan');
        });
    }

    public function test_the_prompt_names_the_target_only(): void
    {
        $this->reply('Selamat pagi');
        $this->translator()->translate('Good morning', 'en', 'ms');

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][1]['content'];

            // Naming a source we only assumed is what made models echo the input
            // when a visitor answered a Malay conversation in English.
            return str_contains($prompt, 'into Malay')
                && ! str_contains($prompt, 'from English');
        });
    }

    public function test_an_echoed_input_is_retried_once_with_a_firmer_prompt(): void
    {
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => 'how are you?']]]])
            ->push(['choices' => [['message' => ['content' => 'Apa khabar?']]]]);

        $this->assertSame('Apa khabar?', $this->translator()->translate('how are you?', 'en', 'ms'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->data()['messages'][1]['content'], 'returned the text unchanged'));
    }

    public function test_an_echo_that_survives_the_retry_is_reported_as_a_failure(): void
    {
        // Better to tell the agent it failed than to show them the original text
        // labelled as a translation.
        $this->reply('how are you?');

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('returned the text unchanged');

        $this->translator()->translate('how are you?', 'en', 'ms');
    }

    public function test_single_words_may_legitimately_come_back_unchanged(): void
    {
        // Names, reference numbers and "OK" survive translation; failing those
        // would block perfectly good messages.
        $this->reply('OK');

        $this->assertSame('OK', $this->translator()->translate('OK', 'en', 'ms'));
        Http::assertSentCount(1);
    }

    public function test_it_strips_the_wrappers_models_add(): void
    {
        foreach ([
            '  Good morning  ' => 'Good morning',
            '"Good morning"' => 'Good morning',
            "```\nGood morning\n```" => 'Good morning',
            'Translation: Good morning' => 'Good morning',
            "<think>the user wants Malay</think>\nGood morning" => 'Good morning',
        ] as $raw => $expected) {
            $this->reply($raw);
            $this->assertSame($expected, $this->translator()->translate('Selamat pagi', 'ms', 'en'));
        }
    }

    public function test_multi_line_messages_keep_their_line_breaks(): void
    {
        $this->reply("First line\nSecond line");

        $this->assertSame("First line\nSecond line", $this->translator()->translate("Baris satu\nBaris dua", 'ms', 'en'));
    }

    public function test_emails_and_urls_are_hidden_from_the_model_and_restored_after(): void
    {
        // The model sees placeholders, and echoes them back in its answer.
        $this->reply('Email me at [[0]] or see [[1]]');

        $this->assertSame(
            'Email me at agent@niise.gov.my or see https://niise.gov.my/faq',
            $this->translator()->translate('E-mel saya di agent@niise.gov.my atau lihat https://niise.gov.my/faq', 'ms', 'en'),
        );

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][1]['content'];

            return str_contains($prompt, '[[0]]')
                && ! str_contains($prompt, 'agent@niise.gov.my')
                && ! str_contains($prompt, 'https://niise.gov.my/faq');
        });
    }

    public function test_an_http_error_becomes_a_translation_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('model not found');

        $this->translator()->translate('Selamat pagi', 'ms', 'en');
    }

    public function test_an_empty_reply_is_an_error_rather_than_an_empty_message(): void
    {
        $this->reply('   ');

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('empty translation');

        $this->translator()->translate('Selamat pagi', 'ms', 'en');
    }

    public function test_a_missing_model_is_reported_before_any_request(): void
    {
        config(['translation.model' => '']);
        Http::fake();

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('TRANSLATION_MODEL');

        try {
            $this->translator()->translate('Selamat pagi', 'ms', 'en');
        } finally {
            Http::assertNothingSent();
        }
    }
}
