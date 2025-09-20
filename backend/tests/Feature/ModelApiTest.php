<?php

namespace Tests\Feature;

use App\Enums\ModelStatus;
use App\Enums\Role;
use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ModelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['learning_rate' => 0.2],
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(TrainModelJob::class);

        $this->assertDatabaseHas('training_runs', [
            'model_id' => $model->id,
            'status' => 'queued',
        ]);
    }

    public function test_evaluation_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson("/api/v1/models/{$model->id}/evaluate", [
            'dataset_id' => $model->dataset_id,
            'metrics' => ['f1' => 0.82],
            'notes' => 'Smoke test',
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(EvaluateModelJob::class);
    }

    public function test_index_returns_paginated_collection_with_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        PredictiveModel::factory()->create([
            'id' => 'model-latest',
            'name' => 'Latest Model',
            'status' => ModelStatus::Active,
            'tag' => 'baseline',
            'trained_at' => now(),
            'updated_at' => now(),
        ]);

        PredictiveModel::factory()->create([
            'id' => 'model-older',
            'name' => 'Older Model',
            'status' => ModelStatus::Active,
            'tag' => 'baseline',
            'trained_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        PredictiveModel::factory()->create([
            'id' => 'model-other',
            'name' => 'Filtered Model',
            'status' => ModelStatus::Inactive,
            'tag' => 'baseline',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->getJson('/api/v1/models', [
            'page' => 1,
            'per_page' => 1,
            'sort' => '-trained_at',
            'filter' => [
                'status' => 'active',
                'tag' => 'baseline',
            ],
        ]);

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame('model-latest', $payload['data'][0]['id']);
        $this->assertSame(2, $payload['meta']['total']);
        $this->assertSame(1, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertNotEmpty($payload['links']['next']);

        $secondPage = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->getJson('/api/v1/models', [
            'page' => 2,
            'per_page' => 1,
            'sort' => '-trained_at',
            'filter' => [
                'status' => 'active',
                'tag' => 'baseline',
            ],
        ]);

        $secondPage->assertOk();

        $secondPayload = $secondPage->json();

        $this->assertSame('model-older', $secondPayload['data'][0]['id']);
        $this->assertNull($secondPayload['links']['next']);
    }
}
