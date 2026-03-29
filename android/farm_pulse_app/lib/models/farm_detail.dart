class FarmDetail {
  const FarmDetail({
    required this.id,
    this.name,
    this.status,
    this.lastSeenAt,
    this.gpuCount,
    this.gpuTemps,
    this.totalKhs,
    this.totalPowerW,
    this.lastStats,
    this.rigInfo,
    this.summaryMiner,
    this.summaryAlgo,
    this.summaryUptimeSec,
    this.summaryNetIps,
    this.heatWarning,
  });

  final String id;
  final String? name;
  final String? status;
  final String? lastSeenAt;
  final int? gpuCount;
  final List<double>? gpuTemps;
  final double? totalKhs;
  final double? totalPowerW;
  final Map<String, dynamic>? lastStats;
  final Map<String, dynamic>? rigInfo;
  final String? summaryMiner;
  final String? summaryAlgo;
  final int? summaryUptimeSec;
  final List<String>? summaryNetIps;
  final bool? heatWarning;

  factory FarmDetail.fromJson(Map<String, dynamic> json) {
    List<double>? temps;
    final gt = json['gpu_temps'];
    if (gt is List) {
      temps = gt.map((e) => (e as num).toDouble()).toList();
    }
    List<String>? ips;
    final ni = json['summary_net_ips'];
    if (ni is List) {
      ips = ni.map((e) => '$e').toList();
    }
    Map<String, dynamic>? last;
    final ls = json['last_stats'];
    if (ls is Map<String, dynamic>) last = ls;

    Map<String, dynamic>? ri;
    final r = json['rig_info'];
    if (r is Map<String, dynamic>) ri = r;

    return FarmDetail(
      id: '${json['id'] ?? ''}',
      name: json['name'] as String?,
      status: json['status'] as String?,
      lastSeenAt: json['last_seen_at'] as String?,
      gpuCount: (json['gpu_count'] as num?)?.toInt(),
      gpuTemps: temps,
      totalKhs: (json['total_khs'] as num?)?.toDouble(),
      totalPowerW: (json['total_power_w'] as num?)?.toDouble(),
      lastStats: last,
      rigInfo: ri,
      summaryMiner: json['summary_miner'] as String?,
      summaryAlgo: json['summary_algo'] as String?,
      summaryUptimeSec: (json['summary_uptime_sec'] as num?)?.toInt(),
      summaryNetIps: ips,
      heatWarning: json['heat_warning'] as bool?,
    );
  }
}
