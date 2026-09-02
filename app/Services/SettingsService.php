<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\ProfileNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SettingsService
{
    public function __construct(
        private IntegrationSettingsService $settings,
        private IpInfoService $ipInfo,
        private MultiloginClient $multilogin,
        private ProfileNumberService $numbers,
        private AuditService $audit,
    ) {
    }

    public function pageData(): array
    {
        return [
            'ipinfo' => $this->settings->getSettings('ipinfo'),
            'calendly' => $this->settings->getSettings('calendly'),
            'multilogin' => $this->settings->getSettings('multilogin'),
            'rows' => IntegrationSetting::query()->get()->keyBy('provider'),
            'masked' => fn (?string $v) => $this->settings->masked($v),
        ];
    }

    public function saveProvider(string $provider, array $input): void
    {
        if ($provider === 'ipinfo') {
            $data = ['api_token' => $input['api_token'] ?? ''];
        } elseif ($provider === 'calendly') {
            $data = [
                'access_token' => $input['access_token'] ?? '',
                'webhook_signing_key' => $input['webhook_signing_key'] ?? '',
                'organization_uri' => $input['organization_uri'] ?? '',
                'webhook_url' => $input['webhook_url'] ?? '',
            ];
        } elseif ($provider === 'multilogin') {
            $data = [
                'automation_token' => $input['automation_token'] ?? '',
                'base_url' => $input['base_url'] ?? 'https://api.multilogin.com',
                'workspace_id' => $input['workspace_id'] ?? '',
                'geo_folder_id' => $input['geo_folder_id'] ?? '',
                'static_folder_id' => $input['static_folder_id'] ?? '',
                'template_profile_id' => $input['template_profile_id'] ?? '',
                'template_name_filter' => $input['template_name_filter'] ?? 'template',
                'workspaces_endpoint' => $input['workspaces_endpoint'] ?? '',
                'folders_endpoint' => $input['folders_endpoint'] ?? '',
                'browser_type' => $input['browser_type'] ?? 'mimic',
                'os_type' => $input['os_type'] ?? 'windows',
                'simulation_mode' => ! empty($input['simulation_mode']) ? 'true' : 'false',
                'test_endpoint' => $input['test_endpoint'] ?? '/workspace/statistics',
                'profile_search_endpoint' => $input['profile_search_endpoint'] ?? '/profile/search',
                'profile_create_endpoint' => $input['profile_create_endpoint'] ?? '/profile/create',
                'profile_clone_endpoint' => $input['profile_clone_endpoint'] ?? '/profile/clone',
                'proxy_generate_endpoint' => $input['proxy_generate_endpoint'] ?? 'https://profile-proxy.multilogin.com/v1/proxy/connection_url',
                'multilogin_proxy_type' => $input['multilogin_proxy_type'] ?? 'residential',
                'multilogin_proxy_protocol' => $input['multilogin_proxy_protocol'] ?? 'http',
                'multilogin_proxy_session_type' => $input['multilogin_proxy_session_type'] ?? 'sticky',
                'multilogin_proxy_ip_ttl' => $input['multilogin_proxy_ip_ttl'] ?? '0',
                'multilogin_proxy_strict_mode' => ! empty($input['multilogin_proxy_strict_mode']) ? 'true' : 'false',
            ];
        } else {
            throw new RuntimeException('Unknown integration provider.');
        }

        $this->settings->saveSettings($provider, $data, true);
    }

    public function connectMultilogin(array $input): string
    {
        $token = trim((string) ($input['automation_token'] ?? ''));
        $baseUrl = trim((string) ($input['base_url'] ?? 'https://api.multilogin.com'));
        $simulation = ! empty($input['simulation_mode']);
        $workspaceId = trim((string) ($input['workspace_id'] ?? ''));

        $this->settings->saveSettings('multilogin', [
            'automation_token' => $token,
            'base_url' => $baseUrl,
            'workspace_id' => $workspaceId,
            'simulation_mode' => $simulation ? 'true' : 'false',
        ], true);

        try {
            $client = new MultiloginClient($token, $baseUrl);
            $result = $client->discover($workspaceId);

            $data = array_merge($result['endpoints'] ?? [], [
                'discovery_cache' => $result,
                'workspace_id' => $result['selected_workspace_id']
                    ?? (($result['workspaces'][0]['id'] ?? '') ?: ''),
            ]);
            $this->settings->saveSettings('multilogin', $data, true);

            $marked = 0;
            foreach ($result['profiles'] ?? [] as $profile) {
                $number = $this->numbers->extractNumber($profile['name'] ?? '');
                if ($number) {
                    // Discovery is global settings — mark on every company pool that has this number free/created.
                    $rows = ProfileNumber::query()->where('number', $number)->get();
                    foreach ($rows as $numberRow) {
                        $numberRow->status = 'created';
                        $numberRow->multilogin_profile_id = $profile['id'] ?? '';
                        $numberRow->profile_name = (string) ($profile['name'] ?? '');
                        $numberRow->save();
                        $marked++;
                    }
                }
            }

            $row = IntegrationSetting::query()->where('provider', 'multilogin')->first();
            if ($row) {
                $row->last_test_status = 'success';
                $row->last_test_message = $result['message'] ?? 'Connected.';
                $row->save();
            }

            $this->audit->log(
                'Multilogin automatic discovery',
                ($result['message'] ?? '')." Numbered profiles marked: {$marked}."
            );

            return ($result['message'] ?? 'Connected.')." Existing numbered profiles synchronized: {$marked}.";
        } catch (Throwable $exc) {
            $row = IntegrationSetting::query()->where('provider', 'multilogin')->first();
            if ($row) {
                $row->last_test_status = 'error';
                $row->last_test_message = $exc->getMessage();
                $row->save();
            }
            throw $exc;
        }
    }

    public function testProvider(string $provider): string
    {
        $row = IntegrationSetting::query()->where('provider', $provider)->first();
        try {
            if ($provider === 'ipinfo') {
                $result = $this->ipInfo->lookup('8.8.8.8');
                $message = "Connected. Test lookup: {$result['city']}, {$result['region']}, {$result['country_code']}";
            } elseif ($provider === 'multilogin') {
                [$ok, $message] = $this->multilogin->test();
                if (! $ok) {
                    throw new RuntimeException($message);
                }
            } elseif ($provider === 'calendly') {
                $cfg = $this->settings->getSettings('calendly');
                $token = $cfg['access_token'] ?? '';
                if (! $token) {
                    throw new RuntimeException('Calendly access token is not configured.');
                }
                Http::withToken($token)
                    ->timeout(20)
                    ->withOptions(['verify' => false])
                    ->get('https://api.calendly.com/users/me')
                    ->throw();
                $message = 'Calendly connection successful.';
            } else {
                throw new RuntimeException('Unknown provider.');
            }

            if ($row) {
                $row->last_test_status = 'success';
                $row->last_test_message = $message;
                $row->save();
            }

            return $message;
        } catch (Throwable $exc) {
            if ($row) {
                $row->last_test_status = 'error';
                $row->last_test_message = $exc->getMessage();
                $row->save();
            }
            throw $exc;
        }
    }

    public function syncNumbers(?\App\Models\Company $company = null): array
    {
        if (! $company) {
            throw new RuntimeException('Select a company before syncing Multilogin profiles.');
        }

        if (! $this->multilogin->isConfiguredFor($company)) {
            throw new RuntimeException(
                'No Multilogin token available. Add a Multilogin token on the company '
                .'(Companies → Edit) or globally in Integrations → Multilogin.'
            );
        }

        $client = $this->multilogin->forCompany($company);
        $profiles = $client->search_profiles();

        return $this->numbers->syncFromProfiles($company->id, $profiles);
    }
}
