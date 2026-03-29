/// Базовый URL API приложения (без завершающего `/`).
/// Локально (OSPanel): http://hive-management/app-api. На проде — свой https://…/app-api.
class AppConfig {
  AppConfig._();

  static const String defaultBaseUrl = 'http://hive-management/app-api';
}
