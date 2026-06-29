#!/data/data/com.termux/files/usr/bin/bash
# =============================================================
# Установка Majordomo на Termux (Android)
# Адаптация официального инсталлятора Ubuntu для Termux
# Протестировано: Termux 0.119+, Android 8+, PHP 8.5, MariaDB 12
# =============================================================

PREFIX=/data/data/com.termux/files/usr
HOME_DIR=/data/data/com.termux/files/home
HTDOCS="$HOME_DIR/htdocs"
LOGS="$HTDOCS/cycle_cached"

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   Установка Majordomo на Termux Android  ║"
echo "╚══════════════════════════════════════════╝"
echo ""

echo -n "Введите пароль root MySQL (например: majordomo123) > "
read -r MYSQL_PASS

# =============================================================
# ШАГ 1: Установка пакетов
# =============================================================
echo ""
echo ">>> ШАГ 1: Установка пакетов..."
pkg update -y
pkg install -y git mariadb php php-fpm php-gd lighttpd redis phpmyadmin wget composer

echo "Проверка PHP расширений:"
php -m | grep -E "curl|mbstring|xml|pdo|mysqli" || true

# =============================================================
# ШАГ 2: Отключение OPcache
# php-cgi падает с Permission denied из-за OPcache shm lock на Android
# =============================================================
echo ""
echo ">>> ШАГ 2: Отключение OPcache..."
mkdir -p $PREFIX/etc/php/conf.d
cat > $PREFIX/etc/php/conf.d/opcache_off.ini << 'EOF'
opcache.enable=0
opcache.enable_cli=0

; Подавляем некритичные ошибки — они роняют циклы на PHP 8
; E_ERROR и E_PARSE остаются видимыми
error_reporting = E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED
display_errors = Off
log_errors = On
EOF
echo "OPcache отключён, error_reporting настроен"

# =============================================================
# ШАГ 3: Запуск MariaDB
# =============================================================
echo ""
echo ">>> ШАГ 3: Запуск MariaDB..."

if ps aux | grep -q "[m]ariadbd "; then
    echo "MariaDB уже запущена — пропускаем"
else
    mariadbd-safe --datadir=$PREFIX/var/lib/mysql > /dev/null 2>&1 &
    echo "Ожидание запуска MariaDB (10 сек)..."
    sleep 10
    if ps aux | grep -q "[m]ariadbd "; then
        echo "MariaDB запущена"
    else
        echo "ОШИБКА: MariaDB не запустилась!"
        echo "Попробуйте вручную: mariadbd-safe --datadir=$PREFIX/var/lib/mysql &"
        exit 1
    fi
fi

# =============================================================
# ШАГ 4: Настройка пароля MySQL
# =============================================================
echo ""
echo ">>> ШАГ 4: Настройка пароля MySQL..."

if mariadb -u root -p"$MYSQL_PASS" -e "SELECT 1;" > /dev/null 2>&1; then
    echo "Подключение работает — пропускаем"
else
    mariadb -u root << SQLEOF 2>/dev/null || true
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_PASS';
FLUSH PRIVILEGES;
SQLEOF
    if mariadb -u root -p"$MYSQL_PASS" -e "SELECT 1;" > /dev/null 2>&1; then
        echo "Пароль установлен"
    else
        echo "ОШИБКА: Не удалось подключиться с паролем '$MYSQL_PASS'"
        echo "Введите правильный пароль root MySQL:"
        read -r MYSQL_PASS
        mariadb -u root -p"$MYSQL_PASS" -e "SELECT 1;" > /dev/null 2>&1 || {
            echo "Неверный пароль. Прерываем установку."
            exit 1
        }
    fi
fi

# =============================================================
# ШАГ 5: Отключение MySQL strict mode
# =============================================================
echo ""
echo ">>> ШАГ 5: Отключение MySQL strict mode..."
mkdir -p $PREFIX/etc/mysql/conf.d
cat > $PREFIX/etc/mysql/conf.d/disable_strict_mode.cnf << 'EOF'
[mysqld]
sql_mode=IGNORE_SPACE,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
EOF
echo "MySQL strict mode отключён"

# =============================================================
# ШАГ 6: Клонирование Majordomo
# =============================================================
echo ""
echo ">>> ШАГ 6: Клонирование Majordomo..."

if [ -d "$HTDOCS" ]; then
    echo "Папка $HTDOCS уже существует — пропускаем"
else
    cd "$HOME_DIR"
    git clone https://github.com/sergejey/majordomo.git htdocs
    echo "Majordomo клонирован"
fi

mkdir -p "$LOGS"
chmod -R 777 "$HTDOCS"

