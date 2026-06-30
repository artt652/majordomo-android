#!/data/data/com.termux/files/usr/bin/bash
# Полная остановка Majordomo на Termux

HTDOCS=/data/data/com.termux/files/home/htdocs

echo "Останавливаем watchdog..."
pkill -f watchdog 2>/dev/null

echo "Останавливаем lighttpd..."
pkill lighttpd 2>/dev/null

echo "Останавливаем php-cgi..."
pkill php-cgi 2>/dev/null

echo "Останавливаем cycle.php и дочерние процессы..."
pkill -9 -f "cycle.php" 2>/dev/null
pkill -9 -f "scripts/cycle" 2>/dev/null

sleep 2

echo "Останавливаем Redis..."
redis-cli shutdown nosave 2>/dev/null

echo "Останавливаем MariaDB..."
mariadb-admin -u root shutdown 2>/dev/null || pkill mariadbd 2>/dev/null

sleep 3

echo ""
echo "=== Проверка ==="
REMAINING=$(ps aux | grep -E "php-cgi|lighttpd|cycle\.php|mariadbd|redis-server" | grep -v grep)
if [ -z "$REMAINING" ]; then
    echo "Все процессы остановлены"
else
    echo "ВНИМАНИЕ: остались процессы:"
    echo "$REMAINING"
fi
