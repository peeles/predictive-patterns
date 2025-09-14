<?php
namespace App\MCP;

use App\Jobs\IngestPoliceCrimes;
use App\Services\H3AggregationService;

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
          default => ['error' => 'unknown_tool']
        }
      ];
    } catch (\Throwable $e) {
      return ['error' => 'bad_request', 'message' => $e->getMessage()];
    }
  }

  private function aggregate(array $a): array {
    $bbox = $a['bbox'] ?? throw new \InvalidArgumentException('bbox required');
    $res  = (int)($a['resolution'] ?? 7);
    $from = $a['from'] ?? null;
    $to   = $a['to'] ?? null;
    $svc  = app(H3AggregationService::class);
    $agg  = $svc->aggregateByBbox($bbox, $res, $from, $to);

    $cells = [];
    foreach ($agg as $h3 => $data) {
      $cells[] = ['h3'=>$h3,'count'=>$data['count'],'categories'=>$data['categories']];
    }
    return ['resolution'=>$res,'cells'=>$cells];
  }

  private function ingest(array $a): array {
    $ym = $a['ym'] ?? throw new \InvalidArgumentException('ym required YYYY-MM');
    dispatch(new IngestPoliceCrimes($ym));
    return ['status'=>'queued','ym'=>$ym];
  }

  private function export(array $a): array {
    $res = (int)($a['resolution'] ?? 7);
    $bbox= $a['bbox'] ?? throw new \InvalidArgumentException('bbox required');
    $agg = app(H3AggregationService::class)->aggregateByBbox($bbox, $res, $a['from'] ?? null, $a['to'] ?? null);
    $features = [];
    foreach ($agg as $h3 => $data) {
      $features[] = ['type'=>'Feature','properties'=>['h3'=>$h3,'count'=>$data['count']],'geometry'=>null];
    }
    return ['type'=>'FeatureCollection','features'=>$features];
  }
}
