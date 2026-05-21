<?php

namespace Tests\Unit\Services\AirportSync;

use App\Services\AirportSync\LnmCsvParser;
use Tests\TestCase;

class LnmCsvParserTest extends TestCase
{
    public function test_parses_semicolon_csv_and_skips_empty_identifier_rows(): void
    {
        $path = sys_get_temp_dir() . '/airport-sync-semicolon.csv';

        file_put_contents($path, implode("\n", [
            'ident;name;city;country;laty;lonx;altitude;mag_var;longest_runway_length;longest_runway_surface;has_avgas;has_jetfuel;rating',
            ' aymr ;Moro Airport;Moro;Papua New Guinea;-6.36323;143.24665;4000;1.2;1700;ASPHALT;true;false;4',
            ';Ignored Row;Nowhere;Papua New Guinea;0;0;0;0;0;GRASS;false;false;0',
        ]));

        $records = (new LnmCsvParser())->parse($path);

        $this->assertCount(1, $records);
        $this->assertSame('AYMR', $records->first()['identifier']);
        $this->assertSame('PA', $records->first()['country_code']);
        $this->assertSame('A', $records->first()['longest_runway_surface']);
    }

    public function test_parses_comma_csv_and_maps_basic_fields(): void
    {
        $path = sys_get_temp_dir() . '/airport-sync-comma.csv';

        file_put_contents($path, implode("\n", [
            'ident,name,city,country,country_code,laty,lonx,altitude,mag_var,longest_runway_length,longest_runway_surface,has_avgas,has_jetfuel,rating',
            'YSSY,Sydney,NSW,Australia,AU,-33.94611,151.17722,21,12.3,12999,CONCRETE,1,1,5',
        ]));

        $records = (new LnmCsvParser())->parse($path);

        $this->assertCount(1, $records);
        $this->assertSame('YSSY', $records->first()['identifier']);
        $this->assertSame('AU', $records->first()['country_code']);
        $this->assertSame(5, $records->first()['size']);
        $this->assertTrue($records->first()['has_jetfuel']);
    }
}
