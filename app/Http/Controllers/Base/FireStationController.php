<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
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
     * 回傳所有消防隊點位
     */
    public function index()
    {
        try {
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

            return $this->sendResponse([
                'tableList' => $results,
                'total' => count($results),
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