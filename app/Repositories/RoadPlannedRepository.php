<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoadPlannedRepository
{
    public function getFiltered(?string $district, ?string $category): Collection
    {
        $query = DB::table('roads_planned')
            ->select(DB::raw('
                id,
                road_width,
                width_m,
                width_category,
                ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
            '))
            ->orderBy('id');

        if ($district) {
            $query->whereRaw('
                ST_Within(
                    roads_planned.geom,
                    (SELECT geom FROM districts WHERE district_name = ? LIMIT 1)
                )
            ', [$district]);
        }

        if ($category) {
            $query->where('width_category', $category);
        }

        return $query->get();
    }
}
