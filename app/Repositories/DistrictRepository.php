<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
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

    /**
     * 一次查詢取得所有行政區的中心點與窄巷總數。
     * 用三個子查詢計算：計畫窄巷數、實測窄巷數、重疊數，避免 N+1。
     */
    public function getAllWithNarrowAlleyCounts(): Collection
    {
        return collect(DB::select("
            SELECT
                d.id,
                d.district_name                                          AS name,
                ROUND((d.area_m2 / 1000000)::numeric, 2)                AS area_km2,
                ST_AsText(ST_Transform(ST_Centroid(d.geom), 4326))      AS label_center,
                COALESCE(planned.cnt, 0)
                    + GREATEST(
                        COALESCE(actual.cnt, 0) - COALESCE(overlap.cnt, 0),
                        0
                    )                                                    AS narrow_count
            FROM districts d

            -- 計畫窄巷：寬度 < 6m，以空間交集對應行政區
            LEFT JOIN (
                SELECT d2.district_name, COUNT(*) AS cnt
                FROM roads_planned rp
                INNER JOIN districts d2 ON ST_Intersects(rp.geom, d2.geom)
                WHERE rp.width_m < 6
                GROUP BY d2.district_name
            ) planned ON planned.district_name = d.district_name

            -- 實測窄巷：消防局記錄，含重疊部分
            LEFT JOIN (
                SELECT na.district, COUNT(*) AS cnt
                FROM narrow_alleys_temp na
                JOIN roads_planned rp ON na.matched_road_id = rp.id
                GROUP BY na.district
            ) actual ON actual.district = d.district_name

            -- 重疊：計畫 < 6m 且實測也有記錄（避免重複計算）
            LEFT JOIN (
                SELECT na.district, COUNT(*) AS cnt
                FROM narrow_alleys_temp na
                JOIN roads_planned rp ON na.matched_road_id = rp.id
                WHERE rp.width_m < 6
                GROUP BY na.district
            ) overlap ON overlap.district = d.district_name

            ORDER BY d.district_name
        "));
    }
}
