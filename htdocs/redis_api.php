<?php
/**
 * Redis Monitor API для Majordomo на Termux
 * Размещение: ~/htdocs/redis_api.php
 * Доступ: http://IP:8080/redis_api.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Получаем данные через redis-cli
function redisCmd($cmd) {
    $output = shell_exec('redis-cli ' . $cmd . ' 2>/dev/null');
    return trim($output ?? '');
}

function redisInfo($section = '') {
    $cmd = $section ? "info $section" : "info";
    $raw = redisCmd($cmd);
    $result = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') continue;
        [$key, $val] = array_pad(explode(':', $line, 2), 2, '');
        $result[trim($key)] = trim($val);
    }
    return $result;
}

try {
    // Проверка доступности Redis
    $ping = redisCmd('ping');
    if ($ping !== 'PONG') {
        echo json_encode(['error' => 'Redis недоступен', 'ping' => $ping]);
        exit;
    }

    $server  = redisInfo('server');
    $stats   = redisInfo('stats');
    $memory  = redisInfo('memory');
    $clients = redisInfo('clients');

    // Ключи
    $allKeys = array_filter(explode("\n", redisCmd('keys "*"')));
    $mjdKeys = array_values(array_filter($allKeys, fn($k) => str_starts_with(trim($k), 'mjd:')));
    $pKeys   = array_values(array_filter($allKeys, fn($k) => str_starts_with(trim($k), 'p:')));

    // Значения ключей Majordomo
    $mjdData = [];
    foreach (array_slice($mjdKeys, 0, 20) as $key) {
        $key = trim($key);
        $val = redisCmd('get "' . $key . '"');
        $mjdData[] = ['key' => $key, 'value' => $val];
    }

    // Hit rate
    $hits   = (int)($stats['keyspace_hits'] ?? 0);
    $misses = (int)($stats['keyspace_misses'] ?? 0);
    $total  = $hits + $misses;
    $hitRate = $total > 0 ? round($hits / $total * 100, 1) : 0;

    // Uptime в днях
    $uptimeSecs = (int)($server['uptime_in_seconds'] ?? 0);
    $uptimeDays = round($uptimeSecs / 86400, 1);

    echo json_encode([
        'status'         => 'OK',
        'version'        => $server['redis_version'] ?? '?',
        'uptime_days'    => $uptimeDays,
        'hits'           => $hits,
        'misses'         => $misses,
        'hit_rate'       => $hitRate,
        'ops_per_sec'    => (int)($stats['instantaneous_ops_per_sec'] ?? 0),
        'total_commands' => (int)($stats['total_commands_processed'] ?? 0),
        'total_conn'     => (int)($stats['total_connections_received'] ?? 0),
        'clients'        => (int)($clients['connected_clients'] ?? 0),
        'keys_total'     => count($allKeys),
        'keys_mjd'       => count($mjdKeys),
        'keys_p'         => count($pKeys),
        'mem_used'       => $memory['used_memory_human'] ?? '?',
        'mem_peak'       => $memory['used_memory_peak_human'] ?? '?',
        'mem_rss'        => $memory['used_memory_rss_human'] ?? '?',
        'mjd_keys'       => $mjdData,
        'server_info'    => [
            'tcp_port'         => $server['tcp_port'] ?? '6379',
            'os'               => $server['os'] ?? '?',
            'arch_bits'        => $server['arch_bits'] ?? '?',
            'redis_mode'       => $server['redis_mode'] ?? '?',
            'role'             => 'master',
            'aof_enabled'      => $server['aof_enabled'] ?? '0',
        ],
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
