<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class FireHydrantController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳消防栓列表
     * 支援 ?district=萬華區 篩選
     */
    public function index()
    {
        try {
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

            return $this->sendResponse([
                'tableList' => $results,
                'total' => count($results),
            ], '獲取消防栓資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取消防栓資料錯誤,錯誤代碼「FH011」,請通知管理員!!', ['error' => '獲取消防栓資料錯誤,錯誤代碼「FH011」,請通知管理員!!']);
            }
        }
    }
}