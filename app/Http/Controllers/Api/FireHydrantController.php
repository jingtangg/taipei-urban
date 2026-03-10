<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class FireHydrantController extends Controller
{
    /**
     * 回傳消防栓列表
     * 支援 ?district=萬華區 篩選
     */
    public function index()
    {
        $district = request('district');

        $query = "
            SELECT
                id,
                wpid,
                type,
                district,
                ST_X(geom) AS x,
                ST_Y(geom) AS y
            FROM fire_hydrants
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