import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import '../models/farm.dart';

class FarmPulseClient {
  FarmPulseClient({
    required this.baseUrl,
    required this.apiKey,
    Dio? dio,
  }) : _dio = dio ?? Dio();

  final String baseUrl;
  final String apiKey;
  final Dio _dio;

  String get _root => baseUrl.replaceAll(RegExp(r'/$'), '');

  Map<String, String> get _headers => {'X-Api-Key': apiKey};

  Future<List<Farm>> fetchFarms() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_root/farms',
      options: Options(headers: _headers),
    );
    final data = res.data;
    if (data == null) return [];
    final list = data['farms'];
    if (list is! List) return [];
    return list
        .map((e) => Farm.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Подключение к `wss://…/app-api/ws?token=…`
  WebSocketChannel connectWebSocket() {
    final uri = _wsUri();
    return WebSocketChannel.connect(uri);
  }

  Uri _wsUri() {
    final u = Uri.parse(_root);
    final scheme = u.scheme == 'https' ? 'wss' : 'ws';
    final path = u.path.endsWith('/') ? '${u.path}ws' : '${u.path}/ws';
    return Uri(
      scheme: scheme,
      host: u.host,
      port: u.hasPort ? u.port : null,
      path: path,
      queryParameters: {'token': apiKey},
    );
  }

  static List<Farm>? parseWsSnapshot(String message) {
    try {
      final map = jsonDecode(message) as Map<String, dynamic>;
      if (map['type'] != 'farms_snapshot') return null;
      final data = map['data'];
      if (data is! Map<String, dynamic>) return null;
      final list = data['farms'];
      if (list is! List) return null;
      return list
          .map((e) => Farm.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return null;
    }
  }
}
