# Мобильное приложение FarmPulse (Flutter)

Клиент находится в **`farmpulse/android/farm_pulse_app/`** — тот же REST, что и веб-панель:

- `GET /api/v2/farms/farms.php` — список ферм
- `GET /api/v2/farms/workers.php?farm_id=…` — детали и `last_stats` (в т.ч. `gpu_cards`, `miner_stats`)

Отдельный бэкенд под приложение не нужен: укажите **базовый URL сайта** (например `https://farmpulse.its-good.ru`), клиент сам добавит `/api`.

Подробности по установке Flutter и `flutter run`: **[`../android/README.md`](../android/README.md)**.

Нативный проект под Android Studio (Kotlin) из корня `farmpulse` **удалён** — единственный клиент в репозитории: **Flutter**.
