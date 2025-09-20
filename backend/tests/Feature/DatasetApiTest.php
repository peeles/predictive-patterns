<?php

namespace Tests\Feature;

use App\Enums\CrimeIngestionStatus;
use App\Enums\Role;
use App\Models\CrimeIngestionRun;
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

        Storage::disk('local')->assertExists($data['file_path']);

        $this->assertDatabaseHas('datasets', [
            'name' => 'Test Dataset',
            'status' => 'ready',
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

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->getJson('/api/v1/datasets/runs', [
            'per_page' => 2,
            'sort' => '-records_expected',
            'filter' => [
                'status' => 'completed',
                'dry_run' => 'false',
            ],
        ]);

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
}
