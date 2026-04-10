<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FireStationRepository
{
    public function getFiltered(?string $district): Collection
    {
        $query = DB::table('fire_stations')
            ->select(DB::raw('
                id,
                name,
                address,
                ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
            '))
            ->orderBy('id');

        if ($district) {
            $query->whereRaw('
                ST_Within(
                    fire_stations.geom,
                    (SELECT geom FROM districts WHERE district_name = ? LIMIT 1)
                )
            ', [$district]);
        }

        return $query->get();
    }
}
