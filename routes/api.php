<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\DistrictController;
use App\Http\Controllers\Api\FireHydrantController;
use App\Http\Controllers\Api\FireStationController;
use App\Http\Controllers\Api\RoadPlannedController;
use App\Http\Controllers\Api\RoadMeasuredController;

Route::get('/districts', [DistrictController::class, 'index']);
Route::get('/fire-hydrants', [FireHydrantController::class, 'index']);
Route::get('/fire-stations', [FireStationController::class, 'index']);
Route::get('/roads/planned', [RoadPlannedController::class, 'index']);
Route::get('/roads/measured', [RoadMeasuredController::class, 'index']);