import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../models/farm.dart';
import '../services/credential_store.dart';
import '../services/farmpulse_client.dart' show FarmPulseClient, formatFarmPulseError;
import 'farm_detail_screen.dart';
import 'settings_screen.dart';

class FarmsScreen extends StatefulWidget {
  const FarmsScreen({
    super.key,
    required this.baseUrl,
  });

  final String baseUrl;

  @override
  State<FarmsScreen> createState() => _FarmsScreenState();
}

class _FarmsScreenState extends State<FarmsScreen> {
  final _store = CredentialStore();
  late FarmPulseClient _client;
  late String _baseUrl;
  var _farms = <Farm>[];
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _baseUrl = widget.baseUrl;
    _client = FarmPulseClient(baseUrl: _baseUrl);
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final list = await _client.fetchFarms();
      if (!mounted) return;
      setState(() {
        _farms = list;
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

  Future<void> _openSettings() async {
    final result = await Navigator.of(context).push<(String, String?)?>(
      MaterialPageRoute<(String, String?)?>(
        builder: (_) => const SettingsScreen(),
      ),
    );
    if (result == null || !mounted) return;
    setState(() {
      _baseUrl = result.$1;
      _client = FarmPulseClient(baseUrl: _baseUrl);
    });
    await _refresh();
  }

  Future<void> _logout() async {
    await _store.clearAll();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil<void>(
      MaterialPageRoute<void>(
        builder: (ctx) => SettingsScreen(
          onFirstLaunchSaved: (url, _) {
            Navigator.of(ctx).pushAndRemoveUntil<void>(
              MaterialPageRoute<void>(
                builder: (_) => FarmsScreen(baseUrl: url),
              ),
              (_) => false,
            );
          },
        ),
      ),
      (_) => false,
    );
  }

  void _openFarm(String id) {
    Navigator.of(context).push<void>(
      MaterialPageRoute<void>(
        builder: (_) => FarmDetailScreen(
          baseUrl: _baseUrl,
          farmId: id,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('FarmPulse'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _refresh,
          ),
          IconButton(
            icon: const Icon(Icons.settings),
            onPressed: _openSettings,
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading && _farms.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _farms.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton(onPressed: _refresh, child: const Text('Повторить')),
            ],
          ),
        ),
      );
    }
    if (_farms.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Text('Нет ферм в ответе API.'),
              const SizedBox(height: 8),
              TextButton(onPressed: _logout, child: const Text('Сменить URL')),
            ],
          ),
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        padding: const EdgeInsets.all(8),
        itemCount: _farms.length + 1,
        itemBuilder: (context, i) {
          if (i == 0) {
            return ListTile(
              title: Text(_error != null ? 'Ошибка: $_error' : 'Поток: REST'),
              subtitle: Text(
                'API: ${_client.apiRootForDisplay}\nВсего: ${_farms.length}',
              ),
            );
          }
          final f = _farms[i - 1];
          final name = f.name;
          final id = f.id;
          return Card(
            child: ListTile(
              title: Text(name?.isNotEmpty == true ? name! : 'Ферма $id'),
              subtitle: Text(
                '${f.status} · GPUs ${f.gpuCount} · last: ${f.lastSeenAt ?? "—"}\n'
                'temps: ${f.gpuTemps.isEmpty ? "—" : f.gpuTemps.map((t) => t.toStringAsFixed(0)).join(", ")}\n'
                'hash: ${f.totalKhs != null ? f.totalKhs!.toStringAsFixed(0) : "—"} kH/s',
              ),
              isThreeLine: true,
              onTap: () => _openFarm(id),
            ),
          );
        },
      ),
    );
  }
}
