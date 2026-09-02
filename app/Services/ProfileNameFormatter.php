<?php

namespace App\Services;

class ProfileNameFormatter
{
    /**
     * Profile name format:
     *   001 - Name Surname <ShortCompany> GEO <Country> <Region> <City>
     *   001 - Name Surname <ShortCompany> STATIC <Country> <Region> <City>
     */
    public function geo(int $number, string $fullName, string $companyShort = '', ?string $country = null, ?string $region = null, ?string $city = null): string
    {
        return $this->build($number, $fullName, $companyShort, 'GEO', $country, $region, $city);
    }

    public function staticName(int $number, string $fullName, string $companyShort = '', ?string $country = null, ?string $region = null, ?string $city = null): string
    {
        return $this->build($number, $fullName, $companyShort, 'STATIC', $country, $region, $city);
    }

    private function build(int $number, string $fullName, string $companyShort, string $suffix, ?string $country, ?string $region, ?string $city): string
    {
        $head = array_values(array_filter([
            trim($fullName),
            trim((string) $companyShort),
        ], fn ($p) => $p !== ''));

        $location = array_values(array_filter([
            trim((string) $country),
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
