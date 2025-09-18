<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\HexAggregate;
use App\Models\Crime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Aggregates crime counts across H3 cells with optional temporal filtering.
 */
class H3AggregationService
{
    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];

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
}
