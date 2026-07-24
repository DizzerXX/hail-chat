<?php

namespace Tests\Feature;

use App\Contracts\TranslatorInterface;
use App\Exceptions\TranslationException;
use App\Services\Translation\CachingTranslator;
use App\Services\Translation\HttpTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['translation.url' => 'https://ai.test']);
    }

    public function test_the_interface_resolves_to_the_caching_translator(): void
    {
        $this->assertInstanceOf(CachingTranslator::class, app(TranslatorInterface::class));
    }

    public function test_identical_text_is_translated_once_then_served_from_cache(): void
    {
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'I need help'])]);
        $translator = app(TranslatorInterface::class);

        // Same text, twice (as if from two different conversations).
        $this->assertSame('I need help', $translator->translate('Saya perlukan bantuan', 'ms', 'en'));
        $this->assertSame('I need help', $translator->translate('Saya perlukan bantuan', 'ms', 'en'));

        // The AI was only called on the miss; the durable row was written.
        Http::assertSentCount(1);
        $this->assertDatabaseHas('translation_cache', [
            'source' => 'ms',
            'target' => 'en',
            'text' => 'Saya perlukan bantuan',
            'translation' => 'I need help',
        ]);
    }

    public function test_the_durable_tier_serves_after_the_fast_tier_is_cleared(): void
    {
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'Selamat pagi'])]);
        $translator = app(TranslatorInterface::class);

        $translator->translate('Good morning', 'en', 'ms');

        // Drop the fast tier — the DB tier must still spare the AI a second call.
        Cache::flush();
        $this->assertSame('Selamat pagi', $translator->translate('Good morning', 'en', 'ms'));

        Http::assertSentCount(1);
    }

    public function test_same_language_is_a_no_op_and_never_calls_the_service(): void
    {
        Http::fake();
        $translator = app(TranslatorInterface::class);

        $this->assertSame('Unchanged', $translator->translate('Unchanged', 'en', 'en'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('translation_cache', 0);
    }

    public function test_a_200_with_success_false_is_treated_as_a_failure(): void
    {
        // The service returns HTTP 200 even when translation fails.
        Http::fake(['ai.test/*' => Http::response([
            'success' => false,
            'error' => 'Translation request timed out.',
        ])]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('timed out');

        app(HttpTranslator::class)->translate('Hello', 'en', 'ms');
    }

    public function test_a_400_validation_error_throws(): void
    {
        Http::fake(['ai.test/*' => Http::response(['error' => 'Source language is required'], 400)]);

        $this->expectException(TranslationException::class);

        app(HttpTranslator::class)->translate('Hello', 'en', 'ms');
    }

    public function test_a_failure_is_not_cached(): void
    {
        Http::fake(['ai.test/*' => Http::response(['success' => false, 'error' => 'boom'])]);
        $translator = app(TranslatorInterface::class);

        try {
            $translator->translate('Hello', 'en', 'ms');
        } catch (TranslationException) {
            // expected
        }

        $this->assertDatabaseCount('translation_cache', 0);
        $this->assertNull(Cache::get('translation:'.hash('sha256', 'en:ms:Hello')));
    }
}
