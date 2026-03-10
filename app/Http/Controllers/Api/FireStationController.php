<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class FireStationController extends Controller
{
    /**
     * 回傳所有消防隊點位
     */
    public function index()
    {
        $results = DB::select("
            SELECT
                id,
                name,
                address,
                ST_X(geom) AS x,
                ST_Y(geom) AS y
            FROM fire_stations
            ORDER BY id
        ");

        return response()->json([
            'data'  => $results,
            'count' => count($results),
        ]);
    }
}