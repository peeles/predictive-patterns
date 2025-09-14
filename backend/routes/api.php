<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HexController;

Route::get('/hexes', [HexController::class, 'index']);
