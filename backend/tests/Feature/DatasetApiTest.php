<?php

namespace Tests\Feature;

use App\Enums\CrimeIngestionStatus;
use App\Enums\DatasetStatus;
use App\Enums\Role;
use App\Models\CrimeIngestionRun;
use App\Models\Dataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatasetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_ingest_accepts_file_upload(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('dataset.csv', 10, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'Test Dataset',
            'description' => 'Factory dataset',
            'source_type' => 'file',
            'file' => $file,
            'metadata' => ['ingested_via' => 'test'],
        ]);

        $response->assertCreated();

        $data = $response->json();
        $this->assertSame('Test Dataset', $data['name']);
        $this->assertNotNull($data['file_path']);
        $this->assertSame(0, $data['features_count']);

        Storage::disk('local')->assertExists($data['file_path']);

        $this->assertDatabaseHas('datasets', [
            'name' => 'Test Dataset',
            'status' => 'ready',
        ]);
    }

    public function test_dataset_ingest_accepts_excel_mime_for_csv_uploads(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('dataset.csv', 10, 'application/vnd.ms-excel');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'Excel CSV Dataset',
            'description' => 'Spreadsheet export',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertCreated();

        $data = $response->json();
        Storage::disk('local')->assertExists($data['file_path']);
    }

    /**
     * @throws \JsonException
     */
    public function test_dataset_ingest_accepts_metadata_json_string(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('dataset.csv', 10, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $metadata = json_encode(['submittedAt' => '2025-09-22T19:36:16Z'], JSON_THROW_ON_ERROR);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'Metadata Dataset',
            'source_type' => 'file',
            'file' => $file,
            'metadata' => $metadata,
        ]);

        $response->assertCreated();

        $this->assertSame(
            ['submittedAt' => '2025-09-22T19:36:16Z'],
            $response->json('metadata')
        );
    }

    public function test_dataset_ingest_infers_missing_name_and_source_type_from_file_upload(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('crime-data-export.csv', 10, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'file' => $file,
            'metadata' => ['ingested_via' => 'wizard'],
        ]);

        $response->assertCreated();

        $response->assertJsonPath('name', 'crime-data-export');
        $response->assertJsonPath('source_type', 'file');
    }

    public function test_dataset_ingest_rejects_geojson_with_non_wgs84_crs(): void
    {
        Storage::fake('local');

        $geoJson = json_encode([
            'type' => 'FeatureCollection',
            'crs' => [
                'type' => 'name',
                'properties' => ['name' => 'EPSG:3857'],
            ],
            'features' => [[
                'type' => 'Feature',
                'properties' => [],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [-3.2, 53.4],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $file = UploadedFile::fake()->createWithContent('dataset.geojson', $geoJson, 'application/geo+json');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'GeoJSON Dataset',
            'description' => 'Invalid CRS',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * @throws \JsonException
     */
    public function test_dataset_ingest_rejects_geojson_with_invalid_geometry(): void
    {
        Storage::fake('local');

        $geoJson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => [],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [-3.0, 53.0],
                        [-3.1, 53.1],
                        [-3.2, 53.2],
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $file = UploadedFile::fake()->createWithContent('dataset.geojson', $geoJson, 'application/geo+json');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'GeoJSON Dataset',
            'description' => 'Invalid geometry',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    /**
     * @throws \JsonException
     */
    public function test_dataset_ingest_accepts_geojson_with_wgs84_coordinates(): void
    {
        Storage::fake('local');

        $geoJson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Sample'],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [-3.0, 53.0],
                        [-2.9, 53.05],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $file = UploadedFile::fake()->createWithContent('dataset.geojson', $geoJson, 'application/geo+json');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'GeoJSON Dataset',
            'description' => 'Valid geometry',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('datasets', [
            'name' => 'GeoJSON Dataset',
        ]);
    }

    public function test_runs_endpoint_supports_pagination_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        CrimeIngestionRun::factory()->create([
            'id' => 1,
            'status' => CrimeIngestionStatus::Completed,
            'dry_run' => false,
            'started_at' => now(),
            'records_expected' => 200,
        ]);

        CrimeIngestionRun::factory()->create([
            'id' => 2,
            'status' => CrimeIngestionStatus::Completed,
            'dry_run' => true,
            'started_at' => now()->subDay(),
            'records_expected' => 150,
        ]);

        CrimeIngestionRun::factory()->create([
            'id' => 3,
            'status' => CrimeIngestionStatus::Failed,
            'dry_run' => false,
            'started_at' => now()->subDays(2),
        ]);

        $query = http_build_query([
            'per_page' => 2,
            'sort' => '-records_expected',
            'filter' => [
                'status' => 'completed',
                'dry_run' => 'false',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/datasets/runs?'.$query);

        $response->assertOk();

        $payload = $response->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame(200, $payload['data'][0]['records_expected']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertNull($payload['links']['next']);
        $this->assertNotNull($payload['links']['first']);
    }

    public function test_dataset_index_supports_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $matching = Dataset::factory()->create([
            'name' => 'Alpha Observations',
            'status' => DatasetStatus::Ready,
            'source_type' => 'file',
        ]);

        Dataset::query()->whereKey($matching->getKey())->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $matching->refresh();

        $other = Dataset::factory()->create([
            'name' => 'Beta Reference',
            'status' => DatasetStatus::Failed,
        ]);

        Dataset::query()->whereKey($other->getKey())->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $query = http_build_query([
            'per_page' => 10,
            'sort' => '-created_at',
            'filter' => [
                'status' => DatasetStatus::Ready->value,
                'search' => 'Alpha',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/datasets?'.$query);

        $response->assertOk();

        $payload = $response->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame($matching->id, $payload['data'][0]['id']);
        $this->assertSame('ready', $payload['data'][0]['status']);
        $this->assertArrayHasKey('features_count', $payload['data'][0]);
        $this->assertSame(0, $payload['data'][0]['features_count']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(10, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
    }
}
