<?php

namespace Tests\Feature;

use App\Exceptions\MitelApiException;
use App\Services\WorkItems\Mitel\MitelClient;
use App\Services\WorkItems\Mitel\MitelTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MitelAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mitel.base_url' => 'https://micc.test/miccsdk',
            'mitel.client_id' => 'tdechat',
            'mitel.client_secret' => 'someservicesecret',
        ]);
    }

    public function test_token_is_requested_via_client_credentials_form_post_and_cached(): void
    {
        Http::fake([
            'micc.test/AuthorizationServer/Token' => Http::response([
                'access_token' => 'tok-1',
                'token_type' => 'bearer',
                'expires_in' => 5183999,
                'refresh_token' => 'refresh_token',
            ]),
        ]);

        $tokens = app(MitelTokenProvider::class);

        $this->assertSame('tok-1', $tokens->getToken());
        $this->assertSame('tok-1', $tokens->getToken());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://micc.test/AuthorizationServer/Token'
                && $request->isForm()
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'tdechat'
                && $request['client_secret'] === 'someservicesecret';
        });
    }

    public function test_auth_url_defaults_to_the_site_root_and_respects_override(): void
    {
        config(['mitel.base_url' => 'https://micc.test:8443/miccsdk']);
        $this->assertSame(
            'https://micc.test:8443/AuthorizationServer/Token',
            app(MitelTokenProvider::class)->authUrl(),
        );

        config(['mitel.auth_url' => 'https://sso.example.com/token']);
        $this->assertSame('https://sso.example.com/token', app(MitelTokenProvider::class)->authUrl());
    }

    public function test_api_calls_bear_the_fetched_token(): void
    {
        Http::fake([
            'micc.test/AuthorizationServer/Token' => Http::response(['access_token' => 'tok-live', 'expires_in' => 3600]),
            'micc.test/miccsdk/*' => Http::response([['Id' => 'q-1', 'Name' => 'Support']]),
        ]);

        app(MitelClient::class)->listQueues();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/openmedia/queues')
            && $request->header('Authorization')[0] === 'Bearer tok-live');
    }

    public function test_401_refreshes_the_token_and_retries_once(): void
    {
        Http::fake([
            'micc.test/AuthorizationServer/Token' => Http::sequence()
                ->push(['access_token' => 'stale', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
            'micc.test/miccsdk/api/v1/openmedia/queues' => Http::sequence()
                ->push('unauthorized', 401)
                ->push([['Id' => 'q-1', 'Name' => 'Support']]),
        ]);

        $queues = app(MitelClient::class)->listQueues();

        $this->assertSame([['Id' => 'q-1', 'Name' => 'Support']], $queues);
        // token(stale) -> queues 401 -> token(fresh) -> queues 200
        Http::assertSentCount(4);
    }

    public function test_token_endpoint_failure_throws(): void
    {
        Http::fake(['micc.test/AuthorizationServer/Token' => Http::response('nope', 500)]);

        $this->expectException(MitelApiException::class);

        app(MitelTokenProvider::class)->getToken();
    }
}
