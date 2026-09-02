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
            '002 - Ana Black Diligent 16:00 GEO US Texas Austin',
            $f->geo(2, 'Ana Black', 'Diligent', '16:00', 'us', 'Texas', 'Austin')
        );
    }

    public function test_geo_omits_empty_segments(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('002 - Ana Black Diligent GEO US', $f->geo(2, 'Ana Black', 'Diligent', '', 'US', '', ''));
        $this->assertSame('002 - Ana Black GEO', $f->geo(2, 'Ana Black', '', '', '', '', ''));
    }

    public function test_static_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('003 - Ana Black Global 16:00 STATIC GB England London', $f->staticName(3, 'Ana Black', 'Global', '16:00', 'gb', 'England', 'London'));
    }

    public function test_has_usable_geo_location_requires_country(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertFalse($f->hasUsableGeoLocation('', '', ''));
        $this->assertTrue($f->hasUsableGeoLocation('', '', 'US'));
    }
}
