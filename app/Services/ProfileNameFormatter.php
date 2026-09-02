<?php

namespace App\Services;

class ProfileNameFormatter
{
    /**
     * Profile name format:
     *   001 - Name Surname <ShortCompany> <ScheduledTime> GEO
     *   001 - Name Surname <ShortCompany> <ScheduledTime> STATIC
     */
    public function geo(int $number, string $fullName, string $companyShort = '', ?string $scheduledAt = null): string
    {
        return $this->build($number, $fullName, $companyShort, $scheduledAt, 'GEO');
    }

    public function staticName(int $number, string $fullName, string $companyShort = '', ?string $scheduledAt = null): string
    {
        return $this->build($number, $fullName, $companyShort, $scheduledAt, 'STATIC');
    }

    private function build(int $number, string $fullName, string $companyShort, ?string $scheduledAt, string $suffix): string
    {
        $middle = array_values(array_filter([
            trim($fullName),
            trim((string) $companyShort),
            trim((string) $scheduledAt),
        ], fn ($p) => $p !== ''));

        return sprintf('%03d - %s %s', $number, implode(' ', $middle), $suffix);
    }

    public function hasUsableGeoLocation(?string $city, ?string $region, ?string $country): bool
    {
        return trim((string) $country) !== '';
    }
}
