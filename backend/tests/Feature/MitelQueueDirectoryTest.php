<?php

namespace Tests\Feature;

use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MitelQueueDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mitel.base_url' => 'https://micc.test/miccsdk',
            'mitel.token' => 'secret-token',
        ]);
    }

    public function test_list_queues_normalizes_and_caches(): void
    {
        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia/queues' => Http::response([
                ['Id' => 'q-1', 'Name' => 'Support'],
                ['Id' => 'q-2', 'Name' => 'Billing'],
            ]),
        ]);

        $provider = app(MitelOpenMediaProvider::class);

        $first = $provider->listQueues();
        $second = $provider->listQueues();

        $this->assertSame([
            ['id' => 'q-1', 'name' => 'Support'],
            ['id' => 'q-2', 'name' => 'Billing'],
        ], $first);
        $this->assertSame($first, $second);

        Http::assertSentCount(1);
    }
}
