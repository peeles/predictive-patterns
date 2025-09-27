<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

class DatasetRiskLabelGenerator
{
    /**
     * Ensure dataset rows contain usable risk_score and label columns.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $columnMap
     *
     * @return void
     */
    public static function ensureColumns(array &$rows, array $columnMap): void
    {
        if ($rows === []) {
            return;
        }

        $riskColumn = $columnMap['risk_score'] ?? 'risk_score';
        $labelColumn = $columnMap['label'] ?? 'label';

        $needsRisk = false;
        $needsLabel = false;

        foreach ($rows as $row) {
            if (! self::hasNumericValue($row, $riskColumn)) {
                $needsRisk = true;
            }

            if (! self::hasNumericValue($row, $labelColumn)) {
                $needsLabel = true;
            }

            if ($needsRisk && $needsLabel) {
                break;
            }
        }

        if (! $needsRisk && ! $needsLabel) {
            self::normalizeExistingValues($rows, $riskColumn, $labelColumn);

            return;
        }

        $stats = self::collectDatasetStats($rows, $columnMap);

        $totalCount = count($rows);
        $histogram = $needsLabel ? array_fill(0, 101, 0) : null;
        $maxRiskValue = 0.0;

        foreach ($rows as $index => &$row) {
            $existingRisk = self::extractNumeric($row[$riskColumn] ?? null);

            $risk = $existingRisk !== null
                ? max(0.0, min(1.0, $existingRisk))
                : self::computeRiskScore($row, $columnMap, $stats);

            $risk = max(0.0, min(1.0, $risk));

            $row[$riskColumn] = $risk;
            $maxRiskValue = max($maxRiskValue, $risk);

            if ($needsLabel && $histogram !== null) {
                $bin = (int) floor($risk * 100);
                $bin = max(0, min(100, $bin));
                $histogram[$bin]++;
            }
        }
        unset($row);

        if ($needsLabel) {
            $threshold = self::resolveRiskThreshold($histogram ?? [], $totalCount);
            $positiveCount = 0;

            foreach ($rows as &$row) {
                $existingLabel = self::extractNumeric($row[$labelColumn] ?? null);

                if ($existingLabel !== null) {
                    $label = (int) round($existingLabel);
                } else {
                    $risk = (float) ($row[$riskColumn] ?? 0.0);
                    $label = ($risk >= $threshold && $risk > 0.0) ? 1 : 0;
                }

                if ($label > 0) {
                    $positiveCount++;
                }

                $row[$labelColumn] = $label;
            }
            unset($row);

            if ($positiveCount === 0 && $maxRiskValue > 0.0) {
                foreach ($rows as &$row) {
                    if ((float) ($row[$riskColumn] ?? 0.0) === $maxRiskValue) {
                        $row[$labelColumn] = 1;
                    }
                }
                unset($row);
            }
        } else {
            foreach ($rows as &$row) {
                $row[$labelColumn] = (int) round((float) ($row[$labelColumn] ?? 0));
            }
            unset($row);
        }

        return;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $riskColumn
     * @param string $labelColumn
     *
     * @return void
     */
    private static function normalizeExistingValues(array &$rows, string $riskColumn, string $labelColumn): void
    {
        foreach ($rows as &$row) {
            $risk = self::extractNumeric($row[$riskColumn] ?? null);
            $label = self::extractNumeric($row[$labelColumn] ?? null);

            if ($risk !== null) {
                $row[$riskColumn] = $risk;
            }

            if ($label !== null) {
                $row[$labelColumn] = (int) round($label);
            }
        }
        unset($row);
    }

    private static function hasNumericValue(array $row, string $column): bool
    {
        if (! array_key_exists($column, $row)) {
            return false;
        }

        return self::extractNumeric($row[$column]) !== null;
    }

    private static function extractNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (! is_numeric($trimmed)) {
                return null;
            }

            return (float) $trimmed;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     category_counts: array<string, int>,
     *     min_count: int,
     *     max_count: int,
     *     min_time: int|null,
     *     max_time: int|null,
     *     time_span: int|null
     * }
     */
    private static function collectDatasetStats(array $rows, array $columnMap): array
    {
        $categoryKey = $columnMap['category'] ?? 'category';
        $timestampKey = $columnMap['timestamp'] ?? 'timestamp';

        $categoryCounts = [];
        $minTime = null;
        $maxTime = null;

        foreach ($rows as $row) {
            $category = self::normalizeString($row[$categoryKey] ?? '');

            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }

            $timestamp = self::parseTimestamp($row[$timestampKey] ?? null);

            if ($timestamp instanceof CarbonImmutable) {
                $timestampValue = $timestamp->getTimestamp();
                $minTime = $minTime === null ? $timestampValue : min($minTime, $timestampValue);
                $maxTime = $maxTime === null ? $timestampValue : max($maxTime, $timestampValue);
            }
        }

        if ($categoryCounts === []) {
            $categoryCounts['__default__'] = count($rows);
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $columnMap
     * @param array{
     *     category_counts: array<string, int>,
     *     min_count: int,
     *     max_count: int,
     *     min_time: int|null,
     *     max_time: int|null,
     *     time_span: int|null
     * } $stats
     */
    private static function computeRiskScore(array $row, array $columnMap, array $stats): float
    {
        $categoryKey = $columnMap['category'] ?? 'category';
        $timestampKey = $columnMap['timestamp'] ?? 'timestamp';

        $category = self::normalizeString($row[$categoryKey] ?? '');
        $categoryCounts = $stats['category_counts'];

        $count = $category !== '' ? ($categoryCounts[$category] ?? 0) : 0;

        if ($category === '' && $categoryCounts !== []) {
            $count = $stats['min_count'];
        }

        if ($stats['max_count'] === $stats['min_count']) {
            $categoryScore = $stats['max_count'] > 0 ? 0.5 : 0.0;
        } else {
            $denominator = max($stats['max_count'] - $stats['min_count'], 1);
            $categoryScore = ($count - $stats['min_count']) / $denominator;
        }

        $recencyScore = 0.5;

        if ($stats['time_span'] !== null && $stats['time_span'] > 0) {
            $timestamp = self::parseTimestamp($row[$timestampKey] ?? null);

            if ($timestamp instanceof CarbonImmutable && $stats['min_time'] !== null) {
                $recencyScore = ($timestamp->getTimestamp() - $stats['min_time']) / $stats['time_span'];
            }
        }

        $score = (0.6 * $categoryScore) + (0.4 * $recencyScore);

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param array<int, int> $histogram
     */
    private static function resolveRiskThreshold(array $histogram, int $totalCount): float
    {
        if ($totalCount === 0) {
            return 0.0;
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

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::createFromInterface($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed);
        }
        catch (Throwable) {
            return null;
        }
    }
}

