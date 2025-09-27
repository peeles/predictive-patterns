<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

class DatasetRowPreprocessor
{
    /**
     * Prepare dataset entries for model training without loading the full CSV into memory at once.
     *
     * @param string $path
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     entries: list<array{timestamp: CarbonImmutable, features: list<float>, label: int}>,
     *     feature_names: list<string>,
     *     categories: list<string>
     * }
     */
    public static function prepareTrainingData(string $path, array $columnMap): array
    {
        $analysis = self::analyseCsv($path, $columnMap);
        $categoryList = self::deriveCategoryList($analysis['category_counts']);
        $featureNames = self::buildFeatureNames($categoryList);

        $processed = self::collectRows($path, $columnMap, $analysis, $categoryList, true);
        $labels = self::resolveLabels(
            $processed['risks'],
            $processed['raw_labels'],
            $processed['max_risk']
        );

        $entries = [];

        foreach ($processed['timestamps'] as $index => $timestamp) {
            $entries[] = [
                'timestamp' => $timestamp,
                'features' => $processed['features'][$index],
                'label' => $labels[$index],
            ];
        }

        return [
            'entries' => $entries,
            'feature_names' => $featureNames,
            'categories' => $categoryList,
        ];
    }

    /**
     * Prepare dataset features and labels for model evaluation.
     *
     * @param string $path
     * @param array<string, string> $columnMap
     * @param list<string> $categories
     *
     * @return array{
     *     features: list<list<float>>,
     *     labels: list<int>
     * }
     */
    public static function prepareEvaluationData(string $path, array $columnMap, array $categories): array
    {
        $analysis = self::analyseCsv($path, $columnMap);
        $processed = self::collectRows($path, $columnMap, $analysis, $categories, false);
        $labels = self::resolveLabels(
            $processed['risks'],
            $processed['raw_labels'],
            $processed['max_risk']
        );

        return [
            'features' => $processed['features'],
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<string>
     */
    private static function deriveCategoryList(array $counts): array
    {
        unset($counts['__default__']);

        $categories = array_keys($counts);
        sort($categories);

        return $categories;
    }

    /**
     * @return list<string>
     */
    private static function buildFeatureNames(array $categories): array
    {
        $featureNames = [
            'hour_of_day',
            'day_of_week',
            'latitude',
            'longitude',
            'risk_score',
        ];

        foreach ($categories as $category) {
            $featureNames[] = sprintf('category_%s', $category);
        }

        return $featureNames;
    }

    /**
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     category_counts: array<string, int>,
     *     min_count: int,
     *     max_count: int,
     *     min_time: int|null,
     *     max_time: int|null,
     *     time_span: int|null,
     *     has_numeric_risk: bool
     * }
     */
    private static function analyseCsv(string $path, array $columnMap): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
        }

        try {
            $header = null;
            $columnIndexes = [];
            $categoryCounts = [];
            $minTime = null;
            $maxTime = null;
            $hasNumericRisk = false;

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = self::normalizeHeaderRow($data);
                    $columnIndexes = self::mapColumnIndexes($header, $columnMap);
                    self::assertRequiredColumns($columnIndexes);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $timestampValue = self::extractValue($data, $columnIndexes['timestamp'] ?? null);
                $timestamp = TimestampParser::parse($timestampValue);

                if (! $timestamp instanceof CarbonImmutable) {
                    continue;
                }

                $categoryValue = self::extractValue($data, $columnIndexes['category'] ?? null);
                $category = self::normalizeString($categoryValue);

                if ($category !== '') {
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                }

                $timestampSeconds = $timestamp->getTimestamp();
                $minTime = $minTime === null ? $timestampSeconds : min($minTime, $timestampSeconds);
                $maxTime = $maxTime === null ? $timestampSeconds : max($maxTime, $timestampSeconds);

                if (! $hasNumericRisk) {
                    $riskValue = self::extractNumeric(self::extractValue($data, $columnIndexes['risk_score'] ?? null));
                    $hasNumericRisk = $riskValue !== null;
                }

            }
        } finally {
            fclose($handle);
        }

        if ($categoryCounts === []) {
            $categoryCounts['__default__'] = 0;
        }

        $maxCount = max($categoryCounts);
        $minCount = min($categoryCounts);

        $timeSpan = ($minTime !== null && $maxTime !== null)
            ? max($maxTime - $minTime, 0)
            : null;

