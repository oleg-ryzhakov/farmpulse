# FarmPulse — Android-приложение

Папка для **нативного** клиента под Android (не WebView): работа с API FarmPulse, быстрый UI, **системные push-уведомления**.

## Стек (по решению из обсуждения)

| Компонент | Выбор | Зачем |
|-----------|--------|--------|
| Фреймворк | **Flutter** | Один код под Android, быстрый UI и hot reload, удобная работа в **Cursor** (как VS Code) |
| Язык | **Dart** | Язык Flutter; пакеты через `pub.dev` |
| IDE | **Cursor** + терминал | Основной редактор; **Android Studio** — по необходимости (SDK, эмулятор, редкая отладка) |
| Сеть | **HTTP-клиент** (`dio` или `http`) | JSON-RPC / REST к вашему бэкенду (тот же хост, что веб-панель, пути вида `/api/...`) |
| Push | **Firebase Cloud Messaging (FCM)** | Сервер шлёт события в FCM → приложение показывает **системные** уведомления (каналы Android 8+) |

**Только Android** — сборка **APK/AAB**, без iOS.

## Что нужно установить на машине разработчика

1. **Flutter SDK** (stable): [установка под Windows](https://docs.flutter.dev/get-started/install/windows), каталог `flutter\bin` в `PATH`.
2. **Android SDK** (через Android Studio → SDK Manager или `sdkmanager`): platform-tools, build-tools, нужный API level.
3. Проверка: `flutter doctor` — исправить всё, что помечено красным.
4. Согласие лицензий: `flutter doctor --android-licenses`.

Телефон по USB с отладкой или эмулятор — для `flutter run`.

## Где лежит код Flutter

Каркас уже создан в подкаталоге **`farm_pulse_app/`** (рядом с этим `README`).

| | |
|--|--|
| Каталог проекта | `farmpulse/android/farm_pulse_app/` |
| Точка входа Dart | `farm_pulse_app/lib/main.dart` |
| Android (Kotlin, манифест) | `farm_pulse_app/android/` |
| Идентификатор приложения | `--org ru.itsgood.farmpulse` → пакет `ru.itsgood.farmpulse.farm_pulse_app` |
| Платформы в репозитории | только **Android** (`flutter create ... --platforms=android`) |

### Первый запуск на устройстве

1. Подключите телефон с **отладкой по USB** или запустите **эмулятор** в Android Studio (Device Manager).
2. В терминале:

```bat
cd C:\OSPanel\domains\hive-management\farmpulse\android\farm_pulse_app
flutter devices
flutter pub get
flutter run
```

`flutter run` по умолчанию возьмёт единственное Android-устройство; если их несколько: `flutter run -d <id>` (id из `flutter devices`).

### Пересоздать с нуля (если понадобится)

Из каталога `farmpulse/android/`:

```bash
flutter create --org ru.itsgood.farmpulse --platforms=android --project-name farm_pulse_app farm_pulse_app
```

Имя пакета `ru.itsgood.farmpulse` — пример; замените на свой reverse-DNS при необходимости.

## План работ (черновик дорожной карты)

1. **Каркас**: `flutter create`, тема, навигация (например `go_router` или встроенный `Navigator`).
2. **Конфиг**: базовый URL API (prod / dev), хранение токена или логина/пароля (secure storage).
3. **API-слой**: клиент к существующим эндпоинтам FarmPulse (как в веб-панели и sidecar), модели JSON, обработка ошибок.
4. **Экраны**: вход / список ферм / детали (по фактическому API).
5. **FCM**: проект в Firebase Console, `google-services.json` в `android/app`, пакеты `firebase_messaging`, регистрация токена на сервере (эндпоинт на бэкенде — отдельная задача).
6. **Сборка релиза**: `flutter build apk` или `appbundle` для Google Play.

## Связь с репозиторием farmpulse

- Сервер и API: каталоги `../web/`, `../api/`.
- Документ по подключению ригов (контекст продукта): `../docs/CONNECT-RIG.md`.

Корень **hive-management** в git игнорирует каталог `farmpulse/` (отдельный репозиторий). Версионирование клиента — в репозитории **farmpulse** на GitHub или локальные бэкапы.
