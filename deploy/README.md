# Деплой на сервер

На прод нужны **`web/`** (включая **`web/client/`** — установщик для ригов: `wget …/client/install.sh`) и **`api/`**. Для мобильного API в памяти — ещё **`api-application/server/`** (Python). Папки `docs/`, примеры nginx — не обязательны для работы сайта.

**eWeLink (привязка аккаунта):** Node-скрипт лежит в **`api/ewelink-node/`** (входит в деплой вместе с `api/`). После `git pull` на сервере один раз: `cd api/ewelink-node && npm ci`, на VPS нужны Node.js, переменные `EWELINK_APP_ID` / `EWELINK_APP_SECRET` и ключ `FARMPULSE_EWELINK_KEY` (см. комментарии в `api/v2/integrations/ewelink.php`).

Готовый **исправленный** конфиг nginx для `sites-available` (один `server` на 443, без вложенности): [`sites-available-farmpulse.conf`](sites-available-farmpulse.conf) — можно скопировать на сервер целиком в `/etc/nginx/sites-available/farmpulse` (после бэкапа старого файла).

Синхронизация ферм в **Python app-api** после heartbeat рига: скопировать [`api/v2/farms/app_api_sync.json.example`](../api/v2/farms/app_api_sync.json.example) → `api/v2/farms/app_api_sync.json`, указать `api_key` (как `FARMPULSE_APP_API_KEY`) и `base_url` публичного `app-api`. Без этого файла worker только пишет `config.json`, память Python не обновляется.

Файлы на VPS **ниоткуда сами не подтягиваются**: вы один раз кладёте дерево проекта туда через **git** (`clone` / `pull`) или **rsync/scp** с вашего ПК, либо **CI** по push. После обновления кода — при необходимости `pip install -r api-application/server/requirements.txt`, перезапуск `farmpulse-app-api`, `nginx reload` (см. `api-application/README.md`).

## Варианты

### 1. `rsync` с ПК (просто и прозрачно)

Скрипт-пример: **`rsync-deploy.sh.example`** — копирует только `web/` и `api/`, не трогает `config.json` на сервере. При использовании Python-сервиса добавьте в rsync каталог **`api-application/`** (или деплойте его отдельной командой).

Скопируйте в `rsync-deploy.sh`, выставьте `DEPLOY_HOST` (например `user@192.0.2.10`) и при необходимости `REMOTE_PATH` (по умолчанию `/var/www/hive-management/farmpulse`; если на сервере клонирован только `farmpulse` в `/var/www/farmpulse` — задайте его), сделайте исполняемым: `chmod +x rsync-deploy.sh`.

### 2. Git на сервере

На VPS: клонировать репозиторий (или только каталог `farmpulse`), затем:

```bash
cd /var/www/hive-management/farmpulse && git pull && sudo systemctl reload php8.3-fpm
```

Чтобы не тащить лишнее, можно:

- держать в отдельной ветке только прод-код; или  
- **sparse checkout** только `web` и `api`; или  
- отдельный маленький репозиторий только `farmpulse` (только `web` + `api`).

### 3. CI/CD (GitHub Actions / GitLab)

По push в `main`: `rsync` или `scp` на сервер по SSH-ключу, либо pull на сервере через webhook.

### 4. Что не перезаписывать

На сервере **не затирайте** при деплое:

- `api/v2/farms/config.json` — пароли и фермы  
- при необходимости: `api/v2/market/wallets.json`, кэши `*.cache.json` — в скрипте они в исключениях

---

PHP-FPM после правок PHP обычно не обязателен к reload, но можно: `sudo systemctl reload php8.3-fpm`.

## Команды после обновления (VPS и риги)

### Сервер (VPS)

Путь к коду подставьте под свою схему: **монорепозиторий** `hive-management` или **только** `farmpulse`.

```bash
# Монорепозиторий hive-management (в корне есть farmpulse/):
cd /var/www/hive-management && git pull

# Только репозиторий farmpulse на сервере:
# cd /var/www/farmpulse && git pull

# PHP: перезагрузить FPM или Apache (имя сервиса под свою версию PHP)
sudo systemctl reload php8.3-fpm
# или: sudo systemctl reload apache2
# или: sudo systemctl reload nginx && sudo systemctl reload php8.3-fpm

# Опционально: расширение msgpack для тел запросов hive-agent в MessagePack
# sudo pecl install msgpack
# подключить extension=msgpack.so в php.ini и снова reload php-fpm
```

**FarmPulse app-api** (uvicorn):

```bash
cd /var/www/hive-management/farmpulse/api-application/server
# при необходимости: . .venv/bin/activate && pip install -r requirements.txt
sudo systemctl restart farmpulse-app-api
```

**Проверка app-api** (локально в OSPanel домен `hive-management`; на проде — свой `https://…`):

```bash
curl -sS http://hive-management/app-api/health
```

### Фермы (Hive OS / Linux с sidecar)

Обновить клиент с панели (базовый URL без завершающего `/`):

```bash
wget -qO- http://hive-management/client/install.sh | bash -s -- http://hive-management
```

На проде замените оба вхождения `http://hive-management` на публичный URL, например `https://farm.example.com`.

Обновить только `sidecar.sh` с уже развёрнутого сайта:

```bash
sudo cp /opt/farmpulse/bin/sidecar.sh /opt/farmpulse/bin/sidecar.sh.bak
sudo wget -O /opt/farmpulse/bin/sidecar.sh 'http://hive-management/client/sidecar.sh'
sudo chmod +x /opt/farmpulse/bin/sidecar.sh
sudo systemctl restart farmpulse-sidecar.timer
sudo farmpulse-sidecar trace
```
