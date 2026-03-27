# Деплой на сервер

На прод нужны **`web/`** (включая **`web/client/`** — установщик для ригов: `wget …/client/install.sh`) и **`api/`**. Для мобильного API в памяти — ещё **`api-application/server/`** (Python). Папки `docs/`, примеры nginx — не обязательны для работы сайта.

Файлы на VPS **ниоткуда сами не подтягиваются**: вы один раз кладёте дерево проекта туда через **git** (`clone` / `pull`) или **rsync/scp** с вашего ПК, либо **CI** по push. После обновления кода — при необходимости `pip install -r api-application/server/requirements.txt`, перезапуск `farmpulse-app-api`, `nginx reload` (см. `api-application/README.md`).

## Варианты

### 1. `rsync` с ПК (просто и прозрачно)

Скрипт-пример: **`rsync-deploy.sh.example`** — копирует только `web/` и `api/`, не трогает `config.json` на сервере. При использовании Python-сервиса добавьте в rsync каталог **`api-application/`** (или деплойте его отдельной командой).

Скопируйте в `rsync-deploy.sh`, выставьте `DEPLOY_HOST` и путь, сделайте исполняемым: `chmod +x rsync-deploy.sh`.

### 2. Git на сервере

На VPS: клонировать репозиторий (или только каталог `farmpulse`), затем:

```bash
cd /var/www/farmpulse && git pull && sudo systemctl reload php8.3-fpm
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
