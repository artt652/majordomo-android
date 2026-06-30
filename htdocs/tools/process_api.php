<?php
/**
 * Process Manager API
 * Termux/Android — управление процессами Majordomo
 *
 * Размещение: ~/htdocs/tools/process_api.php
 *
 * Действия (GET ?action=...):
 *   status   — состояние всех процессов + системная статистика
 *   log      — хвост лога (?type=cycle|watchdog|lighttpd|boot, ?lines=50)
 *
 * Действия (POST JSON {action, target}):
 *   start    — запустить процесс
 *   stop     — остановить процесс (SIGTERM → SIGKILL)
 *   restart  — остановить + запустить
 *   restart_all — перезапустить все сервисы
 *
 * Совместимость: PHP 7.4 / PHP 8.x, Termux Android (без root)
 */

// ── Изоляция от Majordomo namespace ──────────────────────────────────────────
// tools/ намеренно НЕ включает config.php, чтобы не тянуть весь фреймворк
// (паттерн уже используется в redis_api.php, restart_api.php, log_api.php)
// Пути вычисляем относительно этого файла.
// ─────────────────────────────────────────────────────────────────────────────

define('TOOLS_DIR',   __DIR__);
define('HTDOCS_DIR',  dirname(__DIR__));
define('PREFIX_DIR',  '/data/data/com.termux/files/usr');
define('HOME_DIR',    '/data/data/com.termux/files/home');

// ── Простая защита: токен в заголовке или GET-параметре ──────────────────────
// Токен = md5 от пароля БД из config.php (не передаём пароль напрямую).
// Если config.php недоступен — работаем только с localhost.
function checkAuth(): void
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // Без токена разрешаем только localhost
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        return;
    }
    // Для внешних запросов требуем токен
    $token_file = HTDOCS_DIR . '/.proc_token';
    if (!file_exists($token_file)) {
        http_response_code(403);
        exit(json_encode(['error' => 'No token configured']));
    }
    $expected = trim(file_get_contents($token_file));
    $provided  = $_SERVER['HTTP_X_PROC_TOKEN']
              ?? $_GET['token']
              ?? '';
    if (!hash_equals($expected, $provided)) {
        http_response_code(403);
        exit(json_encode(['error' => 'Forbidden']));
    }
}

// ── CORS для localhost ────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Proc-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

checkAuth();

// ── Роутинг ───────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
} elseif ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $target = $body['target'] ?? '';
}

