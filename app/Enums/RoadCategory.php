<?php

namespace App\Enums;

class RoadCategory
{
    const NARROW = 'narrow';
    const MID    = 'mid';
    const WIDE   = 'wide';

    const ALL = [self::NARROW, self::MID, self::WIDE];
}
