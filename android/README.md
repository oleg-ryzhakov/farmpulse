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

## Как инициализировать проект (когда будете готовы)

Рекомендуется создать приложение **в подкаталоге**, чтобы не смешивать с этим `README`:

```bash
cd android
flutter create --org ru.itsgood.farmpulse farm_pulse_app
cd farm_pulse_app
flutter pub get
flutter run
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

Эта папка `android/` — только заготовка под клиент; код Flutter появится после `flutter create` (см. выше).
