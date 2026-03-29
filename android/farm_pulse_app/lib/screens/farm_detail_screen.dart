import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../models/farm_detail.dart';
import '../models/gpu_card.dart';
import '../services/farmpulse_client.dart' show FarmPulseClient, formatFarmPulseError;
import '../theme/app_theme.dart';
import '../utils/formatters.dart';
import '../utils/stats_parser.dart';
import '../widgets/hashrate_sparkline.dart';
import '../widgets/hive_bottom_nav.dart';

class FarmDetailScreen extends StatefulWidget {
  const FarmDetailScreen({
    super.key,
    required this.baseUrl,
    required this.farmId,
  });

  final String baseUrl;
  final String farmId;

  @override
  State<FarmDetailScreen> createState() => _FarmDetailScreenState();
}

class _FarmDetailScreenState extends State<FarmDetailScreen> {
  late final FarmPulseClient _client = FarmPulseClient(baseUrl: widget.baseUrl);
  FarmDetail? _farm;
  String? _error;
  bool _loading = true;
  int _navIndex = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final d = await _client.fetchFarmDetail(widget.farmId);
      if (!mounted) return;
      setState(() {
        _farm = d;
        _loading = false;
      });
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = formatFarmPulseError(e);
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: AppColors.textSecondary),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: Text(
          _farm?.name?.isNotEmpty == true ? _farm!.name! : 'Воркер #${widget.farmId}',
          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _load,
          ),
          IconButton(
            icon: const Icon(Icons.grid_view),
            onPressed: () {},
          ),
        ],
      ),
      body: IndexedStack(
        index: _navIndex,
        children: [
          _buildOverviewBody(),
          const PlaceholderTab(title: 'Полётные листы'),
          const PlaceholderTab(title: 'Статистика'),
          const PlaceholderTab(title: 'Тюнинг'),
          PlaceholderTab(title: 'Больше\n${widget.baseUrl}'),
        ],
      ),
      bottomNavigationBar: WorkerDetailBottomNav(
        currentIndex: _navIndex,
        onTap: (i) => setState(() => _navIndex = i),
      ),
    );
  }

  Widget _buildOverviewBody() {
    if (_loading && _farm == null) {
      return const Center(child: CircularProgressIndicator(color: AppColors.accent));
    }
    if (_error != null && _farm == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.accent,
                  foregroundColor: Colors.black,
                ),
                onPressed: _load,
                child: const Text('Повторить'),
              ),
            ],
          ),
        ),
      );
    }
    final f = _farm!;
    final last = f.lastStats;
    final cards = gpuCardsFromLastStats(last);
    final hs = minerStatsHsKhs(last);
    final ms = minerStatsFirstMap(last);
    final la = cpuAvgStrings(last);
    final rig = f.rigInfo;
    final fallbackRows = _buildFallbackGpuRows(last, hs);

    return RefreshIndicator(
      color: AppColors.accent,
      onRefresh: _load,
      child: CustomScrollView(
        slivers: [
          if (_error != null)
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Text(
                  'Предупреждение: $_error',
                  style: const TextStyle(color: AppColors.redHive, fontSize: 12),
                ),
              ),
            ),
          SliverToBoxAdapter(child: _WorkerTitleRow(farm: f, la: la)),
          SliverToBoxAdapter(child: _MinerFlightBlock(farm: f, ms: ms)),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: _GpuStripRow(cards: cards, last: last),
            ),
          ),
          SliverToBoxAdapter(child: _SystemInfoBlock(farm: f, rig: rig)),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: HashrateSparkline(
                    khsTotal: f.totalKhs,
                    label: (f.summaryAlgo ?? 'HASH').toUpperCase(),
                  ),
                ),
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: _MetricsGrid(farm: f, last: last),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: Text(
                'Оборудование',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(color: AppColors.textSecondary),
              ),
            ),
          ),
          if (cards.isNotEmpty)
            SliverPadding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              sliver: SliverList(
                delegate: SliverChildBuilderDelegate(
                  (context, i) {
                    final c = cards[i];
                    final hr = i < hs.length ? formatHashrateKhs(hs[i]) : '—';
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _GpuCardTile(
                        card: c,
                        hashStr: hr,
                        index: c.index ?? i,
                      ),
                    );
                  },
                  childCount: cards.length,
                ),
              ),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              sliver: SliverList(
                delegate: SliverChildBuilderDelegate(
                  (context, i) => fallbackRows[i],
                  childCount: fallbackRows.length,
                ),
              ),
            ),
          const SliverToBoxAdapter(child: SizedBox(height: 32)),
        ],
      ),
    );
  }

  List<Widget> _buildFallbackGpuRows(Map<String, dynamic>? last, List<double> hs) {
    final temps = doubleArrayFromStats(last, 'temp');
    final fans = doubleArrayFromStats(last, 'fan');
    final powers = doubleArrayFromStats(last, 'power');
    final lens = [temps.length, fans.length, powers.length];
    final n = lens.isEmpty ? 0 : lens.reduce((a, b) => a > b ? a : b);
    if (n == 0) {
      return [
        const Padding(
          padding: EdgeInsets.all(16),
          child: Text('Нет данных GPU в last_stats', style: TextStyle(color: AppColors.textSecondary)),
        ),
      ];
    }
    return List.generate(n, (i) {
      final t = i < temps.length ? temps[i].toDouble() : null;
      final fan = i < fans.length ? fans[i].toDouble() : null;
      final pw = i < powers.length ? powers[i].toDouble() : null;
      final hot = t != null && t >= 80;
      final hr = i < hs.length ? formatHashrateKhs(hs[i]) : '—';
      return Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'GPU $i',
                  style: const TextStyle(color: AppColors.blueInfo, fontWeight: FontWeight.w600),
                ),
                Text(
                  'GPU $i',
                  style: const TextStyle(color: AppColors.greenHive, fontWeight: FontWeight.w600),
                ),
                const Divider(height: 16),
                Wrap(
                  spacing: 12,
                  runSpacing: 8,
                  children: [
                    _statChip('Hash', hr),
                    _statChip('TEMP', t != null && t > 0 ? '${t.round()}°' : '—', hot: hot),
                    _statChip('Fan', fan != null ? '${fan.round()}%' : '—'),
                    _statChip('W', pw != null && pw > 0 ? '${pw.round()} W' : '—'),
                  ],
                ),
              ],
            ),
          ),
        ),
      );
    });
  }
}

