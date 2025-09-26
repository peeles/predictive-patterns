<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Support\DatasetRiskLabelGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ModelTrainingService
{
    /**
     * @param array<string, mixed> $hyperparameters
     * @param callable|null $progressCallback
     *
     * @return array{
     *     metrics: array<string, float>,
     *     artifact_path: string,
     *     version: string,
     *     metadata: array<string, mixed>,
     *     hyperparameters: array<string, mixed>
     * }
     */
    public function train(
        TrainingRun $run,
        PredictiveModel $model,
        array $hyperparameters = [],
        ?callable $progressCallback = null,
    ): array
    {
        $dataset = $model->dataset;

        if (! $dataset instanceof Dataset) {
            throw new RuntimeException('Predictive model does not have an associated dataset.');
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset is missing a file path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" was not found.', $dataset->file_path));
        }

        $path = $disk->path($dataset->file_path);

        if ($progressCallback !== null) {
            $progressCallback(15.0);
        }

        $columnMap = $this->resolveColumnMap($dataset);
        $rows = $this->loadCsv($path, $columnMap);

        if ($rows === []) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $rows = DatasetRiskLabelGenerator::ensureColumns($rows, $columnMap);
        $prepared = $this->prepareEntries($rows, $columnMap);
        unset($rows);

        $resolvedHyperparameters = $this->resolveHyperparameters($hyperparameters);

        if ($progressCallback !== null) {
            $progressCallback(35.0);
        }

        $entries = $prepared['entries'];

        if ($entries === []) {
            throw new RuntimeException('No usable rows were found in the dataset.');
        }

        usort($entries, function (array $a, array $b): int {
            /** @var CarbonImmutable $aTimestamp */
            $aTimestamp = $a['timestamp'];
            /** @var CarbonImmutable $bTimestamp */
            $bTimestamp = $b['timestamp'];

            return $aTimestamp <=> $bTimestamp;
        });

        $splits = $this->splitEntries($entries, $resolvedHyperparameters['validation_split']);

        if ($progressCallback !== null) {
            $progressCallback(55.0);
        }
        $normalizedTrain = $this->normalizeFeatures($splits['train_features']);
        $normalizedValidation = $this->applyNormalization(
            $splits['validation_features'],
            $normalizedTrain['means'],
            $normalizedTrain['std_devs']
        );

        $weights = $this->trainLogisticRegression(
            $normalizedTrain['features'],
            $splits['train_labels'],
            (float) $resolvedHyperparameters['learning_rate'],
            (int) $resolvedHyperparameters['iterations']
        );

        if ($progressCallback !== null) {
            $progressCallback(75.0);
        }

        $validationPredictions = $this->predictProbabilities($weights, $normalizedValidation);
        $metrics = $this->calculateMetrics($splits['validation_labels'], $validationPredictions);

        $trainedAt = now();
        $version = $trainedAt->format('YmdHis');
        $artifactPath = sprintf('models/%s/%s.json', $model->getKey(), $version);

        $artifact = [
            'model_id' => $model->getKey(),
            'training_run_id' => $run->getKey(),
            'trained_at' => $trainedAt->toIso8601String(),
            'weights' => $weights,
            'feature_names' => $prepared['feature_names'],
            'feature_means' => $normalizedTrain['means'],
            'feature_std_devs' => $normalizedTrain['std_devs'],
            'categories' => $prepared['categories'],
            'hyperparameters' => $resolvedHyperparameters,
            'metrics' => $metrics,
        ];

        $disk->put($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT));

        if ($progressCallback !== null) {
            $progressCallback(90.0);
        }

        return [
            'metrics' => $metrics,
            'artifact_path' => $artifactPath,
            'version' => $version,
            'metadata' => ['artifact_path' => $artifactPath],
            'hyperparameters' => $resolvedHyperparameters,
        ];
    }

    /**
     * @param array<string, string> $columnMap
     *
     * @return list<array<string, mixed>>
     */
    private function loadCsv(string $path, array $columnMap): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
        }

        try {
            $rows = [];
            $header = null;
            $columnIndexes = [];
            $normalizedRequired = array_values(array_unique(array_filter(
                array_map(
                    static fn ($value) => is_string($value) ? $value : '',
                    array_values($columnMap)
                ),
                static fn ($value) => $value !== ''
            )));

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = $this->normalizeHeaderRow($data);

                    if ($normalizedRequired !== []) {
                        $headerIndexMap = [];

                        foreach ($header as $index => $column) {
                            if ($column === '') {
                                continue;
                            }

                            $headerIndexMap[$column] = $index;
                        }

                        foreach ($normalizedRequired as $column) {
                            if (array_key_exists($column, $headerIndexMap)) {
                                $columnIndexes[$column] = $headerIndexMap[$column];
                            }
                        }

                        foreach ($columnMap as $key => $column) {
                            if (! is_string($column) || $column === '') {
                                continue;
                            }

                            if (! array_key_exists($column, $columnIndexes)) {
                                throw new RuntimeException(
                                    sprintf('Dataset is missing required column "%s".', $key)
                                );
                            }
                        }
                    }

                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $row = [];

                foreach ($columnMap as $column) {
                    if (! is_string($column) || $column === '') {
                        continue;
                    }

                    $index = $columnIndexes[$column] ?? null;
                    $row[$column] = $index !== null ? ($data[$index] ?? null) : null;
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveColumnMap(Dataset $dataset): array
    {
        $mapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];

        return [
            'timestamp' => $this->resolveMappedColumn($mapping, 'timestamp', 'timestamp'),
            'latitude' => $this->resolveMappedColumn($mapping, 'latitude', 'latitude'),
            'longitude' => $this->resolveMappedColumn($mapping, 'longitude', 'longitude'),
            'category' => $this->resolveMappedColumn($mapping, 'category', 'category'),
            'risk_score' => $this->resolveMappedColumn($mapping, 'risk', 'risk_score'),
            'label' => $this->resolveMappedColumn($mapping, 'label', 'label'),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function resolveMappedColumn(array $mapping, string $key, string $default): string
    {
        $value = $mapping[$key] ?? $default;

        if (! is_string($value) || trim($value) === '') {
            $value = $default;
        }

        $normalized = $this->normalizeColumnName($value);

        if ($normalized === '') {
            $normalized = $this->normalizeColumnName($default);
        }

        if ($normalized === '') {
            $normalized = $default;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     entries: list<array{timestamp: CarbonImmutable, features: list<float>, label: int}>,
     *     feature_names: list<string>,
     *     categories: list<string>
     * }
     */
    private function prepareEntries(array $rows, array $columnMap): array
    {
        $requiredColumns = ['timestamp', 'latitude', 'longitude', 'category', 'risk_score', 'label'];

        $categories = [];

        foreach ($rows as $row) {
            foreach ($requiredColumns as $column) {
                $mapped = $columnMap[$column] ?? $column;

                if (! array_key_exists($mapped, $row)) {
                    throw new RuntimeException(sprintf('Dataset is missing required column "%s".', $column));
                }
            }

            $categoryKey = $columnMap['category'];
            $category = (string) ($row[$categoryKey] ?? '');

            if ($category !== '') {
                $categories[$category] = true;
            }
        }

        $categoryList = array_keys($categories);
        sort($categoryList);

        $featureNames = [
            'hour_of_day',
            'day_of_week',
            'latitude',
            'longitude',
            'risk_score',
        ];

        foreach ($categoryList as $category) {
            $featureNames[] = sprintf('category_%s', $category);
        }

        $entries = [];

        foreach ($rows as $row) {
            $timestampKey = $columnMap['timestamp'];
            $timestampString = (string) ($row[$timestampKey] ?? '');

            if ($timestampString === '') {
                continue;
            }

            $timestamp = CarbonImmutable::parse($timestampString);
            $hour = $timestamp->hour / 23.0;
            $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

            $latitude = (float) ($row[$columnMap['latitude']] ?? 0.0);
            $longitude = (float) ($row[$columnMap['longitude']] ?? 0.0);
            $riskScore = (float) ($row[$columnMap['risk_score']] ?? 0.0);
            $label = (int) ($row[$columnMap['label']] ?? 0);

            $features = [$hour, $dayOfWeek, $latitude, $longitude, $riskScore];
            $rowCategory = (string) ($row[$columnMap['category']] ?? '');

            foreach ($categoryList as $category) {
                $features[] = $rowCategory === $category ? 1.0 : 0.0;
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'features' => $features,
                'label' => $label,
            ];
        }

        return [
            'entries' => $entries,
            'feature_names' => $featureNames,
            'categories' => $categoryList,
        ];
    }

    /**
     * @param array<int, mixed> $columns
     *
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $columns): array
    {
        $normalized = [];
        $used = [];

        foreach ($columns as $value) {
            if (! is_string($value)) {
                $normalized[] = '';

                continue;
            }

            $column = $this->normalizeColumnName($value);

            if ($column === '') {
                $column = trim($value);
            }

            $base = $column;
            $suffix = 1;

            while ($column !== '' && in_array($column, $used, true)) {
                $suffix++;
                $column = sprintf('%s_%d', $base, $suffix);
            }

            if ($column !== '') {
                $used[] = $column;
            }

            $normalized[] = $column;
        }

        return $normalized;
    }

    private function normalizeColumnName(string $column): string
    {
        $column = preg_replace('/^\xEF\xBB\xBF/u', '', $column) ?? $column; // Remove UTF-8 BOM.
        $column = trim($column);

        if ($column === '') {
            return '';
        }

        $column = mb_strtolower($column, 'UTF-8');
        $column = str_replace(['-', '/'], ' ', $column);
        $column = preg_replace('/[^a-z0-9]+/u', '_', $column) ?? $column;
        $column = preg_replace('/_+/', '_', $column) ?? $column;

        return trim($column, '_');
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, features: list<float>, label: int}> $entries
     *
     * @return array{
     *     train_features: list<list<float>>,
     *     train_labels: list<int>,
     *     validation_features: list<list<float>>,
     *     validation_labels: list<int>
     * }
     */
    private function splitEntries(array $entries, float $validationSplit): array
    {
        $total = count($entries);

        if ($total === 0) {
            return [
                'train_features' => [],
                'train_labels' => [],
                'validation_features' => [],
                'validation_labels' => [],
            ];
        }

        $validationCount = (int) round($total * $validationSplit);
        $validationCount = max(1, min($validationCount, $total - 1));
        $trainCount = $total - $validationCount;

        if ($trainCount < 1) {
            $trainCount = $total - 1;
            $validationCount = 1;
        }

        $trainEntries = array_slice($entries, 0, $trainCount);
        $validationEntries = array_slice($entries, $trainCount);

        if ($validationEntries === []) {
            $validationEntries = $trainEntries;
        }

        return [
            'train_features' => array_map(static fn ($entry) => $entry['features'], $trainEntries),
            'train_labels' => array_map(static fn ($entry) => $entry['label'], $trainEntries),
            'validation_features' => array_map(static fn ($entry) => $entry['features'], $validationEntries),
            'validation_labels' => array_map(static fn ($entry) => $entry['label'], $validationEntries),
        ];
    }

    /**
     * @param list<list<float>> $features
     *
     * @return array{
     *     features: list<list<float>>,
     *     means: list<float>,
     *     std_devs: list<float>
     * }
     */
    private function normalizeFeatures(array $features): array
    {
        if ($features === []) {
            return ['features' => [], 'means' => [], 'std_devs' => []];
        }

        $featureCount = count($features[0]);
        $means = array_fill(0, $featureCount, 0.0);

        foreach ($features as $row) {
            foreach ($row as $index => $value) {
                $means[$index] += $value;
            }
        }

        $rowCount = count($features);

        foreach ($means as $index => $value) {
            $means[$index] = $value / $rowCount;
        }

        $variances = array_fill(0, $featureCount, 0.0);

        foreach ($features as $row) {
            foreach ($row as $index => $value) {
                $delta = $value - $means[$index];
                $variances[$index] += $delta * $delta;
            }
        }

        $stdDevs = [];

        foreach ($variances as $index => $value) {
            $std = sqrt($value / max(1, $rowCount));
            $stdDevs[$index] = $std > 0 ? $std : 1.0;
        }

        $normalized = [];

        foreach ($features as $row) {
            $normalized[] = array_map(
                static fn ($value, $mean, $std) => ($value - $mean) / $std,
                $row,
                $means,
                $stdDevs
            );
        }

        return ['features' => $normalized, 'means' => $means, 'std_devs' => $stdDevs];
    }

    /**
     * @param list<list<float>> $features
     * @param list<float> $means
     * @param list<float> $stdDevs
     *
     * @return list<list<float>>
     */
    private function applyNormalization(array $features, array $means, array $stdDevs): array
    {
        if ($features === []) {
            return [];
        }

        $normalized = [];

        foreach ($features as $row) {
            $normalized[] = array_map(
                static fn ($value, $mean, $std) => ($value - $mean) / $std,
                $row,
                $means,
                $stdDevs
            );
        }

        return $normalized;
    }

    /**
     * @param list<list<float>> $features
     * @param list<int> $labels
     *
     * @return list<float>
     */
    private function trainLogisticRegression(array $features, array $labels, float $learningRate, int $iterations): array
    {
        if ($features === []) {
            throw new RuntimeException('Cannot train a model without features.');
        }

        $featureCount = count($features[0]);
        $weights = array_fill(0, $featureCount + 1, 0.0);
        $sampleCount = count($features);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            foreach ($features as $index => $row) {
                $input = array_merge([1.0], $row);
                $prediction = $this->sigmoid($this->dotProduct($weights, $input));
                $error = $prediction - (float) $labels[$index];

                foreach ($weights as $weightIndex => $weight) {
                    $gradient = $error * $input[$weightIndex];
                    $weights[$weightIndex] = $weight - ($learningRate / $sampleCount) * $gradient;
                }
            }
        }

        return $weights;
    }

    /**
     * @param list<float> $weights
     * @param list<list<float>> $features
     *
     * @return list<float>
     */
    private function predictProbabilities(array $weights, array $features): array
    {
        $predictions = [];

        foreach ($features as $row) {
            $input = array_merge([1.0], $row);
            $predictions[] = $this->sigmoid($this->dotProduct($weights, $input));
        }

        return $predictions;
    }

    /**
     * @param list<int> $labels
     * @param list<float> $predictions
     *
     * @return array{accuracy: float, precision: float, recall: float, f1: float}
     */
    private function calculateMetrics(array $labels, array $predictions): array
    {
        $tp = $tn = $fp = $fn = 0;

        foreach ($predictions as $index => $probability) {
            $predicted = $probability >= 0.5 ? 1 : 0;
            $actual = $labels[$index] ?? 0;

            if ($predicted === 1 && $actual === 1) {
                $tp++;
            } elseif ($predicted === 0 && $actual === 0) {
                $tn++;
            } elseif ($predicted === 1 && $actual === 0) {
                $fp++;
            } elseif ($predicted === 0 && $actual === 1) {
                $fn++;
            }
        }

        $total = max(1, $tp + $tn + $fp + $fn);
        $accuracy = ($tp + $tn) / $total;
        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1Denominator = $precision + $recall;
        $f1 = $f1Denominator > 0 ? (2 * $precision * $recall) / $f1Denominator : 0.0;

        return [
            'accuracy' => round($accuracy, 4),
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{learning_rate: float, iterations: int, validation_split: float}
     */
    private function resolveHyperparameters(array $input): array
    {
        $learningRate = isset($input['learning_rate']) ? (float) $input['learning_rate'] : 0.3;
        $iterations = isset($input['iterations']) ? (int) $input['iterations'] : 600;
        $validationSplit = isset($input['validation_split']) ? (float) $input['validation_split'] : 0.2;

        $learningRate = max(0.0001, min($learningRate, 1.0));
        $iterations = max(100, min($iterations, 5000));
        $validationSplit = max(0.1, min($validationSplit, 0.5));

        return [
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
            'validation_split' => $validationSplit,
        ];
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
