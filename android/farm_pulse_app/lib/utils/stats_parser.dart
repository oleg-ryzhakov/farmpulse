import 'dart:convert';

import '../models/gpu_card.dart';

/// Нормализация массивов Hive: [0, …] или без заглушки.
List<num> normalizeGpuArray(dynamic raw) {
  if (raw is! List) return [];
  final values = <num>[];
  for (final v in raw) {
    if (v is num) values.add(v);
  }
  if (values.length > 1 && values[0] == 0) {
    return values.sublist(1);
  }
  return values;
}

List<GpuCard> gpuCardsFromLastStats(Map<String, dynamic>? lastStats) {
  if (lastStats == null) return [];
  final raw = lastStats['gpu_cards'];
  if (raw is! List) return [];
  return raw
      .map((e) => e is Map<String, dynamic> ? GpuCard.fromJson(e) : null)
      .whereType<GpuCard>()
      .toList();
}

List<num> doubleArrayFromStats(Map<String, dynamic>? lastStats, String key) {
  if (lastStats == null) return [];
  return normalizeGpuArray(lastStats[key]);
}

/// kH/s по GPU из miner_stats*.hs и hs_units (как в вебе).
List<double> minerStatsHsKhs(Map<String, dynamic>? lastStats) {
  if (lastStats == null) return [];
  for (final mk in ['miner_stats', 'miner_stats2', 'miner_stats3']) {
    dynamic ms = lastStats[mk];
    if (ms == null) continue;
    if (ms is String) {
      try {
        ms = jsonDecode(ms) as Map<String, dynamic>?;
      } catch (_) {
        continue;
      }
    }
    if (ms is! Map<String, dynamic>) continue;
    final hs = ms['hs'];
    if (hs is! List) continue;
    final units = (ms['hs_units'] as String?)?.toLowerCase() ?? 'khs';
    final out = <double>[];
    for (final v in hs) {
      if (v is! num) continue;
      final raw = v.toDouble();
      final double khs;
      if (units == 'h' || units == 'hs') {
        khs = raw / 1000;
      } else if (units == 'mhs' || units == 'mh') {
        khs = raw * 1000;
      } else if (units == 'ghs' || units == 'gh') {
        khs = raw * 1000000;
      } else {
        khs = raw;
      }
      out.add(khs);
    }
    return out;
  }
  return [];
}

/// Первый блок miner_stats* (как в minerStatsHsKhs).
Map<String, dynamic>? minerStatsFirstMap(Map<String, dynamic>? lastStats) {
  if (lastStats == null) return null;
  for (final mk in ['miner_stats', 'miner_stats2', 'miner_stats3']) {
    dynamic ms = lastStats[mk];
    if (ms == null) continue;
    if (ms is String) {
      try {
        ms = jsonDecode(ms) as Map<String, dynamic>?;
      } catch (_) {
        continue;
      }
    }
    if (ms is Map<String, dynamic>) return ms;
  }
  return null;
}

List<String> cpuAvgStrings(Map<String, dynamic>? last) {
  if (last == null) return [];
  final v = last['cpuavg'];
  if (v is List) {
    return v.map((e) => e.toString()).toList();
  }
  return [];
}

int? cputempFirst(Map<String, dynamic>? last) {
  if (last == null) return null;
  final v = last['cputemp'];
  if (v is List && v.isNotEmpty) {
    final x = v.first;
    if (x is num) return x.round();
  }
  return null;
}

String? diskFreeStr(Map<String, dynamic>? last) {
  if (last == null) return null;
  final v = last['df'];
  if (v is String) return v;
  return null;
}

String? ramSummary(Map<String, dynamic>? last) {
  if (last == null) return null;
  final v = last['mem'];
  if (v is List && v.length >= 2) {
    return 'всего ${v[0]} · своб. ${v[1]} MiB';
  }
  return null;
}
