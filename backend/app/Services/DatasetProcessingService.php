<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Models\Dataset;
use App\Models\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DatasetProcessingService
{
    private ?bool $featuresTableExists = null;

    public function __construct(private readonly DatasetPreviewService $previewService)
    {
    }

    /**
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     */
    public function finalise(Dataset $dataset, array $schemaMapping = [], array $additionalMetadata = []): Dataset
    {
        if ($additionalMetadata !== []) {
            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $additionalMetadata);
        }

        $preview = $this->generatePreview($dataset);

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
            $dataset->refresh();
            $this->populateFeaturesFromMapping($dataset, $schemaMapping);
        }

        if ($this->featuresTableExists()) {
            $dataset->loadCount('features');
        }

        event(DatasetStatusUpdated::fromDataset($dataset, 1.0));

        return $dataset;
    }

    public function mergeMetadata(mixed $existing, array $additional): array
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

        Feature::query()->where('dataset_id', $dataset->getKey())->delete();

        $index = 0;

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
                $index++;
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to derive dataset features', [
                'dataset_id' => $dataset->getKey(),
                'error' => $exception->getMessage(),
            ]);
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

    private function featuresTableExists(): bool
    {
        if ($this->featuresTableExists !== null) {
            return $this->featuresTableExists;
        }

        return $this->featuresTableExists = Schema::hasTable('features');
    }

    private function generatePreview(Dataset $dataset): ?array
    {
        $path = $dataset->file_path;

        if ($path === null) {
            return null;
        }

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        try {
            return $this->previewService->summarise(
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

        return null;
    }
}
