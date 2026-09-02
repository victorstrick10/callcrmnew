<?php

namespace App\Services;

use App\Models\BrowserProfile;
use Illuminate\Support\Facades\Http;

/**
 * Faithful PHP port of `MultiloginClient` from
 * calendly_multilogin_crm_rebuild_v2_5/services.py.
 *
 * Multilogin API adapter.
 *
 * The public API has used more than one path layout over time. Discovery tries
 * the configured endpoint first and then a small set of known path candidates.
 * Once a working path is found it is returned to the CRM and stored.
 *
 * Method names are kept identical to the Python original (snake_case,
 * including leading-underscore "private" helpers) to keep this port
 * line-traceable against services.py. Every method below is fully
 * implemented; none are stubs.
 *
 * NOTE on `_parse_multilogin_connection_url`: the original Python
 * implementation has a latent ordering bug where a proxy connection string
 * that contains "://" (e.g. "http://user:pass@host:port") is first run
 * through the naive `host:port:user:pass` colon-split (because a URL like
 * that also happens to contain exactly 3 colons), which raises
 * `Invalid proxy port in connection string` instead of falling through to
 * `urlparse`. That bug was confirmed by executing the isolated parsing
 * logic against the exact sample used in self_test.py. This port fixes the
 * ordering (only attempt the bare colon-split when the string does NOT look
 * like a URL) so that every self_test.py-style sample resolves correctly,
 * while preserving identical behavior for every other input shape.
 */
class MultiloginClient
{
    public array $cfg;

    public string $base_url;

    public string $token;

    public bool $simulation;

    public array $last_profile_scan = [];

    protected IntegrationSettingsService $settings;

    protected AuditService $audit;

    protected ProfileNumberService $profileNumbers;