try {
    switch ($action) {
        case 'status':
            echo json_encode(getStatus(), JSON_UNESCAPED_UNICODE);
            break;
        case 'log':
            $type  = preg_replace('/[^a-z0-9_]/', '', $_GET['type'] ?? 'cycle');
            $lines = max(20, min(500, (int)($_GET['lines'] ?? 80)));
            echo json_encode(getLog($type, $lines), JSON_UNESCAPED_UNICODE);
            break;
        case 'start':
            echo json_encode(actionStart($target), JSON_UNESCAPED_UNICODE);
            break;
        case 'stop':
            echo json_encode(actionStop($target), JSON_UNESCAPED_UNICODE);
            break;
        case 'restart':
            echo json_encode(actionRestart($target), JSON_UNESCAPED_UNICODE);
            break;
        case 'restart_all':
            echo json_encode(actionRestartAll(), JSON_UNESCAPED_UNICODE);
            break;
        case 'debug':
            echo json_encode(getDebugInfo(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
exit;

// =============================================================================
//  ОПРЕДЕЛЕНИЯ ПРОЦЕССОВ
// =============================================================================

/**
 * Описание всех отслеживаемых процессов.
 * grep_pattern — POSIX ERE, передаётся в `grep -E`.
 * start_cmd    — команда запуска (если применимо из PHP).
 * type         — 'core' | 'cycle'
 */
function getProcessDefs(): array
{
    $htdocs = HTDOCS_DIR;
    $prefix  = PREFIX_DIR;
    return [
        // ── Core services ──────────────────────────────────────────────────
        [
            'id'           => 'lighttpd',
            'name'         => 'lighttpd',
            'cmd_display'  => 'lighttpd -f .../lighttpd.conf',
            // Из debug: cmd = "lighttpd .../bin/lighttpd -f .../lighttpd.conf"
            'grep_pattern' => 'lighttpd -f',
            'start_cmd'    => "lighttpd -f {$prefix}/etc/lighttpd/lighttpd.conf",
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        [
            'id'           => 'php-cgi',
            'name'         => 'php-cgi',
            'cmd_display'  => 'php-cgi -b .../php-cgi.sock',
            'grep_pattern' => 'php-cgi',
            'start_cmd'    => "PHP_INI_SCAN_DIR={$prefix}/etc/php/conf.d php-cgi -b {$prefix}/var/run/php-cgi.sock",
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        [
            'id'           => 'mariadbd',
            'name'         => 'mariadbd',
            'cmd_display'  => 'mariadbd --datadir=...',
            // Ищем только основной демон по пути к бинарнику, НЕ mariadbd-safe.
            // Из debug: pid 17478 cmd содержит "/usr/bin/mariadbd /usr/bin/mariadbd --basedir=..."
            // mariadbd-safe (pid 17404) содержит "sh .../mariadbd-safe" — не совпадёт.
            'grep_pattern' => 'bin/mariadbd ',
            'start_cmd'    => "mariadbd-safe --datadir={$prefix}/var/lib/mysql > /dev/null 2>&1 &",
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        [
            'id'           => 'redis',
            'name'         => 'redis-server',
            'cmd_display'  => 'redis-server *:6379',
            'grep_pattern' => 'redis-server',
            'start_cmd'    => 'redis-server > /dev/null 2>&1 &',
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        [
            'id'           => 'cycle',
            'name'         => 'cycle.php',
            'cmd_display'  => "php {$htdocs}/cycle.php",
            // Паттерн ищет cycle.php НЕ внутри scripts/ (это дочерние циклы)
            'grep_pattern' => 'php.*[/ ]cycle\.php',
            'start_cmd'    => "nohup php -d opcache.enable=0 {$htdocs}/cycle.php > {$htdocs}/cycle_cached/cycle.log 2>&1 &",
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        [
            'id'           => 'watchdog',
            'name'         => 'watchdog.sh',
            'cmd_display'  => "bash {$htdocs}/watchdog.sh",
            'grep_pattern' => 'watchdog\.sh',
            'start_cmd'    => "nohup bash {$htdocs}/watchdog.sh > /dev/null 2>&1 &",
            'stop_signal'  => 'TERM',
            'type'         => 'core',
        ],
        // ── Cycle scripts ──────────────────────────────────────────────────
        // Динамически добавляются из scripts/cycle_*.php (см. getCycleScripts)
    ];
}

// =============================================================================
//  STATUS
// =============================================================================

function getStatus(): array
{
    $defs   = getProcessDefs();
    $cycles = getCycleDefs();

    $cores = [];
    foreach ($defs as $def) {
        $cores[] = enrichProcess($def);
    }

    $cycleRows = [];
    foreach ($cycles as $def) {
        $cycleRows[] = enrichProcess($def);
    }

    return [
        'cores'  => $cores,
        'cycles' => $cycleRows,
        'sys'    => getSysStats(),
        'ts'     => time(),
    ];
}

/**
 * Возвращает определения цикл-скриптов на основе реальных файлов
 * в scripts/cycle_*.php — тот же список, что использует cycle.php.
 */
function getCycleDefs(): array
{
    $htdocs  = HTDOCS_DIR;
    $scripts = $htdocs . '/scripts';
    $defs    = [];

    if (!is_dir($scripts)) {
        return $defs;
    }

    $files = glob($scripts . '/cycle_*.php');
    if (!$files) {
        return $defs;
    }

    sort($files);
    foreach ($files as $file) {
        $basename = basename($file);          // cycle_main.php
        $id       = pathinfo($file, PATHINFO_FILENAME); // cycle_main
        $defs[] = [
            'id'           => $id,
            'name'         => $basename,
            'cmd_display'  => "php scripts/{$basename}",
            // Ищем дочерние php-процессы с этим именем скрипта
            'grep_pattern' => 'php.*scripts/' . preg_quote($basename, '/'),
            'start_cmd'    => null, // цикл-скрипты запускает только cycle.php
            'stop_signal'  => 'TERM',
            'type'         => 'cycle',
        ];
    }

    return $defs;
}

/**
 * Получает PID(ы) процесса через `ps aux | grep pattern`.
 * Возвращает массив ['pid' => int, 'cpu' => float, 'mem_mb' => int, 'cmd' => string].
 * На Termux `ps aux` работает без root.
 */
function findProcesses(string $pattern): array
{
    // Используем grep -v grep чтобы не поймать сам grep
    // -E для расширенных регулярных выражений
    $cmd    = "ps aux 2>/dev/null | grep -E " . escapeshellarg($pattern)
            . " | grep -v grep | grep -v process_api";
    $output = [];
    exec($cmd, $output);

    $result = [];
    foreach ($output as $line) {
        // ps aux на Termux/Android: USER PID %CPU %MEM VSZ RSS TTY STAT START TIME COMMAND
        $parts = preg_split('/\s+/', trim($line), 11);
        if (count($parts) < 11) {
            continue;
        }
        $result[] = [
            'pid'    => (int)$parts[1],
            'cpu'    => (float)$parts[2],
            'mem_mb' => (int)round((int)$parts[5] / 1024), // RSS в KB → MB
            'cmd'    => $parts[10] ?? '',
        ];
    }
    return $result;
}

/**
 * Определяет uptime процесса по /proc/PID/stat (Linux/Android).
 * Fallback: 0.
 */
function getProcessUptime(int $pid): int
{
    $stat_file = "/proc/{$pid}/stat";
    if (!file_exists($stat_file)) {
        return 0;
    }

    $uptime_file = '/proc/uptime';
    if (!file_exists($uptime_file)) {
        return 0;
    }

    $stat    = file_get_contents($stat_file);
    $uptime  = (float)explode(' ', file_get_contents($uptime_file))[0];
    $hz      = 100; // Android/Linux стандарт

    // Поле 22 в /proc/PID/stat — starttime в ticks
    // Формат: pid (comm) state ... (22-е поле, индекс от 0)
    // Имя процесса может содержать пробелы → обрезаем его
    $stat = preg_replace('/\(.+?\)/', '()', $stat);
    $fields = explode(' ', $stat);
    if (!isset($fields[21])) {
        return 0;
    }

    $starttime_ticks = (int)$fields[21];
    $proc_start      = $starttime_ticks / $hz;
    $uptime_sec      = (int)($uptime - $proc_start);

    return max(0, $uptime_sec);
}

/**
 * Дополняет определение процесса реальными данными из системы.
 */
function enrichProcess(array $def): array
{
    $procs = findProcesses($def['grep_pattern']);

    if (empty($procs)) {
        return [
            'id'         => $def['id'],
            'name'       => $def['name'],
            'cmd'        => $def['cmd_display'],
            'type'       => $def['type'] ?? 'core',
            'status'     => 'stopped',
            'pid'        => null,
            'cpu'        => '0.0',
            'mem'        => 0,
            'memPct'     => '0.0',
            'uptime'     => null,
            'canStart'   => $def['start_cmd'] !== null,
        ];
    }

    // Берём первый найденный процесс (обычно он один)
    $p   = $procs[0];
    $pid = $p['pid'];

    // Суммируем CPU если несколько воркеров (например php-cgi)
    $totalCpu = 0.0;
    $totalMem = 0;
    foreach ($procs as $pp) {
        $totalCpu += $pp['cpu'];
        $totalMem += $pp['mem_mb'];
    }

    $uptime = getProcessUptime($pid);

    // Получаем реальный %MEM из /proc/meminfo для расчёта
    static $totalRamMb = null;
    if ($totalRamMb === null) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
            $totalRamMb = (int)($m[1] / 1024);
        } else {
            $totalRamMb = 3800; // fallback
        }
    }
    $memPct = $totalRamMb > 0
        ? round($totalMem / $totalRamMb * 100, 1)
        : 0.0;

    // Определяем статус: если uptime > 0 и процесс жив — running
    // Если uptime очень маленький (< 5 сек) — warning (только что стартовал)
    $status = 'running';
    if ($uptime > 0 && $uptime < 5) {
        $status = 'warning'; // только что запустился
    }

    // Termux ps aux дублирует бинарник: "lighttpd /path/bin/lighttpd -f ..."
    // Оставляем только часть начиная с реального пути (второе вхождение).
    $cleanCmd = function(string $raw): string {
        // Убираем начальное "basename /full/path" если оно есть
        // Пример: "lighttpd /data/.../bin/lighttpd -f conf" → "/data/.../bin/lighttpd -f conf"
        if (preg_match('/^\S+\s+(\/\S+.*)$/', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    };

    $displayCmd = count($procs) > 1
        ? $cleanCmd($p['cmd']) . ' (+' . (count($procs)-1) . ' workers)'
        : $cleanCmd($p['cmd']);

    return [
        'id'        => $def['id'],
        'name'      => $def['name'],
        'cmd'       => $displayCmd,
        'type'      => $def['type'] ?? 'core',
        'status'    => $status,
        'pid'       => $pid,
        'cpu'       => number_format($totalCpu, 1, '.', ''),
        'mem'       => $totalMem,
        'memPct'    => (string)$memPct,
        'uptime'    => $uptime,
        'canStart'  => $def['start_cmd'] !== null,
    ];
}

// =============================================================================
//  SYSTEM STATS
// =============================================================================

function getSysStats(): array
{
    // ── CPU (из /proc/stat, два снимка с интервалом 200ms) ──────────────────
    $cpuPct = getCpuUsage();

    // ── RAM (из /proc/meminfo) ───────────────────────────────────────────────
    $ramTotal = $ramUsed = 0;
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
            $ramTotal = (int)($m[1] / 1024);
        }
        if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) {
            $ramUsed = $ramTotal - (int)($m[1] / 1024);
        }
    }

    // ── Disk (df на HTDOCS_DIR) ──────────────────────────────────────────────
    // Termux df не поддерживает -BM; используем df без флагов (выдаёт 1K-блоки)
    // или df -k (явно 1K-блоки). Fallback: df -h и парсим суффикс.
    $diskUsed = $diskTotal = 0;
    $dfOut = [];
    exec('df -k ' . escapeshellarg(HTDOCS_DIR) . ' 2>/dev/null | tail -1', $dfOut);
    if (!empty($dfOut[0])) {
        $parts = preg_split('/\s+/', trim($dfOut[0]));
        // Стандарт POSIX df -k: Filesystem 1K-blocks Used Available Use% Mounted
        // Некоторые Android df: Filesystem Size Used Avail Use% Mounted (human-readable без флага)
        if (count($parts) >= 4) {
            $rawTotal = $parts[1] ?? '0';
            $rawUsed  = $parts[2] ?? '0';

            // Если значение содержит суффикс (G/M/K) — это human-readable df
            $parseSize = function(string $s): int {
                $s = trim($s);
                if (preg_match('/^([\d.]+)([GMKTP])/i', $s, $m)) {
                    $n = (float)$m[1];
                    switch (strtoupper($m[2])) {
                        case 'G': return (int)($n * 1024);
                        case 'M': return (int)$n;
                        case 'K': return (int)($n / 1024);
                        case 'T': return (int)($n * 1024 * 1024);
                        default:  return (int)$n;
                    }
                }
                // Числовое значение — трактуем как 1K-блоки → MB
                return (int)round((int)$s / 1024);
            };

            $diskTotal = $parseSize($rawTotal);
            $diskUsed  = $parseSize($rawUsed);
        }
    }
    // Если df не сработал — пробуем df -h (human-readable, всегда работает на Termux)
    if ($diskTotal === 0) {
        $dfOut = [];
        exec('df -h ' . escapeshellarg(HTDOCS_DIR) . ' 2>/dev/null | tail -1', $dfOut);
        if (!empty($dfOut[0])) {
            $parts = preg_split('/\s+/', trim($dfOut[0]));
            if (count($parts) >= 3) {
                $parseH = function(string $s): int {
                    if (preg_match('/^([\d.]+)G/i', $s, $m)) return (int)((float)$m[1] * 1024);
                    if (preg_match('/^([\d.]+)M/i', $s, $m)) return (int)(float)$m[1];
                    if (preg_match('/^([\d.]+)T/i', $s, $m)) return (int)((float)$m[1] * 1024 * 1024);
                    return 0;
                };
                $diskTotal = $parseH($parts[1] ?? '');
                $diskUsed  = $parseH($parts[2] ?? '');
            }
        }
    }

    // ── Load average ─────────────────────────────────────────────────────────
    // /proc/loadavg недоступен на Android без root → fallback через команду uptime.
    // uptime выдаёт: "... load average: 0.52, 0.48, 0.41"
    $load = ['—', '—', '—'];
    $loadavg = @file_get_contents('/proc/loadavg');
    if ($loadavg && trim($loadavg) !== '') {
        $parts = explode(' ', trim($loadavg));
        if (isset($parts[0]) && is_numeric($parts[0])) {
            $load = [
                number_format((float)$parts[0], 2, '.', ''),
                number_format((float)($parts[1] ?? 0), 2, '.', ''),
                number_format((float)($parts[2] ?? 0), 2, '.', ''),
            ];
        }
    }
    if ($load[0] === '—') {
        $uptimeOut = [];
        exec('uptime 2>/dev/null', $uptimeOut);
        if (!empty($uptimeOut[0])) {
            // "... load average: 0.52, 0.48, 0.41" или "load averages: 0.52 0.48 0.41"
            if (preg_match('/load averages?:\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i', $uptimeOut[0], $m)) {
                $load = [
                    number_format((float)$m[1], 2, '.', ''),
                    number_format((float)$m[2], 2, '.', ''),
                    number_format((float)$m[3], 2, '.', ''),
                ];
            }
        }
    }

    // ── System uptime ────────────────────────────────────────────────────────
    // /proc/uptime недоступен на Android без root → парсим из команды uptime.
    // uptime: " 3:22  up 2 days, 14:05, ..." или "... up 14:05, ..."
    $uptimeSec = 0;
    $uptimeStr = @file_get_contents('/proc/uptime');
    if ($uptimeStr && trim($uptimeStr) !== '') {
        $uptimeSec = (int)explode(' ', trim($uptimeStr))[0];
    }
    if ($uptimeSec === 0) {
        $uptimeOut = [];
        exec('uptime 2>/dev/null', $uptimeOut);
        if (!empty($uptimeOut[0])) {
            $u = $uptimeOut[0];
            // "up X days, HH:MM" → дни + часы + минуты
            $days = $hours = $mins = 0;
            if (preg_match('/(\d+)\s+day/', $u, $m))  $days  = (int)$m[1];
            if (preg_match('/up.*?(\d+):(\d+)/', $u, $m)) {
                $hours = (int)$m[1];
                $mins  = (int)$m[2];
            } elseif (preg_match('/(\d+)\s+min/', $u, $m)) {
                $mins = (int)$m[1];
            }
            $uptimeSec = $days * 86400 + $hours * 3600 + $mins * 60;
        }
    }

    return [
        'cpu'       => $cpuPct,
        'ramUsed'   => $ramUsed,
        'ramTotal'  => $ramTotal > 0 ? $ramTotal : 3800,
        'diskUsed'  => $diskUsed,
        'diskTotal' => $diskTotal > 0 ? $diskTotal : 32768,
        'load'      => $load,
        'uptimeSec' => $uptimeSec,
    ];
}

/**
 * Измеряет CPU%.
 *
 * Метод 1: два снимка /proc/stat с паузой 100ms (работает на большинстве Android).
 * Метод 2: top -bn2 -d0.1 (fallback если /proc/stat пустой или ticks не меняются).
 * Метод 3: суммируем %cpu из ps aux (грубый, но всегда работает).
 */
function getCpuUsage(): int
{
    // ── Метод 1: /proc/stat ───────────────────────────────────────────────────
    $readStat = function (): array {
        $stat = @file_get_contents('/proc/stat');
        if (!$stat) return [];
        // Ищем строку "cpu  ..." (суммарная по всем ядрам)
        if (!preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat, $m)) {
            return [];
        }
        // $m[1..7] = user, nice, system, idle, iowait, irq, softirq
        return array_map('intval', array_slice($m, 1));
    };

    $s1 = $readStat();
    if (!empty($s1)) {
        usleep(100000); // 100ms — достаточно для Android
        $s2 = $readStat();
        if (!empty($s2)) {
            // idle = поле 4 (индекс 3), iowait = поле 5 (индекс 4)
            $idle1  = $s1[3] + $s1[4];
            $idle2  = $s2[3] + $s2[4];
            $total1 = array_sum($s1);
            $total2 = array_sum($s2);
            $dTotal = $total2 - $total1;
            $dIdle  = $idle2  - $idle1;
            if ($dTotal > 0) {
                return (int)round(($dTotal - $dIdle) / $dTotal * 100);
            }
        }
    }

    // ── Метод 2: top -bn2 (два прохода, берём второй) ────────────────────────
    // Termux top: "Cpu(s): 12.3%us, 3.4%sy, ..." или "%Cpu(s): 12.3 us,"
    $topOut = [];
    exec('top -bn2 -d0.1 2>/dev/null | grep -E "^(%)?[Cc]pu" | tail -1', $topOut);
    if (!empty($topOut[0])) {
        // Формат A: "Cpu(s): 12.3%us, 3.4%sy, 0.0%ni, 84.3%id, ..."
        if (preg_match('/(\d+\.?\d*)[%\s]*id/', $topOut[0], $m)) {
            return (int)round(100 - (float)$m[1]);
        }
        // Формат B: "%Cpu(s): 12.3 us, 3.4 sy, ... 84.3 id,"
        if (preg_match('/(\d+\.?\d*)\s+id/', $topOut[0], $m)) {
            return (int)round(100 - (float)$m[1]);
        }
    }

    // ── Метод 3: суммируем %CPU из ps aux ────────────────────────────────────
    // Самый грубый, но работает везде. Сумма > 100% на многоядерных — делим на nproc.
    $psOut = [];
    exec('ps aux 2>/dev/null | awk \'{sum += $3} END {print sum}\'', $psOut);
    if (!empty($psOut[0]) && is_numeric($psOut[0])) {
        $totalCpu = (float)$psOut[0];
        // Получаем количество ядер для нормализации
        $ncpuOut = [];
        exec('nproc 2>/dev/null || echo 4', $ncpuOut);
        $ncpu = max(1, (int)($ncpuOut[0] ?? 4));
        return (int)min(100, round($totalCpu / $ncpu));
    }

    return 0;
}

// =============================================================================
//  LOGS
// =============================================================================

/**
 * Читает лог и возвращает массив строк с timestamp, level, message.
 * Поддерживает форматы:
 *  - Majordomo DebMes: "YYYY-MM-DD HH:MM:SS [category] message"
 *  - lighttpd: "YYYY-MM-DD HH:MM:SS: (mod_...) message"
 *  - plain text с датой в начале
 */
function getLog(string $type, int $maxLines = 80): array
{
    $htdocs = HTDOCS_DIR;

    // ── Определяем путь к лог-файлу ─────────────────────────────────────────
    // Majordomo пишет логи через DebMes в cms/debmes/YYYY-MM-DD/CATEGORY
    // (без расширения). Watchdog логирует туда же в категорию "watchdog".
    $today    = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $paths = [];
    switch ($type) {
        case 'cycle':
            // cycle.php пишет в stdout → перенаправляется в cycle_cached/cycle.log
            $paths[] = $htdocs . '/cycle_cached/cycle.log';
            // и через DebMes в категорию 'boot'
            $paths[] = $htdocs . "/cms/debmes/{$today}/boot";
            $paths[] = $htdocs . "/cms/debmes/{$yesterday}/boot";
            break;
        case 'watchdog':
            $paths[] = $htdocs . "/cms/debmes/{$today}/watchdog";
            $paths[] = $htdocs . "/cms/debmes/{$yesterday}/watchdog";
            break;
        case 'lighttpd':
            $paths[] = $htdocs . '/cycle_cached/lighttpd_error.log';
            $paths[] = PREFIX_DIR . '/var/log/lighttpd/error.log';
            break;
        case 'boot':
            $paths[] = $htdocs . "/cms/debmes/{$today}/boot";
            $paths[] = $htdocs . "/cms/debmes/{$yesterday}/boot";
            $paths[] = $htdocs . '/cycle_cached/cycle.log';
            break;
        default:
            // Произвольная категория DebMes
            $safe = preg_replace('/[^a-z0-9_]/', '', $type);
            $paths[] = $htdocs . "/cms/debmes/{$today}/{$safe}";
            $paths[] = $htdocs . "/cms/debmes/{$yesterday}/{$safe}";
    }

    // ── Читаем первый существующий файл ─────────────────────────────────────
    $raw = '';
    foreach ($paths as $path) {
        if (file_exists($path) && is_readable($path)) {
            // Читаем хвост файла через tail — экономим память на больших файлах
            $tailLines = $maxLines * 3; // с запасом, т.к. парсим
            $out = [];
            exec("tail -n {$tailLines} " . escapeshellarg($path) . " 2>/dev/null", $out);
            $raw .= implode("\n", $out) . "\n";
            break; // используем только первый найденный файл
        }
    }

    if ($raw === '') {
        return [['ts' => date('Y-m-d H:i:s'), 'level' => 'info', 'msg' => 'Log file not found or empty']];
    }

    // ── Парсинг строк ────────────────────────────────────────────────────────
    $lines  = array_filter(explode("\n", $raw));
    $result = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $ts    = '';
        $level = 'info';
        $msg   = $line;

        // Формат Majordomo DebMes: "2024-01-15 14:22:01 [boot] Starting cycle..."
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(?:\[[\w]+\]\s+)?(.+)$/', $line, $m)) {
            $ts  = $m[1];
            $msg = $m[2];
        }
        // Формат lighttpd: "2024-01-15 14:22:01: (mod_fastcgi.c.xxx) message"
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}):\s*(.+)$/', $line, $m)) {
            $ts  = $m[1];
            $msg = $m[2];
        }
        // Формат "HH:MM:SS message" (stdout cycle.php)
        elseif (preg_match('/^(\d{2}:\d{2}:\d{2})\s+(.+)$/', $line, $m)) {
            $ts  = date('Y-m-d') . ' ' . $m[1];
            $msg = $m[2];
        }

        // Определяем уровень по содержимому
        $msgLower = strtolower($msg);
        if (strpos($msgLower, 'error') !== false || strpos($msgLower, 'fatal') !== false
            || strpos($msgLower, 'failed') !== false || strpos($msgLower, 'cannot') !== false) {
            $level = 'err';
        } elseif (strpos($msgLower, 'warn') !== false || strpos($msgLower, 'broken') !== false
            || strpos($msgLower, 'closed') !== false || strpos($msgLower, 'crash') !== false) {
            $level = 'warn';
        } elseif (strpos($msgLower, 'ok') !== false || strpos($msgLower, 'started') !== false
            || strpos($msgLower, 'connected') !== false || strpos($msgLower, 'success') !== false
            || strpos($msgLower, 'repaired') !== false) {
            $level = 'ok';
        } elseif (strpos($msgLower, 'starting') !== false || strpos($msgLower, 'loading') !== false
            || strpos($msgLower, 'checking') !== false || strpos($msgLower, 'auto-recovery') !== false) {
            $level = 'info';
        }

        $result[] = ['ts' => $ts, 'level' => $level, 'msg' => $msg];
    }

    // Возвращаем последние N строк
    return array_values(array_slice($result, -$maxLines));
}

