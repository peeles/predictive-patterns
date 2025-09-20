<?php

namespace Tests\Feature;

use App\Enums\Role;
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
}
