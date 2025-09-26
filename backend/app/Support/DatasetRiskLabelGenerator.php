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
     * @return array<int, array<string, mixed>>
     */
    public static function ensureColumns(array $rows, array $columnMap): array
    {
        if ($rows === []) {
            return $rows;
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
            return self::normalizeExistingValues($rows, $riskColumn, $labelColumn);
        }

        $computedRiskScores = self::buildRiskScores($rows, $columnMap);

        foreach ($rows as $index => $row) {
            $existingRisk = self::extractNumeric($row[$riskColumn] ?? null);

            $rows[$index][$riskColumn] = $existingRisk ?? $computedRiskScores[$index];
        }

        if ($needsLabel) {
            $riskValues = array_map(
                static fn (array $row) => (float) ($row[$riskColumn] ?? 0.0),
                $rows
            );

            $computedLabels = self::buildLabels($riskValues);

            foreach ($rows as $index => $row) {
                $existingLabel = self::extractNumeric($row[$labelColumn] ?? null);

                $rows[$index][$labelColumn] = $existingLabel !== null
                    ? (int) round($existingLabel)
                    : $computedLabels[$index];
            }
        } else {
            foreach ($rows as $index => $row) {
                $rows[$index][$labelColumn] = (int) round((float) ($row[$labelColumn] ?? 0));
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $riskColumn
     * @param string $labelColumn
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeExistingValues(array $rows, string $riskColumn, string $labelColumn): array
    {
        foreach ($rows as $index => $row) {
            $risk = self::extractNumeric($row[$riskColumn] ?? null);
            $label = self::extractNumeric($row[$labelColumn] ?? null);

            if ($risk !== null) {
                $rows[$index][$riskColumn] = $risk;
            }

            if ($label !== null) {
                $rows[$index][$labelColumn] = (int) round($label);
            }
        }

        return $rows;
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
     * @return array<int, float>
     */
    private static function buildRiskScores(array $rows, array $columnMap): array
    {
        $categoryKey = $columnMap['category'] ?? 'category';
        $timestampKey = $columnMap['timestamp'] ?? 'timestamp';

        $categoryCounts = [];
        $timestampValues = [];

        foreach ($rows as $index => $row) {
            $category = self::normalizeString($row[$categoryKey] ?? '');

            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }

            $timestamp = self::parseTimestamp($row[$timestampKey] ?? null);

            if ($timestamp instanceof CarbonImmutable) {
                $timestampValues[$index] = $timestamp->getTimestamp();
            }
        }

        if ($categoryCounts === []) {
            $categoryCounts['__default__'] = count($rows);
        }

        $maxCount = max($categoryCounts);
        $minCount = min($categoryCounts);

        $minTime = $timestampValues !== [] ? min($timestampValues) : null;
        $maxTime = $timestampValues !== [] ? max($timestampValues) : null;
        $timeSpan = ($minTime !== null && $maxTime !== null) ? max($maxTime - $minTime, 0) : null;

        $riskScores = [];

        foreach ($rows as $index => $row) {
            $category = self::normalizeString($row[$categoryKey] ?? '');
            $count = $category !== '' ? ($categoryCounts[$category] ?? 0) : 0;

            if ($category === '' && $categoryCounts !== []) {
                $count = $minCount;
            }

            if ($maxCount === $minCount) {
                $categoryScore = $maxCount > 0 ? 0.5 : 0.0;
            } else {
                $categoryScore = ($count - $minCount) / max($maxCount - $minCount, 1);
            }

            $recencyScore = 0.5;

            if ($timeSpan !== null && $timeSpan > 0 && array_key_exists($index, $timestampValues)) {
                $recencyScore = ($timestampValues[$index] - $minTime) / $timeSpan;
            }

            $score = (0.6 * $categoryScore) + (0.4 * $recencyScore);
            $riskScores[$index] = max(0.0, min(1.0, $score));
        }

        return $riskScores;
    }

    /**
     * @param list<float> $riskScores
     *
     * @return list<int>
     */
    private static function buildLabels(array $riskScores): array
    {
        $labelCount = count($riskScores);
        $labels = array_fill(0, $labelCount, 0);

        if ($labelCount === 0) {
            return $labels;
        }

        $uniqueValues = array_unique(array_map(static fn ($value) => round($value, 6), $riskScores));

        if (count($uniqueValues) <= 1) {
            return $labels;
        }

        $sorted = $riskScores;
        sort($sorted, SORT_NUMERIC);

        $thresholdIndex = (int) floor(0.75 * max($labelCount - 1, 0));
        $threshold = $sorted[$thresholdIndex];

        foreach ($riskScores as $index => $score) {
            if ($score >= $threshold && $score > 0.0) {
                $labels[$index] = 1;
            }
        }

        if (array_sum($labels) === 0) {
            $maxScore = max($riskScores);

            if ($maxScore > 0.0) {
                foreach ($riskScores as $index => $score) {
                    if ($score === $maxScore) {
                        $labels[$index] = 1;
                    }
                }
            }
        }

        return $labels;
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

