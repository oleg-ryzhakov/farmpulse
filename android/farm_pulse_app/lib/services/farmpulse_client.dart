import 'package:dio/dio.dart';

import '../models/farm.dart';
import '../models/farm_detail.dart';
import '../utils/api_base.dart';

/// HTTP-клиент к PHP API веб-панели: `/api/v2/farms/…` (как `farm-app.js`).
class FarmPulseClient {
  FarmPulseClient({
    required String baseUrl,
    Dio? dio,
  })  : _dio = dio ?? Dio(),
        _apiRoot = normalizeApiBase(baseUrl);

  final Dio _dio;
  final String _apiRoot;

  String get apiRootForDisplay => _apiRoot;

  Future<List<Farm>> fetchFarms() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '${_apiRoot}v2/farms/farms.php',
      options: Options(
        validateStatus: (s) => s != null && s < 500,
      ),
    );
    final data = res.data;
    if (data == null || data['status'] != 'OK') {
      throw DioException(
        requestOptions: res.requestOptions,
        message: data?['message']?.toString() ?? 'Ошибка списка ферм',
        response: res,
      );
    }
    final list = data['farms'];
    if (list is! List) return [];
    return list
        .map((e) => Farm.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<FarmDetail> fetchFarmDetail(String farmId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '${_apiRoot}v2/farms/workers.php',
      queryParameters: {'farm_id': farmId},
      options: Options(
        validateStatus: (s) => s != null && s < 500,
      ),
    );
    final data = res.data;
    if (data == null || data['status'] != 'OK') {
      throw DioException(
        requestOptions: res.requestOptions,
        message: data?['message']?.toString() ?? 'Ферма не найдена',
        response: res,
      );
    }
    final f = data['farm'];
    if (f is! Map<String, dynamic>) {
      throw DioException(
        requestOptions: res.requestOptions,
        message: 'Некорректный ответ',
        response: res,
      );
    }
    return FarmDetail.fromJson(f);
  }
}
