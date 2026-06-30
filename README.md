# majordomo-android

Набор файлов для запуска [Majordomo](https://github.com/sergejey/majordomo) на Android через Termux без root-прав.

Адаптация инсталлятора под платформу с ограничениями Termux (нет root, SELinux блокирует unix-сокеты дочерним процессам, нет systemd, PHP 8.5)

Подробная инструкция, диагностика и объяснения архитектурных решений — в [majordomo_termux_guide.md](majordomo_termux_guide.md).

## Быстрая установка

```bash
pkg install wget -y
wget -O ~/install_majordomo.sh \
    https://raw.githubusercontent.com/artt652/majordomo-android/main/install_majordomo_termux.sh
chmod +x ~/install_majordomo.sh
bash ~/install_majordomo.sh
```

Скрипт ставит пакеты, разворачивает Majordomo, настраивает MariaDB/Redis/lighttpd/php-cgi, создаёт автозапуск через Termux:Boot и поднимает все сервисы.

## Структура репозитория

```
majordomo-android/
├── install_majordomo_termux.sh   # Скрипт установки
├── htdocs/
│   ├── lib/
│   │   └── redis_compat.php      # Враппер Redis через Predis (используется только если нет ext-redis)
│   ├── watchdog.sh                # Watchdog для php-cgi и cycle.php
│   ├── restart.sh                 # Ручной перезапуск всех сервисов
│   ├── start.sh                   # Запуск сервисов
│   ├── stop.sh                    # Остановка сервисов
│   └── tools/
│       ├── redis_api.php          # API для монитора Redis
│       ├── redis_monitor.html     # Дашборд мониторинга Redis
│       ├── log_api.php            # API для просмотра логов
│       ├── log_viewer.html        # Просмотрщик логов
│       ├── process_api.php        # API для менеджера процессов
│       └── process_manager.html   # Менеджер процессов
└── config/
    ├── lighttpd.conf               # Конфиг веб-сервера (пути подставляются скриптом установки)
    └── redis.conf                  # Конфиг Redis (RDB снапшоты отключены)
```

`tinyfilemanager.php` скачивается из апстрима ([prasathmani/tinyfilemanager](https://github.com/prasathmani/tinyfilemanager)) напрямую в `htdocs/tools/` — не хранится в этом репозитории.

OPcache/error_reporting, отключение MySQL strict mode и конфиг phpMyAdmin генерируются скриптом установки на лету (heredoc), отдельными файлами в репозитории не хранятся.

## Доступ после установки

| Сервис | Адрес |
|---|---|
| Majordomo | `http://127.0.0.1:8080` |
| phpMyAdmin | `http://127.0.0.1:8080/phpmyadmin/` |
| TinyFileManager | `http://127.0.0.1:8080/tools/tinyfilemanager.php` |
| Redis монитор | `http://127.0.0.1:8080/tools/redis_monitor.html` |
| Просмотр логов | `http://127.0.0.1:8080/tools/log_viewer.html` |
| Менеджер процессов | `http://127.0.0.1:8080/tools/process_manager.html` |
| WebSocket | порт `8001` |

IP устройства (для доступа с других устройств в той же сети):
```bash
ifconfig | grep "inet " | grep -v "127.0.0.1" | awk '{print $2}' | head -1
```
`ip route get 1` на части устройств Termux без root возвращает `Permission denied` (ограничение netlink-сокета), поэтому используется `ifconfig` (из пакета `net-tools`, ставится инсталлятором).

## Требования

- Android 7+
- [Termux](https://termux.dev) (актуальная версия, не из Google Play)
- [Termux:Boot](https://f-droid.org/packages/com.termux.boot/) для автозапуска после перезагрузки

## Ключевые особенности

- **php-fpm не работает** на Android (`shm_open` заблокирован) → используется `php-cgi`
- **php-cgi работает через TCP (`127.0.0.1:9000`), а не unix-сокет** → SELinux на Android блокирует `accept()` в дочерних процессах при unix-сокете, это было подтверждено опытным путём
- **OPcache отключён** → падает с `Permission denied` из-за shm lock на Android
- **DB_HOST = 127.0.0.1** (не `localhost`) → форсирует TCP вместо unix-сокета для MariaDB
- **Predis вместо php-redis** → расширение `redis` несовместимо с PHP API Termux; `redis_compat.php` подключается только если `extension_loaded('redis')` вернул `false`, и только если Redis действительно слушает порт (проверка `fsockopen`)
- **Redis без RDB-снапшотов** → `save ""` в `redis.conf`. Данные в Redis (кэш состояний устройств) нужны только пока система работает и пересоздаются заново при каждом запуске — сохранять их на диск не требуется. К тому же периодические фоновые записи снапшотов на Android без root могут завершаться ошибкой или просто зря нагружать флеш-память.
- **Watchdog** (`watchdog.sh`) → следит за `php-cgi` и дочерними процессами `cycle_*`, перезапускает при падении; логи в `~/htdocs/cycle_cached/`
- **`restart.sh` / `start.sh` / `stop.sh`** → ручное управление

## Известные ограничения

- Android может выгружать процессы Termux в фоне — для надёжной работы нужно отключить оптимизацию батареи для Termux и держать foreground-уведомление активным

## Разработка

Android-Termux специфичные патчи (поведение lighttpd, окружение Termux) остаются в этом репозитории. Исправления логики и совместимости с PHP 8, пригодные для всех платформ, отправляются upstream-PR в [sergejey/majordomo](https://github.com/sergejey/majordomo) (ветка `alpha`).