# Устанавливаем Predis — чистый PHP клиент Redis (без расширения php-redis)
echo "Установка Predis..."
cd "$HTDOCS"
composer require predis/predis --quiet     && echo "Predis установлен"     || echo "ВНИМАНИЕ: не удалось установить Predis"

# Скачиваем враппер Redis (Predis совместимость)
wget -q -O "$HTDOCS/lib/redis_compat.php" \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/htdocs/lib/redis_compat.php" \
    && echo "redis_compat.php скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать redis_compat.php"

# Скачиваем tinyfilemanager в корень htdocs/tools/
echo "Скачивание tinyfilemanager..."
wget -q -O "$HTDOCS/tools/tinyfilemanager.php"     "https://raw.githubusercontent.com/prasathmani/tinyfilemanager/master/tinyfilemanager.php"     && echo "tinyfilemanager.php скачан"     || echo "ВНИМАНИЕ: не удалось скачать tinyfilemanager.php"

# Скачиваем Redis монитор
wget -q -O "$HTDOCS/tools/redis_api.php" \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/htdocs/redis_api.php" \
    && echo "redis_api.php скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать redis_api.php"

wget -q -O "$HTDOCS/tools/redis_monitor.html" \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/htdocs/redis_monitor.html" \
    && echo "redis_monitor.html скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать redis_monitor.html"

echo "Redis монитор: http://IP:8080/redis_monitor.html"

# =============================================================
# ШАГ 7: Настройка config.php
# =============================================================
echo ""
echo ">>> ШАГ 7: Настройка config.php..."

# Создаём чистый config.php с настройками для Termux
cat > "$HTDOCS/config.php" << CONFIGEOF
<?php
Define('DB_HOST', '127.0.0.1');
Define('DB_NAME', 'db_terminal');
Define('DB_USER', 'root');
Define('DB_PASSWORD', '123');

Define('DIR_TEMPLATES', "./templates/");
Define('DIR_MODULES', "./modules/");
Define('DEBUG_MODE', 1);
Define('UPDATES_REPOSITORY_NAME', 'smarthome');

Define('PROJECT_TITLE', 'MajordomoSL');
Define('PROJECT_BUGTRACK', "bugtrack@smartliving.ru");

date_default_timezone_set('UTC');

Define('DOC_ROOT', dirname(__FILE__));
Define('SERVER_ROOT', '/data/data/com.termux/files/home/htdocs');
Define('PATH_TO_PHP', 'php');
Define('PATH_TO_MYSQLDUMP', 'mariadb-dump');
Define('PATH_TO_MYSQL', 'mariadb');

Define('BASE_URL', 'http://127.0.0.1:8080');

Define('ROOT', DOC_ROOT."/");
Define('ROOTHTML', "/");
Define('PROJECT_DOMAIN', isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : php_uname("n"));

$restart_threads = array(
    'cycle_execs.php',
    'cycle_main.php',
    'cycle_ping.php',
    'cycle_scheduler.php',
    'cycle_states.php',
    'cycle_webvars.php'
);

Define('GIT_URL', 'https://github.com/sergejey/majordomo/');
Define('MASTER_UPDATE_URL', GIT_URL.'archive/alpha.tar.gz');

Define('GETURL_WARNING_TIMEOUT', 5);
Define('ENABLE_PANEL_ACCELERATION', 1);

// Redis через Predis враппер (php-redis несовместим с Termux PHP API)
define('USE_REDIS', '127.0.0.1');

if (file_exists(DOC_ROOT . '/vendor/autoload.php')) {
    require_once DOC_ROOT . '/vendor/autoload.php';
}
if (file_exists(DOC_ROOT . '/lib/redis_compat.php')) {
    require_once DOC_ROOT . '/lib/redis_compat.php';
}

CONFIGEOF

echo "config.php создан"
php -l "$HTDOCS/config.php" && echo "Синтаксис OK" || echo "ОШИБКА синтаксиса!"

# =============================================================
# ШАГ 8: Создание и импорт БД
# =============================================================
echo ""
echo ">>> ШАГ 8: Создание базы данных..."

