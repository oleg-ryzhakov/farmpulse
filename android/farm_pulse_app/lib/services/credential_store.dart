import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class CredentialStore {
  static const _kBaseUrl = 'farmpulse_base_url';
  static const _kApiKey = 'farmpulse_api_key';

  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  Future<String?> readBaseUrl() => _storage.read(key: _kBaseUrl);

  Future<String?> readApiKey() => _storage.read(key: _kApiKey);

  Future<void> write({required String baseUrl, String? apiKey}) async {
    await _storage.write(key: _kBaseUrl, value: baseUrl);
    if (apiKey == null || apiKey.isEmpty) {
      await _storage.delete(key: _kApiKey);
    } else {
      await _storage.write(key: _kApiKey, value: apiKey);
    }
  }

  Future<void> clearApiKey() => _storage.delete(key: _kApiKey);

  Future<void> clearAll() async {
    await _storage.delete(key: _kBaseUrl);
    await _storage.delete(key: _kApiKey);
  }
}
