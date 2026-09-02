<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MultiloginBookmarkService
{
    public function __construct(
        private IntegrationSettingsService $settings,
    ) {
    }

    /**
     * @return list<array{name:string,title:string,url:string,folder:string}>
     */
    public function bookmarksFromHtml(?string $htmlPath = null): array
    {
        $path = $htmlPath ?: resource_path('multilogin/Bookmarks.html');
        if (! is_file($path)) {
            // Legacy local path used during early Multilogin bookmark setup.
            $fallback = storage_path('app/multilogin/Bookmarks.html');
            $path = is_file($fallback) ? $fallback : $path;
        }
        if (! is_file($path)) {
            throw new RuntimeException("Bookmarks HTML not found at {$path}");
        }

        $html = file_get_contents($path);
        if ($html === false || $html === '') {
            throw new RuntimeException("Unable to read bookmarks HTML at {$path}");
        }

        preg_match_all('/<A\s+HREF="([^"]+)"[^>]*>(.*?)<\/A>/is', $html, $matches, PREG_SET_ORDER);

        $bookmarks = [];
        foreach ($matches as $row) {
            $title = html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '') {
                continue;
            }
            $label = $title !== '' ? $title : $url;
            // Multilogin/Chromium display name is `name`; keep `title` for compatibility.
            $bookmarks[] = [
                'name' => $label,
                'title' => $label,
                'url' => $url,
                'folder' => 'Bookmarks bar',
            ];
        }

        if ($bookmarks === []) {
            throw new RuntimeException('No bookmarks found in HTML export.');
        }

        return $bookmarks;
    }

    /**
     * Write Multilogin-compatible bookmark JSON where the local agent can read it.
     */
    public function ensureImportJsonFile(?string $htmlPath = null): string
    {
        $bookmarks = $this->bookmarksFromHtml($htmlPath);
        $dir = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME') ?: sys_get_temp_dir()), '\\/').DIRECTORY_SEPARATOR.'mlx'.DIRECTORY_SEPARATOR.'bookmarks';
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create Multilogin bookmarks directory: {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.'call_crm_api_bookmarks.json';
        $json = json_encode($bookmarks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write bookmark JSON at {$path}");
        }

        return $path;
    }

    public function launcherBaseUrl(): string
    {
        $cfg = $this->settings->getSettings('multilogin');
        $base = $cfg['launcher_base_url'] ?? 'https://127.0.0.1:45001';

        return rtrim((string) $base, '/');
    }

    public function importForProfile(string $profileId, string $token, ?string $htmlPath = null): void
    {
        if ($profileId === '' || str_starts_with($profileId, 'sim-')) {
            return;
        }

        $jsonPath = $this->ensureImportJsonFile($htmlPath);
        $url = $this->launcherBaseUrl().'/api/v1/profile/'.$profileId.'/bookmarks/import';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->withOptions(['verify' => false])->timeout(60)->post($url, [
            'paths' => [$jsonPath],
            'operation' => 'override',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Multilogin bookmark import failed (HTTP '.$response->status().'): '
                .substr($response->body(), 0, 300)
            );
        }
    }
}
