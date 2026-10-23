<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class DashboardRepository
{
    public function __construct(private DistrictRepository $districts)
    {
    }

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

    /**
     * 各行政區窄巷密度排名。
     * 計數與 /districts/metadata 共用 DistrictRepository::getAllWithNarrowAlleyCounts()，
     * 確保兩支 API 的密度一致（計畫窄巷 + 實測新增，重疊者只計一次）。
     */
    public function getDistrictRankings(): array
    {
        $stats = $this->districts->getAllWithNarrowAlleyCounts()
            ->map(fn ($row) => [
                'district'    => $row->name,
                'total_count' => (int) $row->narrow_count,
                'density'     => $row->area_km2 > 0 ? round($row->narrow_count / $row->area_km2, 1) : 0,
            ])
            ->sortByDesc('density')
            ->values();

        $rankings = [];
        foreach ($stats as $i => $row) {
            $rankings[] = ['rank' => $i + 1] + $row;
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
