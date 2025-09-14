<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\H3AggregationService;

class HexController extends Controller {
  public function index(Request $r, H3AggregationService $svc) {
    $bbox = $r->string('bbox') ?? abort(422,'bbox required');
    $res  = (int) ($r->integer('resolution') ?? 7);
    $from = $r->input('from');
    $to   = $r->input('to');

    $agg  = $svc->aggregateByBbox($bbox, $res, $from, $to);

    $cells = [];
    foreach ($agg as $h3 => $data) {
      $cells[] = ['h3'=>$h3, 'count'=>$data['count'], 'categories'=>$data['categories']];
    }
    return response()->json(['resolution'=>$res, 'cells'=>$cells]);
  }
}
