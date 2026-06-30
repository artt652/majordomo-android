#!/data/data/com.termux/files/usr/bin/bash
# Перезапуск Majordomo на Termux

PREFIX=/data/data/com.termux/files/usr
HTDOCS=/data/data/com.termux/files/home/htdocs

echo "Останавливаем процессы..."
pkill lighttpd 2>/dev/null
pkill php-cgi 2>/dev/null
pkill -f watchdog 2>/dev/null
pkill -9 -f "cycle.php" 2>/dev/null
pkill -9 -f "scripts/cycle" 2>/dev/null
sleep 3

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

echo "Запускаем lighttpd..."
lighttpd -f $PREFIX/etc/lighttpd/lighttpd.conf
sleep 2

ps aux | grep -q "[l]ighttpd" && echo "lighttpd: OK" || {
    echo "ОШИБКА: lighttpd не запустился!"
    tail -5 "$HTDOCS/cycle_cached/lighttpd_error.log"
    exit 1
}

echo "Запускаем cycle.php..."
nohup php -d opcache.enable=0 $HTDOCS/cycle.php \
  > $HTDOCS/cycle_cached/cycle.log 2>&1 &
echo $! > $HTDOCS/cycle_cached/cycle.php.lock
sleep 3

echo "Запускаем watchdog..."
nohup bash $HTDOCS/watchdog.sh > /dev/null 2>&1 &

sleep 2
echo ""
echo "=== Статус ==="
ps aux | grep -E "php-cgi|lighttpd|cycle\.php" | grep -v grep
echo ""
curl -s -o /dev/null -w "HTTP: %{http_code}\n" "http://127.0.0.1:8080/admin.php"
