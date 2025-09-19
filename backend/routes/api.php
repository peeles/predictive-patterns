<?php

use App\Http\Controllers\Api\v1\DatasetController;
use App\Http\Controllers\Api\v1\ExportController;
use App\Http\Controllers\Api\v1\HealthController;
use App\Http\Controllers\Api\v1\HexController;
use App\Http\Controllers\Api\v1\ModelController;
use App\Http\Controllers\Api\v1\NlqController;
use App\Http\Controllers\Api\v1\PredictionController;
use Illuminate\Support\Facades\Route;

Route::get('/hexes', [HexController::class, 'index']);
Route::get('/hexes/geojson', [HexController::class, 'geoJson']);
Route::get('/export', ExportController::class);
Route::post('/nlq', NlqController::class);

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::post('/datasets/ingest', [DatasetController::class, 'ingest']);

    Route::get('/models', [ModelController::class, 'index']);
    Route::get('/models/{id}', [ModelController::class, 'show']);
    Route::post('/models/train', [ModelController::class, 'train']);
    Route::post('/models/{id}/evaluate', [ModelController::class, 'evaluate']);

    Route::post('/predictions', [PredictionController::class, 'store']);
    Route::get('/predictions/{id}', [PredictionController::class, 'show']);
});
