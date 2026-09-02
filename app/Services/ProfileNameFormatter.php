<?php

namespace App\Services;

class ProfileNameFormatter
{
    public function geo(int $number, string $fullName, ?string $city, ?string $region, ?string $country): string
    {
        $parts = array_values(array_filter([
            trim((string) $city),
            trim((string) $region),
            trim((string) $country),
        ], fn ($p) => $p !== ''));

        $location = implode(',', $parts);
        $label = sprintf('%03d', $number);

        return trim($label.' '.$fullName.' '.$location).' (api)';
    }

    public function staticName(int $number, string $fullName): string
    {
        return sprintf('%03d', $number).' '.$fullName.' Static (api)';
    }

    public function hasUsableGeoLocation(?string $city, ?string $region, ?string $country): bool
    {
        return trim((string) $country) !== '';
    }
}
