<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CalendlyApiClient
{
    /** @var array<int, array{user_uri:?string,organization_uri:?string}> */
    private array $meCache = [];

    /**
     * @return array{user_uri:?string,organization_uri:?string}
     */
    public function me(Company $company): array
    {
        $key = $company->id;
        if (isset($this->meCache[$key])) {
            return $this->meCache[$key];
        }

        $resource = $this->http($company)
            ->get('https://api.calendly.com/users/me')
            ->throw()
            ->json('resource') ?? [];

        return $this->meCache[$key] = [
            'user_uri' => $resource['uri'] ?? null,
            'organization_uri' => $resource['current_organization'] ?? null,
        ];
    }

    /**
     * List scheduled events. When $inviteeEmail is set, returns all events for that lead
     * (including multiple bookings / reschedule history depending on $status).
     *
     * @return list<array<string,mixed>>
     */
    public function listScheduledEvents(
        Company $company,
        ?string $inviteeEmail = null,
        ?string $status = null,
        array $extraQuery = []
    ): array {
        $me = $this->me($company);
        $baseQuery = array_merge([
            'count' => 100,
        ], $extraQuery);

        if ($status !== null && $status !== '') {
            $baseQuery['status'] = $status;
        }

        if ($inviteeEmail) {
            $baseQuery['invitee_email'] = strtolower($inviteeEmail);
        }

        $scopeAttempts = $this->scopeAttempts($company, $me);
        if ($scopeAttempts === []) {
            throw new RuntimeException("Company {$company->slug}: could not resolve Calendly user/org");
        }

        $lastError = null;
        foreach ($scopeAttempts as $scope) {
            $query = array_merge($baseQuery, $scope);
            try {
                return $this->paginate($company, 'https://api.calendly.com/scheduled_events', $query);
            } catch (RequestException $e) {
                $lastError = $e;
                $body = (string) ($e->response?->body() ?? '');
                // Bad/mismatched organization URI → try next scope (token org, then user).
                if ($e->response?->status() === 400 && str_contains($body, 'organization')) {
                    continue;
                }
                throw $e;
            }
        }

        throw $lastError ?? new RuntimeException("Company {$company->slug}: Calendly event list failed");
    }

    /**
     * Prefer a valid stored org URI, else the token's current_organization, else user URI.
     *
     * @param  array{user_uri:?string,organization_uri:?string}  $me
     * @return list<array<string,string>>
     */
    private function scopeAttempts(Company $company, array $me): array
    {
        $attempts = [];
        $storedOrg = trim((string) $company->calendly_org_uri);
        $tokenOrg = $me['organization_uri'] ?? null;
        $user = $me['user_uri'] ?? null;

        if ($this->isValidOrganizationUri($storedOrg)) {
            $attempts[] = ['organization' => $storedOrg];
        }

        if ($tokenOrg && $tokenOrg !== $storedOrg && $this->isValidOrganizationUri($tokenOrg)) {
            $attempts[] = ['organization' => $tokenOrg];
        }

        if ($user) {
            $attempts[] = ['user' => $user];
        }

        return $attempts;
    }

    private function isValidOrganizationUri(string $uri): bool
    {
        return (bool) preg_match('#^https://api\.calendly\.com/organizations/[A-Za-z0-9\-]+$#', $uri);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEventInvitees(Company $company, string $eventUri, ?string $email = null): array
    {
        $uuid = basename(parse_url($eventUri, PHP_URL_PATH) ?: $eventUri);
        $query = ['count' => 100];
        if ($email) {
            $query['email'] = strtolower($email);
        }

        return $this->paginate($company, "https://api.calendly.com/scheduled_events/{$uuid}/invitees", $query);
    }

    public function testToken(Company $company): array
    {
        return $this->me($company);
    }

    /**
     * Follow Calendly's next_page URL when present (avoids invalid page_token rebuilds).
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function paginate(Company $company, string $url, array $query): array
    {
        $collection = [];
        $page = 0;
        $nextUrl = null;

        do {
            $page++;

            if ($nextUrl) {
                // next_page already includes page_token + original filters.
                $json = $this->requestJson($company, $nextUrl, []);
            } else {
                $json = $this->requestJson($company, $url, $query);
            }

            foreach (($json['collection'] ?? []) as $item) {
                if (is_array($item)) {
                    $collection[] = $item;
                }
            }

            $nextUrl = $json['pagination']['next_page'] ?? null;
            if (is_string($nextUrl) && $nextUrl !== '') {
                usleep(150000);
            } else {
                $nextUrl = null;
            }
        } while ($nextUrl && $page < 50);

        return $collection;
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function requestJson(Company $company, string $url, array $query): array
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts < 4) {
            $attempts++;
            try {
                $pending = $this->http($company);
                $response = $query === []
                    ? $pending->get($url)
                    : $pending->get($url, $query);

                if ($response->status() === 429) {
                    usleep(500000 * $attempts);
                    continue;
                }

                // Do not retry 4xx validation errors (e.g. bad page_token).
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                    $response->throw();
                }

                $response->throw();

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status !== null && $status >= 400 && $status < 500 && $status !== 429) {
                    throw $e;
                }
                $lastError = $e;
                usleep(400000 * $attempts);
            } catch (Throwable $e) {
                $lastError = $e;
                usleep(400000 * $attempts);
            }
        }

        throw $lastError ?? new RuntimeException('Calendly request failed');
    }

    private function http(Company $company): PendingRequest
    {
        // Local Windows PHP often lacks a CA bundle (cURL error 60).
        return Http::withToken($this->token($company))
            ->timeout(120)
            ->connectTimeout(30)
            ->withOptions(['verify' => false])
            ->acceptJson();
    }

    private function token(Company $company): string
    {
        $token = $company->getCalendlyApiToken();
        if (! $token) {
            throw new RuntimeException("Company {$company->slug} missing Calendly API token");
        }

        return $token;
    }
}
