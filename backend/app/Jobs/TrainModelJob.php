<?php

namespace App\Jobs;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\TrainingRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrainModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $hyperparameters
     */
    public function __construct(private readonly string $trainingRunId, private readonly ?array $hyperparameters = null)
    {
    }

    public function handle(): void
    {
        $run = TrainingRun::query()->with('model')->findOrFail($this->trainingRunId);
        $model = $run->model;

        if ($model === null) {
            Log::warning('Training run without associated model', ['training_run_id' => $run->id]);

            return;
        }

        $run->fill([
            'status' => TrainingStatus::Running,
            'started_at' => now(),
            'error_message' => null,
            'hyperparameters' => $this->hyperparameters ?? $run->hyperparameters,
        ])->save();

        $model->fill([
            'status' => ModelStatus::Training,
        ])->save();

        try {
            $metrics = [
                'accuracy' => random_int(60, 95) / 100,
                'precision' => random_int(60, 95) / 100,
                'recall' => random_int(60, 95) / 100,
            ];

            $run->fill([
                'status' => TrainingStatus::Completed,
                'finished_at' => now(),
                'metrics' => $metrics,
            ])->save();

            $model->fill([
                'status' => ModelStatus::Active,
                'trained_at' => now(),
                'metrics' => $metrics,
            ])->save();
        } catch (Throwable $exception) {
            $run->fill([
                'status' => TrainingStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $model->fill([
                'status' => ModelStatus::Failed,
            ])->save();

            Log::error('Training job failed', [
                'training_run_id' => $run->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