// =============================================================================
//  PROCESS ACTIONS
// =============================================================================

/**
 * Находит определение процесса по id.
 */
function findDef(string $id): ?array
{
    $all = array_merge(getProcessDefs(), getCycleDefs());
    foreach ($all as $def) {
        if ($def['id'] === $id) {
            return $def;
        }
    }
    return null;
}

/**
 * Убивает процесс(ы) по паттерну.
 * Сначала SIGTERM (15), через 3 сек — SIGKILL (9) если ещё жив.
 */
function killProcess(string $pattern, string $signal = 'TERM'): bool
{
    $procs = findProcesses($pattern);
    if (empty($procs)) {
        return true; // уже не запущен
    }

    foreach ($procs as $p) {
        $pid = (int)$p['pid'];
        if ($pid > 1) {
            exec("kill -" . escapeshellarg($signal) . " {$pid} 2>/dev/null");
        }
    }

    // Ждём завершения (до 3 сек)
    $waited = 0;
    while ($waited < 30) {
        usleep(100000); // 100ms
        $waited++;
        $still = findProcesses($pattern);
        if (empty($still)) {
            return true;
        }
    }

    // SIGKILL если не завершился
    $procs = findProcesses($pattern);
    foreach ($procs as $p) {
        $pid = (int)$p['pid'];
        if ($pid > 1) {
            exec("kill -9 {$pid} 2>/dev/null");
        }
    }

    usleep(500000);
    return empty(findProcesses($pattern));
}

