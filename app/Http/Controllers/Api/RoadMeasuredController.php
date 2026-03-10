<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RoadMeasuredController extends Controller
{
    /**
     * 回傳實測道路列表
     * 支援 ?district=萬華區 篩選
     */
    public function index()
    {
        $district = request('district');

        $query = "
            SELECT
                id,
                road_name,
                district,
                measured_width,
                avg_width,
                road_length,
                ST_AsGeoJSON(geom) AS geojson
            FROM roads_measured
        ";

        if ($district) {
            $results = DB::select($query . " WHERE district = ? ORDER BY id", [$district]);
        } else {
            $results = DB::select($query . " ORDER BY id");
        }

        return response()->json([
            'data'  => $results,
            'count' => count($results),
        ]);
    }
}