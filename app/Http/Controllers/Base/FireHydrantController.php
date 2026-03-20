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
     * 回傳所有消防栓點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     */
    public function index()
    {
        try {
            $hydrants = DB::table('fire_hydrants')
                ->select(DB::raw('
                    id,
                    wpid,
                    type,
                    district,
                    ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
                '))
                ->orderBy('id')
                ->get();

            $tableList = $hydrants->map(function($hydrant) {
                return [
                    'id' => (string)$hydrant->id,
                    'wpid' => (string)$hydrant->wpid,
                    'type' => (string)$hydrant->type,
                    'district' => (string)$hydrant->district,
                    'geometry' => json_decode($hydrant->geometry, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total' => count($tableList),
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