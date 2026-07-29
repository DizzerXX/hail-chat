<?php

namespace Tests\Feature;

use App\Models\ChatSettings;
use App\Models\Conversation;
use App\Models\Inbox;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrechatFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Inbox $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        ChatSettings::forgetCurrent();
        $this->inbox = Inbox::factory()->create(['bot_enabled' => false]);
    }

    private function configureFields(array $overrides): void
    {
        ChatSettings::current()->update($overrides);
        ChatSettings::forgetCurrent();
    }

    private function start(array $payload)
    {
        return $this->postJson("/api/v1/public/inboxes/{$this->inbox->slug}/conversations", array_merge([
            'message' => 'hello',
            'language' => 'en',
        ], $payload));
    }

    public function test_custom_answers_are_normalized_into_meta_fields(): void
    {
        $this->configureFields([
            'custom_fields' => [
                ['key' => 'order_no', 'label' => 'Order number', 'type' => 'text', 'required' => true],
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Basic', 'Pro']],
            ],
        ]);

        $uuid = $this->start([
            'fields' => ['order_no' => 'A-1001', 'plan' => 'Pro', 'unknown' => 'ignored'],
        ])->assertCreated()->json('data.uuid');

        $fields = Conversation::where('uuid', $uuid)->sole()->meta['fields'];

        // Self-describing (key + label + value), and only configured keys kept.
        $this->assertEqualsCanonicalizing(
            [
                ['key' => 'order_no', 'label' => 'Order number', 'value' => 'A-1001'],
                ['key' => 'plan', 'label' => 'Plan', 'value' => 'Pro'],
            ],
            $fields,
        );
    }

    public function test_a_required_custom_field_is_enforced(): void
    {
        $this->configureFields([
            'custom_fields' => [['key' => 'order_no', 'label' => 'Order number', 'type' => 'text', 'required' => true]],
        ]);

        $this->start(['fields' => []])
            ->assertStatus(422)->assertJsonValidationErrors('fields.order_no');
    }

    public function test_a_select_answer_must_be_one_of_the_options(): void
    {
        $this->configureFields([
            'custom_fields' => [['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'options' => ['Basic', 'Pro']]],
        ]);

        $this->start(['fields' => ['plan' => 'Enterprise']])
            ->assertStatus(422)->assertJsonValidationErrors('fields.plan');

        $this->start(['fields' => ['plan' => 'Pro']])->assertCreated();
    }

    private array $tree = [
        ['key' => 'perkhidmatan', 'label' => 'Perkhidmatan', 'children' => [
            ['key' => 'visa', 'label' => 'Visa', 'children' => [
                ['key' => 'visa_tanpa', 'label' => 'Visa tanpa rujukan'],
            ]],
        ]],
        ['key' => 'semakan', 'label' => 'Semakan Agensi'],
    ];

    public function test_a_cascade_answer_is_resolved_into_a_self_describing_path(): void
    {
        $this->configureFields([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => $this->tree]],
        ]);

        $uuid = $this->start(['fields' => ['services' => ['perkhidmatan', 'visa', 'visa_tanpa']]])
            ->assertCreated()->json('data.uuid');

        $field = Conversation::where('uuid', $uuid)->sole()->meta['fields'][0];
        $this->assertSame('services', $field['key']);
        $this->assertSame('Perkhidmatan / Visa / Visa tanpa rujukan', $field['value']);
        $this->assertSame([
            ['key' => 'perkhidmatan', 'label' => 'Perkhidmatan'],
            ['key' => 'visa', 'label' => 'Visa'],
            ['key' => 'visa_tanpa', 'label' => 'Visa tanpa rujukan'],
        ], $field['path']);
    }

    public function test_a_top_level_leaf_is_a_complete_cascade_answer(): void
    {
        $this->configureFields([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => $this->tree]],
        ]);

        $uuid = $this->start(['fields' => ['services' => ['semakan']]])->assertCreated()->json('data.uuid');
        $this->assertSame('Semakan Agensi', Conversation::where('uuid', $uuid)->sole()->meta['fields'][0]['value']);
    }

    public function test_incomplete_or_invalid_cascade_paths_are_rejected(): void
    {
        $this->configureFields([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => $this->tree]],
        ]);

        // Stops on a node that still has children (not a leaf).
        $this->start(['fields' => ['services' => ['perkhidmatan', 'visa']]])
            ->assertStatus(422)->assertJsonValidationErrors('fields.services');

        // A key that isn't in the tree.
        $this->start(['fields' => ['services' => ['nope']]])
            ->assertStatus(422)->assertJsonValidationErrors('fields.services');

        // Required, but nothing chosen.
        $this->start(['fields' => []])
            ->assertStatus(422)->assertJsonValidationErrors('fields.services');
    }

    public function test_required_email_is_enforced_only_when_enabled_and_required(): void
    {
        // Not required by default → fine without an email.
        $this->start([])->assertCreated();

        $this->configureFields(['field_email_enabled' => true, 'field_email_required' => true]);

        $this->start([])->assertStatus(422)->assertJsonValidationErrors('visitor_email');
        $this->start(['visitor_email' => 'a@b.com'])->assertCreated();
    }
}
