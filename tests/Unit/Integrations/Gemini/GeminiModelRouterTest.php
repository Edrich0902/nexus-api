<?php

namespace Tests\Unit\Integrations\Gemini;

use App\Integrations\Gemini\Exceptions\GeminiQuotaException;
use App\Integrations\Gemini\GeminiModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiModelRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.cascade' => ['gemma-4-31b-it', 'gemini-3.1-flash-lite'],
            'services.gemini.budget_timezone' => 'UTC',
            'services.gemini.models' => [
                'gemma-4-31b-it' => [
                    'label' => 'Gemma 4 31B',
                    'rpm' => 30,
                    'rpd' => 14400,
                    'supports_vision' => true,
                ],
                'gemini-3.1-flash-lite' => [
                    'label' => 'Gemini 3.1 Flash Lite',
                    'rpm' => 15,
                    'rpd' => 500,
                    'supports_vision' => true,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function interactionOk(string $text, string $model): array
    {
        return [
            'id' => 'test',
            'status' => 'completed',
            'object' => 'interaction',
            'model' => $model,
            'steps' => [
                ['type' => 'thought', 'signature' => 'x'],
                [
                    'type' => 'model_output',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ],
        ];
    }

    public function test_uses_first_model_when_healthy(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => function (Request $request) {
                $model = $request->data()['model'] ?? '';
                $this->assertSame('gemma-4-31b-it', $model);
                $this->assertStringEndsWith('/interactions', $request->url());

                return Http::response($this->interactionOk('{"ok":true}', 'gemma-4-31b-it'));
            },
        ]);

        $router = app(GeminiModelRouter::class);
        $result = $router->generateJson('hi');

        $this->assertSame('gemma-4-31b-it', $result->modelId);
        $this->assertStringContainsString('ok', $result->text);
    }

    public function test_falls_back_on_429(): void
    {
        Http::fake(function (Request $request) {
            $model = $request->data()['model'] ?? '';
            if ($model === 'gemma-4-31b-it') {
                return Http::response(['error' => ['message' => 'rate', 'code' => 'too_many_requests']], 429);
            }

            return Http::response($this->interactionOk('{"fallback":true}', 'gemini-3.1-flash-lite'));
        });

        $router = app(GeminiModelRouter::class);
        $result = $router->generateJson('hi');

        $this->assertSame('gemini-3.1-flash-lite', $result->modelId);
    }

    public function test_does_not_treat_success_json_mentioning_quota_as_rate_limit(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->interactionOk('{"notes":"Watch daily API quota carefully"}', 'gemma-4-31b-it'),
            ),
        ]);

        $router = app(GeminiModelRouter::class);
        $result = $router->generateJson('hi');

        $this->assertSame('gemma-4-31b-it', $result->modelId);
        $this->assertStringContainsString('quota', $result->text);
    }

    public function test_throws_when_all_exhausted(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'rate']], 429),
        ]);

        $this->expectException(GeminiQuotaException::class);
        app(GeminiModelRouter::class)->generateJson('hi');
    }

    public function test_snapshot_lists_models(): void
    {
        $snap = app(GeminiModelRouter::class)->snapshot();
        $this->assertTrue($snap['any_available']);
        $this->assertCount(2, $snap['models']);
        $this->assertSame('gemma-4-31b-it', $snap['models'][0]['id']);
    }
}
