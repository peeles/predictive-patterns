<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Crime;
use App\Services\H3GeometryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class HexAggregationTest extends TestCase
{
    private const TOKEN = 'test-token';

    use RefreshDatabase;

    public function test_returns_aggregated_counts_for_bbox(): void
    {
        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res6' => '86052c07fffffff',
        ]);

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 11:00:00'),
            'lat' => 53.41,
            'lng' => -2.91,
            'h3_res6' => '86052c07fffffff',
        ]);

        Crime::factory()->create([
            'category' => 'assault',
            'occurred_at' => Carbon::parse('2024-02-10 09:00:00'),
            'lat' => 51.5,
            'lng' => -0.12,
            'h3_res6' => '8702a5fffffffff',
        ]);

        $response = $this->withToken(self::TOKEN)
            ->getJson('/api/hexes?bbox=-3,53,0,55&resolution=6&from=2024-03-01&to=2024-03-31');

        $response->assertOk()
            ->assertJson([
                'resolution' => 6,
                'cells' => [
                    [
                        'h3' => '86052c07fffffff',
                        'count' => 2,
                        'categories' => ['burglary' => 2],
                    ],
                ],
            ]);

        $response->assertJsonMissing(['h3' => '8702a5fffffffff']);
    }

    public function test_geojson_response_includes_polygon_coordinates(): void
    {
        Crime::factory()->create([
            'category' => 'theft',
            'occurred_at' => Carbon::parse('2024-01-15 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res6' => '86052c07fffffff',
        ]);

        $mock = Mockery::mock(H3GeometryService::class);
        $mock->shouldReceive('polygonCoordinates')
            ->andReturn([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]])
            ->atLeast()->once();

        $this->app->instance(H3GeometryService::class, $mock);

        $response = $this->withToken(self::TOKEN)
            ->getJson('/api/hexes/geojson?bbox=-3,53,0,55&resolution=6');

        $response->assertOk()
            ->assertJsonStructure([
                'type',
                'features' => [
                    [
                        'type',
                        'properties' => ['h3', 'count', 'categories'],
                        'geometry' => ['type', 'coordinates'],
                    ],
                ],
            ]);
    }

    public function test_validation_errors_are_returned_for_invalid_input(): void
    {
        $this->withToken(self::TOKEN)->getJson('/api/hexes?bbox=invalid')
            ->assertStatus(422);

        $this->withToken(self::TOKEN)->getJson('/api/hexes?bbox=-3,53,0,55&resolution=2')
            ->assertStatus(422);

        $this->withToken(self::TOKEN)->getJson('/api/hexes?bbox=-3,53,0,55&from=2024-05-01&to=2024-04-01')
            ->assertStatus(422);
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/hexes?bbox=-3,53,0,55')
            ->assertUnauthorized();
    }
}
