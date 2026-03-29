class GpuCard {
  const GpuCard({
    this.index,
    this.name,
    this.busId,
    this.vbios,
    this.memTotal,
    this.coreMhz,
    this.memMhz,
    this.temp,
    this.fan,
    this.w,
    this.brand,
    this.memType,
    this.plimMin,
    this.plimDef,
    this.plimMax,
  });

  final int? index;
  final String? name;
  final String? busId;
  final String? vbios;
  final String? memTotal;
  final int? coreMhz;
  final int? memMhz;
  final int? temp;
  final int? fan;
  final int? w;
  final String? brand;
  final String? memType;
  final String? plimMin;
  final String? plimDef;
  final String? plimMax;

  factory GpuCard.fromJson(Map<String, dynamic> json) {
    int? toInt(dynamic v) {
      if (v is int) return v;
      if (v is num) return v.toInt();
      return null;
    }

    return GpuCard(
      index: toInt(json['index']),
      name: json['name'] as String?,
      busId: json['bus_id'] as String?,
      vbios: json['vbios'] as String?,
      memTotal: json['mem_total'] as String?,
      coreMhz: toInt(json['core_mhz']),
      memMhz: toInt(json['mem_mhz']),
      temp: toInt(json['temp']),
      fan: toInt(json['fan']),
      w: toInt(json['w']),
      brand: json['brand'] as String?,
      memType: json['mem_type'] as String?,
      plimMin: json['plim_min'] as String?,
      plimDef: json['plim_def'] as String?,
      plimMax: json['plim_max'] as String?,
    );
  }
}