class _WorkerTitleRow extends StatelessWidget {
  const _WorkerTitleRow({required this.farm, required this.la});

  final FarmDetail farm;
  final List<String> la;

  @override
  Widget build(BuildContext context) {
    final name = farm.name ?? '—';
    final laStr = la.length >= 3 ? '${la[0]} ${la[1]} ${la[2]}' : (la.isEmpty ? '—' : la.join(' '));
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconButton(
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
            icon: const Icon(Icons.star_border, color: AppColors.textSecondary),
            onPressed: () {},
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w600,
                    color: Colors.white,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        laStr,
                        style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
                      ),
                    ),
                    if (farm.totalPowerW != null)
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.bolt, size: 18, color: AppColors.blueInfo),
                          const SizedBox(width: 4),
                          Text(
                            '${farm.totalPowerW!.toStringAsFixed(1)} W',
                            style: const TextStyle(fontSize: 13, color: Colors.white),
                          ),
                        ],
                      ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MinerFlightBlock extends StatelessWidget {
  const _MinerFlightBlock({
    required this.farm,
    required this.ms,
  });

  final FarmDetail farm;
  final Map<String, dynamic>? ms;

  @override
  Widget build(BuildContext context) {
    final minerLine = farm.summaryMiner ?? _minerNameFromStats(ms) ?? '—';
    final eff = _minerEfficiency(ms);
    final ar = _minerSharesLine(ms);
    final hashLine = formatHashrateKhs(farm.totalKhs);
    final poolLine = farm.summaryAlgo ?? '—';

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Полётный лист',
                style: TextStyle(fontSize: 11, color: AppColors.textSecondary, letterSpacing: 0.5),
              ),
              const SizedBox(height: 6),
              Text(minerLine, style: const TextStyle(fontSize: 14, color: Colors.white)),
              const SizedBox(height: 8),
              if (eff != null)
                Text(
                  eff,
                  style: const TextStyle(fontSize: 13, color: AppColors.textSecondary),
                ),
              if (ar != null) ...[
                const SizedBox(height: 4),
                Text(ar, style: const TextStyle(fontSize: 13, color: AppColors.textSecondary)),
              ],
              const SizedBox(height: 8),
              Text(
                '$poolLine · $hashLine',
                style: const TextStyle(fontSize: 14, color: AppColors.purpleHash, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String? _minerNameFromStats(Map<String, dynamic>? ms) {
    if (ms == null) return null;
    for (final k in ['miner', 'name', 'miner_name', 'version']) {
      final v = ms[k];
      if (v is String && v.trim().isNotEmpty) return v.trim();
    }
    return null;
  }

  static String? _minerEfficiency(Map<String, dynamic>? ms) {
    if (ms == null) return null;
    final v = ms['efficiency'] ?? ms['eff'];
    if (v is num) return '${v.toStringAsFixed(2)}%';
    if (v is String && v.isNotEmpty) return v;
    return null;
  }

  static String? _minerSharesLine(Map<String, dynamic>? ms) {
    if (ms == null) return null;
    dynamic ar = ms['ar'];
    if (ar is List && ar.length >= 2) {
      final a = ar[0];
      final r = ar[1];
      return 'A $a R $r';
    }
    final a = ms['accepted'];
    final r = ms['rejected'];
    if (a != null || r != null) return 'A ${a ?? "—"} R ${r ?? "—"}';
    return null;
  }
}

class _GpuStripRow extends StatelessWidget {
  const _GpuStripRow({required this.cards, required this.last});

  final List<GpuCard> cards;
  final Map<String, dynamic>? last;

  @override
  Widget build(BuildContext context) {
    if (cards.isNotEmpty) {
      return SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: cards.asMap().entries.map((e) {
            final i = e.key;
            final c = e.value;
            return Padding(
              padding: EdgeInsets.only(right: i < cards.length - 1 ? 8 : 0),
              child: _GpuMiniCell(
                label: 'GPU ${c.index ?? i}',
                temp: c.temp?.toDouble(),
                fan: c.fan?.toDouble(),
                volt: c.w?.toDouble(),
              ),
            );
          }).toList(),
        ),
      );
    }
    final temps = doubleArrayFromStats(last, 'temp');
    final fans = doubleArrayFromStats(last, 'fan');
    final powers = doubleArrayFromStats(last, 'power');
    final n = [temps.length, fans.length, powers.length].fold<int>(0, (a, b) => a > b ? a : b);
    if (n == 0) return const SizedBox.shrink();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: List.generate(n, (i) {
          final t = i < temps.length ? temps[i].toDouble() : null;
          final fan = i < fans.length ? fans[i].toDouble() : null;
          final pw = i < powers.length ? powers[i].toDouble() : null;
          return Padding(
            padding: EdgeInsets.only(right: i < n - 1 ? 8 : 0),
            child: _GpuMiniCell(
              label: 'GPU $i',
              temp: t,
              fan: fan,
              volt: pw,
            ),
          );
        }),
      ),
    );
  }
}

