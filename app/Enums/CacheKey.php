<?php

namespace App\Enums;

class CacheKey
{
    const PREFIX = 'taipei_urban';

    public static function narrowAlleyStats(?string $district): string
    {
        $suffix = $district ?? 'all';
        return self::PREFIX . ':narrow_alley_stats:' . $suffix;
    }

    public static function districtRankings(): string
    {
        return self::PREFIX . ':district_rankings';
    }

    public static function hydrantStats(?string $district): string
    {
        $suffix = $district ?? 'all';
        return self::PREFIX . ':hydrant_stats:' . $suffix;
    }
}
