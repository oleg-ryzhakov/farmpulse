import 'package:dio/dio.dart';

import '../models/farm.dart';
import '../models/farm_detail.dart';
import '../utils/api_base.dart';

/// HTTP-клиент к PHP API веб-панели: `/api/v2/farms/…` (как `farm-app.js`).
/// X-Api-Key для этих эндпоинтов **не используется** (ключ был для отдельного app-api).
class FarmPulseClient {
  FarmPulseClient({
    required String baseUrl,
    Dio? dio,
  })  : _dio = dio ?? _createDio(),
        _apiRoot = normalizeApiBase(baseUrl);

  final Dio _dio;
  final String _apiRoot;

  String get apiRootForDisplay => _apiRoot;

  static Dio _createDio() {
    return Dio(
      BaseOptions(
        connectTimeout: const Duration(seconds: 25),
        receiveTimeout: const Duration(seconds: 45),
        validateStatus: (code) => code != null && code < 600,
        responseType: ResponseType.json,
      ),
    );
  }

  Future<List<Farm>> fetchFarms() async {
    final res = await _dio.get<dynamic>(
      '${_apiRoot}v2/farms/farms.php',
    );
    if (res.statusCode != 200) {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: _httpMessage(res),
      );
    }
    final data = res.data;
    if (data is! Map<String, dynamic>) {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: _notJsonMessage(data),
      );
    }
    if (data['status'] != 'OK') {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: data['message']?.toString() ?? 'Ошибка списка ферм',
      );
    }
    final list = data['farms'];
    if (list is! List) return [];
    return list
        .map((e) => Farm.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<FarmDetail> fetchFarmDetail(String farmId) async {
    final res = await _dio.get<dynamic>(
      '${_apiRoot}v2/farms/workers.php',
      queryParameters: {'farm_id': farmId},
    );
    if (res.statusCode != 200) {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: _httpMessage(res),
      );
    }
    final data = res.data;
    if (data is! Map<String, dynamic>) {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: _notJsonMessage(data),
      );
    }
    if (data['status'] != 'OK') {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: data['message']?.toString() ?? 'Ферма не найдена',
      );
    }
    final f = data['farm'];
    if (f is! Map<String, dynamic>) {
      throw DioException(
        requestOptions: res.requestOptions,
        response: res,
        message: 'Некорректный ответ',
      );
    }
    return FarmDetail.fromJson(f);
  }

  static String _httpMessage(Response<dynamic> res) {
    return 'HTTP ${res.statusCode}: ${_bodySnippet(res.data)}';
  }

  static String _notJsonMessage(dynamic data) {
    return 'Ответ не JSON (проверьте URL и HTTPS): ${_bodySnippet(data)}';
  }

  static String _bodySnippet(dynamic data) {
    if (data is String) {
      final t = data.trim();
      return t.length > 200 ? '${t.substring(0, 200)}…' : t;
    }
    return data.toString();
  }
}

/// Сообщение для SnackBar / текста ошибки.
String formatFarmPulseError(Object e) {
  if (e is DioException) {
    final b = StringBuffer();
    b.write(e.message ?? e.type.name);
    if (e.response != null) {
      final code = e.response!.statusCode;
      if (code != null) b.write(' (HTTP $code)');
    }
    return b.toString();
  }
  return e.toString();
}
