# Установка Majordomo на Android через Termux

## О проекте

Majordomo — PHP-система домашней автоматизации. Эта инструкция описывает установку на Android-устройство через Termux без root-прав. Старый телефон или планшет превращается в полноценный сервер умного дома, работающий 24/7.

**Протестировано:** Termux 0.119+, Android 7+, PHP 8.5, MariaDB 12, Redis

---

## Требования

- Android 7+
- **Termux** — с [официального сайта](https://termux.dev) или [GitHub](https://github.com/termux/termux-app/releases) (не из Google Play)
- **Termux:Boot** из [F-Droid](https://f-droid.org/packages/com.termux.boot/) — для автозапуска

---

## Архитектура стека

```
Android
└── Termux
    ├── MariaDB         (база данных, TCP 127.0.0.1:3306)
    ├── Redis           (кэш состояний устройств, TCP 127.0.0.1:6379, без RDB-снапшотов)
    ├── php-cgi         (FastCGI через TCP 127.0.0.1:9000)
    ├── lighttpd        (веб-сервер, порт 8080)
    ├── cycle.php       (основной демон Majordomo)
    └── watchdog.sh     (следит за php-cgi и дочерними cycle_*)
```

---

## Ключевые особенности Termux vs стандартная установка

| Параметр | Linux/Ubuntu | Termux (Android) |
|---|---|---|
| Веб-сервер | Apache + mod_php | lighttpd + php-cgi |
| PHP FastCGI | php-fpm | php-cgi (fpm не работает) |
| php-cgi транспорт | unix socket | **TCP 127.0.0.1:9000** |
| OPcache | включён | **отключён** (shm_open заблокирован) |
| DB_HOST | localhost | **127.0.0.1** (TCP вместо unix socket) |
| mysqldump | /usr/bin/mysqldump | mariadb-dump |
| Redis клиент | php-redis расширение | **Predis** (чистый PHP, только если нет ext-redis) |
| Redis персистентность | RDB снапшоты включены | **отключены** (`save ""`) |
| Перезапуск MySQL из веб-панели | через sudo | пока не используется (`DISABLE_MYSQL_RESTART` закомментирован — не реализовано в upstream) |
| Автозапуск | systemd | Termux:Boot |
| cycle.php | systemd service | nohup напрямую |

---

## Почему php-fpm не работает на Android

PHP-FPM и php-cgi при старте пытаются создать lock через `shm_open()`. Android без root запрещает этот системный вызов → ошибка `Permission denied (13)`. Решение — запускать `php-cgi` вручную с отключённым OPcache до старта lighttpd.

## Почему php-cgi работает через TCP, а не unix socket

Изначально php-cgi запускался через unix socket (`-b $PREFIX/var/run/php-cgi.sock`). На практике это оказалось нестабильно: SELinux на Android блокирует системный вызов `accept()` в дочерних процессах php-cgi при работе через unix socket. Решение — TCP `127.0.0.1:9000` с `PHP_FCGI_CHILDREN=4` и `PHP_FCGI_MAX_REQUESTS=500`. php-cgi обязательно должен быть поднят **до** lighttpd.

## Почему php-redis не работает

`php-redis` в Termux скомпилирован под другую версию PHP API и несовместим. Используется **Predis** — чистый PHP клиент. Враппер `lib/redis_compat.php` эмулирует класс `Redis` через Predis прозрачно для Majordomo, но подключается только если:
1. расширение `redis` не загружено (`!extension_loaded('redis')`), и
2. Redis реально слушает порт 6379 на момент старта (`fsockopen`-проверка) — иначе `USE_REDIS` вообще не определяется, и Majordomo работает без Redis-кэша.

## Почему Redis запускается без RDB-снапшотов

Данные в Redis (кэш состояний устройств) нужны только пока система работает и пересоздаются заново при каждом запуске — сохранять их на диск не требуется. К тому же периодические фоновые записи снапшотов на Android без root могут завершаться ошибкой записи или просто зря нагружать флеш-память. Поэтому `redis-server` запускается с конфигом `$PREFIX/etc/redis.conf`, где `save ""` отключает периодический bgsave.

---

## Установка

Все команды выполняются автоматически скриптом `install_majordomo_termux.sh`.

### Шаг 0: Подготовка

```bash
pkg install wget -y
wget -O ~/install_majordomo.sh https://raw.githubusercontent.com/artt652/majordomo-android/main/install_majordomo_termux.sh
chmod +x ~/install_majordomo.sh
bash ~/install_majordomo.sh
```

### Шаг 1: Установка пакетов

```bash
pkg install git mariadb php php-fpm php-gd lighttpd redis phpmyadmin wget composer
```

### Шаг 2: Отключение OPcache и настройка PHP

```ini
opcache.enable=0
opcache.enable_cli=0
error_reporting = E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED
display_errors = Off
post_max_size = 200M
upload_max_filesize = 50M
max_file_uploads = 150
max_input_time = 180
```

Без отключения OPcache — php-cgi падает с `Permission denied`. Без подавления warnings — циклы роняются на PHP 8 предупреждениях. Лимиты загрузки увеличены под рекомендации Majordomo (модули с файлами/бэкапами).

### Шаг 2.1: Настройка Redis

```ini
save ""
stop-writes-on-bgsave-error no
appendonly no
loglevel warning
```

Конфиг скачивается из репозитория (`config/redis.conf`), при недоступности сети генерируется локально тем же содержимым. Сохраняется в `$PREFIX/etc/redis.conf` и используется при каждом запуске `redis-server` (и в boot-скрипте, и при ручном запуске/перезапуске).

### Шаг 3: Запуск MariaDB

Проверяется по процессу. Если уже запущена — пропускается.

### Шаг 4-5: Пароль MySQL и strict mode

Подключение проверяется перед установкой пароля. Strict mode отключается для совместимости со старыми модулями.

### Шаг 6: Клонирование и зависимости

```bash
git clone https://github.com/sergejey/majordomo.git ~/htdocs
composer require predis/predis
```

Если папка `~/htdocs` уже существует, скрипт спрашивает подтверждение на перезапись (удаление и повторное клонирование) — старая установка по умолчанию не трогается.

Автоматически скачиваются:
- в корень `~/htdocs/`: `lib/redis_compat.php`, `watchdog.sh`, `restart.sh`, `start.sh`, `stop.sh`
- в `~/htdocs/tools/`: `tinyfilemanager.php` (из апстрима prasathmani/tinyfilemanager), `redis_api.php`, `redis_monitor.html`, `log_api.php`, `log_viewer.html`, `process_api.php`, `process_manager.html`

### Шаг 7: config.php

```php
Define('DB_HOST', '127.0.0.1');          // TCP, не localhost!
Define('PATH_TO_MYSQLDUMP', 'mariadb-dump');
Define('PATH_TO_MYSQL', 'mariadb');
Define('BASE_URL', 'http://127.0.0.1:8080');
Define('ENABLE_PANEL_ACCELERATION', 1);
//define('DISABLE_MYSQL_RESTART', true);    // Пока ещё не реализовано в upstream

if (!extension_loaded('redis') && file_exists(DOC_ROOT . '/lib/redis_compat.php')) {
    require_once DOC_ROOT . '/lib/redis_compat.php';
}
if (@fsockopen('127.0.0.1', 6379, $errno, $errstr, 1)) {
    define('USE_REDIS', '127.0.0.1');
}
```

### Шаг 8: Импорт БД

Если БД уже существует — пропускается.

### Шаг 9: lighttpd + phpMyAdmin

`lighttpd.conf` скачивается из репозитория `majordomo-android`, пути подставляются через `sed`. phpMyAdmin настраивается на TCP подключение к MariaDB — без этого ошибка `No such file or directory`.

### Шаг 10: Автозапуск и watchdog

Boot скрипт `~/.termux/boot/majordomo.sh`:

```
1. Остановка старых процессов  (защита от двойного запуска)
2. MariaDB     (sleep 8)
3. Redis       (конфиг $PREFIX/etc/redis.conf, sleep 1)
4. php-cgi     (TCP 127.0.0.1:9000, sleep 3, ОБЯЗАТЕЛЬНО до lighttpd!)
5. lighttpd
6. cycle.php
7. watchdog.sh
```

`watchdog.sh` — единый watchdog (без supervisord, однослойная архитектура): следит за процессом php-cgi и дочерними процессами `[s]cripts/cycle_*` (не за родительским `cycle.php`).

### Шаг 11: Запуск

Тот же порядок, что и в boot-скрипте: Redis (с проверкой, что процесс реально поднялся), php-cgi, lighttpd, cycle.php, watchdog. Скрипт ждёт до 3 минут пока cycle.php запустится и **не прерывается** при таймауте — watchdog перезапустит автоматически.

### Шаг 12: Проверка

```bash
ps aux | grep -E "mariadbd|redis|php-cgi|lighttpd|cycle" | grep -v grep
curl -s http://127.0.0.1:8080/ | head -3
```

---

## Доступ к системе

- **Majordomo:** `http://IP:8080`
- **phpMyAdmin:** `http://IP:8080/phpmyadmin/` (root / пароль из установки)
- **TinyFileManager:** `http://IP:8080/tools/tinyfilemanager.php` (admin / admin@123 — сменить пароль по умолчанию после первого входа!)
- **Redis монитор:** `http://IP:8080/tools/redis_monitor.html`
- **Просмотр логов:** `http://IP:8080/tools/log_viewer.html`
- **Менеджер процессов:** `http://IP:8080/tools/process_manager.html`
- **WebSocket:** порт `8001` (прямое подключение браузера)

IP устройства:
```bash
ifconfig | grep "inet " | grep -v "127.0.0.1" | awk '{print $2}' | head -1
```
`ip route get` и `ifconfig` на части прошивок Android без root возвращают `Permission denied` или некорректный адрес (например, адрес шлюза вместо адреса устройства) — команда выше надёжнее, так как не требует специальных прав.

---

## Совместимость модулей

**Протестированы и работают:**

- Все стандартные модули Majordomo
- **YaDevices**
- **Каналы RSS**
- **File Manager** — в настройках указать корневую папку:
  ```
  /data/data/com.termux/files/home/htdocs/
  ```
- **Backup** — в настройках создать папки и прописать пути:
  ```
  Локальная папка:           /data/data/com.termux/files/home/htdocs/backup/
  Временная папка для копии: /data/data/com.termux/files/home/htdocs/backup_temp/
  ```

**Не работают:**

- **KodExplorer** — написан под PHP 7, несовместим с PHP 8
- Модули использующие системные утилиты отсутствующие в Termux

> Если неподдерживаемый модуль роняет php-cgi — watchdog перезапустит через 30 секунд.

---

## Ручной перезапуск

Рекомендуется через готовые скрипты:
```bash
bash ~/htdocs/restart.sh   # полный перезапуск всех сервисов
bash ~/htdocs/stop.sh      # остановить всё
bash ~/htdocs/start.sh     # запустить всё
```

Вручную (если скрипты недоступны):
```bash
pkill lighttpd 2>/dev/null
pkill php-cgi 2>/dev/null
pkill -f watchdog 2>/dev/null
pkill -f "cycle.php" 2>/dev/null
pkill -f "scripts/cycle" 2>/dev/null
pkill redis-server 2>/dev/null
pkill -f mariadbd 2>/dev/null
sleep 3
bash ~/.termux/boot/majordomo.sh
```

---

## Обновление Majordomo

Обновление через штатные средства — модуль **saverestore** или **Маркет дополнений** в веб-интерфейсе. Файлы ядра не модифицированы

---

## Управление watchdog

```bash
pkill -f watchdog.sh                              # остановить
nohup bash ~/htdocs/watchdog.sh > /dev/null 2>&1 & # запустить
tail -20 ~/htdocs/cycle_cached/watchdog.log        # лог
```

---

## Диагностика

### php-cgi не запускается (ошибка 13)
```bash
php -r "echo ini_get('opcache.enable');"  # должно быть 0
echo -e "opcache.enable=0\nopcache.enable_cli=0" \
    > $PREFIX/etc/php/conf.d/opcache_off.ini
```

### Ошибка подключения к БД
```bash
grep "DB_HOST" ~/htdocs/config.php  # должно быть 127.0.0.1
```

### lighttpd 503 / нет ответа от php-cgi
```bash
pkill php-cgi
PHP_FCGI_CHILDREN=4 PHP_FCGI_MAX_REQUESTS=500 \
PHP_INI_SCAN_DIR=$PREFIX/etc/php/conf.d \
    php-cgi -b 127.0.0.1:9000 > /dev/null 2>&1 &
sleep 2
php -r "var_dump(@fsockopen('127.0.0.1', 9000, \$e, \$s, 2));"  # должно быть resource, не false
```

### lighttpd bind() ошибка 98 (порт занят)
```bash
pkill lighttpd; sleep 1
lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
```

### phpMyAdmin: No such file or directory
```bash
cat > $PREFIX/share/phpmyadmin/config.inc.php << 'EOF'
<?php
$cfg['blowfish_secret'] = 'majordomo_termux_secret_key_32ch';
$i = 0; $i++;
$cfg['Servers'][$i]['auth_type']    = 'cookie';
$cfg['Servers'][$i]['host']         = '127.0.0.1';
$cfg['Servers'][$i]['port']         = 3306;
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['TempDir'] = '/data/data/com.termux/files/home/htdocs/cycle_cached/pma_tmp';
EOF
```

### Redis не используется, хотя установлен
```bash
ps aux | grep "[r]edis-server"                          # запущен ли процесс
php -r "var_dump(@fsockopen('127.0.0.1', 6379, \$e, \$s, 1));"  # доступен ли порт
grep "USE_REDIS" ~/htdocs/config.php                     # определена ли константа
```
Если процесс не запущен или порт недоступен на момент старта php-cgi/cycle.php — `USE_REDIS` не определится, и Majordomo продолжит работу без Redis (это штатное поведение, не ошибка).

### Redis не подхватывает конфиг без RDB
```bash
ps aux | grep "[r]edis-server"          # проверить, с каким файлом запущен процесс
cat $PREFIX/etc/redis.conf              # должна быть строка save ""
pkill redis-server; sleep 1
redis-server $PREFIX/etc/redis.conf > /dev/null 2>&1 &
```
Если `redis-server` был когда-то запущен вручную без аргумента `$PREFIX/etc/redis.conf` (например, голой командой `redis-server &`), он использует дефолтные настройки с включёнными RDB-снапшотами.

---

## Логи

| Файл | Содержимое |
|---|---|
| `~/htdocs/cycle_cached/cycle.log` | Основной цикл |
| `~/htdocs/cycle_cached/lighttpd_error.log` | Ошибки веб-сервера |
| `~/htdocs/cycle_cached/watchdog.log` | Перезапуски watchdog |

---

## Файлы, добавленные установщиком

| Файл | Назначение |
|---|---|
| `~/htdocs/lib/redis_compat.php` | Враппер Redis через Predis (условный, см. config.php) |
| `~/htdocs/watchdog.sh` | Watchdog для php-cgi и дочерних cycle_* |
| `~/htdocs/restart.sh` | Скрипт полного перезапуска всех сервисов |
| `~/htdocs/start.sh` | Скрипт запуска всех сервисов |
| `~/htdocs/stop.sh` | Скрипт остановки всех сервисов |
| `~/htdocs/tools/tinyfilemanager.php` | Файловый менеджер |
| `~/htdocs/tools/redis_api.php` | API для монитора Redis |
| `~/htdocs/tools/redis_monitor.html` | Дашборд мониторинга Redis |
| `~/htdocs/tools/log_api.php` | API для просмотра логов |
| `~/htdocs/tools/log_viewer.html` | Просмотрщик логов |
| `~/htdocs/tools/process_api.php` | API для менеджера процессов |
| `~/htdocs/tools/process_manager.html` | Менеджер процессов |
| `~/.termux/boot/majordomo.sh` | Автозапуск (Termux:Boot) |
| `$PREFIX/etc/php/conf.d/opcache_off.ini` | Настройки PHP (генерируется инлайн) |
| `$PREFIX/etc/redis.conf` | Конфиг Redis без RDB-снапшотов (скачивается из репозитория или генерируется инлайн) |
| `$PREFIX/etc/lighttpd/lighttpd.conf` | Конфиг веб-сервера (скачивается из репозитория) |
| `$PREFIX/etc/mysql/conf.d/disable_strict_mode.cnf` | Отключение strict mode (генерируется инлайн) |
| `$PREFIX/share/phpmyadmin/config.inc.php` | Конфиг phpMyAdmin (генерируется инлайн) |
