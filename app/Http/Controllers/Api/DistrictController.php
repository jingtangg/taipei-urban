<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DistrictController extends Controller
{
    /**
     * 回傳所有行政區的基本資訊
     * 不含幾何（geom），只用於下拉選單與統計
     */
    public function index()
    {
        $districts = DB::select("
            SELECT
                id,
                district_name,
                ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2
            FROM districts
            ORDER BY district_name
        ");

        return response()->json([
            'data' => $districts,
            'count' => count($districts),
        ]);
    }
}