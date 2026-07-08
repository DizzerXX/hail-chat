<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFaqTest extends TestCase
{
    use RefreshDatabase;

    private Inbox $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = Inbox::factory()->create();
        Faq::factory()->count(2)->create();
        Faq::factory()->english()->create(['question' => 'How do I reset my password?']);
        Faq::factory()->create(['is_active' => false]);
    }

    public function test_lists_default_language_when_none_given(): void
    {
        $this->getJson("/api/v1/public/inboxes/{$this->inbox->slug}/faqs")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.language', 'ms');
    }

    public function test_language_filter_actually_filters(): void
    {
        $response = $this->getJson("/api/v1/public/inboxes/{$this->inbox->slug}/faqs?language=en");

        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(
            ['en'],
            collect($response->json('data'))->pluck('language')->unique()->values()->all(),
        );
    }

    public function test_unknown_language_falls_back_to_default(): void
    {
        $this->getJson("/api/v1/public/inboxes/{$this->inbox->slug}/faqs?language=de")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.language', 'ms');
    }

    public function test_inactive_faqs_are_hidden(): void
    {
        $response = $this->getJson("/api/v1/public/inboxes/{$this->inbox->slug}/faqs");

        $this->assertNotContains(
            false,
            collect($response->json('data'))->pluck('id')
                ->map(fn (int $id) => Faq::find($id)->is_active)->all(),
        );
    }
}
