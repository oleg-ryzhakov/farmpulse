# FarmPulse — Android-приложение

Папка для клиента под Android: **Flutter** (не WebView). Работа с тем же HTTP API, что и веб-панель (`/api/v2/farms/…`).

| | |
|--|--|
| Каталог проекта | `farmpulse/android/farm_pulse_app/` |
| Точка входа | `farm_pulse_app/lib/main.dart` |
| Пакет | `ru.itsgood.farmpulse.farm_pulse_app` |

## Стек

| Компонент | Выбор |
|-----------|--------|
| Фреймворк | **Flutter** |
| Сеть | **dio** → JSON REST PHP (`farms.php`, `workers.php`) |
| Хранение URL | **flutter_secure_storage** |

## Установка и запуск

1. [Flutter SDK](https://docs.flutter.dev/get-started/install/windows) в `PATH`, `flutter doctor` без критичных ошибок.
2. В терминале:

```bat
cd farmpulse\android\farm_pulse_app
flutter pub get
flutter devices
flutter run
```

3. **Подключение:** на первом экране укажите URL хоста (например `https://farmpulse.its-good.ru`). Суффикс `/api` добавляется автоматически. Поле **X-Api-Key** опционально (резерв под отдельный app-api).

4. Список ферм → нажатие открывает **детали** (сводка + GPU, как на `farm.php`).

На эмуляторе вместо `hive-management` используйте `http://10.0.2.2/...` или IP ПК в LAN.

## Сборка APK

```bat
flutter build apk
```

APK: `build/app/outputs/flutter-apk/app-release.apk` (для отладки: `flutter build apk --debug`).

## Связь с сервером

- PHP API: `farmpulse/api/`
- Веб: `farmpulse/web/`

Контракт **владелец ↔ отдельный app-api** (если появится): см. `docs/MOBILE-CLIENT-SERVER.md` — текущее приложение использует **только публичные PHP-эндпоинты веба**.
