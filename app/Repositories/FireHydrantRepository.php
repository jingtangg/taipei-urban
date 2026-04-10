<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FireHydrantRepository
{
    public function getFiltered(?string $district): Collection
    {
        $query = DB::table('fire_hydrants')
            ->select(DB::raw('
                id,
                wpid,
                type,
                district,
                ST_AsGeoJSON(ST_Transform(geom, 4326)) AS geometry
            '))
            ->orderBy('id');

        if ($district) {
            $query->where('district', $district);
        }

        return $query->get();
    }
}
