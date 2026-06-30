# majordomo-android

Набор файлов для запуска [Majordomo](https://github.com/sergejey/majordomo) на Android через Termux без root-прав.

## Быстрая установка

```bash
pkg install wget -y
wget -O ~/install_majordomo.sh \
    https://raw.githubusercontent.com/artt652/majordomo-android/main/install_majordomo_termux.sh
chmod +x ~/install_majordomo.sh
bash ~/install_majordomo.sh
```

## Структура репозитория

```
majordomo-android/
├── install_majordomo_termux.sh   # Скрипт установки
├── htdocs/
│   ├── lib/
│   │   └── redis_compat.php      # Враппер Redis через Predis
│   ├── watchdog.sh               # Watchdog для php-cgi и cycle.php
│   ├── redis_api.php             # API для монитора Redis
│   └── redis_monitor.html        # Дашборд мониторинга Redis
└── config/
    ├── lighttpd.conf             # Конфиг веб-сервера
    ├── opcache_off.ini           # Настройки PHP (OPcache + error_reporting)
    ├── disable_strict_mode.cnf   # MySQL strict mode off
    └── phpmyadmin_config.inc.php # phpMyAdmin TCP подключение
```

## Доступ после установки

| Сервис | Адрес |
|---|---|
| Majordomo | `http://IP:8080` |
| phpMyAdmin | `http://IP:8080/phpmyadmin/` |
| TinyFileManager | `http://IP:8080/tinyfilemanager.php` |
| Redis монитор | `http://IP:8080/redis_monitor.html` |
| WebSocket | порт `8001` |

IP устройства:
```bash
ifconfig | grep "inet " | grep -v "127.0.0.1"
```

## Требования

- Android 8+
- [Termux](https://termux.dev) (не из Google Play)
- [Termux:Boot](https://f-droid.org/packages/com.termux.boot/) для автозапуска

## Ключевые особенности

- **php-fpm не работает** на Android (shm_open заблокирован) → используется php-cgi
- **OPcache отключён** → php-cgi запускается через unix socket вручную до lighttpd
- **DB_HOST = 127.0.0.1** (не localhost) → TCP вместо unix socket
- **Predis вместо php-redis** → php-redis несовместим с PHP API Termux
- **Watchdog** → следит за php-cgi и cycle.php, логи в DebMes Majordomo

## Подробная документация

См. [majordomo_termux_guide.md](majordomo_termux_guide.md)
