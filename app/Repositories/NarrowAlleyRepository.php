<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NarrowAlleyRepository
{
    public function getFiltered(?string $district, ?string $category): Collection
    {
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

        if ($district) {
            $query->where('na.district', $district);
        }

        if ($category) {
            $query->where('na.category', $category);
        }

        return $query->get();
    }
}
