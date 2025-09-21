<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Crime;
use App\Services\H3GeometryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class HeatmapTileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_tile_payload_with_time_filters(): void
    {
        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res7' => '87283080dffffff',
        ]);

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 18:00:00'),
            'lat' => 53.405,
            'lng' => -2.905,
            'h3_res7' => '87283080dffffff',
        ]);

        // Outside the requested horizon window and should be excluded
        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-04 09:00:00'),
            'lat' => 53.401,
            'lng' => -2.902,
            'h3_res7' => '87283080dffffff',
        ]);

        // Outside the tile bounds entirely
        Crime::factory()->create([
            'category' => 'theft',
            'occurred_at' => Carbon::parse('2024-03-01 11:00:00'),
            'lat' => 51.5,
            'lng' => -0.12,
            'h3_res7' => '8702a5fffffffff',
        ]);

        $mock = Mockery::mock(H3GeometryService::class);
        $mock->shouldReceive('polygonCoordinates')
            ->once()
            ->andReturn([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]]);

        $this->app->instance(H3GeometryService::class, $mock);

        $tokens = $this->issueTokensForRole(Role::Viewer);

        $response = $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/heatmap/9/251/165?ts_start=2024-03-01T00:00:00Z&horizon=48');

        $response->assertOk()
            ->assertJsonPath('meta.max_count', 2)
            ->assertJsonPath('meta.timeframe.from', '2024-03-01T00:00:00+00:00')
            ->assertJsonPath('meta.timeframe.to', '2024-03-03T00:00:00+00:00')
            ->assertJsonPath('cells.0.count', 2)
            ->assertJsonPath('cells.0.h3', '87283080dffffff')
            ->assertJsonStructure([
                'z',
                'x',
                'y',
                'resolution',
                'meta' => [
                    'bounds' => ['west', 'south', 'east', 'north'],
                    'max_count',
                    'timeframe' => ['from', 'to'],
                    'horizon_hours',
                ],
                'cells' => [[
                    'h3',
                    'count',
                    'categories',
                    'centroid' => ['lat', 'lng'],
                    'polygon',
                ]],
            ]);
    }

    public function test_rejects_out_of_range_tiles(): void
    {
        $tokens = $this->issueTokensForRole(Role::Viewer);

        $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/heatmap/2/8/1')
            ->assertStatus(422);
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/heatmap/9/251/165')
            ->assertUnauthorized();
    }
}
