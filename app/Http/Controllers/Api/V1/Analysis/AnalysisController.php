<?php

namespace App\Http\Controllers\Api\V1\Analysis;

use App\Http\Controllers\Controller;
use App\Integrations\Gemini\GeminiModelRouter;
use Illuminate\Http\JsonResponse;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly GeminiModelRouter $router,
    ) {}

    public function quota(): JsonResponse
    {
        return response()->json($this->router->snapshot());
    }
}
