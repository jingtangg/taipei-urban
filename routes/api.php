<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


use App\Http\Controllers\Base\DistrictController;
use App\Http\Controllers\Base\FireHydrantController;
use App\Http\Controllers\Base\FireStationController;
use App\Http\Controllers\Base\RoadPlannedController;
use App\Http\Controllers\Base\RoadMeasuredController;
use App\Http\Controllers\Base\NarrowAlleyController;

Route::get('/districts', [DistrictController::class, 'index']);
Route::get('/districts/geojson', [DistrictController::class, 'geojson']);
Route::get('/fire-hydrants', [FireHydrantController::class, 'index']);
Route::get('/fire-stations', [FireStationController::class, 'index']);
Route::get('/roads/planned', [RoadPlannedController::class, 'index']);
Route::get('/roads/measured', [RoadMeasuredController::class, 'index']);

// 窄巷資料
Route::get('/narrow-alleys', [NarrowAlleyController::class, 'index']);
Route::get('/narrow-alleys/stats', [NarrowAlleyController::class, 'stats']);
Route::get('/narrow-alleys/{id}/road-geometry', [NarrowAlleyController::class, 'roadGeometry']);