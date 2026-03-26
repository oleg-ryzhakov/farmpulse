# FarmPulse client (Hive OS sidecar)

Отдельный процесс на риге **рядом** со штатным Hive: `hive-agent` по-прежнему ходит в облако Hive, этот клиент — только на **ваш** `api/worker/api.php`.

## Что делает

- Периодически шлёт **`stats`** (температуры GPU через `nvidia-smi`, heartbeat).
- Один раз при первом запуске проверяет **`hello`** (id и пароль как в панели FarmPulse).
- Выполняет команды из ответа API (**reboot** / **exec**, например `sreboot`), которые вы ставите из веб-панели.

**Не меняет** `HIVE_HOST_URL` и не подменяет настройки штатного Hive.

## Файлы

| Файл | Назначение |
|------|------------|
| `install.sh` | Скачивает скрипты с сервера в `/opt/farmpulse/bin`, ставит systemd |
| `firstrun_farmpulse.sh` | Интерактивно: URL, id рига, пароль → `/etc/farmpulse.env`, таймер |
| `sidecar.sh` | Один цикл опроса (вызывается timer) |
| `systemd/*.service` `*.timer` | Периодический запуск sidecar |
| `uninstall.sh` | Удаление клиента с рига |
| `VERSION` | Версия набора скриптов |

## Установка на риге (SSH)

1. В панели FarmPulse создайте ферму и запомните **ID** и **пароль**.

2. Установите клиент (подставьте свой базовый URL, без хвостового `/`):

   ```bash
   wget -qO- https://YOUR_DOMAIN/client/install.sh | bash -s -- https://YOUR_DOMAIN
   ```

   Либо скачайте `install.sh` и выполните:

   ```bash
   sudo bash install.sh https://YOUR_DOMAIN
   ```

3. Первичная настройка:

   ```bash
   sudo firstrun_farmpulse
   ```

4. Проверка:

   ```bash
   systemctl status farmpulse-sidecar.timer
   journalctl -u farmpulse-sidecar.service -n 30
   ```

## Деплой скриптов на сервер

Каталог `client/` должен отдаваться по HTTPS как статика, иначе `wget` из шага 2 не сработает.

Пример: положить рядом с `web` и `api` на VPS:

```
/var/www/farmpulse/
  web/
  api/
  client/    ← этот каталог
```

В nginx см. `deploy/nginx-site.conf.example` — блок `location ^~ /client/`.

После `git pull` на сервере обновите и `client/`, если менялись скрипты.

## Зависимости на риге

- `bash`, `curl`, `python3` (есть в Hive OS).
- Для температур NVIDIA: `nvidia-smi`.

## Удаление

```bash
sudo bash /opt/farmpulse/bin/uninstall.sh
```

(если уже удалили каталог — скопируйте `uninstall.sh` с сервера или из репозитория и запустите.)
