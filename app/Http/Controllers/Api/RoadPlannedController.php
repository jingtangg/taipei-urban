<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RoadPlannedController extends Controller
{
    /**
     * 回傳計畫道路列表
     * 支援 ?category=narrow|mid|wide 篩選
     */
    public function index()
    {
        $category = request('category');

        $query = "
            SELECT
                id,
                road_width,
                width_m,
                width_category,
                ST_AsGeoJSON(geom) AS geojson
            FROM roads_planned
        ";

        if ($category) {
            $results = DB::select($query . " WHERE width_category = ? ORDER BY id", [$category]);
        } else {
            $results = DB::select($query . " ORDER BY id");
        }

        return response()->json([
            'data'  => $results,
            'count' => count($results),
        ]);
    }
}