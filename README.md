# farmpulse

Здесь **веб-морда** (HTML + PHP + CSS + JS) и **отдельный каталог API** (только JSON и worker для ригов).

## Структура

| Каталог | Назначение |
|---------|------------|
| **`web/`** | Сайт: `index.php`, `farm.php`, статика `css/`, `js/`, **`client/`** (скрипты Hive OS sidecar). |
| **`web/includes/config.php`** | Базовый путь к API (по умолчанию `/api`). Переопределение: `WEB_API_BASE` в окружении. |
| **`api/`** | Сервис API: `worker/api.php`, `v2/farms/*`, `v2/market/*`, диспетчер `api/index.php`. |
| **`deploy/`** | Пример nginx, **`README.md`** и **`rsync-deploy.sh.example`** — как выкатывать `web/` + `api/`. |
| **`web/client/`** | Установщик для **Hive OS** (sidecar): `install.sh`, `firstrun_farmpulse`, см. **`web/client/README.md`**. |
| **`docs/`** | Документация. |

Браузер ходит на тот же хост: страницы с `/`, запросы XHR на **`/api/v2/...`** (кросс-домен не нужен).

## Локальный запуск (одна команда)

Из каталога **`farmpulse`**:

```bash
php -S localhost:8080 -t web web/router-dev.php
```

Открыть http://localhost:8080/ — статика и PHP из `web/`, пути `/api/...` из `api/`, **`/client/...`** — из `web/client/`.

## GitHub

Локально инициализирован отдельный репозиторий в этой папке. Пошагово создать проект на GitHub и сделать первый push: **`docs/GITHUB-SETUP.md`**.

## Деплой без ручного копирования всего проекта

См. **`deploy/README.md`**: варианты **rsync** (скрипт-пример), **git pull** на сервере, CI/CD. На прод **`web/`** (с **`web/client/`** для `wget` с рига) + **`api/`**; `config.json` и логи на сервере по умолчанию не перезаписываются.

## Продакшен (nginx)

См. **`deploy/nginx-site.conf.example`**: локально (OSPanel) домен **`hive-management`** (каталог `domains/hive-management`). На VPS замените `server_name`, `root` и пути к сертификатам на свой домен и каталог (часто корень репозитория: `/var/www/hive-management`, внутри — `farmpulse/`). **`default_server`** в примере не используется: на одном nginx он может быть только один раз.

DNS: запись **A** для вашего домена → IP сервера. HTTPS: `certbot --nginx -d hive-management` (или ваш FQDN) после того, как имя смотрит на сервер.

После деплоя скопируйте `api/v2/farms/config.json.example` → `config.json` и задайте фермы.

## Риги Hive OS

Установка sidecar на риг: **`web/client/README.md`** — `wget http://hive-management/client/install.sh` и `firstrun_farmpulse` (на проде подставьте публичный URL).

## URL для рига (worker)

`http://hive-management/api/worker/api.php?id_rig=<ID>&method=stats`  
Локально в OSPanel. Снаружи: `https://<ваш-домен>/api/worker/...` или по IP: `http://<IP>/api/worker/api.php?...`

## Связь с родительским проектом

Заготовка в `../` (hive-management). Дублирование правок — по необходимости.
