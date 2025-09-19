<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PredictRequest;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\PredictionOutput;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;

class PredictionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    public function store(PredictRequest $request, PredictionService $service): JsonResponse
    {
        $this->authorize('create', Prediction::class);

        $validated = $request->validated();
        $model = PredictiveModel::query()->findOrFail($validated['model_id']);
        $datasetId = $validated['dataset_id'] ?? null;
        $dataset = $datasetId !== null ? Dataset::query()->findOrFail($datasetId) : null;

        $prediction = $service->queuePrediction(
            $model,
            $dataset,
            $validated['parameters'] ?? [],
            $request->generateTiles(),
            $request->user(),
            $validated['metadata'] ?? [],
        )->load(['outputs']);

        return response()->json($this->transform($prediction), JsonResponse::HTTP_ACCEPTED);
    }

    public function show(string $id): JsonResponse
    {
        $prediction = Prediction::query()->with(['outputs', 'model'])->findOrFail($id);

        $this->authorize('view', $prediction);

        return response()->json($this->transform($prediction));
    }

    private function transform(Prediction $prediction): array
    {
        return [
            'id' => $prediction->id,
            'model_id' => $prediction->model_id,
            'dataset_id' => $prediction->dataset_id,
            'status' => $prediction->status instanceof PredictionStatus ? $prediction->status->value : (string) $prediction->status,
            'parameters' => $prediction->parameters,
            'metadata' => $prediction->metadata,
            'error_message' => $prediction->error_message,
            'queued_at' => optional($prediction->queued_at)->toIso8601String(),
            'started_at' => optional($prediction->started_at)->toIso8601String(),
            'finished_at' => optional($prediction->finished_at)->toIso8601String(),
            'outputs' => $prediction->outputs->map(fn (PredictionOutput $output) => [
                'id' => $output->id,
                'format' => $output->format instanceof PredictionOutputFormat ? $output->format->value : (string) $output->format,
                'payload' => $output->payload,
                'tileset_path' => $output->tileset_path,
                'created_at' => optional($output->created_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
