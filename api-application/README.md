# API приложения (мобильный клиент)

Сервис на **Python (FastAPI)**: состояние ферм **в оперативной памяти**, REST + **WebSocket** для потока обновлений. Отдельно от приёма данных от ригов (`../api/worker/`).

Общая схема: [`../docs/MOBILE-CLIENT-SERVER.md`](../docs/MOBILE-CLIENT-SERVER.md).

---

## Где сейчас «исторические» данные PHP

| Что | Где |
|-----|-----|
| Файл | `api/v2/farms/config.json` |
| Обновление | `api/worker/api.php` при `hello` / `stats` / `message` |

Этот Python-сервис **не** заменяет worker автоматически: при старте можно подтянуть снимок из JSON (`FARMPULSE_BOOTSTRAP_CONFIG`), дальше — обновления через `POST /internal/heartbeat` (позже можно вызывать из того же места, что и запись в `config.json`).

---

## Эндпоинты (защита: `X-Api-Key` или `Authorization: Bearer …`)

| Метод | Путь | Назначение |
|-------|------|------------|
| GET | `/health` | Проверка без ключа |
| GET | `/farms` | JSON-снимок списка ферм |
| POST | `/internal/heartbeat` | Тело JSON: `farm_id`, опционально `name`, `gpu_temps`, `gpu_count`, `status` |
| WS | `/ws?token=<ключ>` | Подписка: при каждом изменении — сообщение `{"type":"farms_snapshot","data":{...}}` |

Переменная окружения **`FARMPULSE_APP_API_KEY`** обязательна (кроме `/health`).

---

## Порт 443 и два API

**Оба** могут идти через **один HTTPS (443)**:

- **`/api/...`** — nginx → PHP-FPM (как сейчас).
- **`/app-api/...`** — nginx → прокси на **localhost:8000** (uvicorn).

Отдельный публичный порт для приложения **не обязателен**: достаточно префикса и прокси. Отдельный порт имеет смысл только для изоляции без nginx или для отладки.

Пример nginx: [`../deploy/nginx-site.conf.example`](../deploy/nginx-site.conf.example) (блок `location ^~ /app-api/`).

Снаружи:

- `https://ваш-домен/app-api/health`
- `https://ваш-домен/app-api/farms` (с заголовком ключа)
- `wss://ваш-домен/app-api/ws?token=...`

---

## Установка на сервере (Ubuntu, типичный VPS)

1. **Python 3.10+** (часто уже есть):

   ```bash
   sudo apt update
   sudo apt install -y python3 python3-venv python3-pip
   ```

2. **Код** — каталог `api-application/server` в дереве деплоя, например `/var/www/farmpulse/api-application/server`.

3. **Виртуальное окружение и зависимости:**

   ```bash
   cd /var/www/farmpulse/api-application/server
   python3 -m venv .venv
   . .venv/bin/activate
   pip install -r requirements.txt
   ```

4. **Секрет** — скопировать `env.example` → `/etc/farmpulse/app-api.env` (права `640`, root:www-data), задать **`FARMPULSE_APP_API_KEY`**. Пример: [`../deploy/app-api.env.example`](../deploy/app-api.env.example).

5. **systemd** — [`../deploy/farmpulse-app-api.service.example`](../deploy/farmpulse-app-api.service.example): скопировать в `/etc/systemd/system/farmpulse-app-api.service`, поправить пути, затем:

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now farmpulse-app-api
   sudo systemctl status farmpulse-app-api
   ```

6. **nginx** — добавить `location` для `/app-api/` (см. пример выше), проверить и перезагрузить:

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

7. Проверка:

   ```bash
   curl -sS http://127.0.0.1:8000/health
   curl -sS -H "X-Api-Key: ВАШ_КЛЮЧ" http://127.0.0.1:8000/farms
   ```

---

## Локальная отладка (Windows / разработка)

```bat
cd farmpulse\api-application\server
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
set FARMPULSE_APP_API_KEY=test-key-12345
uvicorn main:app --reload --host 127.0.0.1 --port 8000
```

Открыть `http://127.0.0.1:8000/docs` — интерактивная схема OpenAPI.

---

## Ограничения текущей версии

- После перезапуска процесса память пуста, если не задан `FARMPULSE_BOOTSTRAP_CONFIG` и не пришли новые heartbeat.
- Автоматический перевод в `offline` по таймауту не реализован — можно добавить фоновую задачу позже.
