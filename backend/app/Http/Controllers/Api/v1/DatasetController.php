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
use App\Services\DatasetPreviewService;
use App\Support\InteractsWithPagination;
use App\Support\ResolvesRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;
use Throwable;

class DatasetController extends Controller
{
    use ResolvesRoles;
    use InteractsWithPagination;

    public function __construct(private readonly DatasetPreviewService $previewService)
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * List recently uploaded datasets.
     *
     * @throws AuthorizationException
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
     * @return JsonResponse
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

        $preview = null;
        $path = null;

        $uploadedFiles = $this->collectUploadedFiles($request);

        if ($uploadedFiles !== []) {
            if (count($uploadedFiles) === 1) {
                $file = $uploadedFiles[0];
                $fileName = sprintf('%s.%s', Str::uuid(), $file->getClientOriginalExtension());
                $path = $file->storeAs('datasets', $fileName, 'local');
                $dataset->file_path = $path;
                $dataset->mime_type = $file->getMimeType();
                $dataset->checksum = hash_file('sha256', $file->getRealPath());
            } else {
                [$path, $mimeType] = $this->storeCombinedCsv($uploadedFiles);
                $dataset->file_path = $path;
                $dataset->mime_type = $mimeType;
                $dataset->checksum = hash_file('sha256', Storage::disk('local')->path($path));
                $dataset->metadata = $this->mergeMetadata(
                    $dataset->metadata,
                    $this->buildSourceFilesMetadata($uploadedFiles)
                );
            }
        }

        if ($path !== null) {
            try {
                $preview = $this->previewService->summarise(
                    Storage::disk('local')->path($path),
                    $dataset->mime_type
                );
            } catch (Throwable $exception) {
                Log::warning('Failed to generate dataset preview', [
                    'dataset_id' => $dataset->id,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $dataset->save();
        $dataset->refresh();

        if ($preview !== null) {
            $metadata = array_filter([
                'row_count' => $preview['row_count'] ?? 0,
                'preview_rows' => $preview['preview_rows'] ?? [],
                'headers' => $preview['headers'] ?? [],
            ], static function ($value) {
                if (is_array($value)) {
                    return $value !== [];
                }

                return $value !== null;
            });

            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $metadata);
        }

        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();

        if ($this->featuresTableExists()) {
            $dataset->loadCount('features');
        }

        return response()->json($this->transform($dataset), Response::HTTP_CREATED);
    }

    /**
     * Display a single dataset record.
     *
     * @throws AuthorizationException
     */
    public function show(Request $request, Dataset $dataset): JsonResponse
    {
        $this->authorize('view', $dataset);

        if ($this->featuresTableExists()) {
            $dataset->loadCount('features');
        }

        return response()->json($this->transform($dataset));
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
        $metadataCount = (int) Arr::get($dataset->metadata ?? [], 'row_count', 0);

        if (!$this->featuresTableExists()) {
            return $metadataCount;
        }

        $count = $dataset->getAttribute('features_count');

        if ($count !== null && (int) $count > 0) {
            return (int) $count;
        }

        $relationCount = (int) $dataset->features()->count();

        if ($relationCount > 0) {
            return $relationCount;
        }

        return $metadataCount;
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array{source_files: list<string>, source_file_count: int}
     */
    private function buildSourceFilesMetadata(array $files): array
    {
        $names = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();

            if (! is_string($name) || trim($name) === '') {
                $name = $file->getFilename();
            }

            $names[] = (string) $name;
        }

        return [
            'source_files' => $names,
            'source_file_count' => count($names),
        ];
    }

    private function mergeMetadata(mixed $existing, array $additional): array
    {
        $metadata = is_array($existing) ? $existing : [];

        foreach ($additional as $key => $value) {
            if (is_array($value) && $value === []) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array{0: string, 1: string}
     */
    private function storeCombinedCsv(array $files): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'dataset-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary dataset file.');
        }

        $combinedHandle = fopen($temporaryPath, 'wb');

        if ($combinedHandle === false) {
            throw new RuntimeException(sprintf('Unable to open temporary dataset file "%s" for writing.', $temporaryPath));
        }

        try {
            foreach ($files as $index => $file) {
                $handle = fopen($file->getRealPath(), 'rb');

                if ($handle === false) {
                    continue;
                }

                try {
                    if ($index > 0) {
                        $this->ensureTrailingNewline($combinedHandle);
                        $this->discardFirstLine($handle);
                    }

                    stream_copy_to_stream($handle, $combinedHandle);
                } finally {
                    fclose($handle);
                }
            }
        } finally {
            fclose($combinedHandle);
        }

        $fileName = sprintf('%s.csv', Str::uuid());
        $storagePath = 'datasets/' . $fileName;

        $stream = fopen($temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to read combined dataset file "%s".', $temporaryPath));
        }

        Storage::disk('local')->put($storagePath, $stream);
        fclose($stream);

        @unlink($temporaryPath);

        return [$storagePath, 'text/csv'];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function collectUploadedFiles(DatasetIngestRequest $request): array
    {
        $files = Arr::wrap($request->file('files'));
        $files = array_filter($files, static fn ($file) => $file instanceof UploadedFile);

        if ($files === []) {
            $single = $request->file('file');

            if ($single instanceof UploadedFile) {
                $files = [$single];
            }
        }

        return array_values($files);
    }

    /**
     * @param resource $handle
     */
    private function discardFirstLine($handle): void
    {
        while (! feof($handle)) {
            $character = fgetc($handle);

            if ($character === false) {
                break;
            }

            if ($character === "\n") {
                break;
            }

            if ($character === "\r") {
                $next = fgetc($handle);

                if ($next !== "\n" && $next !== false) {
                    fseek($handle, -1, SEEK_CUR);
                }

                break;
            }
        }
    }

    /**
     * @param resource $handle
     */
    private function ensureTrailingNewline($handle): void
    {
        $currentPosition = ftell($handle);

        if ($currentPosition === false || $currentPosition === 0) {
            return;
        }

        if (fseek($handle, -1, SEEK_END) !== 0) {
            fseek($handle, 0, SEEK_END);

            return;
        }

        $lastCharacter = fgetc($handle);

        if ($lastCharacter !== "\n" && $lastCharacter !== "\r") {
            fwrite($handle, PHP_EOL);
        }

        fseek($handle, 0, SEEK_END);
    }
}
