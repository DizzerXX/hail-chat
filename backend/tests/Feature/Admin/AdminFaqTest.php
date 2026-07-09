<?php

namespace Tests\Feature\Admin;

use App\Models\Faq;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create());
    }

    public function test_store_creates_both_language_versions_in_one_group(): void
    {
        $response = $this->postJson('/api/v1/admin/faqs', [
            'translations' => [
                'ms' => [
                    'question' => 'Bagaimana saya menetapkan semula kata laluan?',
                    'answer' => 'Klik "Lupa kata laluan".',
                    'keywords' => ['kata laluan', 'lupa'],
                ],
                'en' => [
                    'question' => 'How do I reset my password?',
                    'answer' => 'Click "Forgot password".',
                    'keywords' => ['password', 'forgot'],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.translations.ms.question', 'Bagaimana saya menetapkan semula kata laluan?')
            ->assertJsonPath('data.translations.en.question', 'How do I reset my password?');

        $group = $response->json('data.translation_group');
        $this->assertSame(2, Faq::where('translation_group', $group)->count());
    }

    public function test_store_requires_at_least_one_language(): void
    {
        $this->postJson('/api/v1/admin/faqs', ['translations' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations');
    }

    public function test_update_upserts_a_missing_language_and_can_remove_one(): void
    {
        $ms = Faq::factory()->create();
        $group = $ms->translation_group;

        // Add EN to an ms-only group.
        $this->putJson("/api/v1/admin/faqs/{$group}", [
            'translations' => [
                'en' => ['question' => 'English question?', 'answer' => 'English answer.', 'keywords' => ['en']],
            ],
        ])->assertOk()->assertJsonPath('data.translations.en.question', 'English question?');

        $this->assertSame(2, Faq::where('translation_group', $group)->count());

        // Remove BM explicitly.
        $this->putJson("/api/v1/admin/faqs/{$group}", [
            'translations' => ['ms' => null],
        ])->assertOk()->assertJsonPath('data.translations.ms', null);

        $this->assertSame(1, Faq::where('translation_group', $group)->count());

        // Removing the last language is rejected.
        $this->putJson("/api/v1/admin/faqs/{$group}", [
            'translations' => ['en' => null],
        ])->assertStatus(422);
    }

    public function test_destroy_removes_the_whole_group(): void
    {
        $ms = Faq::factory()->create();
        Faq::factory()->english()->inGroup($ms->translation_group)->create();

        $this->deleteJson("/api/v1/admin/faqs/{$ms->translation_group}")->assertNoContent();
        $this->assertDatabaseCount('faqs', 0);
    }

    public function test_index_groups_translations_and_searches_both_languages(): void
    {
        $ms = Faq::factory()->create(['question' => 'Bagaimana saya membatalkan langganan?']);
        Faq::factory()->english()->inGroup($ms->translation_group)->create([
            'question' => 'How do I cancel my subscription?',
        ]);
        Faq::factory()->create(['question' => 'Soalan lain sama sekali']);

        $this->getJson('/api/v1/admin/faqs')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response = $this->getJson('/api/v1/admin/faqs?search=subscription')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(
            'Bagaimana saya membatalkan langganan?',
            $response->json('data.0.translations.ms.question'),
        );
    }

    public function test_unknown_group_is_not_found(): void
    {
        $this->putJson('/api/v1/admin/faqs/00000000-0000-0000-0000-000000000000', [
            'translations' => ['ms' => ['question' => 'X?', 'answer' => 'Y.']],
        ])->assertNotFound();

        $this->deleteJson('/api/v1/admin/faqs/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }
}