DB_EXISTS=$(mariadb -u root -p"$MYSQL_PASS" -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='db_terminal';" \
    2>/dev/null | tail -1)

if [ "$DB_EXISTS" -gt "0" ] 2>/dev/null; then
    echo "БД db_terminal уже существует ($DB_EXISTS таблиц) — пропускаем"
    echo "Для переустановки: mariadb -u root -p$MYSQL_PASS -e 'DROP DATABASE db_terminal;'"
else
    mariadb -u root -p"$MYSQL_PASS" -e \
        "CREATE DATABASE IF NOT EXISTS db_terminal CHARACTER SET utf8 COLLATE utf8_general_ci;"

    if [ -f "$HTDOCS/db_terminal.sql" ]; then
        mariadb -u root -p"$MYSQL_PASS" db_terminal < "$HTDOCS/db_terminal.sql"
        echo "БД импортирована"
    else
        echo "ВНИМАНИЕ: db_terminal.sql не найден"
    fi

    mariadb -u root -p"$MYSQL_PASS" << SQLEOF
USE db_terminal;
UPDATE pinghosts SET HOSTNAME='ya.ru';
UPDATE settings SET VALUE='dark' WHERE NAME='THEME';
INSERT IGNORE INTO settings (TITLE,NAME,TYPE,NOTES,VALUE,DEFAULTVALUE,DATA)
    VALUES ('Language','SITE_LANGUAGE','text','','ru','ru','');
INSERT IGNORE INTO settings (TITLE,NAME,TYPE,NOTES,VALUE,DEFAULTVALUE,DATA)
    VALUES ('Time zone','SITE_TIMEZONE','text','','Europe/Moscow','Europe/Moscow','');
SQLEOF
    echo "БД настроена"
fi

# =============================================================
# ШАГ 9: Патчи совместимости PHP 8
# =============================================================
# =============================================================
# ШАГ 9: Настройка lighttpd
# =============================================================
echo ""
echo ">>> ШАГ 9: Настройка lighttpd..."

# Путь к phpMyAdmin (реальный путь в Termux)
PMA_PATH="$PREFIX/share/phpmyadmin"
# Проверяем что папка существует
if [ ! -d "$PMA_PATH" ]; then
    PMA_PATH=$(find $PREFIX/share -name "index.php" 2>/dev/null | grep -i phpmyadmin | head -1 | xargs dirname)
fi

mkdir -p $PREFIX/etc/lighttpd

# Скачиваем конфиг lighttpd
wget -q -O $PREFIX/etc/lighttpd/lighttpd.conf \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/config/lighttpd.conf" \
    && echo "lighttpd.conf скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать lighttpd.conf"

# Подставляем реальный путь к htdocs и phpMyAdmin
sed -i "s|HTDOCS_PATH|$HTDOCS|g" $PREFIX/etc/lighttpd/lighttpd.conf
PMA_PATH="$PREFIX/share/phpmyadmin"
sed -i "s|PMA_PATH|$PMA_PATH|g" $PREFIX/etc/lighttpd/lighttpd.conf

echo "lighttpd.conf создан (phpMyAdmin: http://IP:8080/phpmyadmin)"

# Скачиваем конфиг phpMyAdmin (TCP подключение)
wget -q -O $PREFIX/share/phpmyadmin/config.inc.php \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/config/phpmyadmin_config.inc.php" \
    && echo "phpMyAdmin config.inc.php скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать конфиг phpMyAdmin"
echo "phpMyAdmin: config.inc.php создан"

# =============================================================
# ШАГ 10: Скрипт автозапуска (Termux:Boot)
# =============================================================
echo ""
echo ">>> ШАГ 10: Создание автозапуска..."
mkdir -p "$HOME_DIR/.termux/boot"
cat > "$HOME_DIR/.termux/boot/majordomo.sh" << BOOTEOF
#!/data/data/com.termux/files/usr/bin/bash
# Автозапуск Majordomo (Termux:Boot — установить из F-Droid)

PREFIX=/data/data/com.termux/files/usr
HTDOCS=/data/data/com.termux/files/home/htdocs

# Останавливаем старые процессы — защита от двойного запуска
pkill lighttpd 2>/dev/null
pkill php-cgi 2>/dev/null
pkill -f watchdog 2>/dev/null
pkill -f "cycle.php" 2>/dev/null
pkill -f "scripts/cycle" 2>/dev/null
sleep 2

# MariaDB
mariadbd-safe --datadir=\$PREFIX/var/lib/mysql > /dev/null 2>&1 &
sleep 8

# Redis
redis-server > /dev/null 2>&1 &
sleep 1

# php-cgi через unix socket — ДОЛЖЕН запускаться ДО lighttpd!
PHP_INI_SCAN_DIR=\$PREFIX/etc/php/conf.d \
    php-cgi -b \$PREFIX/var/run/php-cgi.sock > /dev/null 2>&1 &
sleep 3

# lighttpd
lighttpd -f \$PREFIX/etc/lighttpd/lighttpd.conf
sleep 2

# Основной цикл Majordomo
nohup php -d opcache.enable=0 \$HTDOCS/cycle.php \
    > \$HTDOCS/cycle_cached/cycle.log 2>&1 &
echo \$! > \$HTDOCS/cycle_cached/cycle.php.lock

# Единый watchdog (php-cgi + cycle.php)
nohup bash \$HTDOCS/watchdog.sh > /dev/null 2>&1 &
BOOTEOF

chmod +x "$HOME_DIR/.termux/boot/majordomo.sh"
echo "Автозапуск: ~/.termux/boot/majordomo.sh"

# Скачиваем watchdog
wget -q -O "$HTDOCS/watchdog.sh" \
    "https://raw.githubusercontent.com/artt652/majordomo-android/main/htdocs/watchdog.sh" \
    && echo "watchdog.sh скачан" \
    || echo "ВНИМАНИЕ: не удалось скачать watchdog.sh"
chmod +x "$HTDOCS/watchdog.sh"
echo "Watchdog создан: ~/htdocs/watchdog.sh"



# =============================================================
# ШАГ 11: Запуск сервисов
# =============================================================
echo ""
echo ">>> ШАГ 11: Запуск сервисов..."

pkill -f "php.*-S 0.0.0.0" 2>/dev/null || true
pkill php-cgi 2>/dev/null || true
pkill lighttpd 2>/dev/null || true
pkill -f "cycle.php" 2>/dev/null || true
sleep 2

# php-cgi (ДО lighttpd!)
PHP_INI_SCAN_DIR=$PREFIX/etc/php/conf.d \
    php-cgi -b $PREFIX/var/run/php-cgi.sock > /dev/null 2>&1 &
sleep 3
ps aux | grep -q "[p]hp-cgi" && echo "php-cgi: OK" || {
    echo "ОШИБКА: php-cgi не запустился!"
    exit 1
}

# lighttpd
lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
sleep 2
ps aux | grep -q "[l]ighttpd" && echo "lighttpd: OK" || {
    echo "ОШИБКА: lighttpd не запустился!"
    tail -5 "$LOGS/lighttpd_error.log"
    exit 1
}

# cycle.php
cd "$HTDOCS"
nohup php -d opcache.enable=0 "$HTDOCS/cycle.php" \
    > "$LOGS/cycle.log" 2>&1 &
CYC_PID=$!
echo "$CYC_PID" > "$LOGS/cycle.php.lock"

# Ждём до 3 минут — cycle.php делает CHECK TABLE при первом запуске
echo "Ожидание запуска cycle.php (CHECK TABLE ~3 мин при первом запуске)..."
WAITED=0
while [ $WAITED -lt 180 ]; do
    sleep 5
    WAITED=$((WAITED+5))
    if ps aux | grep -q "[p]hp.*cycle\.php"; then
        echo "cycle.php: OK (PID: $CYC_PID, запущен за ${WAITED} сек)"
        break
    fi
    if [ $WAITED -ge 180 ]; then
        echo "ВНИМАНИЕ: cycle.php не запустился за 3 минуты. Watchdog перезапустит автоматически."
        echo "Лог:"
        tail -5 "$LOGS/cycle.log"
    fi
done

# Единый watchdog
pkill -f watchdog 2>/dev/null || true
sleep 1
nohup bash "$HTDOCS/watchdog.sh" > /dev/null 2>&1 &
echo "Watchdog: OK (PID: $!)"

# =============================================================
# ШАГ 12: Проверка
# =============================================================
echo ""
echo ">>> ШАГ 12: Проверка..."
sleep 2

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/ 2>/dev/null || echo "000")
[ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ] \
    && echo "Веб-сервер: OK (HTTP $HTTP_CODE)" \
    || echo "Веб-сервер: ВНИМАНИЕ (HTTP $HTTP_CODE)"

mariadb -u root -p"$MYSQL_PASS" -e \
    "SELECT COUNT(*) as 'Таблиц в БД' FROM information_schema.tables \
     WHERE table_schema='db_terminal';" 2>/dev/null

IP=$(ifconfig 2>/dev/null | grep "inet " | grep -v "127.0.0.1" | awk '{print $2}' | head -1)

echo ""
echo "╔════════════════════════════════════════════════════╗"
echo "║            Установка завершена!                    ║"
echo "╠════════════════════════════════════════════════════╣"
echo "║  Majordomo:   http://$IP:8080              ║"
echo "║  phpMyAdmin:  http://$IP:8080/phpmyadmin   ║"
echo "╠════════════════════════════════════════════════════╣"
echo "║  Логи:                                             ║"
echo "║  Веб:   ~/htdocs/cycle_cached/lighttpd_error.log  ║"
echo "║  Цикл:  ~/htdocs/cycle_cached/cycle.log           ║"
echo "╠════════════════════════════════════════════════════╣"
echo "║  Установите Termux:Boot из F-Droid                 ║"
echo "║  для автозапуска после перезагрузки!               ║"
echo "╚════════════════════════════════════════════════════╝"
