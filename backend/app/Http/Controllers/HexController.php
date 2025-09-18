<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\HexAggregate;
use App\Http\Requests\HexAggregationRequest;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Http\JsonResponse;

/**
 * REST endpoints exposing aggregated H3 cell metrics and GeoJSON polygons.
 */
class HexController extends Controller
{
    /**
     * Return aggregated counts for each H3 cell within the requested bounding box.
     *
     * @return JsonResponse<array<string, mixed>>
     */
    public function index(HexAggregationRequest $request, H3AggregationService $service): JsonResponse
    {
        $aggregates = $service->aggregateByBoundingBox(
            $request->boundingBox(),
            $request->resolution(),
            $request->from(),
            $request->to()
        );

        return response()->json([
            'resolution' => $request->resolution(),
            'cells' => array_map(
                static fn (HexAggregate $aggregate): array => [
                    'h3' => $aggregate->h3Index,
                    'count' => $aggregate->count,
                    'categories' => $aggregate->categories,
                ],
                $aggregates
            ),
        ]);
    }

    /**
     * Return a GeoJSON feature collection describing the aggregated cells.
     *
     * @return JsonResponse<array<string, mixed>>
     */
    public function geojson(
        HexAggregationRequest $request,
        H3AggregationService $aggregationService,
        H3GeometryService $geometryService
    ): JsonResponse {
        $aggregates = $aggregationService->aggregateByBoundingBox(
            $request->boundingBox(),
            $request->resolution(),
            $request->from(),
            $request->to()
        );

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => array_map(
                static function (HexAggregate $aggregate) use ($geometryService): array {
                    return [
                        'type' => 'Feature',
                        'properties' => [
                            'h3' => $aggregate->h3Index,
                            'count' => $aggregate->count,
                            'categories' => $aggregate->categories,
                        ],
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => [
                                $geometryService->polygonCoordinates($aggregate->h3Index),
                            ],
                        ],
                    ];
                },
                $aggregates
            ),
        ]);
    }
}
