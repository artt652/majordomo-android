# Установка Majordomo на Android через Termux

## О проекте

Majordomo — PHP-система домашней автоматизации. Эта инструкция описывает установку на Android-устройство через Termux без root-прав. Старый телефон или планшет превращается в полноценный сервер умного дома, работающий 24/7.

**Протестировано:** Termux 0.119+, PHP 8.5, MariaDB 12.3, Redis 8.8

---

## Требования

- Android (тестировалось на Android 8+)
- **Termux** — с [официального сайта](https://termux.dev) или [GitHub](https://github.com/termux/termux-app/releases) (не из Google Play)
- **Termux:Boot** из [F-Droid](https://f-droid.org/packages/com.termux.boot/) — для автозапуска

---

## Архитектура стека

```
Android
└── Termux
    ├── MariaDB         (база данных, TCP 127.0.0.1:3306)
    ├── Redis           (кэш состояний устройств)
    ├── php-cgi         (FastCGI через unix socket)
    ├── lighttpd        (веб-сервер, порт 8080)
    ├── cycle.php       (основной демон Majordomo)
    └── watchdog.sh     (следит за php-cgi и cycle.php)
```

---

## Ключевые особенности Termux vs стандартная установка

| Параметр | Linux/Ubuntu | Termux (Android) |
|---|---|---|
| Веб-сервер | Apache + mod_php | lighttpd + php-cgi |
| PHP FastCGI | php-fpm | php-cgi (fpm не работает) |
| OPcache | включён | **отключён** (shm_open заблокирован) |
| DB_HOST | localhost | **127.0.0.1** (TCP вместо unix socket) |
| mysqldump | /usr/bin/mysqldump | mariadb-dump |
| Redis клиент | php-redis расширение | **Predis** (чистый PHP) |
| Автозапуск | systemd | Termux:Boot |
| cycle.php | systemd service | nohup напрямую |

---

## Почему php-fpm не работает на Android

PHP-FPM и php-cgi при старте пытаются создать lock через `shm_open()`. Android без root запрещает этот системный вызов → ошибка `Permission denied (13)`. Решение — запускать `php-cgi` вручную через unix socket с отключённым OPcache до старта lighttpd.

## Почему php-redis не работает

`php-redis` в Termux скомпилирован под другую версию PHP API и несовместим. Используется **Predis** — чистый PHP клиент. Враппер `lib/redis_compat.php` эмулирует класс `Redis` через Predis прозрачно для Majordomo.

---

## Установка

Все команды выполняются автоматически скриптом `install_majordomo_termux.sh`.

### Шаг 0: Подготовка

```bash
pkg install wget
wget -O ~/install_majordomo.sh https://raw.githubusercontent.com/artt652/majordomo-android/main/install_majordomo_termux.sh
chmod +x ~/install_majordomo.sh
bash ~/install_majordomo.sh
```

### Шаг 1: Установка пакетов

```bash
pkg install git mariadb php php-fpm lighttpd redis phpmyadmin wget composer
```

### Шаг 2: Отключение OPcache и настройка PHP

```ini
opcache.enable=0
opcache.enable_cli=0
error_reporting = E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
```

Без отключения OPcache — php-cgi падает с `Permission denied`. Без подавления warnings — циклы роняются на PHP 8 предупреждениях (например `rmdir()` на непустую папку).

### Шаг 3: Запуск MariaDB

Проверяется по процессу. Если уже запущена — пропускается.

### Шаг 4-5: Пароль MySQL и strict mode

Подключение проверяется перед установкой пароля. Strict mode отключается для совместимости со старыми модулями.

### Шаг 6: Клонирование и зависимости

```bash
git clone https://github.com/sergejey/majordomo.git ~/htdocs
composer require predis/predis
```

Автоматически создаются: `lib/redis_compat.php`, `tinyfilemanager.php`, `redis_api.php`, `redis_monitor.html`.

### Шаг 7: config.php

```php
Define('DB_HOST', '127.0.0.1');          // TCP, не localhost!
Define('PATH_TO_MYSQLDUMP', 'mariadb-dump');
Define('BASE_URL', 'http://127.0.0.1:8080');
Define('ENABLE_PANEL_ACCELERATION', 1);
Define('SETTINGS_BACKUP_PATH', '.../htdocs/backup/');
define('USE_REDIS', '127.0.0.1');
```

`SETTINGS_BACKUP_PATH` — критически важно. Без неё `startup_maintenance.php` делает `CHECK TABLE` всех таблиц при **каждом** запуске cycle_main (~3 минуты).

### Шаг 8: Импорт БД

Если БД уже существует — пропускается.

### Шаг 9: lighttpd + phpMyAdmin

phpMyAdmin настраивается на TCP подключение к MariaDB — без этого ошибка `No such file or directory`.

### Шаг 10: Автозапуск и watchdog

Boot скрипт `~/.termux/boot/majordomo.sh`:

```
1. Остановка старых процессов  (защита от двойного запуска)
2. MariaDB     (sleep 8)
3. Redis       (sleep 1)
4. php-cgi     (sleep 3, ОБЯЗАТЕЛЬНО до lighttpd!)
5. lighttpd
6. cycle.php
7. watchdog.sh
```

`watchdog.sh` — единый watchdog для php-cgi и cycle.php:
- Проверяет каждые **30 секунд**
- После перезапуска cycle.php ждёт **60 секунд** (время на CHECK TABLE)
- Создаёт папку бэкапа на текущий день

### Шаг 11: Запуск

Скрипт ждёт до 3 минут пока cycle.php запустится и **не прерывается** при таймауте — watchdog перезапустит автоматически.

### Шаг 12: Проверка

```bash
ps aux | grep -E "mariadbd|redis|php-cgi|lighttpd|cycle" | grep -v grep
curl -s http://127.0.0.1:8080/ | head -3
```

---

## Доступ к системе

- **Majordomo:** `http://IP:8080`
- **phpMyAdmin:** `http://IP:8080/phpmyadmin/` (root / пароль из установки)
- **TinyFileManager:** `http://IP:8080/tinyfilemanager.php` (admin / admin@123 — сменить пароль!)
- **Redis монитор:** `http://IP:8080/redis_monitor.html`
- **WebSocket:** порт `8001` (прямое подключение браузера)

IP устройства:
```bash
ifconfig | grep "inet " | grep -v "127.0.0.1"
```

---

## Совместимость модулей

**Протестированы и работают:**

- Все стандартные модули Majordomo
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

Обновление через штатные средства — модуль **saverestore** или **Маркет дополнений** в веб-интерфейсе. Файлы ядра не модифицированы.

После обновления:
```bash
pkill -f "cycle.php" && sleep 2
nohup php -d opcache.enable=0 ~/htdocs/cycle.php \
    > ~/htdocs/cycle_cached/cycle.log 2>&1 &
echo $! > ~/htdocs/cycle_cached/cycle.php.lock
```

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

### lighttpd 503
```bash
PHP_INI_SCAN_DIR=$PREFIX/etc/php/conf.d \
    php-cgi -b $PREFIX/var/run/php-cgi.sock > /dev/null 2>&1 &
sleep 2; pkill lighttpd
lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
```

### lighttpd bind() ошибка 98 (порт занят)
```bash
pkill lighttpd; sleep 1
lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
```

### Циклы зависают после обновления
```bash
mkdir -p ~/htdocs/backup/$(date +%Y%m%d)
grep "SETTINGS_BACKUP_PATH" ~/htdocs/config.php
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
EOF
```

---

## Логи

| Файл | Содержимое |
|---|---|
| `~/htdocs/cycle_cached/cycle.log` | Основной цикл |
| `~/htdocs/cycle_cached/lighttpd_error.log` | Ошибки веб-сервера |
| `~/htdocs/cycle_cached/watchdog.log` | Перезапуски watchdog |

---

## Файлы добавленные установщиком

| Файл | Назначение |
|---|---|
| `~/htdocs/lib/redis_compat.php` | Враппер Redis через Predis |
| `~/htdocs/watchdog.sh` | Watchdog для php-cgi и cycle.php |
| `~/htdocs/tinyfilemanager.php` | Файловый менеджер |
| `~/htdocs/redis_api.php` | API для монитора Redis |
| `~/htdocs/redis_monitor.html` | Дашборд мониторинга Redis |
| `~/.termux/boot/majordomo.sh` | Автозапуск (Termux:Boot) |
| `$PREFIX/etc/php/conf.d/opcache_off.ini` | Настройки PHP |
| `$PREFIX/etc/lighttpd/lighttpd.conf` | Конфиг веб-сервера |
| `$PREFIX/share/phpmyadmin/config.inc.php` | Конфиг phpMyAdmin |
