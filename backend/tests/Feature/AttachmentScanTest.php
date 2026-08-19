<?php

namespace Tests\Feature;

use App\Contracts\VirusScanner;
use App\Exceptions\VirusScanException;
use App\Models\Conversation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        config(['attachments.av.enabled' => true]);
    }

    private function scanner(callable $scan): void
    {
        $this->app->instance(VirusScanner::class, new class ($scan) implements VirusScanner {
            public function __construct(private $scan)
            {
            }

            public function scan(string $path): ?string
            {
                return ($this->scan)($path);
            }
        });
    }

    private function upload(): \Illuminate\Testing\TestResponse
    {
        $conversation = Conversation::factory()->active()->create(['language' => 'en']);

        return $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);
    }

    public function test_a_clean_file_is_accepted(): void
    {
        $this->scanner(fn () => null);

        $this->upload()->assertCreated();
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_an_infected_file_is_rejected(): void
    {
        $this->scanner(fn () => 'Eicar-Test-Signature');

        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_a_scanner_error_blocks_by_default(): void
    {
        $this->scanner(fn () => throw new VirusScanException('clamd unreachable'));

        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_a_scanner_error_can_fail_open(): void
    {
        config(['attachments.av.on_error' => 'allow']);
        $this->scanner(fn () => throw new VirusScanException('clamd unreachable'));

        $this->upload()->assertCreated();
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_scanning_is_skipped_when_disabled(): void
    {
        config(['attachments.av.enabled' => false]);
        // Would throw if the rule ever called it — proves it's not invoked.
        $this->scanner(fn () => throw new VirusScanException('should not run'));

        $this->upload()->assertCreated();
        $this->assertDatabaseCount('attachments', 1);
    }
}
