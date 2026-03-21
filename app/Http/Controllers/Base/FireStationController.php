<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class FireStationController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳消防隊點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大同區 篩選特定行政區（空間過濾）
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('fire_stations')
                ->select(DB::raw('
                    id,
                    name,
                    address,
                    ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
                '))
                ->orderBy('id');

            if ($request->filled('district')) {
                $query->whereRaw('
                    ST_Within(
                        fire_stations.geom,
                        (SELECT geom FROM districts WHERE district_name = ? LIMIT 1)
                    )
                ', [$request->input('district')]);
            }

            $stations = $query->get();

            $tableList = $stations->map(function($station) {
                return [
                    'id' => (string)$station->id,
                    'name' => (string)$station->name,
                    'address' => (string)$station->address,
                    'geometry' => json_decode($station->geometry, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total' => count($tableList),
            ], '獲取消防局資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取消防局資料錯誤,錯誤代碼「FS011」,請通知管理員!!', ['error' => '獲取消防局資料錯誤,錯誤代碼「FS011」,請通知管理員!!']);
            }
        }
    }
}