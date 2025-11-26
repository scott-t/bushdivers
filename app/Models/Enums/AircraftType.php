<?php

namespace App\Models\Enums;

class AircraftType
{
    public const PISTON_SINGLE = 1;
    public const PISTON_TWIN = 2;
    public const PISTON_QUAD = 3;
    public const TURBOPROP_SINGLE = 4;
    public const TURBOPROP_TWIN = 5;
    public const JET_SINGLE = 6;
    public const JET_TWIN = 7;
    public const HELI_SINGLE = 8;
    public const HELI_TWIN = 9;

    public static $labels = [
        self::PISTON_SINGLE => 'Piston Single',
        self::PISTON_TWIN => 'Piston Twin',
        self::PISTON_QUAD => 'Piston Quad',
        self::TURBOPROP_SINGLE => 'Turboprop Single',
        self::TURBOPROP_TWIN => 'Turboprop Twin',
        self::JET_SINGLE => 'Jet Single',
        self::JET_TWIN => 'Jet Twin',
        self::HELI_SINGLE => 'Heli Single',
        self::HELI_TWIN => 'Heli Twin',
    ];
}
