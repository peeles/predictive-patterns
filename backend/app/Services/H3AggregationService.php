<?php
namespace App\Services;

use App\Models\Crime;
use Illuminate\Support\Collection;

class H3AggregationService {
  public function aggregateByBbox(string $bbox, int $res, ?string $from, ?string $to): array {
    [$w,$s,$e,$n] = array_map('floatval', explode(',', $bbox));
    $q = Crime::query()
      ->whereBetween('lng', [$w,$e])
      ->whereBetween('lat', [$s,$n]);

    if ($from) $q->where('occurred_at', '>=', $from);
    if ($to)   $q->where('occurred_at', '<=', $to);

    $col = "h3_res{$res}";
    if (!in_array($res,[6,7,8])) abort(422, 'Supported resolutions: 6,7,8');

    return $q->selectRaw("$col as h3, category, count(*) as c")
      ->groupBy($col,'category')
      ->get()
      ->groupBy('h3')
      ->map(fn(Collection $rows) => [
        'count' => $rows->sum('c'),
        'categories' => $rows->pluck('c','category')->toArray(),
      ])->toArray();
  }
}
