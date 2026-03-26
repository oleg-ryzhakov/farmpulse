# farmpulse

Здесь **веб-морда** (HTML + PHP + CSS + JS) и **отдельный каталог API** (только JSON и worker для ригов).

## Структура

| Каталог | Назначение |
|---------|------------|
| **`web/`** | Сайт: `index.php`, `farm.php`, статика `css/`, `js/`. PHP только для разметки и `WEB_API_BASE`. |
| **`web/includes/config.php`** | Базовый путь к API (по умолчанию `/api`). Переопределение: `WEB_API_BASE` в окружении. |
| **`api/`** | Сервис API: `worker/api.php`, `v2/farms/*`, `v2/market/*`, диспетчер `api/index.php`. |
| **`deploy/`** | Пример nginx, **`README.md`** и **`rsync-deploy.sh.example`** — как выкатывать `web/` + `api/` + при необходимости `client/`. |
| **`client/`** | Скрипты для **Hive OS** (sidecar): `install.sh`, `firstrun_farmpulse`, см. **`client/README.md`**. |
| **`docs/`** | Документация. |

Браузер ходит на тот же хост: страницы с `/`, запросы XHR на **`/api/v2/...`** (кросс-домен не нужен).

## Локальный запуск (одна команда)

Из каталога **`farmpulse`**:

```bash
php -S localhost:8080 -t web web/router-dev.php
```

Открыть http://localhost:8080/ — статика и PHP из `web/`, пути `/api/...` из `api/`, **`/client/...`** — статика установщика из `client/`.

## GitHub

Локально инициализирован отдельный репозиторий в этой папке. Пошагово создать проект на GitHub и сделать первый push: **`docs/GITHUB-SETUP.md`**.

## Деплой без ручного копирования всего проекта

См. **`deploy/README.md`**: варианты **rsync** (скрипт-пример), **git pull** на сервере, CI/CD. На прод обычно **`web/`** + **`api/`** + **`client/`** (для `wget` установщика с рига); `config.json` и логи на сервере по умолчанию не перезаписываются.

## Продакшен (nginx)

См. **`deploy/nginx-site.conf.example`**: домен **`farmpulse.its-good.ru`**, доступ по IP — **публичный IP дописан в `server_name`** (в файле замените `203.0.113.10` на IP VPS). **`default_server`** в примере не используется: на одном nginx он может быть только один раз.

DNS: запись **A** для `farmpulse.its-good.ru` → IP VPS. HTTPS: `certbot --nginx -d farmpulse.its-good.ru` после того, как домен смотрит на сервер.

После деплоя скопируйте `api/v2/farms/config.json.example` → `config.json` и задайте фермы.

## Риги Hive OS

Установка sidecar на риг: **`client/README.md`** (команда `wget …/client/install.sh` и `firstrun_farmpulse`).

## URL для рига (worker)

`https://farmpulse.its-good.ru/api/worker/api.php?id_rig=<ID>&method=stats`  
(после включения HTTPS) или по IP: `http://<IP>/api/worker/api.php?...`

## Связь с родительским проектом

Заготовка в `../` (hive-management). Дублирование правок — по необходимости.
