# Подключение нового рига к FarmPulse

Краткий чеклист: панель → установка клиента на Hive OS → первичная настройка → проверка. Подробности по скриптам и API — в [`web/client/README.md`](../web/client/README.md).

## Условия

- На риге **Hive OS**, доступ по **SSH** (root или sudo).
- В сети рига есть **исходящий HTTPS** до сервера FarmPulse.
- На риге есть `bash`, `curl`, `python3` (в Hive OS обычно уже есть). Для отчёта по GPU — `nvidia-smi`.

## 1. Ферма в панели

1. Откройте веб-панель FarmPulse.
2. Создайте **новую ферму** (или используйте существующую).
3. Сохраните **ID фермы** и **пароль** — они понадобятся на риге (те же данные, что для входа в панель для этого рига/фермы, в зависимости от того, как у вас заведены учётные записи).

## 2. Установка клиента (sidecar)

На риге под root:

```bash
wget -qO- http://hive-management/client/install.sh | bash -s -- http://hive-management
```

**Базовый URL** — тот же хост, с которого открывается панель, **без завершающего `/`**. Локально в OSPanel это обычно `http://hive-management` (имя каталога в `domains/`). На проде или для рига в интернете подставьте **публичный HTTPS**-URL вида `https://farm.example.com` (или IP: `http://203.0.113.1`).

## 3. Первичная настройка

```bash
sudo firstrun_farmpulse
```

Интерактивно укажите:

- **URL** (по умолчанию для локальной разработки: `http://hive-management`).
- **ID фермы** — как в панели; на Hive часто подхватывается из `/hive-config/rig.conf` (`RIG_ID`).
- **Пароль** фермы.
- **Интервал опроса** в секундах (не меньше 10).

Скрипт запишет `/etc/farmpulse.env`, проверит **`hello`** к API и включит **`farmpulse-sidecar.timer`**.

## 4. Проверка

```bash
systemctl status farmpulse-sidecar.timer
journalctl -t farmpulse-sidecar -n 40 --no-pager
```

Должны быть периодические тики и ответы `stats ok` без ошибок авторизации.

Отладка без выполнения команд с панели (reboot/exec):

```bash
sudo farmpulse-sidecar trace
```

## 5. После обновления сервера или клиента

После `git pull` на VPS клиентские скрипты лежат в `web/client/` — их отдаёт nginx как статику. На риге при необходимости обновите бинарники:

```bash
sudo wget -O /opt/farmpulse/bin/sidecar.sh 'http://hive-management/client/sidecar.sh'
sudo chmod +x /opt/farmpulse/bin/sidecar.sh
sudo wget -O /etc/systemd/system/farmpulse-sidecar.service 'http://hive-management/client/systemd/farmpulse-sidecar.service'
sudo systemctl daemon-reload
sudo systemctl restart farmpulse-sidecar.timer
```

Если панель на другом хосте — замените `http://hive-management` на свой базовый URL в командах `wget`.

Или полностью переустановите клиент той же командой `wget … install.sh | bash …`.

## Важно

- Клиент **не меняет** облако Hive (`hive-agent` и `HIVE_HOST_URL` остаются как в Hive OS).
- Удаление: `sudo bash /opt/farmpulse/bin/uninstall.sh` (см. README клиента).
