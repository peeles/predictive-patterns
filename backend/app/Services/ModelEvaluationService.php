<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ModelEvaluationService
{
    /**
     * @param callable|null $progressCallback
     *
     * @return array{accuracy: float, precision: float, recall: float, f1: float}
     */
    public function evaluate(PredictiveModel $model, Dataset $dataset, ?callable $progressCallback = null): array
    {
        $artifactPath = $this->resolveArtifactPath($model);
        $disk = Storage::disk('local');

        if (! $disk->exists($artifactPath)) {
            throw new RuntimeException(sprintf('Model artifact "%s" was not found.', $artifactPath));
        }

        $artifactContents = $disk->get($artifactPath);
        $artifact = json_decode($artifactContents, true);

        if (! is_array($artifact)) {
            throw new RuntimeException('Model artifact could not be decoded.');
        }

        $weights = $this->extractNumericList($artifact['weights'] ?? null, 'weights');
        $featureMeans = $this->extractNumericList($artifact['feature_means'] ?? null, 'feature_means');
        $featureStdDevs = $this->extractNumericList($artifact['feature_std_devs'] ?? null, 'feature_std_devs');

        if ($dataset->file_path === null) {
            throw new RuntimeException('Evaluation dataset is missing a file path.');
        }

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Evaluation dataset "%s" was not found.', $dataset->file_path));
        }

        if ($progressCallback !== null) {
            $progressCallback(15.0);
        }

        $categories = $this->extractStringList($artifact['categories'] ?? null, 'categories');

        if ($progressCallback !== null) {
            $progressCallback(35.0);
        }

        $columnMap = $this->resolveColumnMap($dataset);
        $buffer = DatasetRowPreprocessor::prepareEvaluationData(
            $disk->path($dataset->file_path),
            $columnMap,
            $categories
        );

        if ($buffer->count() === 0) {
            throw new RuntimeException('No usable rows were found in the evaluation dataset.');
        }

        if ($progressCallback !== null) {
            $progressCallback(55.0);
        }

        $metrics = $this->evaluateBuffer($buffer, $weights, $featureMeans, $featureStdDevs);

        if ($progressCallback !== null) {
            $progressCallback(85.0);
        }

        return $metrics;
    }

    private function resolveArtifactPath(PredictiveModel $model): string
    {
        $metadata = $model->metadata ?? [];
        $artifactPath = is_array($metadata) ? ($metadata['artifact_path'] ?? null) : null;

        if (! is_string($artifactPath) || $artifactPath === '') {
            throw new RuntimeException('Model metadata does not contain an artifact path.');
        }

        return $artifactPath;
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

    private function normalizeColumnName(string $column): string
    {
        $column = preg_replace('/^\xEF\xBB\xBF/u', '', $column) ?? $column;
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

    private function evaluateBuffer(
        DatasetRowBuffer $buffer,
        array $weights,
        array $means,
        array $stdDevs
    ): array {
        $tp = $tn = $fp = $fn = 0;

        foreach ($buffer as $row) {
            $normalized = $this->normalizeRow($row['features'], $means, $stdDevs);
            $input = array_merge([1.0], $normalized);

            if (count($weights) !== count($input)) {
                throw new RuntimeException('Model weights do not match feature vector length.');
            }

            $probability = $this->sigmoid($this->dotProduct($weights, $input));
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
        if (count($means) !== count($stdDevs)) {
            throw new RuntimeException('Normalization parameters are misaligned.');
        }

        if (count($features) !== count($means)) {
            throw new RuntimeException('Feature vector size mismatch during normalization.');
        }

        return array_map(
            static fn ($value, $mean, $std) => ($value - $mean) / ($std > 0 ? $std : 1.0),
            $features,
            $means,
            $stdDevs
        );
    }

    /**
     * @param list<float>|mixed $values
     *
     * @return list<float>
     */
    private function extractNumericList(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            throw new RuntimeException(sprintf('Model artifact is missing "%s".', $key));
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
            }

            $normalized[] = (float) $value;
        }

        if ($normalized === []) {
            throw new RuntimeException(sprintf('Model artifact does not contain any values for "%s".', $key));
        }

        return $normalized;
    }

    /**
     * @param list<string>|mixed $values
     *
     * @return list<string>
     */
    private function extractStringList(mixed $values, string $key): array
    {
        if ($values === null) {
            return [];
        }

        if (! is_array($values)) {
            throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

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
