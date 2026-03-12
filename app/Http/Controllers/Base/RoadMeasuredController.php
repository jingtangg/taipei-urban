<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
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
     * 回傳實測道路列表
     * 支援 ?district=萬華區 篩選
     */
    public function index()
    {
        try {
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

            return $this->sendResponse([
                'tableList' => $results,
                'total' => count($results),
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