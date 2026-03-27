import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class CredentialStore {
  static const _kBaseUrl = 'farmpulse_base_url';
  static const _kApiKey = 'farmpulse_api_key';

  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  Future<String?> readBaseUrl() => _storage.read(key: _kBaseUrl);

  Future<String?> readApiKey() => _storage.read(key: _kApiKey);

  Future<void> write({required String baseUrl, required String apiKey}) async {
    await _storage.write(key: _kBaseUrl, value: baseUrl);
    await _storage.write(key: _kApiKey, value: apiKey);
  }

  Future<void> clearApiKey() => _storage.delete(key: _kApiKey);
}
