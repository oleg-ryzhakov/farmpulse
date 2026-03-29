class Farm {
  const Farm({
    required this.id,
    this.name,
    required this.status,
    this.lastSeenAt,
    required this.gpuTemps,
    required this.gpuCount,
    this.totalKhs,
    this.summaryMiner,
    this.summaryAlgo,
    this.heatWarning,
  });

  final String id;
  final String? name;
  final String status;
  final String? lastSeenAt;
  final List<double> gpuTemps;
  final int gpuCount;
  final double? totalKhs;
  final String? summaryMiner;
  final String? summaryAlgo;
  final bool? heatWarning;

  factory Farm.fromJson(Map<String, dynamic> json) {
    final tempsRaw = json['gpu_temps'];
    final temps = <double>[];
    if (tempsRaw is List) {
      for (final v in tempsRaw) {
        if (v is num) temps.add(v.toDouble());
      }
    }
    return Farm(
      id: '${json['id'] ?? ''}',
      name: json['name'] as String?,
      status: '${json['status'] ?? 'unknown'}',
      lastSeenAt: json['last_seen_at'] as String?,
      gpuTemps: temps,
      gpuCount: (json['gpu_count'] is num) ? (json['gpu_count'] as num).toInt() : 0,
      totalKhs: (json['total_khs'] as num?)?.toDouble(),
      summaryMiner: json['summary_miner'] as String?,
      summaryAlgo: json['summary_algo'] as String?,
      heatWarning: json['heat_warning'] as bool?,
    );
  }
}
