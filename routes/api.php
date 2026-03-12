<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


use App\Http\Controllers\Base\DistrictController;
use App\Http\Controllers\Base\FireHydrantController;
use App\Http\Controllers\Base\FireStationController;
use App\Http\Controllers\Base\RoadPlannedController;
use App\Http\Controllers\Base\RoadMeasuredController;

Route::get('/districts', [DistrictController::class, 'index']);
Route::get('/fire-hydrants', [FireHydrantController::class, 'index']);
Route::get('/fire-stations', [FireStationController::class, 'index']);
Route::get('/roads/planned', [RoadPlannedController::class, 'index']);
Route::get('/roads/measured', [RoadMeasuredController::class, 'index']);