<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../backend/bootstrap.php';

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true)) {
    fwrite(STDERR, "Could not create backups directory.\n");
    exit(1);
}

$binary = getenv('MMSC_MYSQLDUMP_BIN') ?: 'mysqldump';
$file = $backupDir . '/mmsc_' . date('Ymd_His') . '.sql';
$c = $GLOBALS['CONFIG'];

$cmd = escapeshellcmd($binary)
    . ' --host=' . escapeshellarg((string)$c['db_host'])
    . ' --port=' . escapeshellarg((string)$c['db_port'])
    . ' --user=' . escapeshellarg((string)$c['db_user'])
    . ' --single-transaction --routines --triggers --events --hex-blob'
    . ' ' . escapeshellarg((string)$c['db_name']);

$env = getenv();
if (!is_array($env)) $env = [];
$env['MYSQL_PWD'] = (string)$c['db_pass'];
$spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $spec, $pipes, __DIR__ . '/..', $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start mysqldump. Install MySQL client tools or set MMSC_MYSQLDUMP_BIN.\n");
    exit(1);
}

$out = fopen($file, 'wb');
if (!$out) {
    proc_terminate($proc);
    fwrite(STDERR, "Could not open backup file for writing.\n");
    exit(1);
}
stream_copy_to_stream($pipes[1], $out);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
fclose($out);
$exit = proc_close($proc);

if ($exit !== 0) {
    @unlink($file);
    fwrite(STDERR, "Backup failed: " . trim($stderr) . "\n");
    exit($exit ?: 1);
}

echo "Backup created: $file\n";
