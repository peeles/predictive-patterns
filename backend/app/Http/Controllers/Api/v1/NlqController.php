<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\NaturalLanguageQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NlqController extends Controller
{
    public function __construct(private readonly NaturalLanguageQueryService $nlqService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3'],
        ]);

        $question = (string) $validated['question'];
        $answer = $this->nlqService->answer($question);

        return response()->json($answer);
    }
}
