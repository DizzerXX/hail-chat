<?php

namespace App\Services\WorkItems\Mitel;

use App\Exceptions\MitelApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OAuth2 client-credentials tokens from the MiCC Authorization Server.
 * Tokens are cached until shortly before expiry; a 401 from the SDK API
 * invalidates the cache so the next call re-authenticates. The returned
 * refresh_token is ignored on purpose — with client credentials,
 * re-requesting a token is always possible and simpler.
 */
class MitelTokenProvider
{
    private const CACHE_KEY = 'mitel.access_token';

    /** Seconds shaved off expires_in so a nearly-dead token is never sent. */
    private const EXPIRY_MARGIN = 300;

    public function getToken(): string
    {
        $token = Cache::get(self::CACHE_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        [$token, $ttl] = $this->requestToken();

        Cache::put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function authUrl(): string
    {
        $configured = config('mitel.auth_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // The Authorization Server lives at the MiCC site root, while
        // base_url points at the /miccsdk sub-path.
        $parts = parse_url((string) config('mitel.base_url'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}/AuthorizationServer/Token";
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function requestToken(): array
    {
        $http = Http::asForm()
            ->acceptJson()
            ->timeout(config('mitel.timeout', 10));

        if (! config('mitel.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post($this->authUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => config('mitel.client_id'),
                'client_secret' => config('mitel.client_secret'),
            ]);
        } catch (Throwable $exception) {
            throw new MitelApiException(
                'Mitel API: token request failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new MitelApiException(
                'Mitel API: token request failed with HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        return [$accessToken, max(60, $expiresIn - self::EXPIRY_MARGIN)];
    }
}
