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
     * 回傳所有行政區的基本資訊
     * 不含幾何（geom），只用於下拉選單與統計
     */
    public function index()
    {
        try {
            $districts = DB::select("
                SELECT
                    id,
                    district_name,
                    ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2
                FROM districts
                ORDER BY district_name
            ");

            return $this->sendResponse([
                'tableList' => $districts,
                'total' => count($districts),
            ], '獲取行政區資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!', ['error' => '獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!']);
            }
        }
    }
}