        return [
            'category_counts' => $categoryCounts,
            'min_count' => $minCount,
            'max_count' => $maxCount,
            'min_time' => $minTime,
            'max_time' => $maxTime,
            'time_span' => $timeSpan,
            'has_numeric_risk' => $hasNumericRisk,
        ];
    }

    /**
     * @param array<string, string> $columnMap
     * @param array{
     *     category_counts: array<string, int>,
     *     min_count: int,
     *     max_count: int,
     *     min_time: int|null,
     *     max_time: int|null,
     *     time_span: int|null,
     *     has_numeric_risk: bool
     * } $analysis
     * @param list<string> $categories
     *
     * @return array{
     *     timestamps: list<CarbonImmutable>,
     *     features: list<list<float>>,
     *     raw_labels: list<int|null>,
     *     risks: list<float>,
     *     max_risk: float
     * }
     */
    private static function collectRows(
        string $path,
        array $columnMap,
        array $analysis,
        array $categories,
        bool $includeTimestamps
    ): array {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
        }

        $timestamps = [];
        $features = [];
        $rawLabels = [];
        $risks = [];
        $maxRisk = 0.0;

        $categoryIndex = array_flip($categories);
        $categoryCount = count($categories);

        try {
            $header = null;
            $columnIndexes = [];

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = self::normalizeHeaderRow($data);
                    $columnIndexes = self::mapColumnIndexes($header, $columnMap);
                    self::assertRequiredColumns($columnIndexes);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $timestampValue = self::extractValue($data, $columnIndexes['timestamp'] ?? null);
                $timestamp = TimestampParser::parse($timestampValue);

                if (! $timestamp instanceof CarbonImmutable) {
                    continue;
                }

                $hour = $timestamp->hour / 23.0;
                $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

                $latitudeValue = self::extractValue($data, $columnIndexes['latitude'] ?? null);
                $longitudeValue = self::extractValue($data, $columnIndexes['longitude'] ?? null);

                $latitude = self::toFloat($latitudeValue);
                $longitude = self::toFloat($longitudeValue);

                $categoryValue = self::extractValue($data, $columnIndexes['category'] ?? null);
                $category = self::normalizeString($categoryValue);

                $existingRisk = self::extractNumeric(self::extractValue($data, $columnIndexes['risk_score'] ?? null));

                if ($analysis['has_numeric_risk'] && $existingRisk !== null) {
                    $risk = max(0.0, min(1.0, $existingRisk));
                } else {
                    $risk = self::computeRiskScore($category, $timestamp, $analysis);
                }

                $maxRisk = max($maxRisk, $risk);

                $risk = max(0.0, min(1.0, $risk));

                $rawLabelValue = self::extractValue($data, $columnIndexes['label'] ?? null);
                $rawLabel = self::extractNumeric($rawLabelValue);
                $rawLabels[] = $rawLabel !== null ? (int) round($rawLabel) : null;

                $risks[] = $risk;

                $rowFeatures = [$hour, $dayOfWeek, $latitude, $longitude, $risk];

                if ($categoryCount > 0) {
                    $encoded = array_fill(0, $categoryCount, 0.0);

                    if ($category !== '' && array_key_exists($category, $categoryIndex)) {
                        $encoded[$categoryIndex[$category]] = 1.0;
                    }

                    $rowFeatures = array_merge($rowFeatures, $encoded);
                }

                $features[] = $rowFeatures;

                if ($includeTimestamps) {
                    $timestamps[] = $timestamp;
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'timestamps' => $timestamps,
            'features' => $features,
            'raw_labels' => $rawLabels,
            'risks' => $risks,
            'max_risk' => $maxRisk,
        ];
    }

    /**
     * @param list<float> $risks
     * @param list<int|null> $rawLabels
     *
     * @return list<int>
     */
    private static function resolveLabels(array $risks, array $rawLabels, float $maxRisk): array
    {
        $labels = [];
        $needsGenerated = false;
        $positiveCount = 0;

        foreach ($rawLabels as $index => $value) {
            if ($value === null) {
                $labels[$index] = null;
                $needsGenerated = true;

                continue;
            }

            $resolved = (int) round($value);
            $resolved = $resolved > 0 ? 1 : 0;

            $labels[$index] = $resolved;

            if ($resolved === 1) {
                $positiveCount++;
            }
        }

        if (! $needsGenerated) {
            if ($positiveCount === 0 && $maxRisk > 0.0) {
                foreach ($labels as $index => $label) {
                    if ($risks[$index] === $maxRisk) {
                        $labels[$index] = 1;
                        $positiveCount++;
                    }
                }
            }

            return array_map(static fn ($value) => $value ?? 0, $labels);
        }

        $threshold = self::determineRiskThreshold($risks);

        foreach ($labels as $index => $label) {
            if ($label === null) {
                $generated = ($risks[$index] >= $threshold && $risks[$index] > 0.0) ? 1 : 0;
                $labels[$index] = $generated;

                if ($generated === 1) {
                    $positiveCount++;
                }

                continue;
            }

            if ($label === 1) {
                $positiveCount++;
            }
        }

        if ($positiveCount === 0 && $maxRisk > 0.0) {
            foreach ($labels as $index => $label) {
                if ($risks[$index] === $maxRisk) {
                    $labels[$index] = 1;
                }
            }
        }

        return array_map(static fn ($value) => $value ?? 0, $labels);
    }

    /**
     * @param list<float> $risks
     */
    private static function determineRiskThreshold(array $risks): float
    {
        if ($risks === []) {
            return 0.0;
        }

        $histogram = array_fill(0, 101, 0);

        foreach ($risks as $risk) {
            $clamped = max(0.0, min(1.0, $risk));
            $bin = (int) floor($clamped * 100);
            $bin = max(0, min(100, $bin));
            $histogram[$bin]++;
        }

        $activeBins = 0;

        foreach ($histogram as $count) {
            if ($count > 0) {
                $activeBins++;
            }
        }

        if ($activeBins <= 1) {
            return 1.1;
        }

        $totalCount = count($risks);
        $targetIndex = (int) floor(0.75 * max($totalCount - 1, 0));
        $targetRank = $targetIndex + 1;
        $cumulative = 0;

        foreach ($histogram as $bin => $count) {
            $cumulative += $count;

            if ($cumulative >= $targetRank) {
                return $bin / 100;
            }
        }

        return 0.0;
    }

    private static function computeRiskScore(string $category, CarbonImmutable $timestamp, array $analysis): float
    {
        $counts = $analysis['category_counts'];
        $count = $category !== '' ? ($counts[$category] ?? 0) : 0;

        if ($category === '' && $counts !== []) {
            $count = $analysis['min_count'];
        }

        if ($analysis['max_count'] === $analysis['min_count']) {
            $categoryScore = $analysis['max_count'] > 0 ? 0.5 : 0.0;
        } else {
            $denominator = max($analysis['max_count'] - $analysis['min_count'], 1);
            $categoryScore = ($count - $analysis['min_count']) / $denominator;
        }

        $recencyScore = 0.5;

        if ($analysis['time_span'] !== null && $analysis['time_span'] > 0 && $analysis['min_time'] !== null) {
            $recencyScore = ($timestamp->getTimestamp() - $analysis['min_time']) / $analysis['time_span'];
            $recencyScore = max(0.0, min(1.0, $recencyScore));
        }

        $score = (0.6 * $categoryScore) + (0.4 * $recencyScore);

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param array<int, string|null> $row
     *
     * @return list<string>
     */
    private static function normalizeHeaderRow(array $row): array
    {
        $normalized = [];
        $used = [];

        foreach ($row as $value) {
            if (! is_string($value)) {
                $normalized[] = '';
                continue;
            }

            $column = self::normalizeColumnName($value);

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

    private static function normalizeColumnName(string $column): string
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

    /**
     * @param list<string> $header
     * @param array<string, string> $columnMap
     *
     * @return array<string, int|null>
     */
    private static function mapColumnIndexes(array $header, array $columnMap): array
    {
        $indexes = [];
        $headerIndexMap = [];

        foreach ($header as $index => $column) {
            if ($column === '') {
                continue;
            }

            $headerIndexMap[$column] = $index;
        }

        foreach ($columnMap as $logical => $column) {
            if (! is_string($column) || $column === '') {
                $indexes[$logical] = null;
                continue;
            }

            $indexes[$logical] = $headerIndexMap[$column] ?? null;
        }

        return $indexes;
    }

    /**
     * @param array<string, int|null> $indexes
     */
    private static function assertRequiredColumns(array $indexes): void
    {
        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $required) {
            if (! array_key_exists($required, $indexes) || $indexes[$required] === null) {
                throw new RuntimeException(sprintf('Dataset is missing required column "%s".', $required));
            }
        }
    }

    /**
     * @param list<string|null> $row
     */
    private static function extractValue(array $row, ?int $index): mixed
    {
        if ($index === null) {
            return null;
        }

        return $row[$index] ?? null;
    }

    private static function normalizeString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function extractNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }

            return (float) $trimmed;
        }

        return null;
    }

    private static function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $numeric = trim($value);

            if ($numeric === '') {
                return 0.0;
            }

            return is_numeric($numeric) ? (float) $numeric : 0.0;
        }

        return 0.0;
    }
}
