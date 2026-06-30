<?php
/**
 * Log Viewer API для Majordomo на Termux
 * Размещение: ~/htdocs/log_api.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Защита от зависания и OOM на Android
ini_set('memory_limit', '32M');
set_time_limit(10);

// Файл в tools/ — логи в родительской папке
$HTDOCS = dirname(dirname(__FILE__));
$DEBMES = $HTDOCS . '/cms/debmes';
$CYCLE_CACHED = $HTDOCS . '/cycle_cached';

$action = $_GET['action'] ?? 'list';

switch ($action) {

    // Список доступных логов
    case 'list':
        $logs = [];

        // DebMes логи (по датам и источникам)
        if (is_dir($DEBMES)) {
            $dates = array_reverse(glob($DEBMES . '/*', GLOB_ONLYDIR));
            foreach (array_slice($dates, 0, 7) as $dateDir) {
                $date = basename($dateDir);
                $files = glob($dateDir . '/*');
                foreach ($files as $file) {
                    $source = basename($file);
                    $size = filesize($file);
                    $logs[] = [
                        'id'     => 'debmes/' . $date . '/' . $source,
                        'name'   => $source,
                        'date'   => $date,
                        'group'  => 'DebMes',
                        'size'   => $size,
                        'lines'  => $size > 0 ? (int)shell_exec('wc -l < ' . escapeshellarg($file)) : 0,
                    ];
                }
            }
        }

        // Логи cycle_cached
        $cycleFiles = [
            'cycle.log'           => 'Основной цикл',
            'lighttpd_error.log'  => 'Lighttpd ошибки',
            'lighttpd_access.log' => 'Lighttpd доступ',
            'watchdog.log'        => 'Watchdog (старый)',
        ];
        foreach ($cycleFiles as $file => $name) {
            $path = $CYCLE_CACHED . '/' . $file;
            if (file_exists($path)) {
                $size = filesize($path);
                $logs[] = [
                    'id'    => 'cached/' . $file,
                    'name'  => $name,
                    'date'  => date('Y-m-d', filemtime($path)),
                    'group' => 'Система',
                    'size'  => $size,
                    'lines' => $size > 0 ? substr_count(file_get_contents($path), "\n") : 0,
                ];
            }
        }

        echo json_encode(['logs' => $logs], JSON_UNESCAPED_UNICODE);
        break;

    // Чтение лога
    case 'read':
        $id = $_GET['id'] ?? '';
        $search = $_GET['search'] ?? '';
        $level = $_GET['level'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 500), 2000);
        $offset = (int)($_GET['offset'] ?? 0);

        // Определяем путь
        if (str_starts_with($id, 'debmes/')) {
            $rel = substr($id, 7); // убираем 'debmes/'
            $path = $DEBMES . '/' . $rel;
        } elseif (str_starts_with($id, 'cached/')) {
            $file = substr($id, 7);
            $path = $CYCLE_CACHED . '/' . basename($file);
        } else {
            echo json_encode(['error' => 'Неверный ID лога']);
            exit;
        }

        if (!file_exists($path) || is_dir($path)) {
            echo json_encode(['error' => 'Файл не найден: ' . $id]);
            exit;
        }

        // Защита: читаем только хвост файла (последние MAX_LINES строк)
        // Большие файлы валят php-cgi на Android
        $MAX_LINES = 1000;
        $fileSize = filesize($path);

        $levelMap = [
            'error'   => ['error', 'ошибка', 'fatal', 'exception', 'failed'],
            'warning' => ['warning', 'warn', 'deprecated', 'notice', 'внимание'],
            'success' => ['ok', 'started', 'connected', 'success', 'запущен', 'готово'],
        ];

        $searchLower = $search ? mb_strtolower($search) : '';

        // Читаем хвост файла через tail — быстро и безопасно
        $tailLines = [];
        if ($fileSize > 0) {
            $raw = shell_exec('tail -n ' . $MAX_LINES . ' ' . escapeshellarg($path) . ' 2>/dev/null');
            if ($raw !== null) {
                $tailLines = explode("
", $raw);
            }
        }

        // Общее число строк через wc -l
        $totalFileLines = (int)shell_exec('wc -l < ' . escapeshellarg($path) . ' 2>/dev/null');

        $parsed = [];
        $lineNumBase = max(0, $totalFileLines - $MAX_LINES);

        foreach ($tailLines as $i => $line) {
            $line = rtrim($line);
            if ($line === '') continue;
            $lineNum = $lineNumBase + $i + 1;

            $lower = mb_strtolower($line);

            if ($searchLower && !str_contains($lower, $searchLower)) continue;

            $lineLevel = 'info';
            foreach ($levelMap as $lvl => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($lower, $kw)) {
                        $lineLevel = $lvl;
                        break 2;
                    }
                }
            }

            if ($level && $lineLevel !== $level) continue;

            $parsed[] = [
                'n'     => $lineNum,
                'text'  => $line,
                'level' => $lineLevel,
            ];
        }

        $truncated = $totalFileLines > $MAX_LINES;

        $total = count($parsed);
        // Последние строки первыми
        $parsed = array_reverse($parsed);
        $page = array_slice($parsed, $offset, $limit);

        echo json_encode([
            'lines'     => $page,
            'total'     => $total,
            'total_file'=> $totalFileLines,
            'truncated' => $truncated,
            'offset'    => $offset,
            'limit'     => $limit,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // Очистка лога
    case 'clear':
        $id = $_GET['id'] ?? '';
        if (str_starts_with($id, 'cached/')) {
            $file = substr($id, 7);
            $path = $CYCLE_CACHED . '/' . basename($file);
            if (file_exists($path)) {
                file_put_contents($path, '');
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['error' => 'Файл не найден']);
            }
        } else {
            echo json_encode(['error' => 'Очистка доступна только для системных логов']);
        }
        break;

    default:
        echo json_encode(['error' => 'Неизвестное действие']);
}
