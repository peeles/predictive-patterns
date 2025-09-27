<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use App\Support\FeatureBuffer;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ModelTrainingService
{
    /**
     * @param TrainingRun $run
     * @param PredictiveModel $model
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
        $prepared = DatasetRowPreprocessor::prepareTrainingData($path, $columnMap);
        $buffer = $prepared['buffer'];

        if (! $buffer instanceof DatasetRowBuffer || $buffer->count() === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $resolvedHyperparameters = $this->resolveHyperparameters($hyperparameters);

        if ($progressCallback !== null) {
            $progressCallback(35.0);
        }

        $splits = $this->splitBufferedEntries($buffer, $resolvedHyperparameters['validation_split']);

        if ($progressCallback !== null) {
            $progressCallback(55.0);
        }

        $weights = $this->trainLogisticRegression(
            $splits['train_buffer'],
            $splits['means'],
            $splits['std_devs'],
            (float) $resolvedHyperparameters['learning_rate'],
            (int) $resolvedHyperparameters['iterations']
        );

        if ($progressCallback !== null) {
            $progressCallback(75.0);
        }

        $metrics = $this->evaluateValidationBuffer(
            $weights,
            $splits['validation_buffer'],
            $splits['means'],
            $splits['std_devs']
        );

        $trainedAt = now();
        $version = $trainedAt->format('YmdHis');
        $artifactPath = sprintf('models/%s/%s.json', $model->getKey(), $version);

        $artifact = [
            'model_id' => $model->getKey(),
            'training_run_id' => $run->getKey(),
            'trained_at' => $trainedAt->toIso8601String(),
            'weights' => $weights,
            'feature_names' => $prepared['feature_names'],
            'feature_means' => $splits['means'],
            'feature_std_devs' => $splits['std_devs'],
            'categories' => $prepared['categories'],
            'category_overflowed' => $prepared['category_overflowed'],
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
     * @param Dataset $dataset
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
    private function splitBufferedEntries(DatasetRowBuffer $buffer, float $validationSplit): array
    {
        $total = $buffer->count();

        if ($total === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $validationCount = (int) round($total * $validationSplit);
        $validationCount = max(0, min($validationCount, max(0, $total - 1)));
        $trainCount = $total - $validationCount;
        $cloneForValidation = false;

        if ($trainCount < 1) {
            $trainCount = $total;
            $validationCount = 0;
        }

        if ($validationCount === 0) {
            $cloneForValidation = true;
        }

        $trainBuffer = new FeatureBuffer();
        $validationBuffer = new FeatureBuffer();

        $means = [];
        $variances = [];
        $trainSeen = 0;
        $rowIndex = 0;

        foreach ($buffer as $row) {
            $useValidation = ! $cloneForValidation && $rowIndex >= $trainCount;

            if ($rowIndex < $trainCount || $cloneForValidation) {
                $trainBuffer->append($row['features'], $row['label']);
                $this->updateRunningStatistics($means, $variances, $trainSeen, $row['features']);
                $trainSeen++;
            }

            if ($useValidation || $cloneForValidation) {
                $validationBuffer->append($row['features'], $row['label']);
            }

            $rowIndex++;
        }

        if ($trainSeen === 0) {
            throw new RuntimeException('Training split did not produce any rows.');
        }

        $stdDevs = [];

        foreach ($means as $index => $mean) {
            $variance = $variances[$index] ?? 0.0;
            $std = sqrt($variance / max(1, $trainSeen));
            $stdDevs[$index] = $std > 0 ? $std : 1.0;
            $means[$index] = $mean;
        }

        return [
            'train_buffer' => $trainBuffer,
            'validation_buffer' => $validationBuffer,
            'means' => array_values($means),
            'std_devs' => array_values($stdDevs),
        ];
    }

    private function updateRunningStatistics(array &$means, array &$variances, int $count, array $features): void
    {
        if ($count === 0) {
            foreach ($features as $index => $value) {
                $means[$index] = (float) $value;
                $variances[$index] = 0.0;
            }

            return;
        }

        $newCount = $count + 1;

        foreach ($features as $index => $value) {
            $currentMean = $means[$index] ?? 0.0;
            $delta = $value - $currentMean;
            $updatedMean = $currentMean + ($delta / $newCount);
            $means[$index] = $updatedMean;

            $variance = $variances[$index] ?? 0.0;
            $variances[$index] = $variance + $delta * ($value - $updatedMean);
        }
    }

    private function trainLogisticRegression(
        FeatureBuffer $buffer,
        array $means,
        array $stdDevs,
        float $learningRate,
        int $iterations
    ): array {
        if ($buffer->count() === 0) {
            throw new RuntimeException('Cannot train a model without features.');
        }

        $featureCount = $buffer->featureCount();
        $weights = array_fill(0, $featureCount + 1, 0.0);
        $sampleCount = max(1, $buffer->count());

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            foreach ($buffer as $row) {
                $normalized = $this->normalizeRow($row['features'], $means, $stdDevs);
                $input = array_merge([1.0], $normalized);
                $prediction = $this->sigmoid($this->dotProduct($weights, $input));
                $error = $prediction - (float) $row['label'];

                foreach ($weights as $index => $weight) {
                    $gradient = $error * $input[$index];
                    $weights[$index] = $weight - ($learningRate / $sampleCount) * $gradient;
                }
            }
        }

        return $weights;
    }

    private function evaluateValidationBuffer(
        array $weights,
        FeatureBuffer $buffer,
        array $means,
        array $stdDevs
    ): array {
        $tp = $tn = $fp = $fn = 0;

        foreach ($buffer as $row) {
            $normalized = $this->normalizeRow($row['features'], $means, $stdDevs);
            $probability = $this->sigmoid($this->dotProduct($weights, array_merge([1.0], $normalized)));
            $predicted = $probability >= 0.5 ? 1 : 0;
            $actual = $row['label'];

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

    private function normalizeRow(array $features, array $means, array $stdDevs): array
    {
        return array_map(
            static fn ($value, $mean, $std) => ($value - $mean) / ($std > 0 ? $std : 1.0),
            $features,
            $means,
            $stdDevs
        );
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
