<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../backend/bootstrap.php';

$dir = realpath(__DIR__ . '/../database/migrations');
if ($dir === false) {
    fwrite(STDERR, "Migration directory not found.\n");
    exit(1);
}

$files = glob($dir . '/*.sql') ?: [];
natsort($files);

$applied = array_column(q_all('SELECT version FROM schema_migrations'), 'version');
$applied = array_fill_keys($applied, true);

$ran = 0;
foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) continue;

    $sql = trim((string)file_get_contents($file));
    if ($sql === '') {
        q('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "Recorded empty migration: $version\n";
        continue;
    }

    try {
        // Migrations may contain DDL (for example CREATE/ALTER VIEW) that can implicitly
        // commit on MySQL/MariaDB. Execute the migration as one script, then record it.
        db()->exec($sql);
        q('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "Applied: $version\n";
        $ran++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: $version\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

echo $ran === 0 ? "No pending migrations.\n" : "Applied $ran migration(s).\n";