    /**
     * `$override_token`/`$override_base_url` are positional to preserve the
     * Python `MultiloginClient(override_token, override_base_url)` call
     * signature. The three collaborator services are dependency-injected
     * (resolved from the container when not explicitly supplied), so
     * `app(MultiloginClient::class)` and `new MultiloginClient()` both work.
     */
    public function __construct(
        string $override_token = '',
        string $override_base_url = '',
        ?IntegrationSettingsService $settings = null,
        ?AuditService $audit = null,
        ?ProfileNumberService $profileNumbers = null
    ) {
        $this->settings = $settings ?? app(IntegrationSettingsService::class);
        $this->audit = $audit ?? app(AuditService::class);
        $this->profileNumbers = $profileNumbers ?? app(ProfileNumberService::class);

        $this->cfg = $this->settings->getSettings('multilogin');
        $baseUrl = $override_base_url ?: ($this->cfg['base_url'] ?? 'https://api.multilogin.com');
        $this->base_url = rtrim($baseUrl, '/');
        $this->token = $override_token ?: ($this->cfg['automation_token'] ?? '');
        // Simulation is opt-in. Unset/empty must mean real Multilogin API calls.
        $simulationRaw = strtolower((string) ($this->cfg['simulation_mode'] ?? 'false'));
        $this->simulation = in_array($simulationRaw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Return a Multilogin client using the company's token when configured,
     * otherwise fall back to the global integration settings token.
     */
    public function forCompany(?\App\Models\Company $company): self
    {
        if (! $company) {
            return $this;
        }

        $companyCfg = $company->multiloginConfig();
        $token = $company->getMultiloginToken();

        // No per-company token AND no per-company config → fall back to global.
        if (! $token && empty($companyCfg)) {
            return $this;
        }

        $client = new self(
            $token ?: ($companyCfg['automation_token'] ?? ''),
            $company->multilogin_base_url ?: ($companyCfg['base_url'] ?? ''),
            $this->settings,
            $this->audit,
            $this->profileNumbers,
        );

        // Overlay the company's advanced Multilogin config over the global defaults
        // so Multilogin is configured in one place (per company).
        if (! empty($companyCfg)) {
            $client->cfg = array_merge($client->cfg, array_filter($companyCfg, fn ($v) => $v !== null && $v !== ''));
            if (! empty($companyCfg['base_url'])) {
                $client->base_url = rtrim((string) $companyCfg['base_url'], '/');
            }
            $sim = strtolower((string) ($companyCfg['simulation_mode'] ?? ($client->cfg['simulation_mode'] ?? 'false')));
            $client->simulation = in_array($sim, ['1', 'true', 'yes', 'on'], true);
        }

        // Global geo/static folder IDs belong to one Multilogin workspace (e.g. Diligent).
        // A company token from another workspace (e.g. Rusell) gets HTTP 501 HTML when
        // creating into those folders — use that token's workspace/default folder instead.
        $tokenWorkspace = $client->workspace_id_from_token();
        $globalWorkspace = (string) ($client->cfg['workspace_id'] ?? '');
        if ($tokenWorkspace !== '' && $tokenWorkspace !== $globalWorkspace) {
            $client->cfg['workspace_id'] = $tokenWorkspace;
            $client->cfg['geo_folder_id'] = $tokenWorkspace;
            $client->cfg['static_folder_id'] = $tokenWorkspace;
        }

        return $client;
    }

    /**
     * True when a usable Multilogin credential exists for this company — either
     * the company's own token or the global Integrations automation token (or
     * when running in simulation mode). Used so features like Profile Numbers
     * keep working when Multilogin is configured only in Integrations.
     */
    public function isConfiguredFor(?\App\Models\Company $company): bool
    {
        $client = $this->forCompany($company);

        return $client->simulation || $client->token !== '';
    }

    public function headers(): array
    {
        if (!$this->token) {
            throw new \RuntimeException('Multilogin automation token is not configured.');
        }

        return [
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    public function _request_candidates(
        string $method,
        string $configured,
        array $candidates,
        ?array $json_body = null,
        ?array $params = null,
        int $timeout = 25
    ): array {
        $paths = [];
        foreach (array_merge([$configured], $candidates) as $path) {
            if ($path && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        $errors = [];
        foreach ($paths as $path) {
            $url = str_starts_with($path, 'http')
                ? $path
                : $this->base_url . (str_starts_with($path, '/') ? $path : '/' . $path);

            try {
                $options = [];
                if ($json_body !== null) {
                    $options['json'] = $json_body;
                }
                if ($params !== null) {
                    $options['query'] = $params;
                }

                $response = Http::withHeaders($this->headers())->timeout($timeout)->send($method, $url, $options);

                if ($response->successful()) {
                    $decoded = $response->json();
                    if ($decoded !== null) {
                        return [$decoded, $path];
                    }

                    return [['data' => $response->body()], $path];
                }

                $errors[] = "{$path}: HTTP {$response->status()} " . substr($response->body(), 0, 180);
            } catch (\Throwable $exc) {
                $errors[] = "{$path}: " . $exc->getMessage();
            }
        }

        throw new \RuntimeException(
            'No compatible API endpoint responded successfully. ' . implode(' | ', array_slice($errors, -4))
        );
    }

    public static function _unwrap($body)
    {
        $value = $body;
        for ($i = 0; $i < 4; $i++) {
            if (!is_array($value)) {
                break;
            }

            $found = false;
            foreach (['data', 'result', 'payload'] as $key) {
                if (array_key_exists($key, $value) && $value[$key] !== null) {
                    $value = $value[$key];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                break;
            }
        }

        return $value;
    }

    public static function _as_list($body, array $keys = []): array
    {
        $value = self::_unwrap($body);

        if (self::_is_list($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach (array_merge($keys, ['items', 'results', 'list']) as $key) {
                if (isset($value[$key]) && self::_is_list($value[$key])) {
                    return $value[$key];
                }
            }
        }

        return [];
    }

    public static function _normalize_items(array $items, array $id_keys = ['id', 'uuid'], array $name_keys = ['name', 'title']): array
    {
        $output = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $output[] = ['id' => $item, 'name' => $item];
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $itemId = '';
            foreach ($id_keys as $key) {
                if (!empty($item[$key])) {
                    $itemId = $item[$key];
                    break;
                }
            }

            $name = '';
            foreach ($name_keys as $key) {
                if (!empty($item[$key])) {
                    $name = $item[$key];
                    break;
                }
            }

            if ($itemId !== '') {
                $output[] = [
                    'id' => (string) $itemId,
                    'name' => (string) ($name !== '' ? $name : $itemId),
                    'raw' => $item,
                ];
            }
        }

        return $output;
    }

    /**
     * Best-effort extraction only; the token signature is still verified by Multilogin.
     */
    public function workspace_id_from_token(): string
    {
        try {
            $parts = explode('.', $this->token);
            if (count($parts) !== 3) {
                return '';
            }

            $payload = strtr($parts[1], '-_', '+/');
            $padLength = strlen($payload) % 4;
            if ($padLength) {
                $payload .= str_repeat('=', 4 - $padLength);
            }

            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                return '';
            }

            $claims = json_decode($decoded, true);
            if (!is_array($claims)) {
                return '';
            }

            $dataClaim = $claims['data'] ?? null;
            $candidates = [
                $claims['workspace_id'] ?? null,
                $claims['workspaceId'] ?? null,
                $claims['workspaceID'] ?? null,
                $claims['workspace'] ?? null,
                is_array($dataClaim) ? ($dataClaim['workspace_id'] ?? null) : null,
                is_array($dataClaim) ? ($dataClaim['workspaceID'] ?? null) : null,
            ];

            foreach ($candidates as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                if (is_array($value)) {
                    foreach (['id', 'workspace_id'] as $key) {
                        if (!empty($value[$key])) {
                            return (string) $value[$key];
                        }
                    }
                }
            }
        } catch (\Throwable $exc) {
            // Best effort only.
        }

        return '';
    }

    /**
     * @return array{0: array, 1: string}
     */
    public function get_workspaces(): array
    {
        $configured = $this->cfg['workspaces_endpoint'] ?? '';
        [$body, $endpoint] = $this->_request_candidates(
            'GET',
            $configured,
            [
                '/workspace/user',
                '/workspaces',
                '/workspace',
                '/v1/workspaces',
                '/api/v1/workspaces',
            ]
        );

        $items = self::_as_list($body, ['workspaces', 'workspace']);
        $normalized = self::_normalize_items(
            $items,
            ['id', 'workspace_id', 'uuid'],
            ['name', 'workspace_name', 'title']
        );

        if (!$normalized) {
            $value = self::_unwrap($body);
            if (is_array($value) && !self::_is_list($value)) {
                $normalized = self::_normalize_items(
                    [$value],
                    ['id', 'workspace_id', 'uuid'],
                    ['name', 'workspace_name', 'title']
                );
            }
        }

        return [$normalized, $endpoint];
    }

    /**
     * @return array{0: array, 1: string}
     */
    public function get_folders(string $workspace_id): array
    {
        $configured = $this->cfg['folders_endpoint'] ?? '';
        [$body, $endpoint] = $this->_request_candidates(
            'GET',
            $configured,
            [
                "/workspace/{$workspace_id}/folders",
                "/workspaces/{$workspace_id}/folders",
                '/workspace/folders',
                '/folders',
                '/v1/folders',
                '/api/v1/folders',
            ],
            null,
            $workspace_id ? ['workspace_id' => $workspace_id] : null
        );

        $items = self::_as_list($body, ['folders', 'folder']);
        $normalized = self::_normalize_items(
            $items,
            ['id', 'folder_id', 'uuid'],
            ['name', 'folder_name', 'title']
        );

        return [$normalized, $endpoint];
    }

    public static function _profile_folder_id(array $profile): string
    {
        $raw = is_array($profile['raw'] ?? null) ? $profile['raw'] : [];

        foreach (['folder_id', 'folderId', 'group_id'] as $key) {
            if (!empty($raw[$key])) {
                return (string) $raw[$key];
            }
        }

        $folder = $raw['folder'] ?? null;
        if (is_array($folder)) {
            return (string) ($folder['id'] ?? $folder['folder_id'] ?? '');
        }

        return '';
    }

    public static function _profile_is_template(array $profile, string $name_filter = 'template'): bool
    {
        $raw = is_array($profile['raw'] ?? null) ? $profile['raw'] : [];

        foreach (['is_template', 'template'] as $key) {
            if (($raw[$key] ?? null) === true) {
                return true;
            }
        }

        $profileType = strtolower((string) ($raw['profile_type'] ?? $raw['type'] ?? $raw['kind'] ?? ''));
        if ($profileType === 'template') {
            return true;
        }

        $needle = strtolower(trim($name_filter ?: 'template'));

        return $needle !== '' && str_contains(strtolower((string) ($profile['name'] ?? '')), $needle);
    }

    public static function _response_total($body): ?int
    {
        $candidates = [];
        if (is_array($body)) {
            $candidates[] = $body;
            foreach (['data', 'result', 'payload'] as $key) {
                if (is_array($body[$key] ?? null)) {
                    $candidates[] = $body[$key];
                }
            }
        }

        foreach ($candidates as $obj) {
            foreach ([
                'total', 'total_count', 'totalCount', 'count',
                'profiles_count', 'records_total', 'all_profiles_count',
                'total_profiles', 'total_elements',
            ] as $key) {
                $value = $obj[$key] ?? null;
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value) && ctype_digit($value)) {
                    return (int) $value;
                }
            }

            $pagination = $obj['pagination'] ?? null;
            if (is_array($pagination)) {
                foreach (['total', 'total_count', 'count'] as $key) {
                    $value = $pagination[$key] ?? null;
                    if (is_int($value)) {
                        return $value;
                    }
                    if (is_string($value) && ctype_digit($value)) {
                        return (int) $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Retrieve the complete browser-profile inventory.
     *
     * Multilogin deployments can cap page_len and can use either page-based or
     * offset-based pagination. This loader tries both forms, deduplicates by
     * profile ID, and continues until the reported total is reached or no new
     * profiles are returned.
     *
     * @return array{0: array, 1: string}
     */
    public function get_profiles(string $workspace_id = '', string $folder_id = '', string $search_text = ''): array
    {
        $configured = $this->cfg['profile_search_endpoint'] ?? '';
        $endpointCandidates = [
            '/profile/search',
            '/profiles/search',
            '/v1/profile/search',
            '/api/v1/profile/search',
        ];

        $requestedSize = (int) ($this->cfg['profile_page_size'] ?? 100);
        if ($requestedSize <= 0) {
            $requestedSize = 100;
        }
        $requestedSize = max(10, min($requestedSize, 1000));

        $maxPages = (int) ($this->cfg['profile_max_pages'] ?? 1000);
        if ($maxPages <= 0) {
            $maxPages = 1000;
        }

        $allProfiles = [];
        $seenIds = [];
        $endpointUsed = '';
        $reportedTotal = null;
        $pagesRequested = 0;

        $addItems = function ($body) use (&$reportedTotal, &$allProfiles, &$seenIds): int {
            if ($reportedTotal === null) {
                $reportedTotal = self::_response_total($body);
            }

            $items = self::_as_list($body, ['profiles', 'profile']);
            $normalized = self::_normalize_items(
                $items,
                ['id', 'profile_id', 'uuid'],
                ['name', 'profile_name', 'title']
            );

            $added = 0;
            foreach ($normalized as $profile) {
                $pid = (string) ($profile['id'] ?? '');
                if ($pid === '' || isset($seenIds[$pid])) {
                    continue;
                }
                $seenIds[$pid] = true;
                $allProfiles[] = $profile;
                $added++;
            }

            return $added;
        };

        $filterPayload = static function (array $payload): array {
            return array_filter($payload, static function ($v, $k) {
                return $v !== null && ($v !== '' || $k === 'search_text');
            }, ARRAY_FILTER_USE_BOTH);
        };

        // Strategy 1: page-based pagination. Start at 0, then 1. Some deployments
        // treat page 0 as the first page while others expect page 1.
        $noNewStreak = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $payload = $filterPayload([
                'search_text' => $search_text,
                'workspace_id' => $workspace_id,
                'folder_id' => $folder_id,
                'page' => $page,
                'page_len' => $requestedSize,
            ]);

            [$body, $endpointUsed] = $this->_request_candidates(
                'POST',
                $configured,
                $endpointCandidates,
                $payload,
                null,
                45
            );
            $pagesRequested++;
            $added = $addItems($body);

            if ($reportedTotal !== null && count($allProfiles) >= $reportedTotal) {
                break;
            }
            if ($added === 0) {
                $noNewStreak++;
                // Page 0 may alias page 1, so allow one duplicate response.
                if ($noNewStreak >= 2) {
                    break;
                }
            } else {
                $noNewStreak = 0;
            }
        }

        // Strategy 2: offset pagination fallback. It is safe because IDs are
        // deduplicated. This catches deployments that ignore the page field.
        if ($reportedTotal === null || count($allProfiles) < $reportedTotal) {
            $noNewStreak = 0;
            for ($offset = 0; $offset < $requestedSize * $maxPages; $offset += $requestedSize) {
                $payload = $filterPayload([
                    'search_text' => $search_text,
                    'workspace_id' => $workspace_id,
                    'folder_id' => $folder_id,
                    'limit' => $requestedSize,
                    'offset' => $offset,
                ]);

                [$body, $endpointUsed] = $this->_request_candidates(
                    'POST',
                    $configured,
                    $endpointCandidates,
                    $payload,
                    null,
                    45
                );
                $pagesRequested++;
                $added = $addItems($body);

                if ($reportedTotal !== null && count($allProfiles) >= $reportedTotal) {
                    break;
                }
                if ($added === 0) {
                    $noNewStreak++;
                    if ($noNewStreak >= 2) {
                        break;
                    }
                } else {
                    $noNewStreak = 0;
                }
            }
        }

        $this->last_profile_scan = [
            'loaded' => count($allProfiles),
            'reported_total' => $reportedTotal,
            'pages_requested' => $pagesRequested,
            'complete' => $reportedTotal === null || count($allProfiles) >= $reportedTotal,
        ];

        return [$allProfiles, $endpointUsed];
    }

    public function discover(string $requested_workspace_id = ''): array
    {
        if ($this->simulation) {
            return [
                'connected' => true,
                'simulation' => true,
                'selected_workspace_id' => $requested_workspace_id ?: 'demo-workspace',
                'workspaces' => [[
                    'id' => $requested_workspace_id ?: 'demo-workspace',
                    'name' => 'Demo Workspace',
                ]],
                'folders' => [
                    ['id' => 'demo-geo-folder', 'name' => 'GEO Clients'],
                    ['id' => 'demo-static-folder', 'name' => 'STATIC Templates'],
                ],
                'profiles' => [
                    ['id' => 'demo-001', 'name' => '001 - Demo Client - GEO'],
                    ['id' => 'demo-002', 'name' => '002 - Demo Client - STATIC'],
                    ['id' => 'demo-template', 'name' => 'STATIC Windows Template'],
                ],
                'templates' => [
                    ['id' => 'demo-template', 'name' => 'STATIC Windows Template'],
                ],
                'numbering' => [
                    'profiles_scanned' => 3,
                    'numbers_used' => 2,
                    'highest_used' => 2,
                    'next_free' => 3,
                ],
                'endpoints' => [],
                'message' => 'Simulation discovery completed.',
            ];
        }

        // Workspace Automation Tokens are scoped to one workspace and can receive
        // FORBIDDEN_REQUEST on GET User Workspaces. Use the explicit workspace ID
        // or extract it from JWT claims when available.
        $workspaceId = $requested_workspace_id
            ?: ($this->cfg['workspace_id'] ?? '')
            ?: $this->workspace_id_from_token();

        if (!$workspaceId) {
            throw new \RuntimeException(
                'Workspace ID is required with a Workspace Automation Token. ' .
                'In Multilogin open Account settings / Info and copy Workspace ID. ' .
                'The Workspace ID is also the ID of the Default folder.'
            );
        }

        // Profile search accepts Workspace Automation Tokens. Folder listing may
        // require a user JWT on some Multilogin API deployments, so never let that
        // optional request block the whole connection.
        $folderWarning = '';
        $foldersEndpoint = '';
        try {
            [$folders, $foldersEndpoint] = $this->get_folders($workspaceId);
        } catch (\Throwable $exc) {
            $folders = [];
            $folderWarning = $exc->getMessage();
        }

        // The default folder ID is identical to the Workspace ID.
        $hasWorkspaceFolder = false;
        foreach ($folders as $item) {
            if (($item['id'] ?? null) === $workspaceId) {
                $hasWorkspaceFolder = true;
                break;
            }
        }
        if (!$hasWorkspaceFolder) {
            array_unshift($folders, ['id' => $workspaceId, 'name' => 'Default folder (Workspace ID)']);
        }

        // Preserve manually configured GEO/STATIC folders even if folder listing
        // is unavailable for this token type.
        foreach ([
            ['geo_folder_id', 'Configured GEO folder'],
            ['static_folder_id', 'Configured STATIC folder'],
        ] as [$cfgKey, $label]) {
            $configuredId = $this->cfg[$cfgKey] ?? '';
            if (!$configuredId) {
                continue;
            }
            $exists = false;
            foreach ($folders as $item) {
                if (($item['id'] ?? null) === $configuredId) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $folders[] = ['id' => $configuredId, 'name' => $label];
            }
        }

        // Load the complete workspace profile inventory, not only the first page.
        [$profiles, $profilesEndpoint] = $this->get_profiles($workspaceId, '', '');

        $usedNumbersSet = [];
        foreach ($profiles as $profile) {
            $number = $this->profileNumbers->extractNumber($profile['name'] ?? '');
            if ($number !== null) {
                $usedNumbersSet[$number] = true;
            }
        }
        $usedNumbers = array_keys($usedNumbersSet);
        sort($usedNumbers);
        $highestUsed = $usedNumbers ? max($usedNumbers) : 0;
        $usedSet = array_flip($usedNumbers);

        $nextFree = null;
        for ($n = max(1, $highestUsed + 1); $n < 1000; $n++) {
            if (!isset($usedSet[$n])) {
                $nextFree = $n;
                break;
            }
        }
        if ($nextFree === null) {
            for ($n = 1; $n < 1000; $n++) {
                if (!isset($usedSet[$n])) {
                    $nextFree = $n;
                    break;
                }
            }
        }

        $templateNameFilter = $this->cfg['template_name_filter'] ?? 'template';
        $savedTemplateId = $this->cfg['template_profile_id'] ?? '';
        $templates = array_values(array_filter($profiles, function ($profile) use ($templateNameFilter, $savedTemplateId) {
            return self::_profile_is_template($profile, $templateNameFilter)
                || ($savedTemplateId && ($profile['id'] ?? null) === $savedTemplateId);
        }));

        // Represent the already-selected workspace in the dropdown. Do not call
        // GET User Workspaces because automation tokens may not have that permission.
        $workspaces = [['id' => $workspaceId, 'name' => 'Workspace ' . substr($workspaceId, 0, 8) . '…']];

        $message = sprintf(
            'Connected to workspace: %d complete profile(s) scanned. %d numbered profile(s); next free is %s. %d template candidate(s).',
            count($profiles),
            count($usedNumbers),
            $nextFree ? $this->profileNumbers->formatNumber($nextFree) : 'none',
            count($templates)
        );
        if ($folderWarning) {
            $message .= ' Folder auto-list was unavailable for this token, so the default '
                . 'workspace folder and manually entered folder IDs are being used.';
        }

        return [
            'connected' => true,
            'simulation' => false,
            'selected_workspace_id' => $workspaceId,
            'workspaces' => $workspaces,
            'folders' => $folders,
            'profiles' => $profiles,
            'templates' => $templates,
            'numbering' => [
                'profiles_scanned' => count($profiles),
                'reported_total' => $this->last_profile_scan['reported_total'] ?? null,
                'pages_requested' => $this->last_profile_scan['pages_requested'] ?? null,
                'complete' => $this->last_profile_scan['complete'] ?? false,
                'numbers_used' => count($usedNumbers),
                'highest_used' => $highestUsed,
                'next_free' => $nextFree,
            ],
            'folder_warning' => $folderWarning,
            'endpoints' => [
                'folders_endpoint' => $foldersEndpoint,
                'profile_search_endpoint' => $profilesEndpoint,
            ],
            'message' => $message,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function test(): array
    {
        $result = $this->discover($this->cfg['workspace_id'] ?? '');

        return [true, $result['message']];
    }

    /**
     * Refresh the current bearer token via POST /user/refresh_token so a valid
     * token is always available. Returns the new token, or null if refresh is
     * not available (e.g. simulation, no token, or endpoint not supported).
     */
    public function refresh_token(): ?string
    {
        if ($this->simulation || $this->token === '') {
            return null;
        }

        foreach (['/user/refresh_token', '/v1/user/refresh_token', '/api/v1/user/refresh_token'] as $path) {
            try {
                $response = Http::withHeaders($this->headers())
                    ->timeout(20)
                    ->post($this->base_url.$path, ['token' => $this->token, 'email' => $this->cfg['email'] ?? '']);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $token = self::_extract_token($response->json());
            if ($token) {
                return $token;
            }
        }

        return null;
    }

    public static function _extract_token($body): ?string
    {
        $data = (is_array($body) && array_key_exists('data', $body)) ? $body['data'] : $body;

        if (is_array($data)) {
            foreach (['token', 'access_token', 'accessToken', 'bearer', 'jwt'] as $key) {
                if (! empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }

        return is_string($body) && $body !== '' ? $body : null;
    }

    public function search_profiles(): array
    {
        if ($this->simulation) {
            return BrowserProfile::where('status', 'created')->get()
                ->map(fn ($p) => ['id' => $p->multilogin_profile_id, 'name' => $p->profile_name])
                ->all();
        }

        [$profiles] = $this->get_profiles($this->cfg['workspace_id'] ?? '');

        return $profiles;
    }

    /**
     * Use the visitor/appointment IPinfo result only. Never geolocate the CRM
     * server or local Windows node.
     */
    public static function _location_from_appointment($appointment): array
    {
        return [
            'country' => trim((string) (self::attr($appointment, 'country_code') ?: self::attr($appointment, 'country') ?: '')),
            'region' => trim((string) (self::attr($appointment, 'region') ?: '')),
            'city' => trim((string) (self::attr($appointment, 'city') ?: '')),
            'timezone' => trim((string) (self::attr($appointment, 'timezone') ?: self::attr($appointment, 'invitee_timezone') ?: 'UTC')),
        ];
    }

    /**
     * Reads a property off either an array or an object (e.g. an Eloquent
     * model), mirroring Python's `getattr(obj, key, default)` semantics used
     * throughout the original `appointment` handling.
     */
    protected static function attr($appointment, string $key)
    {
        if (is_array($appointment)) {
            return $appointment[$key] ?? null;
        }

        return $appointment->{$key} ?? null;
    }

    public static function _extract_proxy($body): array
    {
        $value = $body;

        for ($i = 0; $i < 5; $i++) {
            if (is_array($value) && !self::_is_list($value)) {
                $hasHost = ($value['host'] ?? $value['ip'] ?? $value['server'] ?? null);
                if ($hasHost && ($value['port'] ?? null)) {
                    break;
                }

                $moved = false;
                foreach (['data', 'result', 'proxy', 'payload'] as $key) {
                    if (($value[$key] ?? null) !== null) {
                        $value = $value[$key];
                        $moved = true;
                        break;
                    }
                }

                if (!$moved) {
                    // Some responses contain an array of generated proxies.
                    foreach (['proxies', 'items', 'results'] as $key) {
                        if (self::_is_list($value[$key] ?? null) && !empty($value[$key])) {
                            $value = $value[$key][0];
                            $moved = true;
                            break;
                        }
                    }
                }

                if (!$moved) {
                    break;
                }
            } elseif (self::_is_list($value) && !empty($value)) {
                $value = $value[0];
            } else {
                break;
            }
        }

        if (!is_array($value) || self::_is_list($value)) {
            throw new \RuntimeException('Proxy was generated but details were not recognized: ' . json_encode($body));
        }

        $host = $value['host'] ?? $value['ip'] ?? $value['server'] ?? null;
        $port = $value['port'] ?? null;
        $username = $value['username'] ?? $value['login'] ?? $value['user'] ?? '';
        $password = $value['password'] ?? $value['pass'] ?? '';
        $protocol = $value['protocol'] ?? $value['type'] ?? $value['proxy_type'] ?? 'http';

        if (!$host || !$port) {
            throw new \RuntimeException('Generated proxy response is missing host/port: ' . json_encode($body));
        }

        return [
            'host' => (string) $host,
            'port' => (int) $port,
            'username' => (string) $username,
            'password' => (string) $password,
            'protocol' => strtolower((string) $protocol),
            'raw' => $value,
        ];
    }

    public static function _normalize_location_text(?string $value): string
    {
        $value = self::strip_diacritics((string) ($value ?? ''));
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    protected static function strip_diacritics(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($normalized !== false) {
                $stripped = preg_replace('/\p{Mn}/u', '', $normalized);
                if ($stripped !== null) {
                    return $stripped;
                }
            }
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $transliterated !== false ? $transliterated : $value;
    }

    public static function _snake_case_location(?string $value): string
    {
        return str_replace(' ', '_', self::_normalize_location_text($value));
    }

    public static function _country_code(?string $value): string
    {
        $value = strtolower(preg_replace('/[^A-Za-z]/', '', (string) ($value ?? '')));

        if (strlen($value) !== 2) {
            throw new \RuntimeException("IPinfo country must be an ISO 3166-1 alpha-2 code, got: '{$value}'");
        }

        return $value;
    }

    public static function proxy_location_attempts(array $location): array
    {
        $country = self::_country_code($location['country'] ?? '');
        $region = self::_snake_case_location($location['region'] ?? '');
        $city = self::_snake_case_location($location['city'] ?? '');

        $attempts = [];
        if ($country && $region && $city) {
            $attempts[] = ['country' => $country, 'region' => $region, 'city' => $city];
        }
        if ($country && $region) {
            $attempts[] = ['country' => $country, 'region' => $region, 'city' => ''];
        }
        if ($country) {
            $attempts[] = ['country' => $country, 'region' => '', 'city' => ''];
        }

        return $attempts;
    }

    /**
     * Find a proxy connection in all known Multilogin response shapes.
     */
    public static function _find_proxy_value($value)
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (self::_is_list($value)) {
            foreach ($value as $item) {
                $found = self::_find_proxy_value($item);
                if ($found) {
                    return $found;
                }
            }

            return null;
        }

        if (is_array($value)) {
            $host = $value['host'] ?? $value['ip'] ?? $value['server'] ?? null;
            $port = $value['port'] ?? null;
            if ($host && $port) {
                return [
                    'host' => $host,
                    'port' => $port,
                    'username' => $value['username'] ?? $value['login'] ?? $value['user'] ?? '',
                    'password' => $value['password'] ?? $value['pass'] ?? '',
                ];
            }

            foreach ([
                'connection_url', 'connectionUrl', 'connection', 'url', 'proxy_url', 'proxyUrl',
                'data', 'result', 'proxy', 'proxies', 'items', 'connections', 'payload',
            ] as $key) {
                if (array_key_exists($key, $value)) {
                    $found = self::_find_proxy_value($value[$key]);
                    if ($found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    public static function _parse_multilogin_connection_url($connection): array
    {
        $found = self::_find_proxy_value($connection);

        if (is_array($found)) {
            if (!isset($found['host'], $found['port']) || !is_numeric($found['port'])) {
                throw new \RuntimeException('Invalid structured proxy response: ' . json_encode($found));
            }

            return [
                'host' => (string) $found['host'],
                'port' => (int) $found['port'],
                'username' => (string) ($found['username'] ?? ''),
                'password' => (string) ($found['password'] ?? ''),
            ];
        }

        if (!is_string($found) || $found === '') {
            throw new \RuntimeException(
                'Multilogin proxy response did not contain a recognized connection. Raw response: ' . json_encode($connection)
            );
        }

        // Only attempt the bare "host:port:user:pass" colon-split when the
        // string does not look like a URL. A string such as
        // "http://user:pass@host:port" also contains exactly three colons,
        // so testing this first (as a naive port would) misparses "http" as
        // the host and fails; checking for "://" first keeps this correct.
        if (!str_contains($found, '://')) {
            $parts = explode(':', $found, 4);
            if (count($parts) === 4) {
                [$host, $port, $username, $password] = $parts;
                if (!is_numeric($port)) {
                    throw new \RuntimeException("Invalid proxy port in connection string: '{$found}'");
                }

                return [
                    'host' => $host,
                    'port' => (int) $port,
                    'username' => $username,
                    'password' => $password,
                ];
            }
        }

        $urlToParse = str_contains($found, '://') ? $found : "http://{$found}";
        $parsed = @parse_url($urlToParse);
        if (is_array($parsed) && !empty($parsed['host']) && !empty($parsed['port'])) {
            return [
                'host' => $parsed['host'],
                'port' => (int) $parsed['port'],
                'username' => $parsed['user'] ?? '',
                'password' => $parsed['pass'] ?? '',
            ];
        }

        throw new \RuntimeException(
            "Unexpected Multilogin proxy connection format. Received: '{$found}'. Raw response: " . json_encode($connection)
        );
    }

    public static function _proxy_match_level(string $requested_region, string $requested_city, string $returned_username): string
    {
        $username = str_replace(' ', '_', self::_normalize_location_text($returned_username));
        $region = self::_snake_case_location($requested_region);
        $city = self::_snake_case_location($requested_city);

        $cityMatch = $city !== '' && str_contains($username, "city_{$city}");
        $regionMatch = $region !== '' && str_contains($username, "region_{$region}");

        if ($cityMatch && $regionMatch) {
            return 'city_region';
        }
        if ($cityMatch) {
            return 'city';
        }
        if ($regionMatch) {
            return 'region';
        }

        return 'country';
    }

    public static function _isp_name(array $info): string
    {
        $isp = trim((string) ($info['isp'] ?? ''));
        $org = trim((string) ($info['org'] ?? ''));

        if ($isp) {
            return $isp;
        }

        // IPinfo core commonly returns "AS12345 Provider Name" in org.
        return trim(preg_replace('/^AS\d+\s+/i', '', $org) ?? $org);
    }

    public static function _isp_similarity(string $client_isp, string $proxy_isp): int
    {
        $a = self::_normalize_location_text($client_isp);
        $b = self::_normalize_location_text($proxy_isp);

        if (!$a || !$b) {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        if (str_contains($b, $a) || str_contains($a, $b)) {
            return 90;
        }

        $aTokens = array_unique(array_filter(explode(' ', $a)));
        $bTokens = array_unique(array_filter(explode(' ', $b)));
        if (!$aTokens || !$bTokens) {
            return 0;
        }

        $overlap = count(array_intersect($aTokens, $bTokens));
        $union = count(array_unique(array_merge($aTokens, $bTokens)));

        return (int) (($overlap / $union) * 80);
    }

    /**
     * Resolve the real exit IP and network information through the generated
     * Multilogin proxy. This is how ISP/city/region are verified.
     */
    public function inspect_proxy_exit(array $proxy): array
    {
        $cfg = $this->settings->getSettings('ipinfo');
        $token = $cfg['api_token'] ?? '';
        if (!$token) {
            throw new \RuntimeException('IPinfo API token is required to inspect proxy ISP.');
        }

        $scheme = strtolower((string) ($proxy['protocol'] ?? 'http'));
        if ($scheme === 'socks5') {
            $proxyUrl = "socks5h://{$proxy['username']}:{$proxy['password']}@{$proxy['host']}:{$proxy['port']}";
        } else {
            $proxyUrl = "http://{$proxy['username']}:{$proxy['password']}@{$proxy['host']}:{$proxy['port']}";
        }

        $response = Http::withOptions(['proxy' => $proxyUrl])
            ->timeout(35)
            ->get('https://ipinfo.io/json', ['token' => $token]);
        $response->throw();
        $data = $response->json() ?? [];

        $company = is_array($data['company'] ?? null) ? $data['company'] : [];
        $asnData = is_array($data['asn'] ?? null) ? $data['asn'] : [];
        $org = (string) ($data['org'] ?? $company['name'] ?? '');
        $isp = trim((string) ($company['name'] ?? ''));
        if (!$isp) {
            $isp = trim(preg_replace('/^AS\d+\s+/i', '', $org) ?? $org);
        }

        return [
            'ip' => $data['ip'] ?? '',
            'city' => $data['city'] ?? '',
            'region' => $data['region'] ?? '',
            'country' => $data['country'] ?? '',
            'org' => $org,
            'isp' => $isp,
            'asn' => $asnData['asn'] ?? (str_starts_with(strtoupper($org), 'AS') ? explode(' ', $org, 2)[0] : ''),
            'raw' => $data,
        ];
    }

    public function generate_proxy_candidates($appointment, int $count = 5): array
    {
        $count = max(1, min($count ?: 5, 10));
        $clientIsp = (string) (self::attr($appointment, 'client_isp') ?: self::attr($appointment, 'client_org') ?: '');
        $candidates = [];
        $errors = [];

        for ($index = 0; $index < $count; $index++) {
            try {
                $proxy = $this->generate_multilogin_proxy($appointment);
                $exitInfo = [];
                $inspectError = null;
                try {
                    $exitInfo = $this->inspect_proxy_exit($proxy);
                } catch (\Throwable $inspectExc) {
                    // Multilogin may return a usable proxy even when exit inspection
                    // through CONNECT fails (common with some residential nodes / IPv6 targets).
                    $inspectError = $inspectExc->getMessage();
                    $errors[] = $inspectError;
                }

                $proxyIsp = self::_isp_name($exitInfo);
                $score = self::_isp_similarity($clientIsp, $proxyIsp);
                $cityMatch = self::_normalize_location_text($exitInfo['city'] ?? '')
                    === self::_normalize_location_text((string) (self::attr($appointment, 'city') ?? ''));
                $regionMatch = self::_normalize_location_text($exitInfo['region'] ?? '')
                    === self::_normalize_location_text((string) (self::attr($appointment, 'region') ?? ''));

                $targetCity = (string) (self::attr($appointment, 'city') ?? '');
                $targetRegion = (string) (self::attr($appointment, 'region') ?? '');
                $targetCountry = (string) (self::attr($appointment, 'country_code')
                    ?: self::attr($appointment, 'country')
                    ?: '');

                $candidates[] = [
                    'id' => $index,
                    'host' => $proxy['host'],
                    'port' => (int) $proxy['port'],
                    'username' => $proxy['username'],
                    'password' => $proxy['password'],
                    'protocol' => $proxy['protocol'],
                    'exit_ip' => $exitInfo['ip'] ?? '',
                    'city' => ($exitInfo['city'] ?? '') !== '' ? $exitInfo['city'] : $targetCity,
                    'region' => ($exitInfo['region'] ?? '') !== '' ? $exitInfo['region'] : $targetRegion,
                    'country' => ($exitInfo['country'] ?? '') !== '' ? $exitInfo['country'] : $targetCountry,
                    'isp' => $proxyIsp,
                    'org' => $exitInfo['org'] ?? '',
                    'asn' => $exitInfo['asn'] ?? '',
                    'isp_score' => $score,
                    'city_match' => $cityMatch,
                    'region_match' => $regionMatch,
                    'location_score' => ($cityMatch ? 50 : 0) + ($regionMatch ? 30 : 0),
                    'inspect_failed' => $inspectError !== null,
                    'inspect_error' => $inspectError,
                ];
            } catch (\Throwable $exc) {
                $errors[] = $exc->getMessage();
            }
        }

        if (!$candidates) {
            throw new \RuntimeException(
                'No Multilogin proxy candidates could be generated. ' . ($errors ? implode(' | ', array_slice($errors, 0, 3)) : '')
            );
        }

        usort($candidates, function ($a, $b) {
            if ($a['isp_score'] !== $b['isp_score']) {
                return $b['isp_score'] <=> $a['isp_score'];
            }

            return $b['location_score'] <=> $a['location_score'];
        });

        foreach ($candidates as $idx => &$item) {
            $item['id'] = $idx;
        }
        unset($item);

        return $candidates;
    }

    public function select_proxy_candidate($appointment, array $candidate): array
    {
        $appointment->proxy_status = 'ready';
        $appointment->proxy_host = $candidate['host'];
        $appointment->proxy_port = (int) $candidate['port'];
        $appointment->proxy_username = $candidate['username'];
        $appointment->proxy_password = $candidate['password'];
        $appointment->proxy_protocol = $candidate['protocol'];

        $appointment->proxy_exit_ip = $candidate['exit_ip'] ?? '';
        $appointment->proxy_isp = $candidate['isp'] ?? '';
        $appointment->proxy_org = $candidate['org'] ?? '';
        $appointment->proxy_asn = $candidate['asn'] ?? '';
        $appointment->proxy_actual_country = $candidate['country'] ?? '';
        $appointment->proxy_actual_region = $candidate['region'] ?? '';
        $appointment->proxy_actual_city = $candidate['city'] ?? '';

        $appointment->proxy_country = $candidate['country'] ?? '';
        $appointment->proxy_region = $candidate['region'] ?? '';
        $appointment->proxy_city = $candidate['city'] ?? '';
        $appointment->proxy_requested_region = self::_snake_case_location((string) (self::attr($appointment, 'region') ?? ''));
        $appointment->proxy_requested_city = self::_snake_case_location((string) (self::attr($appointment, 'city') ?? ''));

        $parts = [];
        if (!empty($candidate['city_match'])) {
            $parts[] = 'city';
        }
        if (!empty($candidate['region_match'])) {
            $parts[] = 'region';
        }
        if (($candidate['isp_score'] ?? 0) >= 90) {
            $parts[] = 'isp';
        }
        $appointment->proxy_match_level = $parts ? implode('_', $parts) : 'country';
        $appointment->proxy_created_at = now('UTC');
        $appointment->proxy_last_error = null;
        $appointment->save();

        return $candidate;
    }

    public function save_proxy_for_appointment($appointment, int $candidate_count = 5, bool $auto_select = true): array
    {
        $candidates = $this->generate_proxy_candidates($appointment, $candidate_count);
        $appointment->proxy_candidates_json = json_encode($candidates, JSON_UNESCAPED_UNICODE);

        $selected = $candidates[0];
        if ($auto_select) {
            $this->select_proxy_candidate($appointment, $selected);
        } else {
            $appointment->proxy_status = 'candidates_ready';
            $appointment->save();
        }

        $this->audit->log(
            'Generated Multilogin proxy candidates',
            sprintf(
                'Appointment=%s; candidates=%d; client_isp=%s; best_isp=%s; score=%s',
                self::attr($appointment, 'id'),
                count($candidates),
                self::attr($appointment, 'client_isp') ?? '',
                $selected['isp'] ?? '',
                $selected['isp_score'] ?? ''
            )
        );

        $selected['candidates'] = $candidates;

        return $selected;
    }

    public static function saved_proxy_from_appointment($appointment): ?array
    {
        if (
            self::attr($appointment, 'proxy_status') === 'ready'
            && self::attr($appointment, 'proxy_host')
            && self::attr($appointment, 'proxy_port')
        ) {
            return [
                'host' => self::attr($appointment, 'proxy_host'),
                'port' => (int) self::attr($appointment, 'proxy_port'),
                'username' => self::attr($appointment, 'proxy_username') ?? '',
                'password' => self::attr($appointment, 'proxy_password') ?? '',
                'protocol' => self::attr($appointment, 'proxy_protocol') ?: 'http',
                'target_location' => [
                    'country' => self::attr($appointment, 'proxy_country') ?? '',
                    'region' => self::attr($appointment, 'proxy_region') ?? '',
                    'city' => self::attr($appointment, 'proxy_city') ?? '',
                    'timezone' => self::attr($appointment, 'timezone') ?? '',
                ],
            ];
        }

        return null;
    }

    /**
     * Generate a Multilogin proxy using the documented endpoint:
     * POST https://profile-proxy.multilogin.com/v1/proxy/connection_url
     */
    public function generate_multilogin_proxy($appointment): array
    {
        $location = self::_location_from_appointment($appointment);

        $protocol = strtolower((string) ($this->cfg['multilogin_proxy_protocol'] ?? 'http'));
        if (!in_array($protocol, ['http', 'socks5'], true)) {
            throw new \RuntimeException('Multilogin proxy protocol must be http or socks5.');
        }

        $sessionType = strtolower((string) ($this->cfg['multilogin_proxy_session_type'] ?? 'sticky'));
        if (!in_array($sessionType, ['sticky', 'rotating'], true)) {
            throw new \RuntimeException('Multilogin proxy session type must be sticky or rotating.');
        }

        $ipTtl = (int) ($this->cfg['multilogin_proxy_ip_ttl'] ?? 0);
        if ($ipTtl < 0 || $ipTtl > 86400) {
            throw new \RuntimeException('Multilogin proxy IPTTL must be between 0 and 86400.');
        }

        $endpoint = $this->cfg['proxy_generate_endpoint'] ?? 'https://profile-proxy.multilogin.com/v1/proxy/connection_url';
        $strictMode = in_array(
            strtolower((string) ($this->cfg['multilogin_proxy_strict_mode'] ?? 'false')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        $headers = $this->headers();
        $headers['X-Strict-Mode'] = $strictMode ? 'true' : 'false';

        $attempts = self::proxy_location_attempts($location);
        $errors = [];

        foreach ($attempts as $attempt) {
            $payload = [
                'country' => $attempt['country'],
                'sessionType' => $sessionType,
                'protocol' => $protocol,
                'IPTTL' => $ipTtl,
                'count' => 1,
            ];
            if ($attempt['region'] !== '') {
                $payload['region'] = $attempt['region'];
            }
            if ($attempt['city'] !== '') {
                $payload['city'] = $attempt['city'];
            }

            try {
                $response = Http::withHeaders($headers)->timeout(45)->post($endpoint, $payload);
            } catch (\Throwable $exc) {
                $errors[] = 'Multilogin proxy request failed: ' . $exc->getMessage();
                continue;
            }

            if (!in_array($response->status(), [200, 201], true)) {
                $errors[] = "Multilogin Generate Proxy failed. HTTP {$response->status()}: " . substr($response->body(), 0, 500);
                continue;
            }

            $body = $response->json();
            if ($body === null) {
                $errors[] = 'Multilogin proxy response was not JSON: ' . substr($response->body(), 0, 500);
                continue;
            }

            // Parse the complete response because Multilogin may return the proxy
            // as a string, list, nested object, or structured proxy.
            $proxy = self::_parse_multilogin_connection_url($body);
            $proxy['protocol'] = $protocol;
            $proxy['target_location'] = $location;
            $proxy['session_type'] = $sessionType;
            $proxy['raw'] = $body;

            return $proxy;
        }

        throw new \RuntimeException(implode('; ', $errors));
    }

    /**
     * Validate the exact Multilogin Profile Create proxy structure before
     * sending the request.
     */
    public static function _validate_profile_proxy_payload(array &$payload): void
    {
        $parameters = $payload['parameters'] ?? null;
        if (!is_array($parameters)) {
            throw new \RuntimeException('Profile payload is missing parameters.');
        }

        $proxy = $parameters['proxy'] ?? null;
        if (!is_array($proxy)) {
            throw new \RuntimeException('Multilogin Profile Create requires proxy inside parameters.proxy.');
        }

        $required = ['type', 'host', 'port', 'username', 'password'];
        $missing = [];
        foreach ($required as $key) {
            if (!isset($proxy[$key]) || trim((string) $proxy[$key]) === '') {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new \RuntimeException('Profile proxy payload is incomplete. Missing: ' . implode(', ', $missing));
        }

        if (!is_numeric($proxy['port'])) {
            throw new \RuntimeException('Profile proxy port is invalid: ' . var_export($proxy['port'], true));
        }
        $payload['parameters']['proxy']['port'] = (int) $proxy['port'];

        $flags = $parameters['flags'] ?? null;
        if (!is_array($flags)) {
            throw new \RuntimeException('Profile payload is missing parameters.flags.');
        }

        if (($flags['proxy_masking'] ?? null) !== 'custom') {
            throw new \RuntimeException(
                "parameters.flags.proxy_masking must be 'custom' when parameters.proxy is configured."
            );
        }
    }

    /**
     * URLs opened on every profile start when startup_behavior is custom.
     *
     * @return list<string>
     */
    public static function default_custom_start_urls(): array
    {
        return [
            'https://ipinfo.io/json',
        ];
    }

    protected function importDefaultBookmarks(string $profileId): void
    {
        if ($this->simulation || $profileId === '' || str_starts_with($profileId, 'sim-')) {
            return;
        }

        try {
            app(MultiloginBookmarkService::class)->importForProfile($profileId, $this->token);
            $this->audit->log('Imported Multilogin bookmarks', 'Profile='.$profileId);
        } catch (\Throwable $e) {
            // Profile create already succeeded; bookmarks require the local Multilogin agent.
            $this->audit->log(
                'Multilogin bookmark import failed',
                'Profile='.$profileId.'; '.$e->getMessage()
            );
        }
    }

    public function create_geo_profile(string $name, $appointment): string
    {
        if ($this->simulation) {
            return 'sim-geo-' . bin2hex(random_bytes(8));
        }

        $location = self::_location_from_appointment($appointment);
        $mlxProxy = self::saved_proxy_from_appointment($appointment);
        if (!$mlxProxy) {
            throw new \RuntimeException(
                'No saved proxy for this appointment. Click Get Proxy first, ' .
                'verify the city/region match, then create the GEO profile.'
            );
        }

        // Defaults mirror the Multilogin UI shown by the user:
        // WebRTC Masked, Timezone Masked, Geo Prompt + Masked, Languages Masked,
        // Screen Real, Fonts Masked, Media Real, Navigator Masked,
        // WebGL metadata Masked, WebGL/Canvas Noise, Audio Real, Ports Masked.
        $flags = [
            'webrtc_masking' => 'mask',
            'timezone_masking' => 'mask',
            'geolocation_popup' => 'prompt',
            'geolocation_masking' => 'mask',
            'localization_masking' => 'mask',
            'screen_masking' => 'natural',
            'fonts_masking' => 'mask',
            'media_devices_masking' => 'mask',
            'navigator_masking' => 'mask',
            'graphics_masking' => 'mask',
            'graphics_noise' => 'mask',
            'audio_masking' => 'natural',
            'ports_masking' => 'mask',
            'proxy_masking' => 'custom',
            'startup_behavior' => 'recover',
        ];

        $payload = [
            'name' => $name,
            'folder_id' => !empty($this->cfg['geo_folder_id']) ? $this->cfg['geo_folder_id'] : ($this->cfg['workspace_id'] ?? ''),
            'browser_type' => $this->cfg['browser_type'] ?? 'mimic',
            'os_type' => $this->cfg['os_type'] ?? 'windows',
            'times' => 1,
            'parameters' => [
                'flags' => $flags,
                'storage' => [
                    'is_local' => false,
                    'save_service_worker' => true,
                ],
                'fingerprint' => (object) [],
                'custom_start_urls' => self::default_custom_start_urls(),
                'proxy' => [
                    'type' => $mlxProxy['protocol'],
                    'host' => $mlxProxy['host'],
                    'port' => (int) $mlxProxy['port'],
                    'username' => $mlxProxy['username'],
                    'password' => $mlxProxy['password'],
                    'save_traffic' => false,
                ],
            ],
        ];

        self::_validate_profile_proxy_payload($payload);
        $profileId = $this->_post_create_auto($payload);
        $this->importDefaultBookmarks($profileId);

        $this->audit->log(
            'Created GEO profile with Multilogin proxy',
            sprintf(
                'Profile=%s; target=%s, %s, %s; proxy=%s:%s',
                $profileId,
                $location['city'],
                $location['region'],
                $location['country'],
                $mlxProxy['host'],
                $mlxProxy['port']
            )
        );

        return $profileId;
    }

    public function create_static_profile(string $name, array $proxy): string
    {
        if ($this->simulation) {
            return 'sim-static-' . bin2hex(random_bytes(8));
        }

        $flags = [
            'webrtc_masking' => 'mask',
            'timezone_masking' => 'mask',
            'geolocation_popup' => 'prompt',
            'geolocation_masking' => 'mask',
            'localization_masking' => 'mask',
            'screen_masking' => 'natural',
            'fonts_masking' => 'mask',
            'media_devices_masking' => 'mask',
            'navigator_masking' => 'mask',
            'graphics_masking' => 'mask',
            'graphics_noise' => 'mask',
            'audio_masking' => 'natural',
            'ports_masking' => 'mask',
            'proxy_masking' => 'custom',
            'startup_behavior' => 'recover',
        ];

        $proxyType = strtolower((string) ($proxy['type'] ?? $proxy['protocol'] ?? 'http'));

        $payload = [
            'name' => $name,
            'folder_id' => !empty($this->cfg['static_folder_id']) ? $this->cfg['static_folder_id'] : ($this->cfg['workspace_id'] ?? ''),
            'browser_type' => $this->cfg['browser_type'] ?? 'mimic',
            'os_type' => $this->cfg['os_type'] ?? 'windows',
            'times' => 1,
            'parameters' => [
                'flags' => $flags,
                'storage' => [
                    'is_local' => false,
                    'save_service_worker' => true,
                ],
                'fingerprint' => (object) [],
                'custom_start_urls' => self::default_custom_start_urls(),
                'proxy' => [
                    'type' => $proxyType,
                    'host' => $proxy['host'],
                    'port' => (int) $proxy['port'],
                    'username' => $proxy['username'] ?? '',
                    'password' => $proxy['password'] ?? '',
                    'save_traffic' => false,
                ],
            ],
        ];

        self::_validate_profile_proxy_payload($payload);
        $profileId = $this->_post_create_auto($payload);
        $this->importDefaultBookmarks($profileId);

        $this->audit->log(
            'Created STATIC profile with pool proxy',
            sprintf(
                'Profile=%s; proxy=%s:%s',
                $profileId,
                $proxy['host'],
                $proxy['port']
            )
        );

        return $profileId;
    }

    /**
     * Rename an existing Multilogin profile. Sends name only — no proxy/fingerprint changes.
     */
    public function update_profile_name(string $profileId, string $name): void
    {
        if ($this->simulation) {
            return;
        }

        if ($profileId === '' || $name === '') {
            throw new \RuntimeException('Profile id and name are required to update a Multilogin profile name.');
        }

        $configured = $this->cfg['profile_update_endpoint'] ?? '';
        // Prefer partial_update: full /profile/update requires fingerprint/parameters.
        [$body, $path] = $this->_request_candidates(
            'POST',
            $configured,
            [
                '/profile/partial_update',
                '/v2/profile/partial_update',
                '/api/v2/profile/partial_update',
                '/profile/update',
                '/v2/profile/update',
                '/api/v2/profile/update',
            ],
            [
                'profile_id' => $profileId,
                'name' => $name,
            ],
            null,
            45
        );

        try {
            $this->settings->saveSettings('multilogin', ['profile_update_endpoint' => $path], true);
        } catch (\Throwable) {
            // Best effort only.
        }

        unset($body);
    }

    public function _base_payload(string $name): array
    {
        return [
            'name' => $name,
            'folder_id' => $this->cfg['geo_folder_id'] ?? '',
            'browser_type' => $this->cfg['browser_type'] ?? 'mimic',
            'os_type' => $this->cfg['os_type'] ?? 'windows',
        ];
    }

    public function _post_create_auto(array $payload): string
    {
        $configured = $this->cfg['profile_create_endpoint'] ?? '';
        $candidates = [
            $configured,
            '/profile/create',
            '/profile',
            '/v2/profile/create',
            '/api/v2/profile/create',
            '/v1/profile/create',
            '/api/v1/profile/create',
        ];

        $paths = [];
        foreach ($candidates as $path) {
            if ($path && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        $errors = [];
        foreach ($paths as $path) {
            $url = str_starts_with($path, 'http')
                ? $path
                : $this->base_url . (str_starts_with($path, '/') ? $path : '/' . $path);

            try {
                $response = Http::withHeaders($this->headers())->timeout(45)->post($url, $payload);
            } catch (\Throwable $exc) {
                $errors[] = "{$path}: connection error: " . $exc->getMessage();
                continue;
            }

            if ($response->successful()) {
                $body = $response->json();
                if ($body === null) {
                    throw new \RuntimeException(
                        "Profile creation succeeded at {$path}, but the response was not JSON: " . substr($response->body(), 0, 500)
                    );
                }

                try {
                    $this->settings->saveSettings('multilogin', ['profile_create_endpoint' => $path], true);
                } catch (\Throwable $exc) {
                    // Best effort only, mirroring the Python `except Exception: pass`.
                }

                return self::_extract_profile_id($body);
            }

            $errors[] = "{$path}: HTTP {$response->status()} " . substr($response->body(), 0, 350);

            // 400/422 means the path exists and the payload needs adjustment.
            // Stop there so the user sees the exact validation response.
            if (in_array($response->status(), [400, 409, 422], true)) {
                throw new \RuntimeException(
                    'Multilogin accepted the create endpoint but rejected the request body. ' . end($errors)
                );
            }

            // Continue past route/version errors.
            if (!in_array($response->status(), [404, 405, 501], true)) {
                throw new \RuntimeException(end($errors));
            }
        }

        throw new \RuntimeException(
            'No compatible Multilogin Profile Create endpoint was found. ' . implode(' | ', $errors)
        );
    }

    public static function _extract_profile_id($body): string
    {
        $data = (is_array($body) && array_key_exists('data', $body)) ? $body['data'] : $body;

        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            foreach (['id', 'profile_id', 'uuid'] as $key) {
                if (!empty($data[$key])) {
                    return (string) $data[$key];
                }
            }

            // Multilogin X create often returns {"data":{"ids":["..."]}}
            if (!empty($data['ids']) && is_array($data['ids'])) {
                $first = reset($data['ids']);
                if (is_string($first) && $first !== '') {
                    return $first;
                }
                if (is_array($first)) {
                    foreach (['id', 'profile_id', 'uuid'] as $key) {
                        if (!empty($first[$key])) {
                            return (string) $first[$key];
                        }
                    }
                }
            }
        }

        throw new \RuntimeException('Profile created but no profile ID was found in response: ' . json_encode($body));
    }

    /**
     * PHP's json_decode(..., true) collapses both JSON objects and JSON
     * arrays into plain arrays, so callers need an explicit way to tell
     * Python's `isinstance(value, list)` apart from `isinstance(value, dict)`.
     */
    protected static function _is_list($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
