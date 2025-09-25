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
use App\Models\ShapValue;
use App\Services\PredictionService;
use App\Support\InteractsWithPagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class PredictionController extends Controller
{
    use InteractsWithPagination;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * List prediction jobs with pagination.
     *
     * @throws AuthorizationException
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Prediction::class);

        $perPage = $this->resolvePerPage($request, 10);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'status' => 'status',
                'queued_at' => ['column' => 'queued_at', 'direction' => 'desc'],
                'started_at' => ['column' => 'started_at', 'direction' => 'desc'],
                'finished_at' => ['column' => 'finished_at', 'direction' => 'desc'],
                'created_at' => ['column' => 'created_at', 'direction' => 'desc'],
            ],
            'created_at',
            'desc'
        );

        $query = Prediction::query()
            ->with(['model'])
            ->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            $this->applyStatusFilter($query, Arr::get($filters, 'status'));
            $this->applyModelFilter($query, Arr::get($filters, 'model_id'));
            $this->applyTimeframeFilter($query, Arr::get($filters, 'from'), Arr::get($filters, 'to'));
        }

        $predictions = $query
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json(
            $this->formatPaginatedResponse(
                $predictions,
                fn (Prediction $prediction): array => $this->transformSummary($prediction)
            )
        );
    }

    /**
     * Create a new prediction job
     *
     * @param PredictRequest $request
     * @param PredictionService $service
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
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
        )->load(['outputs', 'model', 'shapValues']);

        return response()->json($this->transform($prediction), JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Get the status and results of a prediction job
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(string $id): JsonResponse
    {
        $prediction = Prediction::query()->with(['outputs', 'model', 'shapValues'])->findOrFail($id);

        $this->authorize('view', $prediction);

        return response()->json($this->transform($prediction));
    }

    /**
     * Apply status filtering to the query when provided.
     */
    private function applyStatusFilter(Builder $query, mixed $status): void
    {
        if ($status === null || $status === '' || (is_array($status) && $status === [])) {
            return;
        }

        $statuses = array_unique(array_filter((array) $status, function (mixed $value): bool {
            if (!is_string($value) || $value === '') {
                return false;
            }

            return in_array($value, array_map(
                static fn (PredictionStatus $case): string => $case->value,
                PredictionStatus::cases()
            ), true);
        }));

        if ($statuses === []) {
            return;
        }

        $query->whereIn('status', $statuses);
    }

    /**
     * Apply model filter when provided.
     */
    private function applyModelFilter(Builder $query, mixed $modelId): void
    {
        if (!is_string($modelId) || $modelId === '') {
            return;
        }

        $query->where('model_id', $modelId);
    }

    /**
     * Apply timeframe filtering based on creation timestamps.
     */
    private function applyTimeframeFilter(Builder $query, mixed $from, mixed $to): void
    {
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);

        if ($fromDate !== null) {
            $query->where('created_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->where('created_at', '<=', $toDate);
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function transformSummary(Prediction $prediction): array
    {
        return [
            'id' => $prediction->id,
            'model_id' => $prediction->model_id,
            'dataset_id' => $prediction->dataset_id,
            'status' => $prediction->status instanceof PredictionStatus
                ? $prediction->status->value
                : (string) $prediction->status,
            'parameters' => $prediction->parameters,
            'metadata' => $prediction->metadata,
            'error_message' => $prediction->error_message,
            'queued_at' => optional($prediction->queued_at)->toIso8601String(),
            'started_at' => optional($prediction->started_at)->toIso8601String(),
            'finished_at' => optional($prediction->finished_at)->toIso8601String(),
            'created_at' => optional($prediction->created_at)->toIso8601String(),
            'model' => $prediction->model ? [
                'id' => $prediction->model->id,
                'name' => $prediction->model->name,
                'version' => $prediction->model->version,
            ] : null,
        ];
    }

    /**
     * Transform a Prediction model to an array suitable for JSON response
     *
     * @param Prediction $prediction
     *
     * @return array
     */
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
            'created_at' => optional($prediction->created_at)->toIso8601String(),
            'model' => $prediction->model ? [
                'id' => $prediction->model->id,
                'name' => $prediction->model->name,
                'version' => $prediction->model->version,
            ] : null,
            'outputs' => $prediction->outputs->map(fn (PredictionOutput $output) => [
                'id' => $output->id,
                'format' => $output->format instanceof PredictionOutputFormat ? $output->format->value : (string) $output->format,
                'payload' => $output->payload,
                'tileset_path' => $output->tileset_path,
                'created_at' => optional($output->created_at)->toIso8601String(),
            ])->all(),
            'shap_values' => $prediction->shapValues->map(fn (ShapValue $value) => [
                'id' => $value->id,
                'feature_name' => $value->feature_name,
                'name' => $value->feature_name,
                'contribution' => (float) $value->value,
                'details' => $value->details,
                'created_at' => optional($value->created_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
