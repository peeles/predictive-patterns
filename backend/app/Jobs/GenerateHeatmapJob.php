<?php

namespace App\Jobs;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Models\Prediction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateHeatmapJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $predictionId,
        private readonly array $parameters,
        private readonly bool $generateTiles = false,
    ) {
    }

    public function handle(): void
    {
        $prediction = Prediction::query()->findOrFail($this->predictionId);

        $prediction->fill([
            'status' => PredictionStatus::Running,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $payload = [
                'prediction_id' => $prediction->id,
                'generated_at' => now()->toIso8601String(),
                'parameters' => $this->parameters,
                'summary' => [
                    'mean_score' => random_int(10, 90) / 100,
                    'max_score' => random_int(50, 100) / 100,
                ],
            ];

            $prediction->outputs()->create([
                'id' => (string) Str::uuid(),
                'format' => PredictionOutputFormat::Json,
                'payload' => $payload,
            ]);

            if ($this->generateTiles) {
                $prediction->outputs()->create([
                    'id' => (string) Str::uuid(),
                    'format' => PredictionOutputFormat::Tiles,
                    'tileset_path' => sprintf('tiles/%s/%s', now()->format('Ymd'), $prediction->id),
                ]);
            }

            $prediction->fill([
                'status' => PredictionStatus::Completed,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::error('Failed to generate prediction output', [
                'prediction_id' => $this->predictionId,
                'exception' => $exception->getMessage(),
            ]);

            $prediction->fill([
                'status' => PredictionStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
