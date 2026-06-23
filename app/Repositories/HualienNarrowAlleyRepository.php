<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HualienNarrowAlleyRepository
{
    public function getFiltered(?string $township): Collection
    {
        $query = DB::table('hualien_narrow_alleys')
            ->select(DB::raw('
                id,
                alley_name,
                township,
                fire_station,
                width_m_min,
                width_m_max,
                risk_level,
                snap_distance_m,
                ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
            '))
            ->whereNotNull('geom')
            ->orderBy('id');

        if ($township) {
            $query->where('township', $township);
        }

        return $query->get();
    }
}
