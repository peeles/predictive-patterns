<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HexController;

Route::get('/hexes', [HexController::class, 'index']);
Route::get('/hexes/geojson', [HexController::class, 'geojson']);
