<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class RoadPlannedController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳計畫道路列表
     * 支援 ?category=narrow|mid|wide 篩選
     */
    public function index()
    {
        try {
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

            return $this->sendResponse([
                'tableList' => $results,
                'total' => count($results),
            ], '獲取計畫道路資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取計畫道路資料錯誤,錯誤代碼「RP011」,請通知管理員!!', ['error' => '獲取計畫道路資料錯誤,錯誤代碼「RP011」,請通知管理員!!']);
            }
        }
    }
}