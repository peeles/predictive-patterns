<?php

namespace Tests\Unit;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Enums\TrainingStatus;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelTrainingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateHeatmapJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_generates_heatmap_from_trained_artifact(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/unit-test.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'metadata' => [],
            'metrics' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $trainingService = app(ModelTrainingService::class);

        $result = $trainingService->train($run, $model, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $model->forceFill([
            'metadata' => ['artifact_path' => $result['artifact_path']],
            'metrics' => $result['metrics'],
        ])->save();

        $parameters = [
            'center' => ['lat' => 40.0, 'lng' => -73.9],
            'radius_km' => 10,
            'observed_at' => '2024-01-10T00:00:00Z',
            'horizon_hours' => 240,
        ];

        $prediction = Prediction::query()->create([
            'model_id' => $model->id,
            'dataset_id' => $dataset->id,
            'status' => PredictionStatus::Queued,
            'parameters' => $parameters,
            'queued_at' => now(),
        ]);

        $job = new GenerateHeatmapJob($prediction->id, $parameters, true);
        $job->handle();

        $prediction->refresh()->load('outputs');

        $this->assertEquals(PredictionStatus::Completed, $prediction->status);
        $this->assertCount(2, $prediction->outputs);

        $jsonOutput = $prediction->outputs
            ->firstWhere('format', PredictionOutputFormat::Json);
        $this->assertNotNull($jsonOutput);

        $payload = $jsonOutput->payload;
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('heatmap', $payload);
        $this->assertArrayHasKey('top_features', $payload);

        $artifact = json_decode(
            Storage::disk('local')->get($result['artifact_path']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $rows = $this->loadDatasetRows($dataset->file_path);
        $expectedScores = $this->scoreDataset($rows, $artifact, $parameters);

        $expectedMean = array_sum($expectedScores) / count($expectedScores);
        $expectedMax = max($expectedScores);

        $this->assertEqualsWithDelta($expectedMean, $payload['summary']['mean_score'], 0.0001);
        $this->assertEqualsWithDelta($expectedMax, $payload['summary']['max_score'], 0.0001);

        $this->assertNotEmpty($payload['heatmap']['points']);
        $this->assertNotEmpty($payload['top_features']);

        $tilesOutput = $prediction->outputs
            ->firstWhere('format', PredictionOutputFormat::Tiles);
        $this->assertNotNull($tilesOutput);
        $this->assertTrue(
            Storage::disk('local')->exists($tilesOutput->tileset_path . '/heatmap.json')
        );
    }

    private function datasetCsv(): string
    {
        return implode("\n", [
            'timestamp,latitude,longitude,category,risk_score,label',
            '2024-01-01T00:00:00Z,40.0,-73.9,burglary,0.10,0',
            '2024-01-02T00:00:00Z,40.0,-73.9,burglary,0.12,0',
            '2024-01-03T00:00:00Z,40.0,-73.9,burglary,0.14,0',
            '2024-01-04T00:00:00Z,40.0,-73.9,burglary,0.18,0',
            '2024-01-05T00:00:00Z,40.0,-73.9,assault,0.72,1',
            '2024-01-06T00:00:00Z,40.0,-73.9,assault,0.74,1',
            '2024-01-07T00:00:00Z,40.0,-73.9,assault,0.78,1',
            '2024-01-08T00:00:00Z,40.0,-73.9,assault,0.82,1',
            '2024-01-09T00:00:00Z,40.0,-73.9,burglary,0.28,0',
            '2024-01-10T00:00:00Z,40.0,-73.9,assault,0.88,1',
            '',
        ]);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function loadDatasetRows(string $path): array
    {
        $absolute = Storage::disk('local')->path($path);
        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            $this->fail(sprintf('Unable to open dataset "%s"', $absolute));
        }

        try {
            $rows = [];
            $header = null;

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $data);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $row = [];

                foreach ($header as $index => $column) {
                    $row[$column] = $data[$index] ?? null;
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $parameters
     *
     * @return list<float>
     */
    private function scoreDataset(array $rows, array $artifact, array $parameters): array
    {
        $entries = $this->prepareEntries($rows, $artifact['categories']);
        $filtered = $this->filterEntries($entries, $parameters);

        if ($filtered === []) {
            $filtered = $entries;
        }

        return $this->scoreEntries($filtered, $artifact);
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     * @param list<string> $artifactCategories
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function prepareEntries(array $rows, array $artifactCategories): array
    {
        $entries = [];
        $categories = array_values(array_map(static fn ($value) => (string) $value, $artifactCategories));

        foreach ($rows as $row) {
            $timestampString = (string) ($row['timestamp'] ?? '');
            if ($timestampString === '') {
                continue;
            }

            try {
                $timestamp = CarbonImmutable::parse($timestampString);
            } catch (\Throwable $e) {
                continue;
            }

            $latitude = (float) ($row['latitude'] ?? 0.0);
            $longitude = (float) ($row['longitude'] ?? 0.0);
            $riskScore = (float) ($row['risk_score'] ?? 0.0);
            $category = (string) ($row['category'] ?? '');

            $hour = $timestamp->hour / 23.0;
            $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

            $features = [$hour, $dayOfWeek, $latitude, $longitude, $riskScore];

            foreach ($categories as $expectedCategory) {
                $features[] = $expectedCategory === $category ? 1.0 : 0.0;
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'category' => $category,
                'features' => $features,
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param array<string, mixed> $parameters
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function filterEntries(array $entries, array $parameters): array
    {
        $center = $this->resolveCenter($parameters['center'] ?? null);
        $radiusKm = $this->resolveFloat($parameters['radius_km'] ?? null);
        $observedAt = $this->resolveTimestamp($parameters['observed_at'] ?? null);
        $horizonHours = $this->resolveFloat($parameters['horizon_hours'] ?? null);

        if ($center === null && $radiusKm !== null) {
            $radiusKm = null;
        }

        $start = null;
        $end = null;

        if ($observedAt !== null) {
            $windowHours = $horizonHours !== null ? max(0.0, $horizonHours) : 24.0;
            $windowMinutes = (int) round($windowHours * 60.0);

            if ($windowMinutes > 0) {
                $start = $observedAt->subMinutes($windowMinutes);
                $end = $observedAt->addMinutes($windowMinutes);
            } else {
                $end = $observedAt;
                $start = $observedAt->subHours(24);
            }
        }

        $filtered = [];

        foreach ($entries as $entry) {
            if ($center !== null && $radiusKm !== null) {
                $distance = $this->haversine($center['lat'], $center['lng'], $entry['latitude'], $entry['longitude']);

                if ($distance > $radiusKm) {
                    continue;
                }
            }

            if ($start !== null && $end !== null) {
                if ($entry['timestamp']->lt($start) || $entry['timestamp']->gt($end)) {
                    continue;
                }
            } elseif ($end !== null && $entry['timestamp']->gt($end)) {
                continue;
            }

            $filtered[] = $entry;
        }

        return $filtered;
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param array<string, mixed> $artifact
     *
     * @return list<float>
     */
    private function scoreEntries(array $entries, array $artifact): array
    {
        $weights = array_map('floatval', $artifact['weights']);
        $means = array_map('floatval', $artifact['feature_means']);
        $stdDevs = array_map(
            static fn ($value) => max(1e-6, (float) $value),
            $artifact['feature_std_devs']
        );

        $scores = [];

        foreach ($entries as $entry) {
            $normalized = [];

            foreach ($entry['features'] as $index => $value) {
                $mean = $means[$index] ?? 0.0;
                $std = $stdDevs[$index] ?? 1.0;
                $normalized[] = ($value - $mean) / $std;
            }

            $input = array_merge([1.0], $normalized);
            $scores[] = $this->sigmoid($this->dotProduct($weights, $input));
        }

        return $scores;
    }

    private function resolveCenter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $lat = $this->resolveFloat($value['lat'] ?? $value['latitude'] ?? $value[1] ?? null);
        $lng = $this->resolveFloat($value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? $value[0] ?? null);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function resolveFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            $parsed = filter_var($value, FILTER_VALIDATE_FLOAT);

            if ($parsed !== false) {
                return (float) $parsed;
            }
        }

        return null;
    }

    private function resolveTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);

        $a = sin($dLat / 2) ** 2 + sin($dLng / 2) ** 2 * cos($lat1Rad) * cos($lat2Rad);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return 6371.0 * $c;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function dotProduct(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }

    private function sigmoid(float $value): float
    {
        if ($value < -60) {
            return 0.0;
        }

        if ($value > 60) {
            return 1.0;
        }

        return 1.0 / (1.0 + exp(-$value));
    }
}
