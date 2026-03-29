import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../models/farm_detail.dart';
import '../models/gpu_card.dart';
import '../services/farmpulse_client.dart';
import '../utils/formatters.dart';
import '../utils/stats_parser.dart';

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
        _error = e.message ?? e.toString();
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
      appBar: AppBar(
        title: Text('Ферма #${widget.farmId}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _load,
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading && _farm == null) {
      return const Center(child: CircularProgressIndicator());
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
              FilledButton(onPressed: _load, child: const Text('Повторить')),
            ],
          ),
        ),
      );
    }
    final f = _farm!;
    final last = f.lastStats;
    final cards = gpuCardsFromLastStats(last);
    final hs = minerStatsHsKhs(last);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    f.name ?? '—',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 8),
                  Text('Статус: ${f.status ?? "—"} · last: ${f.lastSeenAt ?? "—"}'),
                  Text('GPU: ${f.gpuCount ?? "—"} · Hash (kH/s): ${f.totalKhs != null ? f.totalKhs!.toStringAsFixed(2) : "—"}'),
                  Text('Power Σ: ${f.totalPowerW != null ? "${f.totalPowerW!.round()} W" : "—"}'),
                  Text('Майнер: ${f.summaryMiner ?? "—"} · algo: ${f.summaryAlgo ?? "—"}'),
                  Text('Uptime: ${formatUptimeSec(f.summaryUptimeSec)}'),
                  if (f.summaryNetIps != null && f.summaryNetIps!.isNotEmpty)
                    Text('IP: ${f.summaryNetIps!.join(", ")}'),
                  if (f.heatWarning == true)
                    const Text('⚠ GPU ≥80°C', style: TextStyle(color: Colors.orange)),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text('GPU', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          if (cards.isNotEmpty)
            ...cards.asMap().entries.map((e) {
              final i = e.key;
              final c = e.value;
              final hr = i < hs.length ? formatHashrateKhs(hs[i]) : '—';
              return _GpuCardTile(card: c, hashStr: hr, index: c.index ?? i);
            })
          else
            ..._fallbackGpuRows(last, hs),
        ],
      ),
    );
  }

  List<Widget> _fallbackGpuRows(Map<String, dynamic>? last, List<double> hs) {
    final temps = doubleArrayFromStats(last, 'temp');
    final fans = doubleArrayFromStats(last, 'fan');
    final powers = doubleArrayFromStats(last, 'power');
    final lens = [temps.length, fans.length, powers.length];
    final n = lens.isEmpty ? 0 : lens.reduce((a, b) => a > b ? a : b);
    if (n == 0) {
      return [
        const Padding(
          padding: EdgeInsets.all(16),
          child: Text('Нет данных GPU в last_stats'),
        ),
      ];
    }
    return List.generate(n, (i) {
      final t = i < temps.length ? temps[i].toDouble() : null;
      final fan = i < fans.length ? fans[i].toDouble() : null;
      final pw = i < powers.length ? powers[i].toDouble() : null;
      final hot = t != null && t >= 80;
      final hr = i < hs.length ? formatHashrateKhs(hs[i]) : '—';
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('GPU $i', style: const TextStyle(color: Colors.lightBlueAccent, fontWeight: FontWeight.w600)),
              const Text('—', style: TextStyle(color: Colors.grey)),
              Text(
                'GPU $i',
                style: const TextStyle(color: Colors.green, fontWeight: FontWeight.w600),
              ),
              const Text('—', style: TextStyle(fontSize: 12, color: Colors.grey)),
              const Divider(),
              Wrap(
                spacing: 12,
                runSpacing: 8,
                children: [
                  _statChip('Hash', hr),
                  _statChip('TEMP', t != null && t > 0 ? '${t.round()}°' : '—', hot: hot),
                  _statChip('Fan', fan != null ? '${fan.round()}%' : '—'),
                  _statChip('W', pw != null && pw > 0 ? '${pw.round()} W' : '—'),
                  _statChip('CORE', '—'),
                  _statChip('MEM', '—'),
                ],
              ),
            ],
          ),
        ),
      );
    });
  }
}

Widget _statChip(String label, String value, {bool hot = false}) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(label, style: const TextStyle(fontSize: 11, color: Colors.grey)),
      Text(
        value,
        style: TextStyle(
          fontSize: 13,
          fontWeight: hot ? FontWeight.bold : FontWeight.normal,
          color: hot ? Colors.redAccent : null,
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
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('GPU $index', style: const TextStyle(color: Colors.lightBlueAccent, fontWeight: FontWeight.w600)),
            Text(card.busId?.trim().isNotEmpty == true ? card.busId! : '—', style: const TextStyle(fontSize: 12, color: Colors.grey)),
            RichText(
              text: TextSpan(
                style: Theme.of(context).textTheme.bodyMedium,
                children: [
                  TextSpan(text: model, style: const TextStyle(color: Colors.green, fontWeight: FontWeight.w600)),
                  TextSpan(
                    text: ' ${memChip.toString()}',
                    style: TextStyle(color: Colors.grey.shade400),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 4),
            Text(detail, style: const TextStyle(fontSize: 12, color: Colors.grey)),
            const Divider(),
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
