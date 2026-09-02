<?php

namespace Tests\Unit;

use Tests\TestCase;

class RailwayDatabaseUrlTest extends TestCase
{
    public function test_skips_comments_and_appends_sslmode(): void
    {
        require_once base_path('scripts/railway-database-url.php');

        $dir = sys_get_temp_dir().'/railway-url-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/database.env', "# comment\n\npostgresql://user:pass@host:5432/railway\n");

        $url = loadRailwayDatabaseUrl($dir);

        $this->assertStringStartsWith('postgresql://user:pass@host:5432/railway', $url);
        $this->assertStringContainsString('sslmode=require', $url);
    }

    public function test_rejects_empty_placeholder_file(): void
    {
        require_once base_path('scripts/railway-database-url.php');

        $dir = sys_get_temp_dir().'/railway-url-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/database.env', "# Paste your Railway Postgres DATABASE_URL on the next line\n\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('postgresql://');
        loadRailwayDatabaseUrl($dir);
    }
}
