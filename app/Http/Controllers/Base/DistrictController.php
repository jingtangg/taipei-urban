<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class DistrictController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳所有行政區的基本資訊（不含幾何）
     * 用於下拉選單、統計列表等輕量查詢
     */
    public function index()
    {
        try {
            $districts = DB::select("
                SELECT
                    id,
                    district_name AS name,
                    ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2
                FROM districts
                ORDER BY district_name
            ");

            $tableList = array_map(function($district) {
                return [
                    'id' => (string)$district->id,
                    'name' => (string)$district->name,
                    'area_km2' => (float)$district->area_km2,
                ];
            }, $districts);

            return $this->sendResponse([
                'tableList' => $tableList,
                'total' => count($tableList),
            ], '獲取行政區資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!', ['error' => '獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!']);
            }
        }
    }

    /**
     * 回傳所有行政區的完整資料（含幾何邊界與風險統計）
     * 用於地圖圖層顯示與統計面板
     */
    public function geojson()
    {
        try {
            // 查詢行政區基本資料與幾何邊界
            $districts = DB::table('districts')
                ->select(DB::raw('
                    districts.id,
                    districts.district_name AS name,
                    ROUND((districts.area_m2 / 1000000)::numeric, 2) AS area_km2,
                    ST_AsGeoJSON(ST_Transform(districts.geom, 4326)) AS geometry
                '))
                ->orderBy('districts.district_name')
                ->get();

            // 計算每個行政區的窄巷密度與消防栓密度
            $tableList = $districts->map(function($district) {
                // 窄巷密度：窄巷總長度(km) / 區域面積(km²)
                // 使用 roads_planned（都市計畫道路）中 width_category = 'narrow'（< 3.5m）的路段
                $narrowRoadLength = DB::table('roads_planned')
                    ->where('width_category', 'narrow')
                    ->whereRaw('
                        ST_Within(
                            roads_planned.geom,
                            (SELECT geom FROM districts WHERE district_name = ? LIMIT 1)
                        )
                    ', [$district->name])
                    ->selectRaw('SUM(ST_Length(geom)) / 1000 as total_length')
                    ->value('total_length') ?? 0;

                $narrowDensity = $district->area_km2 > 0
                    ? round($narrowRoadLength / $district->area_km2, 2)
                    : 0;

                // 消防栓密度：消防栓數量 / 區域面積(km²)
                $hydrantCount = DB::table('fire_hydrants')
                    ->where('district', $district->name)
                    ->count();

                $hydrantDensity = $district->area_km2 > 0
                    ? round($hydrantCount / $district->area_km2, 1)
                    : 0;

                return [
                    'id' => (string)$district->id,
                    'name' => (string)$district->name,
                    'area_km2' => (float)$district->area_km2,
                    'geometry' => json_decode($district->geometry, true),
                    'narrowDensity' => (float)$narrowDensity,
                    'hydrantDensity' => (float)$hydrantDensity,
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total' => count($tableList),
            ], '獲取行政區資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取行政區資料錯誤,錯誤代碼「DT012」,請通知管理員!!', ['error' => '獲取行政區資料錯誤,錯誤代碼「DT012」,請通知管理員!!']);
            }
        }
    }
}