class _GpuMiniCell extends StatelessWidget {
  const _GpuMiniCell({
    required this.label,
    required this.temp,
    required this.fan,
    required this.volt,
  });

  final String label;
  final double? temp;
  final double? fan;
  final double? volt;

  @override
  Widget build(BuildContext context) {
    final hot = temp != null && temp! >= 80;
    return Container(
      width: 72,
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
      decoration: BoxDecoration(
        color: AppColors.surfaceVariant,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: const Color(0xFF333333)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Text(
            label,
            style: const TextStyle(fontSize: 10, color: AppColors.textSecondary),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 4),
          Text(
            temp != null && temp! > 0 ? '${temp!.round()}°' : '—',
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: hot ? AppColors.redHive : Colors.white,
            ),
          ),
          Text(
            fan != null ? '${fan!.round()}%' : '—',
            style: const TextStyle(fontSize: 11, color: AppColors.textSecondary),
          ),
          Text(
            volt != null && volt! > 0 ? volt!.toStringAsFixed(3) : '—',
            style: const TextStyle(fontSize: 10, color: AppColors.textSecondary),
          ),
        ],
      ),
    );
  }
}

class _SystemInfoBlock extends StatelessWidget {
  const _SystemInfoBlock({required this.farm, required this.rig});

  final FarmDetail farm;
  final Map<String, dynamic>? rig;

