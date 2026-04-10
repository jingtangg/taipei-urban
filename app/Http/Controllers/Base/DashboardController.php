<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\DashboardFilterRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Exception;

class DashboardController extends BaseController
{
    protected $debug = null;

    public function __construct()
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳窄巷統計數據
     * 可透過 ?district=大安區 篩選特定行政區
     * 不傳 district 則回傳全台北市統計
     */
    public function narrowAlleyStatistics(DashboardFilterRequest $request)
    {
        try {
            $district = $request->input('district');

            $cacheKey = $district ? "narrow_alley_stats_{$district}" : 'narrow_alley_stats_all';

            return Cache::remember($cacheKey, 3600, function () use ($district) {
                if ($district) {
                    // 單一行政區：使用 JOIN 取代子查詢，利用空間索引
                    $plannedCount = DB::selectOne("
                        SELECT COUNT(*) as cnt
                        FROM roads_planned rp
                        INNER JOIN districts d ON ST_Intersects(rp.geom, d.geom)
                        WHERE rp.width_m < 6
                          AND d.district_name = ?
                    ", [$district])->cnt;

                } else {
                    // 全市統計：直接計數
                    $plannedCount = DB::table('roads_planned')
                        ->where('width_m', '<', 6)
                        ->count();
                }

                // 消防局實測窄巷（含重疊）
                $actualQuery = DB::table('narrow_alleys_temp as na')
                    ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id');

                if ($district) {
                    $actualQuery->where('na.district', $district);
                }
                $actualCount = $actualQuery->count();

                // 重疊數量（計畫 < 6m 且 實測也是）
                $overlapQuery = DB::table('narrow_alleys_temp as na')
                    ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
                    ->where('rp.width_m', '<', 6);

                if ($district) {
                    $overlapQuery->where('na.district', $district);
                }
                $overlapCount = $overlapQuery->count();

                // 新增數量（計畫 >= 6m 但實測 < 6m）
                $newCount = $actualCount - $overlapCount;

                // 總計（去重）
                $totalCount = $plannedCount + $newCount;

                return $this->sendResponse([
                    'total' => $totalCount,
                    'planned' => $plannedCount,
                    'overlap' => $overlapCount,
                    'new_discovered' => $newCount,
                ], '獲取窄巷統計成功!');
            });

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取窄巷統計錯誤,錯誤代碼「DASH001」,請通知管理員!!', ['error' => '獲取窄巷統計錯誤,錯誤代碼「DASH001」,請通知管理員!!']);
            }
        }
    }

    /**
     * 回傳各行政區密度排名
     * 固定回傳全台北市12個行政區
     */
    public function districtRankings()
    {
        try {
            return Cache::remember('district_rankings', 3600, function () {
                $stats = DB::select("
                    WITH
                    -- A. 計畫已知窄巷（僅計畫）
                    planned_only AS (
                        SELECT
                            ST_Transform(rp.geom, 4326) as geom,
                            ST_Length(ST_Transform(rp.geom, 3826)) as length_m
                        FROM roads_planned rp
                        WHERE rp.width_m < 6
                          AND NOT EXISTS (
                            SELECT 1 FROM narrow_alleys_temp na
                            WHERE na.matched_road_id = rp.id
                          )
                    ),
                    -- B. 實測新發現問題（僅實測）
                    actual_only AS (
                        SELECT
                            ST_Transform(rp.geom, 4326) as geom,
                            ST_Length(ST_Transform(rp.geom, 3826)) as length_m
                        FROM narrow_alleys_temp na
                        JOIN roads_planned rp ON na.matched_road_id = rp.id
                        WHERE rp.width_m >= 6
                    ),
                    -- 合併 A + B
                    all_alleys AS (
                        SELECT * FROM planned_only
                        UNION ALL
                        SELECT * FROM actual_only
                    )
                    -- 按行政區統計
                    SELECT
                        d.district_name,
                        d.area_m2 / 1000000.0 as area_km2,
                        COUNT(a.*) as total_count,
                        COALESCE(SUM(a.length_m), 0) as total_length_m,
                        CASE WHEN d.area_m2 > 0
                            THEN COUNT(a.*) / (d.area_m2 / 1000000.0)
                            ELSE 0
                        END as density
                    FROM districts d
                    LEFT JOIN all_alleys a ON ST_Intersects(
                        a.geom,
                        ST_Transform(d.geom, 4326)
                    )
                    GROUP BY d.district_name, d.area_m2
                    ORDER BY density DESC
                ");

                $rankings = [];
                $rank = 1;
                foreach ($stats as $row) {
                    $density = $row->area_km2 > 0 ? round($row->total_count / $row->area_km2, 1) : 0;

                    $rankings[] = [
                        'rank' => $rank++,
                        'district' => $row->district_name,
                        'total_count' => (int)$row->total_count,
                        'density' => $density,
                    ];
                }

                return $this->sendResponse([
                    'rankings' => $rankings,
                ], '獲取行政區排名成功!');
            });

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取行政區排名錯誤,錯誤代碼「DASH002」,請通知管理員!!', ['error' => '獲取行政區排名錯誤,錯誤代碼「DASH002」,請通知管理員!!']);
            }
        }
    }

    /**
     * 回傳消防栓統計數據
     * 可透過 ?district=大安區 篩選特定行政區
     * 不傳 district 則回傳全台北市統計
     *
     * 計算公式:
     * - 消防栓密度 (個/km²) = 該區消防栓數量 / 該區面積 (km²)
     * - 平均服務半徑 (m) = √(區域面積 / 消防栓數量 / π)
     */
    public function hydrantStatistics(DashboardFilterRequest $request)
    {
        try {
            $district = $request->input('district');

            $cacheKey = $district ? "hydrant_stats_{$district}" : 'hydrant_stats_all';

            return Cache::remember($cacheKey, 3600, function () use ($district) {
                if ($district) {
                    // 單一行政區統計
                    $result = DB::selectOne("
                        SELECT
                            COUNT(fh.id) as total_count,
                            d.area_m2 / 1000000.0 as area_km2
                        FROM districts d
                        LEFT JOIN fire_hydrants fh ON ST_Intersects(
                            fh.geom,
                            d.geom
                        )
                        WHERE d.district_name = ?
                        GROUP BY d.district_name, d.area_m2
                    ", [$district]);
                } else {
                    // 全市統計
                    $result = DB::selectOne("
                        SELECT
                            (SELECT COUNT(*) FROM fire_hydrants) as total_count,
                            SUM(area_m2) / 1000000.0 as area_km2
                        FROM districts
                    ");
                }

                $totalCount = (int)$result->total_count;
                $areaKm2 = (float)$result->area_km2;

                // 計算密度 (個/km²)
                $density = $areaKm2 > 0 ? round($totalCount / $areaKm2, 1) : 0;

                // 計算平均服務半徑 (m): √(面積 / 數量 / π)
                $serviceRadius = $totalCount > 0 ? round(sqrt(($areaKm2 * 1000000) / $totalCount / pi()), 0) : 0;

                return $this->sendResponse([
                    'total_count' => $totalCount,
                    'density' => $density,
                    'service_radius' => $serviceRadius,
                ], '獲取消防栓統計成功!');
            });

        } catch (Exception $e) {
            if ($this->debug == true) {
                return $this->sendError($e->getMessage(), ['error' => $e->getMessage()]);
            } else {
                return $this->sendError('獲取消防栓統計錯誤,錯誤代碼「DASH003」,請通知管理員!!', ['error' => '獲取消防栓統計錯誤,錯誤代碼「DASH003」,請通知管理員!!']);
            }
        }
    }
}