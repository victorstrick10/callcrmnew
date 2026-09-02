<?php

namespace Tests\Unit;

use App\Services\ProfileNameFormatter;
use Tests\TestCase;

class ProfileNameFormatterTest extends TestCase
{
    public function test_geo_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame(
            '002 - Ana Black Diligent 03.09 15:00 GEO',
            $f->geo(2, 'Ana Black', 'Diligent', '03.09 15:00')
        );
    }

    public function test_geo_omits_empty_segments(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('002 - Ana Black Diligent GEO', $f->geo(2, 'Ana Black', 'Diligent', ''));
        $this->assertSame('002 - Ana Black GEO', $f->geo(2, 'Ana Black', '', ''));
    }

    public function test_static_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('003 - Ana Black Global STATIC', $f->staticName(3, 'Ana Black', 'Global', ''));
    }

    public function test_has_usable_geo_location_requires_country(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertFalse($f->hasUsableGeoLocation('', '', ''));
        $this->assertTrue($f->hasUsableGeoLocation('', '', 'US'));
    }
}
