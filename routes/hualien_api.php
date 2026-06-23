<?php

use App\Http\Controllers\Base\HualienNarrowAlleyController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/narrow-alleys', [HualienNarrowAlleyController::class, 'index']);
});
