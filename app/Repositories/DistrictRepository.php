<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class DistrictRepository
{
    public function getAll(): array
    {
        return DB::select("
            SELECT
                id,
                district_name AS name,
                ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2
            FROM districts
            ORDER BY district_name
        ");
    }

    public function getAllWithMetadata(): \Illuminate\Support\Collection
    {
        return DB::table('districts')
            ->select(DB::raw('
                districts.id,
                districts.district_name AS name,
                ROUND((districts.area_m2 / 1000000)::numeric, 2) AS area_km2,
                ST_AsText(ST_Transform(ST_Centroid(districts.geom), 4326)) AS label_center
            '))
            ->orderBy('districts.district_name')
            ->get();
    }

    public function getNarrowAlleyCount(string $districtName): int
    {
        $plannedCount = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM roads_planned rp
            INNER JOIN districts d ON ST_Intersects(rp.geom, d.geom)
            WHERE rp.width_m < 6
              AND d.district_name = ?
        ", [$districtName])->cnt;

        $actualCount = DB::table('narrow_alleys_temp as na')
            ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
            ->where('na.district', $districtName)
            ->count();

        $overlapCount = DB::table('narrow_alleys_temp as na')
            ->join('roads_planned as rp', 'na.matched_road_id', '=', 'rp.id')
            ->where('rp.width_m', '<', 6)
            ->where('na.district', $districtName)
            ->count();

        return $plannedCount + ($actualCount - $overlapCount);
    }
}
