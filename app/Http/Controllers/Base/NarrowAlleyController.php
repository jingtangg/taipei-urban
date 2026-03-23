<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Request;
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
     * 回傳窄巷點位（含 GeoJSON 幾何）
     * 用於地圖圖層顯示
     * 可透過 ?district=大安區 篩選特定行政區
     * 可透過 ?category=紅區 篩選紅區/黃區
     * 可透過 ?type=A 篩選資料類型（A: 標準地址, B: 門牌範圍, C: 描述性）
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('narrow_alleys_temp as na')
                ->leftJoin('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
                ->select(DB::raw('
                    na.id,
                    na.alley_name,
                    na.district,
                    na.category,
                    na.width_m as alley_width,
                    na.address_type,
                    na.matched_road_id,
                    na.snap_distance_m,
                    rp.width_m as road_width,
                    rp.width_category as road_category,
                    ST_AsGeoJSON(ST_SetSRID(ST_MakePoint(na.snapped_lng, na.snapped_lat), 4326)) AS geometry
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

            // 篩選：資料類型（A/B/C）
            if ($request->filled('type')) {
                $query->where('na.address_type', $request->input('type'));
            }

            $alleys = $query->get();

            $tableList = $alleys->map(function($alley) {
                return [
                    'id' => (string)$alley->id,
                    'alley_name' => (string)$alley->alley_name,
                    'district' => (string)$alley->district,
                    'category' => (string)$alley->category,
                    'alley_width' => (float)$alley->alley_width,
                    'road_width' => $alley->road_width ? (float)$alley->road_width : null,
                    'road_category' => $alley->road_category,
                    'width_diff' => $alley->road_width
                        ? round((float)$alley->road_width - (float)$alley->alley_width, 2)
                        : null,
                    'address_type' => (string)$alley->address_type,
                    'matched_road_id' => $alley->matched_road_id,
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

    /**
     * 取得特定窄巷的道路幾何（用於類型 B 顯示完整線段）
     * GET /api/narrow-alleys/{id}/road-geometry
     */
    public function roadGeometry(Request $request, $id)
    {
        try {
            $alley = DB::table('narrow_alleys_temp')
                ->select('id', 'alley_name', 'address_type', 'matched_road_id')
                ->where('id', $id)
                ->first();

            if (!$alley) {
                return $this->sendError('窄巷資料不存在', ['error' => 'Alley not found'], 404);
            }

            if (!$alley->matched_road_id) {
                return $this->sendError('此窄巷未匹配到道路', ['error' => 'No matched road'], 404);
            }

            // 取得道路幾何
            $road = DB::table('roads_planned')
                ->select(DB::raw('
                    id,
                    width_m,
                    width_category,
                    ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
                '))
                ->where('id', $alley->matched_road_id)
                ->first();

            if (!$road) {
                return $this->sendError('道路資料不存在', ['error' => 'Road not found'], 404);
            }

            return $this->sendResponse([
                'alley_id' => (string)$alley->id,
                'alley_name' => (string)$alley->alley_name,
                'address_type' => (string)$alley->address_type,
                'road_id' => (string)$road->id,
                'road_width' => (float)$road->width_m,
                'road_category' => (string)$road->width_category,
                'geometry' => json_decode($road->geometry, true),
            ], '獲取道路幾何成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取道路幾何錯誤,錯誤代碼「NA021」,請通知管理員!!', ['error' => '獲取道路幾何錯誤,錯誤代碼「NA021」,請通知管理員!!']);
            }
        }
    }

    /**
     * 統計資訊
     * GET /api/narrow-alleys/stats
     */
    public function stats(Request $request)
    {
        try {
            // 總筆數
            $total = DB::table('narrow_alleys_temp')
                ->whereNotNull('matched_road_id')
                ->count();

            // 各行政區統計
            $byDistrict = DB::table('narrow_alleys_temp')
                ->select('district', DB::raw('COUNT(*) as count'))
                ->whereNotNull('matched_road_id')
                ->groupBy('district')
                ->orderBy('count', 'desc')
                ->get();

            // 各分類統計（紅區/黃區）
            $byCategory = DB::table('narrow_alleys_temp')
                ->select('category', DB::raw('COUNT(*) as count'))
                ->whereNotNull('matched_road_id')
                ->groupBy('category')
                ->orderBy('category')
                ->get();

            // 各類型統計（A/B/C）
            $byType = DB::table('narrow_alleys_temp')
                ->select('address_type', DB::raw('COUNT(*) as count'))
                ->whereNotNull('matched_road_id')
                ->groupBy('address_type')
                ->orderBy('address_type')
                ->get();

            // 寬度統計
            $widthStats = DB::table('narrow_alleys_temp')
                ->select(DB::raw('
                    ROUND(MIN(width_m)::numeric, 2) as min_width,
                    ROUND(MAX(width_m)::numeric, 2) as max_width,
                    ROUND(AVG(width_m)::numeric, 2) as avg_width
                '))
                ->whereNotNull('matched_road_id')
                ->first();

            return $this->sendResponse([
                'total' => $total,
                'by_district' => $byDistrict,
                'by_category' => $byCategory,
                'by_type' => $byType,
                'width_stats' => $widthStats,
            ], '獲取統計資料成功!');

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取統計資料錯誤,錯誤代碼「NA031」,請通知管理員!!', ['error' => '獲取統計資料錯誤,錯誤代碼「NA031」,請通知管理員!!']);
            }
        }
    }
}
