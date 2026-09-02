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
            '002 Ana Black Houston,Texas,US (api)',
            $f->geo(2, 'Ana Black', 'Houston', 'Texas', 'US')
        );
    }

    public function test_geo_omits_empty_segments(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('002 Ana Black Texas,US (api)', $f->geo(2, 'Ana Black', '', 'Texas', 'US'));
        $this->assertSame('002 Ana Black US (api)', $f->geo(2, 'Ana Black', '', '', 'US'));
    }

    public function test_static_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('003 Ana Black Static (api)', $f->staticName(3, 'Ana Black'));
    }

    public function test_has_usable_geo_location_requires_country(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertFalse($f->hasUsableGeoLocation('', '', ''));
        $this->assertTrue($f->hasUsableGeoLocation('', '', 'US'));
    }
}
