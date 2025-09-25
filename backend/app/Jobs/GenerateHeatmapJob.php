<?php

namespace App\Jobs;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class GenerateHeatmapJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $predictionId,
        private readonly array $parameters,
        private readonly bool $generateTiles = false,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $prediction = Prediction::query()->with(['model', 'dataset'])->findOrFail($this->predictionId);

        $prediction->fill([
            'status' => PredictionStatus::Running,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $artifact = $this->loadLatestArtifact($prediction);
            $rows = $this->loadDatasetRows($prediction);
            $entries = $this->prepareEntries($rows, $artifact['categories']);
            $filtered = $this->filterEntries($entries, $this->parameters);

            if ($filtered === []) {
                $filtered = $entries;
            }

            if ($filtered === []) {
                throw new RuntimeException('No usable dataset rows found for prediction.');
            }

            $scored = $this->scoreEntries($filtered, $artifact);
            $summary = $this->buildSummary($scored, $artifact);
            $heatmap = $this->aggregateHeatmap($scored);
            $topFeatures = $this->rankFeatureInfluences($artifact);

            $payload = [
                'prediction_id' => $prediction->id,
                'generated_at' => now()->toIso8601String(),
                'parameters' => $this->parameters,
                'summary' => $summary,
                'heatmap' => $heatmap,
                'top_features' => $topFeatures,
            ];

            $prediction->outputs()->create([
                'id' => (string) Str::uuid(),
                'format' => PredictionOutputFormat::Json,
                'payload' => $payload,
            ]);

            if ($this->generateTiles) {
                $tilesetPath = sprintf('tiles/%s/%s', now()->format('Ymd'), $prediction->id);
                Storage::disk('local')->put(
                    $tilesetPath . '/heatmap.json',
                    json_encode($heatmap, JSON_PRETTY_PRINT)
                );

                $prediction->outputs()->create([
                    'id' => (string) Str::uuid(),
                    'format' => PredictionOutputFormat::Tiles,
                    'tileset_path' => $tilesetPath,
                ]);
            }

            $prediction->fill([
                'status' => PredictionStatus::Completed,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::error('Failed to generate prediction output', [
                'prediction_id' => $this->predictionId,
                'exception' => $exception->getMessage(),
            ]);

            $prediction->fill([
                'status' => PredictionStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLatestArtifact(Prediction $prediction): array
    {
        $model = $prediction->model;

        if ($model === null) {
            throw new RuntimeException('Prediction is missing an associated model.');
        }

        $metadata = $model->metadata ?? [];
        $artifactPath = $this->resolveArtifactPathFromMetadata($metadata);

        if ($artifactPath === null) {
            $artifactPath = $this->findMostRecentArtifactOnDisk($model);
        }

        if ($artifactPath === null) {
            throw new RuntimeException('Trained artifact for model is unavailable.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($artifactPath)) {
            throw new RuntimeException(sprintf('Model artifact "%s" could not be found.', $artifactPath));
        }

        try {
            $decoded = json_decode($disk->get($artifactPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode model artifact.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid model artifact content.');
        }

        foreach (['weights', 'feature_means', 'feature_std_devs', 'feature_names', 'categories'] as $key) {
            if (! isset($decoded[$key]) || ! is_array($decoded[$key])) {
                throw new RuntimeException(sprintf('Model artifact is missing "%s".', $key));
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function resolveArtifactPathFromMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        if (is_string($metadata['artifact_path'] ?? null) && $metadata['artifact_path'] !== '') {
            return $metadata['artifact_path'];
        }

        if (isset($metadata['artifacts']) && is_array($metadata['artifacts'])) {
            $artifacts = $metadata['artifacts'];

            if ($artifacts !== []) {
                $last = end($artifacts);

                if (is_array($last) && isset($last['path']) && is_string($last['path']) && $last['path'] !== '') {
                    return $last['path'];
                }
            }
        }

        return null;
    }

    private function findMostRecentArtifactOnDisk(PredictiveModel $model): ?string
    {
        $disk = Storage::disk('local');
        $directory = sprintf('models/%s', $model->getKey());

        $files = array_filter(
            $disk->files($directory),
            static fn (string $path): bool => str_ends_with(strtolower($path), '.json')
        );

        if ($files === []) {
            return null;
        }

        rsort($files);

        return $files[0] ?? null;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function loadDatasetRows(Prediction $prediction): array
    {
        $dataset = $prediction->dataset ?? $prediction->model?->dataset;

        if ($dataset === null) {
            throw new RuntimeException('Prediction is missing an associated dataset.');
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset is missing a source file path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" was not found.', $dataset->file_path));
        }

        $path = $disk->path($dataset->file_path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
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
            } catch (Throwable) {
                continue;
            }

            $latitude = (float) ($row['latitude'] ?? $row['lat'] ?? 0.0);
            $longitude = (float) ($row['longitude'] ?? $row['lng'] ?? 0.0);
            $riskScore = (float) ($row['risk_score'] ?? $row['risk'] ?? 0.0);
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
        $radiusKm = $this->resolveFloat($parameters['radius_km'] ?? $parameters['radiusKm'] ?? null);
        $observedAt = $this->resolveTimestamp($parameters['observed_at'] ?? $parameters['timestamp'] ?? $parameters['ts_end'] ?? null);
        $horizonHours = $this->resolveFloat($parameters['horizon_hours'] ?? $parameters['horizon'] ?? $parameters['horizonHours'] ?? null);

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
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}>
     */
    private function scoreEntries(array $entries, array $artifact): array
    {
        $weights = array_map('floatval', $artifact['weights']);
        $means = array_map('floatval', $artifact['feature_means']);
        $stdDevs = array_map(
            static fn ($value) => max(1e-6, (float) $value),
            $artifact['feature_std_devs']
        );

        $scored = [];

        foreach ($entries as $entry) {
            $normalized = [];

            foreach ($entry['features'] as $index => $value) {
                $mean = $means[$index] ?? 0.0;
                $std = $stdDevs[$index] ?? 1.0;
                $normalized[] = ($value - $mean) / $std;
            }

            $input = array_merge([1.0], $normalized);
            $score = $this->sigmoid($this->dotProduct($weights, $input));

            $scored[] = [
                'timestamp' => $entry['timestamp'],
                'latitude' => $entry['latitude'],
                'longitude' => $entry['longitude'],
                'category' => $entry['category'],
                'score' => $score,
            ];
        }

        return $scored;
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}> $entries
     * @param array<string, mixed> $artifact
     *
     * @return array{mean_score: float, max_score: float, min_score: float, count: int, confidence: string, horizon_hours: float|null, radius_km: float|null}
     */
    private function buildSummary(array $entries, array $artifact): array
    {
        $scores = array_map(static fn ($entry) => $entry['score'], $entries);
        $count = count($scores);
        $mean = $count > 0 ? array_sum($scores) / $count : 0.0;
        $max = $scores === [] ? 0.0 : max($scores);
        $min = $scores === [] ? 0.0 : min($scores);
        $stdDev = $this->standardDeviation($scores, $mean);

        $confidence = 'Low';

        if ($count >= 60 && $stdDev <= 0.15 && $max >= 0.7) {
            $confidence = 'High';
        } elseif ($count >= 25 && $max >= 0.5) {
            $confidence = 'Medium';
        }

        $horizon = $this->resolveFloat($this->parameters['horizon_hours'] ?? $this->parameters['horizon'] ?? null);
        $radius = $this->resolveFloat($this->parameters['radius_km'] ?? $this->parameters['radiusKm'] ?? null);

        return [
            'mean_score' => round($mean, 4),
            'max_score' => round($max, 4),
            'min_score' => round($min, 4),
            'count' => $count,
            'confidence' => $confidence,
            'horizon_hours' => $horizon,
            'radius_km' => $radius,
        ];
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}> $entries
     *
     * @return array{points: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>, hotspots: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>}
     */
    private function aggregateHeatmap(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $latKey = round($entry['latitude'], 3);
            $lngKey = round($entry['longitude'], 3);
            $key = sprintf('%s:%s', $latKey, $lngKey);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'lat' => $latKey,
                    'lng' => $lngKey,
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }

            $groups[$key]['sum'] += $entry['score'];
            $groups[$key]['count']++;
        }

        $points = [];

        foreach ($groups as $key => $group) {
            $average = $group['count'] > 0 ? $group['sum'] / $group['count'] : 0.0;

            $points[] = [
                'id' => $key,
                'lat' => $group['lat'],
                'lng' => $group['lng'],
                'intensity' => round($average, 4),
                'count' => $group['count'],
            ];
        }

        usort($points, static fn ($a, $b) => $b['intensity'] <=> $a['intensity']);

        $hotspots = array_slice($points, 0, 5);

        return [
            'points' => $points,
            'hotspots' => $hotspots,
        ];
    }

    /**
     * @return list<array{name: string, contribution: float}>
     */
    private function rankFeatureInfluences(array $artifact): array
    {
        $names = array_map(static fn ($name) => (string) $name, $artifact['feature_names']);
        $weights = array_map('floatval', array_slice($artifact['weights'], 1));
        $stdDevs = array_map(
            static fn ($value) => max(1e-6, (float) $value),
            $artifact['feature_std_devs']
        );

        $influences = [];

        foreach ($names as $index => $name) {
            $weight = $weights[$index] ?? 0.0;
            $scaled = $weight * $stdDevs[$index];

            $influences[] = [
                'name' => $this->prettifyFeatureName($name),
                'contribution' => round($scaled, 4),
            ];
        }

        usort($influences, static fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));

        return array_slice($influences, 0, 5);
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
        } catch (Throwable) {
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

        return $earthRadius * $c;
    }

    /**
     * @param list<float> $values
     */
    private function standardDeviation(array $values, float $mean): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $variance = 0.0;

        foreach ($values as $value) {
            $delta = $value - $mean;
            $variance += $delta * $delta;
        }

        return sqrt($variance / $count);
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

    private function prettifyFeatureName(string $name): string
    {
        $pretty = str_replace('_', ' ', $name);
        $pretty = preg_replace('/\b([a-z])/', static fn ($matches) => strtoupper($matches[1]), $pretty);

        return $pretty ?? $name;
    }
}
