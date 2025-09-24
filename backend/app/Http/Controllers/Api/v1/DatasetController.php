<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\CrimeIngestionStatus;
use App\Enums\DatasetStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\CrimeIngestionRun;
use App\Models\Dataset;
use App\Models\User;
use App\Support\InteractsWithPagination;
use App\Support\ResolvesRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DatasetController extends Controller
{
    use ResolvesRoles;
    use InteractsWithPagination;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * List recently uploaded datasets.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Dataset::class);

        $perPage = $this->resolvePerPage($request, 25);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'name' => 'name',
                'status' => 'status',
                'source_type' => 'source_type',
                'features_count' => 'features_count',
                'ingested_at' => 'ingested_at',
                'created_at' => 'created_at',
            ],
            'created_at',
            'desc'
        );

        $query = Dataset::query();

        if ($this->featuresTableExists()) {
            $query->withCount('features');
        }

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (array_key_exists('source_type', $filters) && filled($filters['source_type'])) {
                $query->where('source_type', $filters['source_type']);
            }

            if (array_key_exists('search', $filters) && filled($filters['search'])) {
                $query->where(function (Builder $builder) use ($filters): void {
                    $term = '%' . $filters['search'] . '%';
                    $builder
                        ->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            }
        }

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $datasets = $query
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($this->formatPaginatedResponse(
            $datasets,
            fn (Dataset $dataset): array => $this->transform($dataset)
        ));
    }

    /**
     * Ingest a new dataset.
     *
     * @param DatasetIngestRequest $request
     *
     * @return JsonResponse*
     * @throws AuthorizationException
     */
    public function ingest(DatasetIngestRequest $request): JsonResponse
    {
        $this->authorize('create', Dataset::class);

        $validated = $request->validated();
        $user = $request->user();
        $createdBy = $user instanceof User ? $user->getKey() : null;

        $dataset = new Dataset([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'source_type' => $validated['source_type'],
            'source_uri' => $validated['source_uri'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'status' => DatasetStatus::Processing,
            'created_by' => $createdBy,
        ]);

        if ($request->file('file') !== null) {
            $file = $request->file('file');
            $fileName = sprintf('%s.%s', Str::uuid(), $file->getClientOriginalExtension());
            $path = $file->storeAs('datasets', $fileName, 'local');
            $dataset->file_path = $path;
            $dataset->mime_type = $file->getMimeType();
            $dataset->checksum = hash_file('sha256', $file->getRealPath());
        }

        $dataset->save();
        $dataset->refresh();

        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();

        if ($this->featuresTableExists()) {
            $dataset->loadCount('features');
        }

        return response()->json($this->transform($dataset), Response::HTTP_CREATED);
    }

    /**
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function runs(Request $request): JsonResponse
    {
        $role = $this->resolveRole($request->user());

        if ($role !== Role::Admin) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to view ingestion runs.');
        }

        $perPage = $this->resolvePerPage($request, 25);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'month' => 'month',
                'status' => 'status',
                'records_expected' => 'records_expected',
                'records_inserted' => 'records_inserted',
                'records_detected' => 'records_detected',
                'records_existing' => 'records_existing',
                'started_at' => 'started_at',
                'finished_at' => 'finished_at',
                'created_at' => 'created_at',
            ],
            'started_at',
            'desc'
        );

        $query = CrimeIngestionRun::query();

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (array_key_exists('month', $filters) && filled($filters['month'])) {
                $query->where('month', $filters['month']);
            }

            if (array_key_exists('dry_run', $filters) && $filters['dry_run'] !== null && $filters['dry_run'] !== '') {
                $value = filter_var($filters['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value !== null) {
                    $query->where('dry_run', $value);
                }
            }
        }

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $runs = $query
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($this->formatPaginatedResponse(
            $runs,
            fn (CrimeIngestionRun $run): array => $this->transformRun($run)
        ));
    }

    private function transform(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'source_type' => $dataset->source_type,
            'source_uri' => $dataset->source_uri,
            'file_path' => $dataset->file_path,
            'checksum' => $dataset->checksum,
            'mime_type' => $dataset->mime_type,
            'metadata' => $dataset->metadata,
            'features_count' => $this->resolveFeaturesCount($dataset),
            'status' => $dataset->status instanceof DatasetStatus ? $dataset->status->value : (string) $dataset->status,
            'ingested_at' => optional($dataset->ingested_at)->toIso8601String(),
            'created_at' => optional($dataset->created_at)->toIso8601String(),
        ];
    }

    private function transformRun(CrimeIngestionRun $run): array
    {
        return [
            'id' => $run->id,
            'month' => $run->month,
            'dry_run' => (bool) $run->dry_run,
            'status' => $run->status instanceof CrimeIngestionStatus ? $run->status->value : (string) $run->status,
            'records_detected' => $run->records_detected,
            'records_expected' => $run->records_expected,
            'records_inserted' => $run->records_inserted,
            'records_existing' => $run->records_existing,
            'error_message' => $run->error_message,
            'archive_checksum' => $run->archive_checksum,
            'archive_url' => $run->archive_url,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'created_at' => optional($run->created_at)->toIso8601String(),
            'updated_at' => optional($run->updated_at)->toIso8601String(),
        ];
    }

    private ?bool $featuresTableExists = null;

    private function featuresTableExists(): bool
    {
        if ($this->featuresTableExists !== null) {
            return $this->featuresTableExists;
        }

        return $this->featuresTableExists = Schema::hasTable('features');
    }

    private function resolveFeaturesCount(Dataset $dataset): int
    {
        if (!$this->featuresTableExists()) {
            return 0;
        }

        $count = $dataset->getAttribute('features_count');

        if ($count !== null) {
            return (int) $count;
        }

        return (int) $dataset->features()->count();
    }
}
