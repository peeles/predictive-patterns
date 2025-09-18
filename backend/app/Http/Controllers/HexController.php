<?php

namespace App\Http\Controllers;

use App\Services\H3GeometryService;
use App\Services\H3AggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HexController extends Controller
{
    /**
     * @param Request $r
     * @param H3AggregationService $svc
     *
     * @return JsonResponse
     */
    public function index(Request $r, H3AggregationService $svc): JsonResponse
    {
        $bbox = $r->string('bbox') ?? abort(422, 'bbox required');

        $res = (int)($r->integer('resolution') ?? 7);
        $from = $r->input('from');
        $to = $r->input('to');

        $agg = $svc->aggregateByBbox($bbox, $res, $from, $to);

        $cells = [];
        foreach ($agg as $h3 => $data) {
            $cells[] = ['h3' => $h3, 'count' => $data['count'], 'categories' => $data['categories']];
        }

        return response()->json(['resolution' => $res, 'cells' => $cells]);
    }

    /**
     * @param Request $r
     * @param H3AggregationService $svc
     * @param H3GeometryService $geo
     *
     * @return JsonResponse
     */
    public function geojson(Request $r, H3AggregationService $svc, H3GeometryService $geo): JsonResponse
    {
        $bbox = $r->string('bbox') ?? abort(422, 'bbox required');

        $res = (int)($r->integer('resolution') ?? 7);
        $from = $r->input('from');
        $to = $r->input('to');

        $agg = $svc->aggregateByBbox($bbox, $res, $from, $to);

        $features = [];
        foreach ($agg as $h3 => $data) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'h3' => $h3,
                    'count' => $data['count'],
                    'categories' => $data['categories'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        $geo->polygonCoordinates($h3)
                    ]
                ]
            ];
        }

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
