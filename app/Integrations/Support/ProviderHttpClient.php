<?php

namespace App\Integrations\Support;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared upstream HTTP helper: rate gate + single 429 retry with Retry-After.
 */
class ProviderHttpClient
{
    public function __construct(
        private readonly UpstreamRateGate $rateGate,
    ) {}

    /**
     * @param  array<string, mixed>  $options  query, headers, json, timeout
     */
    public function send(
        string $provider,
        string $method,
        string $url,
        array $options = [],
    ): Response {
        $maxWait = config("services.rate_limits.{$provider}.max_wait_seconds");
        $this->rateGate->acquire(
            $provider,
            is_numeric($maxWait) ? (int) $maxWait : null,
        );

        $response = $this->dispatch($method, $url, $options);

        if ($response->status() === 429) {
            $retryAfter = max(1, (int) $response->header('Retry-After', 60));
            $this->rateGate->exhaust($provider);
            usleep($retryAfter * 1_000_000);
            $this->rateGate->acquire($provider);
            $response = $this->dispatch($method, $url, $options);
        }

        if ($response->failed() && $response->status() !== 204) {
            throw IntegrationException::fromHttp(
                $provider,
                $response->status(),
                $response->json(),
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function dispatch(string $method, string $url, array $options): Response
    {
        $timeout = (int) ($options['timeout'] ?? 20);
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        $json = $options['json'] ?? null;

        /** @var PendingRequest $client */
        $client = Http::timeout($timeout)->acceptJson()->withHeaders($headers);

        $method = strtoupper($method);

        return match ($method) {
            'GET' => $client->get($url, $query),
            'DELETE' => $client->delete($url, $query),
            'PUT' => $json === null ? $client->put($url) : $client->put($url, $json),
            'POST' => $json === null ? $client->post($url) : $client->post($url, $json),
            default => $client->send($method, $url, [
                'query' => $query,
                'json' => $json,
            ]),
        };
    }
}
