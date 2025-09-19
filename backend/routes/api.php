<?php

use App\Http\Controllers\Api\v1\ExportController;
use App\Http\Controllers\Api\v1\HexController;
use App\Http\Controllers\Api\v1\NlqController;
use Illuminate\Support\Facades\Route;

Route::get('/hexes', [HexController::class, 'index']);
Route::get('/hexes/geojson', [HexController::class, 'geoJson']);
Route::get('/export', ExportController::class);
Route::post('/nlq', NlqController::class);