  @override
  Widget build(BuildContext context) {
    final lines = <Widget>[];
    lines.add(
      _infoLine(
        'Майнер работает',
        formatUptimeSec(farm.summaryUptimeSec),
      ),
    );
    if (farm.summaryNetIps != null && farm.summaryNetIps!.isNotEmpty) {
      lines.add(_infoLine('Локальные IP', farm.summaryNetIps!.join(', ')));
    }
    final ver = _rigStr(rig, 'image_version') ?? _rigStr(rig, 'version');
    if (ver != null) {
      lines.add(_infoLine('Образ / версия', ver));
    }
    final kern = _rigStr(rig, 'kernel');
    if (kern != null) {
      lines.add(_infoLine('Ядро', kern));
    }
    final nv = _rigStr(rig, 'nvidia_version');
    final amd = _rigStr(rig, 'amd_version');
    if (nv != null || amd != null) {
      lines.add(_infoLine('Драйверы', [if (amd != null) 'A $amd', if (nv != null) 'N $nv'].join(' · ')));
    }
    final gpuN = farm.gpuCount;
    if (gpuN != null && gpuN > 0) {
      lines.add(
        Padding(
          padding: const EdgeInsets.only(top: 6, bottom: 12),
          child: Text(
            'GPU × $gpuN',
            style: const TextStyle(color: AppColors.greenHive, fontWeight: FontWeight.w600),
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: lines,
          ),
        ),
      ),
    );
  }

  static Widget _infoLine(String k, String v) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(k, style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
          const SizedBox(height: 2),
          Text(v, style: const TextStyle(fontSize: 13, color: Colors.white)),
        ],
      ),
    );
  }

  static String? _rigStr(Map<String, dynamic>? r, String key) {
    if (r == null) return null;
    final v = r[key];
    if (v == null) return null;
    if (v is String && v.trim().isNotEmpty) return v.trim();
    return v.toString();
  }
}

class _MetricsGrid extends StatelessWidget {
  const _MetricsGrid({required this.farm, required this.last});

  final FarmDetail farm;
  final Map<String, dynamic>? last;

