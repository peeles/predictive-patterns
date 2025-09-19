<?php

namespace App\Services;

use App\DataTransferObjects\HexAggregate;
use App\Models\Crime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Aggregates crime counts across H3 cells with optional temporal filtering.
 */
class H3AggregationService
{
    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];
    private const CACHE_PREFIX = 'h3_aggregations:';
    public const CACHE_VERSION_KEY = self::CACHE_PREFIX.'version';
    private const CACHE_TTL_MINUTES = 10;

    /**
     * Aggregate crimes across H3 cells intersecting the provided bounding box.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return HexAggregate[]
     *
     * @throws InvalidArgumentException When an unsupported resolution is supplied
     */
    public function aggregateByBoundingBox(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): array
    {
        if (!in_array($resolution, self::SUPPORTED_RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Unsupported resolution supplied.');
        }

        $cacheKey = $this->buildCacheKey($boundingBox, $resolution, $from, $to);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->runAggregateQuery($boundingBox, $resolution, $from, $to)
        );
    }

    /**
     * Apply from/to constraints onto the aggregate query if they are supplied.
     *
     * @param Builder $query
     * @param CarbonInterface|null $from
     * @param CarbonInterface|null $to
     */
    private function applyTemporalFilters(
        Builder          $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): void
    {
        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }
    }

    /**
     * Execute the aggregation query without caching.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *
     * @return HexAggregate[]
     */
    private function runAggregateQuery(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): array {
        [$west, $south, $east, $north] = $boundingBox;

        $query = Crime::query()
            ->whereBetween('lng', [$west, $east])
            ->whereBetween('lat', [$south, $north]);

        $this->applyTemporalFilters($query, $from, $to);

        $column = sprintf('h3_res%d', $resolution);

        return $query
            ->selectRaw("$column as h3, category, count(*) as c")
            ->groupBy($column, 'category')
            ->get()
            ->groupBy('h3')
            ->map(
                static function (Collection $rows) {
                    $first = $rows->first();
                    $h3 = (string)($first->h3 ?? '');
                    $count = (int)$rows->sum('c');
                    $categories = $rows
                        ->pluck('c', 'category')
                        ->map(static fn($value) => (int)$value)
                        ->toArray();

                    return new HexAggregate($h3, $count, $categories);
                }
            )
            ->values()
            ->all();
    }

    /**
     * Build a cache key that incorporates the filter parameters and version.
     *
     * @param array $boundingBox
     * @param int $resolution
     * @param CarbonInterface|null $from
     * @param CarbonInterface|null $to
     *
     * @return string
     */
    private function buildCacheKey(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): string {
        $normalizedBbox = array_map(
            static fn(mixed $value): string => number_format((float) $value, 6, '.', ''),
            $boundingBox
        );

        $fromKey = $from?->toIso8601String() ?? 'null';
        $toKey = $to?->toIso8601String() ?? 'null';

        $rawKey = implode('|', [
            implode(',', $normalizedBbox),
            (string) $resolution,
            $fromKey,
            $toKey,
        ]);

        $version = $this->getCacheVersion();

        return sprintf('%s%d:%s', self::CACHE_PREFIX, $version, md5($rawKey));
    }

    /**
     * Retrieve the current cache version, initialising it if necessary.
     *
     * @return int
     */
    private function getCacheVersion(): int
    {
        $version = Cache::get(self::CACHE_VERSION_KEY);

        if ($version === null) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);

            return 1;
        }

        return (int) $version;
    }
}

