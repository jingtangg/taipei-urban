<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class DashboardRepository
{
    public function getNarrowAlleyStatistics(?string $district): array
    {
        if ($district) {
            $plannedCount = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM roads_planned rp
                INNER JOIN districts d ON ST_Intersects(rp.geom, d.geom)
                WHERE rp.width_m < 6
                  AND d.district_name = ?
            ", [$district])->cnt;
        } else {
            $plannedCount = DB::table('roads_planned')
                ->where('width_m', '<', 6)
                ->count();
        }

        $actualQuery = DB::table('narrow_alleys_temp as na')
            ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id');
        if ($district) {
            $actualQuery->where('na.district', $district);
        }
        $actualCount = $actualQuery->count();

        $overlapQuery = DB::table('narrow_alleys_temp as na')
            ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
            ->where('rp.width_m', '<', 6);
        if ($district) {
            $overlapQuery->where('na.district', $district);
        }
        $overlapCount = $overlapQuery->count();

        $newCount = $actualCount - $overlapCount;

        return [
            'total'          => $plannedCount + $newCount,
            'planned'        => $plannedCount,
            'overlap'        => $overlapCount,
            'new_discovered' => $newCount,
        ];
    }

    public function getDistrictRankings(): array
    {
        $stats = DB::select(<<<'SQL'
            WITH
            -- CTE A：都市計畫窄巷（計畫寬度 < 6m，且消防局實測未記錄）
            planned_only AS (
                SELECT
                    ST_Transform(rp.geom, 4326)            AS geom,
                    ST_Length(ST_Transform(rp.geom, 3826)) AS length_m
                FROM roads_planned rp
                WHERE rp.width_m < 6
                  AND NOT EXISTS (
                      SELECT 1 FROM narrow_alleys_temp na
                      WHERE na.matched_road_id = rp.id
                  )
            ),

            -- CTE B：消防局實測新發現（實測有記錄，但計畫寬度 >= 6m，屬新增問題路段）
            actual_only AS (
                SELECT
                    ST_Transform(rp.geom, 4326)            AS geom,
                    ST_Length(ST_Transform(rp.geom, 3826)) AS length_m
                FROM narrow_alleys_temp na
                JOIN roads_planned rp ON na.matched_road_id = rp.id
                WHERE rp.width_m >= 6
            ),

            -- CTE C：合併 A + B，得到去重後的全部問題路段
            all_alleys AS (
                SELECT * FROM planned_only
                UNION ALL
                SELECT * FROM actual_only
            )

            -- 按行政區統計總數與密度
            SELECT
                d.district_name,
                d.area_m2 / 1000000.0                        AS area_km2,
                COUNT(a.*)                                    AS total_count,
                COALESCE(SUM(a.length_m), 0)                 AS total_length_m,
                CASE WHEN d.area_m2 > 0
                    THEN COUNT(a.*) / (d.area_m2 / 1000000.0)
                    ELSE 0
                END                                           AS density
            FROM districts d
            LEFT JOIN all_alleys a ON ST_Intersects(
                a.geom,
                ST_Transform(d.geom, 4326)
            )
            GROUP BY d.district_name, d.area_m2
            ORDER BY density DESC
        SQL);

        $rankings = [];
        $rank = 1;
        foreach ($stats as $row) {
            $density = $row->area_km2 > 0 ? round($row->total_count / $row->area_km2, 1) : 0;
            $rankings[] = [
                'rank'        => $rank++,
                'district'    => $row->district_name,
                'total_count' => (int) $row->total_count,
                'density'     => $density,
            ];
        }

        return $rankings;
    }

    public function getHydrantStatistics(?string $district): array
    {
        if ($district) {
            $result = DB::selectOne("
                SELECT
                    COUNT(fh.id) as total_count,
                    d.area_m2 / 1000000.0 as area_km2
                FROM districts d
                LEFT JOIN fire_hydrants fh ON ST_Intersects(fh.geom, d.geom)
                WHERE d.district_name = ?
                GROUP BY d.district_name, d.area_m2
            ", [$district]);
        } else {
            $result = DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM fire_hydrants) as total_count,
                    SUM(area_m2) / 1000000.0 as area_km2
                FROM districts
            ");
        }

        $totalCount = (int) $result->total_count;
        $areaKm2    = (float) $result->area_km2;
        $density      = $areaKm2 > 0 ? round($totalCount / $areaKm2, 1) : 0;
        $serviceRadius = $totalCount > 0 ? round(sqrt(($areaKm2 * 1000000) / $totalCount / pi()), 0) : 0;

        return [
            'total_count'    => $totalCount,
            'density'        => $density,
            'service_radius' => $serviceRadius,
        ];
    }
}
