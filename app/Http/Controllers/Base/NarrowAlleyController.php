<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\NarrowAlleyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Exception;

class NarrowAlleyController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳窄巷資料（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大安區 篩選特定行政區
     * 可透過 ?category=紅區 篩選紅區/黃區
     */
    public function index(NarrowAlleyRequest $request)
    {
        try {
            $query = DB::table('narrow_alleys_temp as na')
                ->leftJoin('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
                ->select(DB::raw('
                    na.id,
                    na.alley_name,
                    na.district,
                    na.category,
                    na.width_m,
                    na.matched_road_id,
                    na.snap_distance_m,
                    rp.width_m as road_width,
                    ST_AsGeoJSON(ST_Transform(rp.geom, 4326)) AS geometry
                '))
                ->whereNotNull('na.matched_road_id')
                ->orderBy('na.id');

            // 篩選：行政區
            if ($request->filled('district')) {
                $query->where('na.district', $request->input('district'));
            }

            // 篩選：分類（紅區/黃區）
            if ($request->filled('category')) {
                $query->where('na.category', $request->input('category'));
            }

            $alleys = $query->get();

            $tableList = $alleys->map(function($alley) {
                return [
                    'id' => (string)$alley->id,
                    'alley_name' => (string)$alley->alley_name,
                    'district' => (string)$alley->district,
                    'category' => (string)$alley->category,
                    'width_m' => (float)$alley->width_m,
                    'road_width' => $alley->road_width ? (float)$alley->road_width : null,
                    'snap_distance_m' => $alley->snap_distance_m ? (float)$alley->snap_distance_m : null,
                    'geometry' => json_decode($alley->geometry, true),
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total' => count($tableList),
            ], '獲取窄巷資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取窄巷資料錯誤,錯誤代碼「NA011」,請通知管理員!!', ['error' => '獲取窄巷資料錯誤,錯誤代碼「NA011」,請通知管理員!!']);
            }
        }
    }
}