function actionStop(string $id): array
{
    $def = findDef($id);
    if (!$def) {
        return ['ok' => false, 'msg' => "Unknown process: {$id}"];
    }

    $ok = killProcess($def['grep_pattern'], $def['stop_signal'] ?? 'TERM');
    return ['ok' => $ok, 'msg' => $ok ? "Stopped {$id}" : "Failed to stop {$id}"];
}

function actionStart(string $id): array
{
    $def = findDef($id);
    if (!$def) {
        return ['ok' => false, 'msg' => "Unknown process: {$id}"];
    }
    if (empty($def['start_cmd'])) {
        return ['ok' => false, 'msg' => "{$id} can only be started by cycle.php"];
    }

    // Убедимся что не запущен
    $existing = findProcesses($def['grep_pattern']);
    if (!empty($existing)) {
        return ['ok' => false, 'msg' => "{$id} is already running (PID {$existing[0]['pid']})"];
    }

    exec($def['start_cmd'] . ' > /dev/null 2>&1 &');
    usleep(800000); // 800ms — даём время на запуск

    $running = findProcesses($def['grep_pattern']);
    $ok = !empty($running);
    return ['ok' => $ok, 'msg' => $ok ? "Started {$id} (PID {$running[0]['pid']})" : "Failed to start {$id}"];
}

