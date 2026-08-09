<?php

namespace App\Integrations\Gemini;

use App\Integrations\Gemini\Exceptions\GeminiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gemini Interactions API transport (POST /v1beta/interactions).
 *
 * @see https://ai.google.dev/gemini-api/docs/interactions
 */
class GeminiClient
{
    /**
     * @param  string|list<array<string, mixed>>  $input  Plain text or multimodal content parts
     * @param  array<string, mixed>  $generationConfig  Snake_case Interaction generation_config
     */
    public function createInteraction(
        string $modelId,
        string|array $input,
        ?string $systemInstruction = null,
        array $generationConfig = [],
    ): Response {
        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            throw new GeminiException('GEMINI_API_KEY is not configured.', 500);
        }

        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $timeout = max(5, min(90, (int) config('services.gemini.timeout', 45)));
        $connectTimeout = max(2, min(15, (int) config('services.gemini.connect_timeout', 8)));
        $url = "{$base}/interactions";

        $payload = [
            'model' => $modelId,
            'input' => $input,
        ];

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['system_instruction'] = $systemInstruction;
        }

        if ($generationConfig !== []) {
            $payload['generation_config'] = $generationConfig;
        }

        try {
            return Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new GeminiException('Gemini connection failed: '.$e->getMessage(), 503, null, $e);
        }
    }

    /**
     * Pull assistant text from an Interactions API response (preferred) or
     * legacy generateContent-shaped payloads.
     */
    public function extractText(Response $response): string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return '';
        }

        if (isset($json['steps']) && is_array($json['steps'])) {
            return $this->extractFromInteractionSteps($json['steps']);
        }

        // Legacy generateContent shape (kept for broader response tolerance).
        $parts = $json['candidates'][0]['content']['parts'] ?? null;
        if (! is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $chunks[] = $part['text'];
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param  list<mixed>  $steps
     */
    private function extractFromInteractionSteps(array $steps): string
    {
        $chunks = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = (string) ($step['type'] ?? '');
            if ($type !== '' && $type !== 'model_output' && $type !== 'message') {
                continue;
            }

            $content = $step['content'] ?? null;
            if (is_string($content) && $content !== '') {
                $chunks[] = $content;
                continue;
            }
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_string($part) && $part !== '') {
                    $chunks[] = $part;
                    continue;
                }
                if (! is_array($part)) {
                    continue;
                }
                if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                    $chunks[] = $part['text'];
                }
            }
        }

        return trim(implode("\n", $chunks));
    }
}
