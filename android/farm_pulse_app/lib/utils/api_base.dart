import 'package:flutter/foundation.dart';

/// База API всегда `scheme://host[:port]/api/` — независимо от того, вставили ли
/// только домен или полный URL с `/api/v2/.../farms.php` (иначе получалось `.../api/api/...`).
String normalizeApiBase(String input) {
  final uri = Uri.tryParse(input.trim());
  if (uri == null || !uri.hasScheme || uri.host.isEmpty) return '';
  final normalized = Uri(
    scheme: uri.scheme,
    host: uri.host,
    port: uri.hasPort ? uri.port : null,
    path: '/api/',
  );
  if (kDebugMode) {
    // ignore: avoid_print
    print('[FarmPulse] API base: $normalized');
  }
  return normalized.toString();
}
