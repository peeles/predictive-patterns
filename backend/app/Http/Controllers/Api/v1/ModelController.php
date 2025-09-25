<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateModelRequest;
use App\Http\Requests\EvaluateModelRequest;
use App\Http\Requests\TrainModelRequest;
use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\ModelStatusService;
use App\Services\ModelRegistry;
use App\Support\InteractsWithPagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ModelController extends Controller
{
    use InteractsWithPagination;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = PredictiveModel::query()->with(['trainingRuns' => function ($query): void {
            $query->latest('created_at')->limit(3);
        }]);

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('tag', $filters) && filled($filters['tag'])) {
                $query->where('tag', $filters['tag']);
            }

            if (array_key_exists('area', $filters) && filled($filters['area'])) {
                $query->where('area', $filters['area']);
            }

            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        }

        $perPage = $this->resolvePerPage($request, 15);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'name' => 'name',
                'status' => 'status',
                'trained_at' => 'trained_at',
                'updated_at' => 'updated_at',
                'created_at' => 'created_at',
            ],
            'updated_at',
            'desc'
        );

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'updated_at') {
            $query->orderByDesc('updated_at');
        }

        $models = $query
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($this->formatPaginatedResponse(
            $models,
            fn (PredictiveModel $model) => $this->transform($model)
        ));
    }

    /**
     * @throws AuthorizationException
     */
    public function store(CreateModelRequest $request): JsonResponse
    {
        $this->authorize('create', PredictiveModel::class);

        $validated = $request->validated();

        $model = new PredictiveModel();
        $model->id = (string) Str::uuid();
        $model->name = $validated['name'];
        $model->dataset_id = $validated['dataset_id'] ?? null;
        $model->version = $validated['version'] ?? '1.0.0';
        $model->tag = $validated['tag'] ?? null;
        $model->area = $validated['area'] ?? null;
        $model->hyperparameters = $validated['hyperparameters'] ?? null;
        $model->metadata = $validated['metadata'] ?? null;

        $user = $request->user();

        if ($user instanceof User) {
            $model->created_by = $user->getKey();
        }

        $model->save();

        $model = $model->fresh(['trainingRuns']);

        return response()->json([
            'data' => $this->transform($model),
        ], JsonResponse::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $model = PredictiveModel::query()
            ->with(['trainingRuns' => fn ($query) => $query->orderByDesc('created_at')->limit(5)])
            ->findOrFail($id);

        $this->authorize('view', $model);

        return response()->json($this->transform($model));
    }

    public function train(
        TrainModelRequest $request,
        ModelStatusService $statusService,
        IdempotencyService $idempotencyService,
    ): JsonResponse
    {
        $validated = $request->validated();
        $model = PredictiveModel::query()->findOrFail($validated['model_id']);

        $this->authorize('train', $model);

        $cachedResponse = $idempotencyService->getCachedResponse($request, 'models.train', $model->id);

        if ($cachedResponse !== null) {
            return response()->json($cachedResponse, JsonResponse::HTTP_ACCEPTED);
        }

        $user = $request->user();
        $initiatedBy = $user instanceof User ? $user->getKey() : null;
        $hyperparameters = $validated['hyperparameters'] ?? [];

        $run = new TrainingRun([
            'id' => (string) Str::uuid(),
            'status' => TrainingStatus::Queued,
            'hyperparameters' => $hyperparameters,
            'queued_at' => now(),
            'initiated_by' => $initiatedBy,
        ]);

        $run->model()->associate($model);
        $run->save();

        $statusService->markQueued($model->id, 'training');

        $dispatch = TrainModelJob::dispatch($run->id, $hyperparameters ?: null);

        $responsePayload = [
            'message' => 'Training job queued',
            'training_run_id' => $run->id,
            'job_id' => $dispatch?->id ?? $run->id,
        ];

        $idempotencyService->storeResponse($request, 'models.train', $responsePayload, $model->id);

        return response()->json($responsePayload, JsonResponse::HTTP_ACCEPTED);
    }

    public function evaluate(
        string $id,
        EvaluateModelRequest $request,
        ModelStatusService $statusService,
        IdempotencyService $idempotencyService,
    ): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('evaluate', $model);

        $validated = $request->validated();
        $metrics = $validated['metrics'] ?? [];

        $cachedResponse = $idempotencyService->getCachedResponse($request, 'models.evaluate', $model->id);

        if ($cachedResponse !== null) {
            return response()->json($cachedResponse, JsonResponse::HTTP_ACCEPTED);
        }

        $statusService->markQueued($model->id, 'evaluating');

        $dispatch = EvaluateModelJob::dispatch(
            $model->id,
            $validated['dataset_id'] ?? null,
            $metrics === [] ? null : $metrics,
            $validated['notes'] ?? null,
        );

        $responsePayload = [
            'message' => 'Evaluation queued',
            'model_id' => $model->id,
            'job_id' => $dispatch?->id ?? (string) Str::uuid(),
        ];

        $idempotencyService->storeResponse($request, 'models.evaluate', $responsePayload, $model->id);

        return response()->json($responsePayload, JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * @throws AuthorizationException
     */
    public function activate(string $id, ModelRegistry $registry): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('activate', $model);

        $registry->activate($model);

        $model = $this->freshModelForResponse($model);

        return response()->json([
            'data' => $this->transform($model),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function deactivate(string $id, ModelRegistry $registry): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('deactivate', $model);

        $registry->deactivate($model);

        $model = $this->freshModelForResponse($model);

        return response()->json([
            'data' => $this->transform($model),
        ]);
    }

    public function status(string $id, ModelStatusService $statusService): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('view', $model);

        $status = $statusService->getStatus($model);

        return response()->json([
            'state' => $status['state'],
            'progress' => $status['progress'],
            'updated_at' => $status['updated_at'],
        ]);
    }

    private function freshModelForResponse(PredictiveModel $model): PredictiveModel
    {
        return $model->fresh([
            'trainingRuns' => fn ($query) => $query->latest('created_at')->limit(3),
        ]) ?? $model;
    }

    private function transform(PredictiveModel $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'dataset_id' => $model->dataset_id,
            'status' => $model->status instanceof ModelStatus ? $model->status->value : (string) $model->status,
            'version' => $model->version,
            'tag' => $model->tag,
            'area' => $model->area,
            'hyperparameters' => $model->hyperparameters,
            'metadata' => $model->metadata,
            'metrics' => $model->metrics,
            'trained_at' => optional($model->trained_at)->toIso8601String(),
            'training_runs' => $model->trainingRuns->map(fn (TrainingRun $run) => [
                'id' => $run->id,
                'status' => $run->status instanceof TrainingStatus ? $run->status->value : (string) $run->status,
                'queued_at' => optional($run->queued_at)->toIso8601String(),
                'started_at' => optional($run->started_at)->toIso8601String(),
                'finished_at' => optional($run->finished_at)->toIso8601String(),
                'metrics' => $run->metrics,
            ])->all(),
        ];
    }
}
