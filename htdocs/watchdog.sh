#!/data/data/com.termux/files/usr/bin/bash
# Watchdog для Majordomo на Termux/Android
# Следит за php-cgi и cycle.php, перезапускает при падении
# Логи пишутся в cms/debmes/ДАТА/watchdog — видны в журнале Majordomo
#
# Репозиторий: https://github.com/YOUR_USER/majordomo-android

PREFIX=/data/data/com.termux/files/usr
HTDOCS=/data/data/com.termux/files/home/htdocs

debmes() {
    local msg="$1"
    local DATE=$(date +%Y-%m-%d)
    local TIME=$(date +%H:%M:%S)
    local DIR="$HTDOCS/cms/debmes/$DATE"
    mkdir -p "$DIR"
    echo "$TIME 0.00000000  $msg" >> "$DIR/watchdog"
}

while true; do
    # Создаём папку бэкапа на текущий день
    # Предотвращает полный CHECK TABLE при каждом перезапуске cycle_main (~3 мин)
    mkdir -p $HTDOCS/backup/$(date +%Y%m%d)

    # Следим за php-cgi (веб-интерфейс)
    if ! ps aux | grep -q "[p]hp-cgi"; then
        debmes "php-cgi упал, перезапускаем..."
        PHP_INI_SCAN_DIR=$PREFIX/etc/php/conf.d \
            php-cgi -b $PREFIX/var/run/php-cgi.sock > /dev/null 2>&1 &
        sleep 3
    fi

    # Следим за cycle.php (основной демон)
    if ! ps aux | grep -q "[p]hp.*cycle\.php"; then
        debmes "cycle.php упал, перезапускаем..."
        cd $HTDOCS
        nohup php -d opcache.enable=0 $HTDOCS/cycle.php \
            > $HTDOCS/cycle_cached/cycle.log 2>&1 &
        echo $! > $HTDOCS/cycle_cached/cycle.php.lock
        # Длинная пауза — cycle.php делает CHECK TABLE при старте (~3 мин)
        # Без паузы watchdog запускает его повторно до завершения CHECK TABLE
        sleep 60
    fi

    sleep 30
done
