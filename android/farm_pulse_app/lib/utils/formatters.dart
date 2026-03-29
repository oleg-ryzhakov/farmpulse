import 'dart:math';

String formatHashrateKhs(double? khs) {
  if (khs == null || khs.isNaN || khs <= 0) return '—';
  final v = khs;
  if (v >= 1000000) return '${(v / 1000000).toStringAsFixed(3)} GH';
  if (v >= 1000) return '${(v / 1000).toStringAsFixed(2)} MH';
  return '${v.round()} kH';
}

String formatUptimeSec(int? sec) {
  if (sec == null || sec < 0) return '—';
  final d = sec ~/ 86400;
  final h = (sec % 86400) ~/ 3600;
  final m = (sec % 3600) ~/ 60;
  if (d > 0) return '${d}d ${h}h';
  if (h > 0) return '${h}h ${m}m';
  return '${m}m';
}
