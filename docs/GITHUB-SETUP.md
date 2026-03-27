# Подключение к GitHub (из Cursor)

Репозиторий **только папки `farmpulse`** — родительский `hive-management` в этот Git не входит.

## Что попадает в Git

- `web/` (включая `web/client/`), `api/`, `deploy/`, `docs/`, корневой `README.md`
- **Не попадают** (см. `.gitignore`): `api/v2/farms/config.json`, логи, кэши, `wallets.json` на сервере

## Шаги на github.com

1. **New repository**  
   - Имя, например: `farmpulse`  
   - **Без** галочки «Add README» (уже есть локально)  
   - Создать.

2. Скопировать URL репозитория (HTTPS или SSH), например:  
   `https://github.com/ВАШ_ЛОГИН/farmpulse.git`

## В Cursor или терминале (в каталоге `farmpulse`)

```bash
cd путь/к/farmpulse
git remote add origin https://github.com/ВАШ_ЛОГИН/farmpulse.git
git push -u origin main
```

Если GitHub просит логин: для HTTPS удобен **Personal Access Token** вместо пароля (Settings → Developer settings → Tokens).

После первого `push` проект в GitHub будет совпадать с локальной `farmpulse`.

## На VPS (по желанию)

```bash
cd /var/www
sudo git clone https://github.com/ВАШ_ЛОГИН/farmpulse.git
# настроить nginx на /var/www/farmpulse/web и api как сейчас
# создать api/v2/farms/config.json с сервера на основе .example
```

Обновление на сервере: `cd /var/www/farmpulse && git pull`.
