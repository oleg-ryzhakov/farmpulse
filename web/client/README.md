# FarmPulse client (Hive OS sidecar)

Скрипты лежат в **`web/client/`** и при деплое попадают в **`…/web/client/`** на сервере. Nginx с `root …/web` отдаёт `/client/install.sh` как **обычный статический файл** — отдельный `alias` для `/client/` не нужен.

Отдельный процесс на риге **рядом** со штатным Hive: `hive-agent` по-прежнему ходит в облако Hive, этот клиент — только на **ваш** `api/worker/api.php`.

## Что делает

- Периодически шлёт **`stats`** (температуры GPU через `nvidia-smi`, heartbeat).
- Один раз при первом запуске проверяет **`hello`** (id и пароль как в панели FarmPulse).
- Выполняет команды из ответа API (**reboot** / **exec**, например `sreboot`), которые вы ставите из веб-панели.

**Не меняет** `HIVE_HOST_URL` и не подменяет настройки штатного Hive.

## Установка на риге (SSH)

1. В панели [FarmPulse](https://farmpulse.its-good.ru/) создайте ферму и запомните **ID** и **пароль**.

2. Установите клиент (базовый URL **без** хвостового `/`):

   ```bash
   wget -qO- https://farmpulse.its-good.ru/client/install.sh | bash -s -- https://farmpulse.its-good.ru
   ```

   Либо:

   ```bash
   sudo bash install.sh https://farmpulse.its-good.ru
   ```

3. Первичная настройка (по умолчанию URL уже [https://farmpulse.its-good.ru](https://farmpulse.its-good.ru)):

   ```bash
   sudo firstrun_farmpulse
   ```

4. Проверка:

   ```bash
   systemctl status farmpulse-sidecar.timer
   journalctl -u farmpulse-sidecar.service -n 30
   ```

## Наглядно: запрос и ответ API

**Один запрос** `stats` (как у таймера). В режиме `trace` ответ **только показывается** — reboot/exec **намеренно не выполняются**, чтобы при отладке не уронить риг. Рабочий таймер `farmpulse-sidecar` команды **выполняет**; для перезагрузки в первую очередь вызывается **`systemctl reboot`** (запрос к PID 1), на Hive при наличии — **`/hive/sbin/sreboot`**.

```bash
sudo farmpulse-sidecar trace
# или: sudo /opt/farmpulse/bin/sidecar.sh trace
```

Если команды не находятся — переустановите клиент (`wget …/client/install.sh | bash …`) или вручную: `sudo ln -sf /opt/farmpulse/bin/farmpulse-watch.sh /usr/bin/farmpulse-watch`.

Показываются URL, тело запроса (`params.temp`), HTTP-код, JSON ответа и кратко `result.command` / `exec`.

**Цикл** (по умолчанию каждые 5 с; интервал: `farmpulse-watch 10`):

```bash
sudo farmpulse-watch
```

**Краткий лог в journal** при работе таймера — добавьте в unit переменную `FARMPULSE_DEBUG=1` (`systemctl edit farmpulse-sidecar.service`, секция `[Service]`), затем:

```bash
journalctl -u farmpulse-sidecar.service -f
```

## Деплой на сервер

Достаточно выкатывать каталог **`web/`** (в нём уже есть `client/`). Отдельно копировать `client/` рядом с `web` **не нужно**.

После `git pull` на VPS: `web/client/` обновится вместе с сайтом.

## Зависимости на риге

- `bash`, `curl`, `python3` (есть в Hive OS).
- Для температур NVIDIA: `nvidia-smi`.

## Удаление

```bash
sudo bash /opt/farmpulse/bin/uninstall.sh
```
