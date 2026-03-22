<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class RoadMeasuredController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳實測道路列表（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=萬華區 篩選特定行政區
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('roads_measured')
                ->select(DB::raw('
                    id,
                    road_name,
                    district,
                    measured_width,
                    avg_width,
                    road_length,
                    ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geojson
                '))
                ->orderBy('id');

            if ($request->filled('district')) {
                $query->where('district', $request->input('district'));
            }

            $results = $query->get();

            $tableList = $results->map(function($road) {
                return [
                    'id' => (string)$road->id,
                    'road_name' => (string)$road->road_name,
                    'district' => (string)$road->district,
                    'measured_width' => (float)$road->measured_width,
                    'avg_width' => (float)$road->avg_width,
                    'road_length' => (float)$road->road_length,
                    'geometry' => json_decode($road->geojson, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total' => count($tableList),
            ], '獲取已開闢道路資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取已開闢道路資料錯誤,錯誤代碼「RM011」,請通知管理員!!', ['error' => '獲取已開闢道路資料錯誤,錯誤代碼「RM011」,請通知管理員!!']);
            }
        }
    }
}