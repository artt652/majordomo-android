<?php
/**
 * Redis Monitor API — безопасная версия для Termux/Android
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

ini_set('memory_limit', '8M');
set_time_limit(4);

$ping = shell_exec('timeout 2 redis-cli ping 2>/dev/null');
if (trim($ping) !== 'PONG') {
    echo json_encode(['error' => 'Redis недоступен']);
    exit;
}

// Один вызов info all
$raw    = shell_exec('timeout 3 redis-cli info all 2>/dev/null');
$dbsize = (int)shell_exec('timeout 2 redis-cli dbsize 2>/dev/null');

// Парсим
$data = [];
foreach (explode("\n", $raw ?? '') as $line) {
    $line = trim($line);
    if (!$line || $line[0] === '#') continue;
    if (strpos($line, ':') !== false) {
        [$k, $v] = explode(':', $line, 2);
        $data[trim($k)] = trim($v);
    }
}

$hits   = (int)($data['keyspace_hits'] ?? 0);
$misses = (int)($data['keyspace_misses'] ?? 0);
$total  = $hits + $misses;

// Ключи Majordomo — только по запросу
$mjdData = [];
if (isset($_GET['keys'])) {
    $keysRaw = shell_exec('timeout 2 redis-cli keys "mjd:*" 2>/dev/null');
    $keys = array_filter(array_map('trim', explode("\n", $keysRaw ?? '')));
    foreach (array_slice(array_values($keys), 0, 10) as $key) {
        $val = shell_exec('timeout 1 redis-cli get ' . escapeshellarg($key) . ' 2>/dev/null');
        $mjdData[] = ['key' => $key, 'value' => trim($val ?? '')];
    }
}

echo json_encode([
    'status'         => 'OK',
    'version'        => $data['redis_version'] ?? '?',
    'uptime_days'    => round((int)($data['uptime_in_seconds'] ?? 0) / 86400, 1),
    'hits'           => $hits,
    'misses'         => $misses,
    'hit_rate'       => $total > 0 ? round($hits / $total * 100, 1) : 0,
    'ops_per_sec'    => (int)($data['instantaneous_ops_per_sec'] ?? 0),
    'total_commands' => (int)($data['total_commands_processed'] ?? 0),
    'total_conn'     => (int)($data['total_connections_received'] ?? 0),
    'clients'        => (int)($data['connected_clients'] ?? 0),
    'keys_total'     => $dbsize,
    'mem_used'       => $data['used_memory_human'] ?? '?',
    'mem_peak'       => $data['used_memory_peak_human'] ?? '?',
    'mjd_keys'       => $mjdData,
    'server_info'    => [
        'tcp_port'    => $data['tcp_port'] ?? '6379',
        'os'          => $data['os'] ?? '?',
        'arch_bits'   => $data['arch_bits'] ?? '?',
        'redis_mode'  => $data['redis_mode'] ?? '?',
        'role'        => $data['role'] ?? 'master',
        'aof_enabled' => $data['aof_enabled'] ?? '0',
        'mem_rss'     => $data['used_memory_rss_human'] ?? '?',
    ],
    'timestamp' => time(),
], JSON_UNESCAPED_UNICODE);