  @override
  Widget build(BuildContext context) {
    final la = cpuAvgStrings(last);
    final ct = cputempFirst(last);
    final disk = diskFreeStr(last);
    final ram = ramSummary(last);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (la.length >= 3)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Средняя загрузка',
                    style: TextStyle(fontSize: 11, color: AppColors.textSecondary),
                  ),
                  const SizedBox(height: 6),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      _laCell('1m', la[0]),
                      _laCell('5m', la[1]),
                      _laCell('15m', la[2]),
                    ],
                  ),
                ],
              ),
            ),
          ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: _metricTile(
                icon: Icons.memory,
                label: 'CPU',
                value: ct != null ? '$ct°' : '—',
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _metricTile(
                icon: Icons.storage,
                label: 'Своб. ДП',
                value: disk ?? '—',
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: _metricTile(
                icon: Icons.sd_storage_outlined,
                label: 'ОЗУ',
                value: ram ?? '—',
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _metricTile(
                icon: Icons.bolt,
                label: 'Потребление',
                value: farm.totalPowerW != null ? '${farm.totalPowerW!.toStringAsFixed(1)} W' : '—',
                iconColor: AppColors.blueInfo,
              ),
            ),
          ],
        ),
      ],
    );
  }

  static Widget _laCell(String label, String v) {
    return Column(
      children: [
        Text(label, style: const TextStyle(fontSize: 10, color: AppColors.textSecondary)),
        Text(v, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: Colors.white)),
      ],
    );
  }

  static Widget _metricTile({
    required IconData icon,
    required String label,
    required String value,
    Color iconColor = AppColors.textSecondary,
  }) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 20, color: iconColor),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    label,
                    style: const TextStyle(fontSize: 11, color: AppColors.textSecondary),
                    maxLines: 2,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              value,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: Colors.white),
            ),
          ],
        ),
      ),
    );
  }
}

Widget _statChip(String label, String value, {bool hot = false}) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
      Text(
        value,
        style: TextStyle(
          fontSize: 13,
          fontWeight: hot ? FontWeight.bold : FontWeight.normal,
          color: hot ? AppColors.redHive : Colors.white,
        ),
      ),
    ],
  );
}

class _GpuCardTile extends StatelessWidget {
  const _GpuCardTile({
    required this.card,
    required this.hashStr,
    required this.index,
  });

  final GpuCard card;
  final String hashStr;
  final int index;

  @override
  Widget build(BuildContext context) {
    final brand = (card.brand ?? 'nvidia').toUpperCase();
    final name = card.name?.trim() ?? '';
    final model = name.isEmpty ? 'GPU $index' : name;
    final mem = card.memTotal?.trim() ?? '';
    final memChip = StringBuffer();
    if (mem.isNotEmpty) {
      memChip.write('$mem · ');
    }
    memChip.write(brand);

    final plParts = [card.plimMin, card.plimDef, card.plimMax]
        .whereType<String>()
        .where((s) => s.trim().isNotEmpty)
        .toList();
    final plStr = plParts.isEmpty ? '' : 'PL ${plParts.join(", ")}';
    final dParts = <String?>[card.memType, card.vbios, plStr.isEmpty ? null : plStr]
        .where((s) => s != null && s!.trim().isNotEmpty)
        .map((e) => e!)
        .toList();
    final detail = dParts.isEmpty ? '—' : dParts.join(' · ');

    final t = card.temp?.toDouble();
    final fan = card.fan?.toDouble();
    final hot = t != null && t >= 80;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceVariant,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text('GPU $index', style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
                ),
              ],
            ),
            if (card.busId?.trim().isNotEmpty == true) ...[
              const SizedBox(height: 4),
              Text(card.busId!, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
            ],
            const SizedBox(height: 6),
            RichText(
              text: TextSpan(
                style: Theme.of(context).textTheme.bodyMedium,
                children: [
                  TextSpan(text: model, style: const TextStyle(color: AppColors.greenHive, fontWeight: FontWeight.w600)),
                  TextSpan(
                    text: ' ${memChip.toString()}',
                    style: const TextStyle(color: AppColors.textSecondary),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 4),
            Text(detail, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
            const Divider(height: 20),
            Wrap(
              spacing: 12,
              runSpacing: 8,
              children: [
                _statChip('Hash', hashStr),
                _statChip('TEMP', t != null ? '${t.round()}°' : '—', hot: hot),
                _statChip('Fan', fan != null ? '${fan.round()}%' : '—'),
                _statChip('W', card.w != null && card.w! > 0 ? '${card.w} W' : '—'),
                _statChip('CORE', card.coreMhz?.toString() ?? '—'),
                _statChip('MEM', card.memMhz?.toString() ?? '—'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