function actionRestart(string $id): array
{
    $stopResult = actionStop($id);
    if (!$stopResult['ok']) {
        // Если не смогли остановить — всё равно пробуем запустить
        // (процесс мог уже быть мёртв)
    }
    usleep(500000);
    $startResult = actionStart($id);
    return [
        'ok'  => $startResult['ok'],
        'msg' => "stop: {$stopResult['msg']} | start: {$startResult['msg']}",
    ];
}

function actionRestartAll(): array
{
    // Порядок важен: сначала останавливаем цикл-скрипты (они дочерние),
    // потом cycle.php, потом watchdog. php-cgi и lighttpd последними.
    $stopOrder = ['cycle', 'watchdog'];
    // Цикл-скрипты убиваем через цикл
    $cyclePattern = 'php.*scripts/cycle_';
    killProcess($cyclePattern, 'TERM');

    foreach ($stopOrder as $id) {
        actionStop($id);
    }

    usleep(1000000); // 1 сек

    // Запускаем в обратном порядке
    $startOrder = ['watchdog', 'cycle'];
    $results = [];
    foreach ($startOrder as $id) {
        $r = actionStart($id);
        $results[] = $r['msg'];
    }

    return ['ok' => true, 'msg' => implode('; ', $results)];
}

// =============================================================================
//  DEBUG — вызывается через ?action=debug
//  Показывает сырые данные: вывод ps, df, /proc/stat, top — помогает
//  диагностировать почему CPU/Disk не читаются на конкретном устройстве.
// =============================================================================
function getDebugInfo(): array
{
    $out = [];

    // PHP info
    $out['php_version'] = PHP_VERSION;
    $out['htdocs']      = HTDOCS_DIR;

    // /proc/stat
    $procStat = @file_get_contents('/proc/stat');
    $out['proc_stat_available'] = $procStat !== false;
    $out['proc_stat_first_line'] = $procStat ? strtok($procStat, "\n") : null;

    // /proc/loadavg
    $out['proc_loadavg'] = trim((string)@file_get_contents('/proc/loadavg'));

    // /proc/uptime
    $out['proc_uptime'] = trim((string)@file_get_contents('/proc/uptime'));

    // /proc/meminfo первые 5 строк
    $meminfo = @file_get_contents('/proc/meminfo');
    $out['proc_meminfo_head'] = $meminfo
        ? implode(' | ', array_slice(explode("\n", $meminfo), 0, 5))
        : null;

    // df raw
    $dfRaw = [];
    exec('df -k ' . escapeshellarg(HTDOCS_DIR) . ' 2>&1', $dfRaw);
    $out['df_k_output'] = $dfRaw;

    $dfRawH = [];
    exec('df -h ' . escapeshellarg(HTDOCS_DIR) . ' 2>&1', $dfRawH);
    $out['df_h_output'] = $dfRawH;

    // ps aux первые 5 строк
    $psRaw = [];
    exec('ps aux 2>/dev/null | head -5', $psRaw);
    $out['ps_aux_head'] = $psRaw;

    // top CPU строка
    $topRaw = [];
    exec('top -bn2 -d0.1 2>/dev/null | grep -E "^(%)?[Cc]pu" | tail -1', $topRaw);
    $out['top_cpu_line'] = $topRaw;

    // nproc
    $nproc = [];
    exec('nproc 2>/dev/null', $nproc);
    $out['nproc'] = $nproc[0] ?? null;

    // scripts/cycle_*.php
    $cycles = glob(HTDOCS_DIR . '/scripts/cycle_*.php');
    $out['cycle_scripts'] = $cycles ? array_map('basename', $cycles) : [];

    // Тест поиска процессов
    $out['findProcesses_lighttpd'] = findProcesses('lighttpd');
    $out['findProcesses_php_cgi']  = findProcesses('php-cgi');
    $out['findProcesses_mariadbd'] = findProcesses('mariadbd');
    $out['findProcesses_redis']    = findProcesses('redis-server');

    // Результат getCpuUsage
    $out['cpu_usage'] = getCpuUsage();

    // Результат getSysStats
    $out['sys_stats'] = getSysStats();

    return $out;
}
