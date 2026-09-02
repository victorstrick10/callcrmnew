<?php

namespace Tests\Unit;

use App\Services\MultiloginBookmarkService;
use App\Services\IntegrationSettingsService;
use Tests\TestCase;

class MultiloginBookmarkServiceTest extends TestCase
{
    public function test_parses_netscape_bookmarks_html(): void
    {
        $html = <<<'HTML'
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<DL><p>
    <DT><H3>Bookmarks bar</H3>
    <DL><p>
        <DT><A HREF="https://www.facebook.com/">Facebook</A>
        <DT><A HREF="https://ipinfo.io/json">IP JSON</A>
    </DL><p>
</DL><p>
HTML;
        $path = storage_path('app/multilogin/_test_bookmarks.html');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $html);

        $svc = new MultiloginBookmarkService(app(IntegrationSettingsService::class));
        $bookmarks = $svc->bookmarksFromHtml($path);

        $this->assertCount(2, $bookmarks);
        $this->assertSame('Facebook', $bookmarks[0]['name']);
        $this->assertSame('Facebook', $bookmarks[0]['title']);
        $this->assertSame('https://www.facebook.com/', $bookmarks[0]['url']);
        $this->assertSame('Bookmarks bar', $bookmarks[0]['folder']);
        @unlink($path);
    }
}
