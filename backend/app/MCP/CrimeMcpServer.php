<?php
namespace App\MCP;

use App\Jobs\IngestPoliceCrimes;
use App\Models\Crime;
use App\Services\H3AggregationService;
use InvalidArgumentException;
use Throwable;

class CrimeMcpServer {
    public function run(): void {
        while (($line = fgets(STDIN)) !== false) {
            $resp = $this->dispatch($line);
            fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_SLASHES).PHP_EOL);
            fflush(STDOUT);
        }
    }

    private function dispatch(string $raw): array {
        try {
            $msg = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
            $id  = $msg['id'] ?? null;
            $tool= $msg['tool'] ?? null;
            $args= $msg['arguments'] ?? [];

            return [
                'id' => $id,
                'result' => match ($tool) {
                    'aggregate_hexes' => $this->aggregate($args),
                    'ingest_crime_data' => $this->ingest($args),
                    'export_geojson' => $this->export($args),
                    'get_categories' => $this->categories(),
                    'list_ingested_months' => $this->ingestedMonths(),
                    'get_top_cells' => $this->topCells($args),
                    default => ['error' => 'unknown_tool']
                }
            ];
        } catch (Throwable $e) {
            return ['error' => 'bad_request', 'message' => $e->getMessage()];
        }
    }

    private function aggregate(array $a): array {
        $bbox = $a['bbox'] ?? throw new InvalidArgumentException('bbox required');
        $res  = (int)($a['resolution'] ?? 7);
        $from = $a['from'] ?? null;
        $to   = $a['to'] ?? null;
        $svc  = app(H3AggregationService::class);
        $agg  = $svc->aggregateByBbox($bbox, $res, $from, $to, $a['crime_type'] ?? null);

        $cells = [];
        foreach ($agg as $h3 => $data) {
            $cells[] = ['h3'=>$h3,'count'=>$data['count'],'categories'=>$data['categories']];
        }
        return ['resolution'=>$res,'cells'=>$cells];
    }

    private function ingest(array $a): array {
        $ym = $a['ym'] ?? throw new InvalidArgumentException('ym required YYYY-MM');
        dispatch(new IngestPoliceCrimes($ym));
        return ['status'=>'queued','ym'=>$ym];
    }

    private function export(array $a): array {
        $res = (int)($a['resolution'] ?? 7);
        $bbox= $a['bbox'] ?? throw new InvalidArgumentException('bbox required');
        $agg = app(H3AggregationService::class)->aggregateByBbox($bbox, $res, $a['from'] ?? null, $a['to'] ?? null, $a['crime_type'] ?? null);
        $features = [];
        foreach ($agg as $h3 => $data) {
            $features[] = ['type'=>'Feature','properties'=>['h3'=>$h3,'count'=>$data['count']],'geometry'=>null];
        }
        return ['type'=>'FeatureCollection','features'=>$features];
    }

    private function categories(): array {
        $categories = Crime::query()
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        return ['categories' => $categories];
    }

    private function ingestedMonths(): array {
        $rows = Crime::query()
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')
            ->orderBy('ym', 'desc')
            ->get()
            ->map(fn ($row) => ['month' => (string) $row->ym, 'count' => (int) ($row->c ?? 0)])
            ->all();

        return ['months' => $rows];
    }

    private function topCells(array $args): array {
        $bbox = $args['bbox'] ?? '-180,-90,180,90';
        $resolution = (int)($args['resolution'] ?? 7);
        $limit = max(1, (int)($args['limit'] ?? 5));
        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;
        $crimeType = $args['crime_type'] ?? null;

        $agg = app(H3AggregationService::class)->aggregateByBbox($bbox, $resolution, $from, $to, $crimeType);
        $cells = [];
        foreach ($agg as $h3 => $payload) {
            $cells[] = ['h3' => $h3, 'count' => $payload['count'], 'categories' => $payload['categories']];
        }

        usort($cells, static fn($a, $b) => $b['count'] <=> $a['count']);
        $cells = array_slice($cells, 0, $limit);

        return [
            'resolution' => $resolution,
            'limit' => $limit,
            'filters' => array_filter([
                'bbox' => $bbox,
                'from' => $from,
                'to' => $to,
                'crime_type' => $crimeType,
            ]),
            'cells' => $cells,
        ];
    }
}
