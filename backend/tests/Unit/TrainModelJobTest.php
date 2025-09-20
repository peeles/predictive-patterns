<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Jobs\TrainModelJob;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TrainModelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_persists_metrics_and_artifact_reference(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/job.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
            'metrics' => null,
            'hyperparameters' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $job = new TrainModelJob($run->id, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $job->handle(app(ModelTrainingService::class));

        $run->refresh();
        $model->refresh();

        $this->assertEquals(TrainingStatus::Completed, $run->status);
        $this->assertEquals(ModelStatus::Active, $model->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($model->trained_at);
        $this->assertEquals(['accuracy', 'precision', 'recall', 'f1'], array_keys($model->metrics));
        $this->assertEquals(1.0, $model->metrics['accuracy']);
        $this->assertEquals(1.0, $run->metrics['accuracy']);
        $this->assertSame($run->metrics, $model->metrics);
        $this->assertNotEmpty($model->version);
        $this->assertNotEmpty($model->metadata['artifact_path']);
        $this->assertTrue(Storage::disk('local')->exists($model->metadata['artifact_path']));
        $this->assertSame(0.25, $model->hyperparameters['learning_rate']);
        $this->assertSame($model->hyperparameters, $run->hyperparameters);
    }

    public function test_handle_marks_run_failed_when_training_service_throws(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing-job.csv',
        ]);

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $job = new TrainModelJob($run->id);

        try {
            $job->handle(app(ModelTrainingService::class));
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Dataset file', $exception->getMessage());
        }

        $run->refresh();
        $model->refresh();

        $this->assertEquals(TrainingStatus::Failed, $run->status);
        $this->assertEquals(ModelStatus::Failed, $model->status);
        $this->assertNotNull($run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    private function datasetCsv(): string
    {
        return implode("\n", [
            'timestamp,latitude,longitude,category,risk_score,label',
            '2024-01-01T00:00:00Z,40.0,-73.9,burglary,0.10,0',
            '2024-01-02T00:00:00Z,40.0,-73.9,burglary,0.12,0',
            '2024-01-03T00:00:00Z,40.0,-73.9,burglary,0.14,0',
            '2024-01-04T00:00:00Z,40.0,-73.9,burglary,0.18,0',
            '2024-01-05T00:00:00Z,40.0,-73.9,assault,0.72,1',
            '2024-01-06T00:00:00Z,40.0,-73.9,assault,0.74,1',
            '2024-01-07T00:00:00Z,40.0,-73.9,assault,0.78,1',
            '2024-01-08T00:00:00Z,40.0,-73.9,assault,0.82,1',
            '2024-01-09T00:00:00Z,40.0,-73.9,burglary,0.28,0',
            '2024-01-10T00:00:00Z,40.0,-73.9,assault,0.88,1',
            '',
        ]);
    }
}
