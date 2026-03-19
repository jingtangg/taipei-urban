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
     * 回傳所有行政區的基本資訊（含幾何邊界）
     * 統一格式供前端使用
     */
    public function index()
    {
        try {
            $districts = DB::select("
                SELECT
                    id,
                    district_name,
                    ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2,
                    ST_AsGeoJSON(ST_Transform(geom, 4326))::json AS geometry
                FROM districts
                ORDER BY district_name
            ");

            $tableList = array_map(function($district) {
                return [
                    'id' => (string)$district->id,
                    'name' => $district->district_name,
                    'area_km2' => (float)$district->area_km2,
                    'geometry' => json_decode($district->geometry),
                    'narrowDensity' => 0,
                    'hydrantDensity' => 0,
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
     * 回傳所有行政區的 GeoJSON 格式（含幾何邊界）
     * 用於地圖圖層顯示
     */
    public function geojson()
    {
        try {
            $districts = DB::select("
                SELECT
                    id,
                    district_name,
                    ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2,
                    ST_AsGeoJSON(ST_Transform(geom, 4326))::json AS geometry
                FROM districts
                ORDER BY district_name
            ");

            // 轉換成 GeoJSON FeatureCollection 格式
            $features = array_map(function($district) {
                return [
                    'type' => 'Feature',
                    'id' => $district->id,
                    'properties' => [
                        'id' => $district->id,
                        'district_name' => $district->district_name,
                        'area_km2' => $district->area_km2,
                    ],
                    'geometry' => json_decode($district->geometry),
                ];
            }, $districts);

            $geojson = [
                'type' => 'FeatureCollection',
                'features' => $features,
            ];

            return $this->sendResponse($geojson, '獲取行政區 GeoJSON 成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取行政區 GeoJSON 錯誤,錯誤代碼「DT012」,請通知管理員!!', ['error' => '獲取行政區 GeoJSON 錯誤,錯誤代碼「DT012」,請通知管理員!!']);
            }
        }
    }
}