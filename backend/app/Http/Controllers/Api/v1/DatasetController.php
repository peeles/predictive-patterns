<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\CrimeIngestionStatus;
use App\Enums\DatasetStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\CrimeIngestionRun;
use App\Models\Dataset;
use App\Models\Feature;
use App\Models\User;
use App\Services\DatasetPreviewService;
use App\Services\H3AggregationService;
use App\Support\InteractsWithPagination;
use App\Support\ResolvesRoles;
use Carbon\CarbonImmutable;
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

    public function __construct(
        private readonly DatasetPreviewService $previewService,
        private readonly H3AggregationService $aggregationService,
    )
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
        $schemaMapping = $this->normaliseSchemaMapping($request->input('schema'));

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

        if ($schemaMapping !== []) {
            $dataset->schema_mapping = $schemaMapping;
        }

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

            if ($schemaMapping !== []) {
                $metadata['schema_mapping'] = $schemaMapping;
                $metadata['derived_features'] = $this->buildDerivedFeaturesSummary(
                    $schemaMapping,
                    $metadata['headers'] ?? [],
                    $metadata['preview_rows'] ?? []
                );
            }

            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $metadata);
        } elseif ($schemaMapping !== []) {
            $dataset->metadata = $this->mergeMetadata($dataset->metadata, [
                'schema_mapping' => $schemaMapping,
                'derived_features' => $this->buildDerivedFeaturesSummary($schemaMapping, [], []),
            ]);
        }

        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();

        if ($schemaMapping !== [] && $dataset->file_path !== null) {
            $this->populateFeaturesFromMapping($dataset, $schemaMapping);
        }

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
            'schema' => $dataset->schema_mapping ?? [],
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

    private function normaliseSchemaMapping(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $allowed = ['timestamp', 'latitude', 'longitude', 'category', 'risk'];
        $normalised = [];

        foreach ($allowed as $key) {
            $value = $schema[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $required) {
            if (! array_key_exists($required, $normalised)) {
                return [];
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, string> $schema
     * @param list<string> $headers
     * @param array<int, array<string, mixed>> $previewRows
     * @return array<string, array<string, mixed>>
     */
    private function buildDerivedFeaturesSummary(array $schema, array $headers, array $previewRows): array
    {
        if ($schema === []) {
            return [];
        }

        $summary = [];

        foreach ($schema as $key => $column) {
            if (! is_string($key) || ! is_string($column) || trim($column) === '') {
                continue;
            }

            $sample = null;

            foreach ($previewRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! array_key_exists($column, $row)) {
                    continue;
                }

                $value = $row[$column];

                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                $sample = $value;
                break;
            }

            $summary[$key] = ['column' => $column];

            if ($sample !== null) {
                $summary[$key]['sample'] = $sample;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, string> $schema
     */
    private function populateFeaturesFromMapping(Dataset $dataset, array $schema): void
    {
        if (! $this->featuresTableExists()) {
            return;
        }

        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $requiredField) {
            if (! array_key_exists($requiredField, $schema)) {
                return;
            }
        }

        $path = Storage::disk('local')->path($dataset->file_path);

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            return;
        }

        $deleted = Feature::query()->where('dataset_id', $dataset->getKey())->delete();

        $index = 0;
        $changesMade = $deleted > 0;

        try {
            foreach ($this->readDatasetRows($path, $dataset->mime_type) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $featureData = $this->buildFeatureFromRow($dataset, $schema, $row, $index);

                if ($featureData === null) {
                    continue;
                }

                Feature::create($featureData);
                $changesMade = true;
                $index++;
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to derive dataset features', [
                'dataset_id' => $dataset->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        if ($changesMade) {
            $this->aggregationService->bumpCacheVersion();
        }
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function readDatasetRows(string $path, ?string $mimeType): iterable
    {
        $mimeType = $mimeType !== null ? strtolower($mimeType) : null;

        if ($mimeType !== null && str_contains($mimeType, 'json')) {
            return [];
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['json', 'geojson'], true)) {
            return [];
        }

        return $this->readCsvRows($path);
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function readCsvRows(string $path): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $headers = null;

            while (($row = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = $this->normaliseCsvHeaders($row);

                    if ($headers === []) {
                        break;
                    }

                    continue;
                }

                if ($this->isEmptyCsvRow($row)) {
                    continue;
                }

                $assoc = $this->combineCsvRow($headers, $row);

                if ($assoc === null || $assoc === []) {
                    continue;
                }

                yield $assoc;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $schema
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function buildFeatureFromRow(Dataset $dataset, array $schema, array $row, int $index): ?array
    {
        $latitudeKey = $schema['latitude'];
        $longitudeKey = $schema['longitude'];
        $timestampKey = $schema['timestamp'];
        $categoryKey = $schema['category'];
        $riskKey = $schema['risk'] ?? null;

        if (! array_key_exists($latitudeKey, $row) || ! array_key_exists($longitudeKey, $row)) {
            return null;
        }

        $latitude = $this->toFloat($row[$latitudeKey] ?? null);
        $longitude = $this->toFloat($row[$longitudeKey] ?? null);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $observedAt = null;

        if (array_key_exists($timestampKey, $row)) {
            $observedAt = $this->parseTimestamp($row[$timestampKey]);
        }

        $category = $row[$categoryKey] ?? null;
        $name = is_scalar($category) ? trim((string) $category) : '';

        if ($name === '') {
            $name = sprintf('Feature %d', $index + 1);
        }

        $properties = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($observedAt instanceof CarbonImmutable) {
            $properties['timestamp'] = $observedAt->toIso8601String();
        }

        if (is_scalar($category) && trim((string) $category) !== '') {
            $properties['category'] = (string) $category;
        }

        if ($riskKey !== null && array_key_exists($riskKey, $row)) {
            $riskScore = $this->toFloat($row[$riskKey]);

            if ($riskScore !== null) {
                $properties['risk_score'] = $riskScore;
            } elseif (is_scalar($row[$riskKey]) && trim((string) $row[$riskKey]) !== '') {
                $properties['risk_score'] = $row[$riskKey];
            }
        }

        $properties = array_filter($properties, static function ($value) {
            if (is_string($value)) {
                return trim($value) !== '';
            }

            return $value !== null;
        });

        return [
            'dataset_id' => $dataset->getKey(),
            'name' => $name,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => $properties,
            'observed_at' => $observedAt,
            'srid' => 4326,
        ];
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        if (is_int($value)) {
            try {
                return CarbonImmutable::createFromTimestamp($value);
            } catch (Throwable) {
                return null;
            }
        }

        if (is_float($value)) {
            try {
                return CarbonImmutable::createFromTimestamp((int) $value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $numeric = trim($value);

            if ($numeric === '') {
                return null;
            }

            if (! is_numeric($numeric)) {
                return null;
            }

            return (float) $numeric;
        }

        return null;
    }

    /**
     * @param array<int, string|null> $headers
     * @return list<string>
     */
    private function normaliseCsvHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $header) {
            if ($header === null) {
                $normalised[] = '';
                continue;
            }

            $value = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $value = trim((string) $value);

            $normalised[] = $value;
        }

        return $normalised;
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value === null) {
                continue;
            }

            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $headers
     * @param array<int, string|null> $row
     * @return array<string, string|null>|null
     */
    private function combineCsvRow(array $headers, array $row): ?array
    {
        if ($headers === []) {
            return null;
        }

        $assoc = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $assoc[$header] = $value;
        }

        foreach ($assoc as $value) {
            if ($value !== null && $value !== '') {
                return $assoc;
            }
        }

        return null;
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

        $combinedHandle = fopen($temporaryPath, 'w+b');

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
        fflush($handle);
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
