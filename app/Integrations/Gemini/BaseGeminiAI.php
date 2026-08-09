<?php

namespace App\Integrations\Gemini;

use App\Integrations\Gemini\Exceptions\GeminiException;
use Illuminate\Support\Facades\Log;

/**
 * Shared Gemini analyse pipeline. Domain subclasses supply prompts only.
 */
abstract class BaseGeminiAI
{
    public const PROMPT_VERSION = 'v2';

    public function __construct(
        protected readonly GeminiModelRouter $router,
    ) {}

    abstract public function domain(): string;

    abstract protected function systemInstruction(): string;

    abstract protected function buildUserPrompt(AnalysisInput $input): string;

    final public function analyse(AnalysisInput $input): DrinkAnalysis
    {
        $payload = $this->buildInteractionInput($input);
        $system = $this->systemInstruction()."\n\n".DrinkAnalysis::schemaInstruction()
            ."\n\nForced domain value for the \"domain\" field: ".$this->domain().'.';

        $result = $this->router->generateJson(
            input: $payload,
            systemInstruction: $system,
            requiresVision: $input->hasImage(),
        );

        try {
            return $this->parseResult($result->text, $result->modelId);
        } catch (GeminiException $e) {
            // One repair pass through the same router.
            Log::info('gemini.repair', ['domain' => $this->domain(), 'model' => $result->modelId]);
            $repair = $this->router->generateJson(
                input: "Your previous response was invalid JSON for the required schema. "
                    ."Fix it and return ONLY valid JSON.\n\nPrevious response:\n{$result->text}",
                systemInstruction: $system,
                requiresVision: false,
            );

            return $this->parseResult($repair->text, $repair->modelId);
        }
    }

    /**
     * Interactions API input: plain string when text-only, multimodal parts with public image URL.
     *
     * @return string|list<array<string, mixed>>
     */
    protected function buildInteractionInput(AnalysisInput $input): string|array
    {
        $text = $this->buildUserPrompt($input);

        if (! $input->hasImage() || ! is_string($input->imageUrl)) {
            return $text;
        }

        return [
            ['type' => 'text', 'text' => $text],
            [
                'type' => 'image',
                'uri' => $input->imageUrl,
                'mime_type' => $this->guessImageMime($input->imageUrl),
            ],
        ];
    }

    private function guessImageMime(string $url): string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return match (true) {
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.webp') => 'image/webp',
            str_ends_with($path, '.gif') => 'image/gif',
            str_ends_with($path, '.heic'), str_ends_with($path, '.heif') => 'image/heic',
            default => 'image/jpeg',
        };
    }

    protected function parseResult(string $text, string $modelId): DrinkAnalysis
    {
        $json = $this->decodeJsonObject($text);
        if ($json === null) {
            throw new GeminiException('Model returned non-JSON analysis payload.', 422);
        }

        return DrinkAnalysis::fromArray($json, $this->domain(), $modelId);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $text): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function formatFieldsBlock(array $fields): string
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                $lines[] = "{$key}: {$value}";
            }
        }

        return $lines === [] ? '(no manual fields provided)' : implode("\n", $lines);
    }
}
