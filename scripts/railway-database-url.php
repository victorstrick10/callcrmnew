<?php

/**
 * Read Railway DATABASE_URL from gitignored database.env.
 * Blank lines and # comments are ignored so the file can include instructions.
 */
function loadRailwayDatabaseUrl(string $root): string
{
    $path = $root.'/database.env';
    if (! is_file($path)) {
        throw new RuntimeException('database.env missing — paste Railway DATABASE_URL (one line).');
    }

    $url = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $url = $line;
        break;
    }

    if ($url === '' || ! preg_match('#^postgres(ql)?://#', $url)) {
        throw new RuntimeException('database.env must contain a postgresql:// URL (comments and blank lines are ignored).');
    }

    if (! str_contains($url, 'sslmode=')) {
        $url .= (str_contains($url, '?') ? '&' : '?').'sslmode=require';
    }

    return $url;
}
