<?php

return [

    // Size 0 — grass strip
    0 => [
        'min_jobs'       => 2,
        'max_jobs'       => 4,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 100],
        ],
        'cargo_weights'  => ['cargo' => 70, 'pax' => 30],
        'max_cargo_lbs'  => 800,
        'max_pax'        => 4,
        'dest_size_bias' => [0 => 5, 1 => 20, 2 => 35, 3 => 30, 4 => 10, 5 => 0],
        'guarantee_hub'  => false,
    ],

    // Size 1 — small airstrip
    1 => [
        'min_jobs'       => 4,
        'max_jobs'       => 8,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 80],
            ['min' => 76, 'max' => 250, 'weight' => 20],
        ],
        'cargo_weights'  => ['cargo' => 65, 'pax' => 35],
        'max_cargo_lbs'  => 2000,
        'max_pax'        => 8,
        'dest_size_bias' => [0 => 5, 1 => 15, 2 => 30, 3 => 30, 4 => 15, 5 => 5],
        'guarantee_hub'  => true,
    ],

    // Size 2 — small airport
    2 => [
        'min_jobs'       => 6,
        'max_jobs'       => 12,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 55],
            ['min' => 76, 'max' => 250, 'weight' => 35],
            ['min' => 251,'max' => 650, 'weight' => 10],
        ],
        'cargo_weights'  => ['cargo' => 60, 'pax' => 40],
        'max_cargo_lbs'  => 8000,
        'max_pax'        => 12,
        'dest_size_bias' => [0 => 10, 1 => 15, 2 => 25, 3 => 25, 4 => 15, 5 => 10],
        'guarantee_hub'  => true,
    ],

    // Size 3 — regional / tarmac
    3 => [
        'min_jobs'       => 10,
        'max_jobs'       => 20,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 40],
            ['min' => 76, 'max' => 250, 'weight' => 40],
            ['min' => 251,'max' => 650, 'weight' => 20],
        ],
        'cargo_weights'  => ['cargo' => 55, 'pax' => 45],
        'max_cargo_lbs'  => 20000,
        'max_pax'        => 20,
        'dest_size_bias' => [0 => 10, 1 => 15, 2 => 20, 3 => 20, 4 => 20, 5 => 15],
        'guarantee_hub'  => true,
    ],

    // Size 4 — large regional
    4 => [
        'min_jobs'       => 15,
        'max_jobs'       => 30,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 30],
            ['min' => 76, 'max' => 250, 'weight' => 40],
            ['min' => 251,'max' => 650, 'weight' => 30],
        ],
        'cargo_weights'  => ['cargo' => 50, 'pax' => 50],
        'max_cargo_lbs'  => 40000,
        'max_pax'        => 30,
        'dest_size_bias' => [0 => 15, 1 => 20, 2 => 20, 3 => 15, 4 => 15, 5 => 15],
        'guarantee_hub'  => false,
    ],

    // Size 5 — international
    5 => [
        'min_jobs'       => 20,
        'max_jobs'       => 40,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 15],
            ['min' => 76, 'max' => 250, 'weight' => 35],
            ['min' => 251,'max' => 650, 'weight' => 50],
        ],
        'cargo_weights'  => ['cargo' => 50, 'pax' => 50],
        'max_cargo_lbs'  => 999999,
        'max_pax'        => 50,
        'dest_size_bias' => [0 => 10, 1 => 10, 2 => 15, 3 => 15, 4 => 25, 5 => 25],
        'guarantee_hub'  => false,
    ],

    // Overlay merged on top of the base profile when airport->is_hub = true
    'hub_overlay' => [
        'min_jobs'       => 30,
        'max_jobs'       => 60,
        'range_bands'    => [
            ['min' => 2,  'max' => 75,  'weight' => 40],
            ['min' => 76, 'max' => 250, 'weight' => 35],
            ['min' => 251,'max' => 650, 'weight' => 25],
        ],
        'dest_size_bias' => [0 => 25, 1 => 25, 2 => 20, 3 => 15, 4 => 10, 5 => 5],
        'guarantee_hub'  => false,
    ],
];
