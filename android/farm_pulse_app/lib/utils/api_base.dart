/// Приводит ввод пользователя к базе вида `https://host/api/` (как веб `WEB_API_BASE`).
String normalizeApiBase(String input) {
  var s = input.trim();
  if (s.isEmpty) return '';
  s = s.replaceAll(RegExp(r'/+$'), '');
  if (!s.toLowerCase().endsWith('/api')) {
    s = '$s/api';
  }
  return '$s/';
}
