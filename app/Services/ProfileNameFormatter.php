<?php

namespace App\Services;

class ProfileNameFormatter
{
    /**
     * Profile name format:
     *   001 - Name Surname <ShortCompany> <HH:MM> GEO <CC> <Region> <City>
     *   001 - Name Surname <ShortCompany> <HH:MM> STATIC <CC> <Region> <City>
     */
    public function geo(int $number, string $fullName, string $companyShort = '', ?string $time = null, ?string $countryCode = null, ?string $region = null, ?string $city = null): string
    {
        return $this->build($number, $fullName, $companyShort, $time, 'GEO', $countryCode, $region, $city);
    }

    public function staticName(int $number, string $fullName, string $companyShort = '', ?string $time = null, ?string $countryCode = null, ?string $region = null, ?string $city = null, string $label = 'STATIC'): string
    {
        return $this->build($number, $fullName, $companyShort, $time, $label, $countryCode, $region, $city);
    }

    private function build(int $number, string $fullName, string $companyShort, ?string $time, string $suffix, ?string $countryCode, ?string $region, ?string $city): string
    {
        $head = array_values(array_filter([
            trim($fullName),
            trim((string) $companyShort),
            trim((string) $time),
        ], fn ($p) => $p !== ''));

        $location = array_values(array_filter([
            strtoupper(trim((string) $countryCode)),
            trim((string) $region),
            trim((string) $city),
        ], fn ($p) => $p !== ''));

        $name = sprintf('%03d - %s %s', $number, implode(' ', $head), $suffix);
        if ($location) {
            $name .= ' '.implode(' ', $location);
        }

        return $name;
    }

    public function hasUsableGeoLocation(?string $city, ?string $region, ?string $country): bool
    {
        return trim((string) $country) !== '';
    }
}
