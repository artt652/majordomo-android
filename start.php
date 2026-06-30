#!/data/data/com.termux/files/usr/bin/bash
# Запуск Majordomo на Termux (первый старт или после полной остановки)

PREFIX=/data/data/com.termux/files/usr
HTDOCS=/data/data/com.termux/files/home/htdocs

# MariaDB
if ps aux | grep -q "[m]ariadbd "; then
    echo "MariaDB: уже запущена"
else
    echo "Запускаем MariaDB..."
    mariadbd-safe --datadir=$PREFIX/var/lib/mysql > /dev/null 2>&1 &
    sleep 8
    ps aux | grep -q "[m]ariadbd " && echo "MariaDB: OK" || {
        echo "ОШИБКА: MariaDB не запустилась!"
        exit 1
    }
fi

# Redis
if ps aux | grep -q "[r]edis-server"; then
    echo "Redis: уже запущен"
else
    echo "Запускаем Redis..."
    redis-server > /dev/null 2>&1 &
    sleep 1
    ps aux | grep -q "[r]edis-server" && echo "Redis: OK" || echo "ВНИМАНИЕ: Redis не запустился"
fi

# php-cgi
if ps aux | grep -q "[p]hp-cgi"; then
    echo "php-cgi: уже запущен"
else
    echo "Запускаем php-cgi..."
    PHP_FCGI_CHILDREN=4 \
    PHP_FCGI_MAX_REQUESTS=500 \
    PHP_INI_SCAN_DIR=$PREFIX/etc/php/conf.d \
      php-cgi -b 127.0.0.1:9000 > /dev/null 2>&1 &
    sleep 3
    ps aux | grep -q "[p]hp-cgi" && echo "php-cgi: OK" || {
        echo "ОШИБКА: php-cgi не запустился!"
        exit 1
    }
fi

# lighttpd
if ps aux | grep -q "[l]ighttpd"; then
    echo "lighttpd: уже запущен"
else
    echo "Запускаем lighttpd..."
    lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
    sleep 2
    ps aux | grep -q "[l]ighttpd" && echo "lighttpd: OK" || {
        echo "ОШИБКА: lighttpd не запустился!"
        tail -5 "$HTDOCS/cycle_cached/lighttpd_error.log"
        exit 1
    }
fi

# cycle.php
if ps aux | grep -q "[p]hp.*cycle\.php"; then
    echo "cycle.php: уже запущен"
else
    echo "Запускаем cycle.php..."
    nohup php -d opcache.enable=0 $HTDOCS/cycle.php \
      > $HTDOCS/cycle_cached/cycle.log 2>&1 &
    echo $! > $HTDOCS/cycle_cached/cycle.php.lock
    sleep 3
    ps aux | grep -q "[p]hp.*cycle\.php" && echo "cycle.php: OK" || echo "ВНИМАНИЕ: cycle.php не запустился"
fi

# watchdog
if ps aux | grep -q "[w]atchdog"; then
    echo "watchdog: уже запущен"
else
    echo "Запускаем watchdog..."
    nohup bash $HTDOCS/watchdog.sh > /dev/null 2>&1 &
    echo "watchdog: OK (PID: $!)"
fi

sleep 2
echo ""
echo "=== Статус ==="
ps aux | grep -E "php-cgi|lighttpd|cycle\.php|mariadbd|redis" | grep -v grep
echo ""
curl -s -o /dev/null -w "HTTP: %{http_code}\n" "http://127.0.0.1:8080/admin.php"
