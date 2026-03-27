import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import '../models/farm.dart';
import '../services/credential_store.dart';
import '../services/farmpulse_client.dart';
import 'settings_screen.dart';

class FarmsScreen extends StatefulWidget {
  const FarmsScreen({
    super.key,
    required this.baseUrl,
    required this.apiKey,
  });

  final String baseUrl;
  final String apiKey;

  @override
  State<FarmsScreen> createState() => _FarmsScreenState();
}

class _FarmsScreenState extends State<FarmsScreen> {
  final _store = CredentialStore();
  late FarmPulseClient _client;
  List<Farm> _farms = [];
  String? _error;
  bool _loading = true;
  bool _wsLive = false;
  StreamSubscription<dynamic>? _wsSub;
  WebSocketChannel? _channel;

  @override
  void initState() {
    super.initState();
    _client = FarmPulseClient(baseUrl: widget.baseUrl, apiKey: widget.apiKey);
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    await _refresh();
    _connectWs();
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

  void _connectWs() {
    _wsSub?.cancel();
    _channel?.sink.close();
    setState(() => _wsLive = false);

    try {
      _channel = _client.connectWebSocket();
      _wsSub = _channel!.stream.listen(
        (message) {
          if (message is! String) return;
          final farms = FarmPulseClient.parseWsSnapshot(message);
          if (farms != null && mounted) {
            setState(() {
              _farms = farms;
              _wsLive = true;
            });
          }
        },
        onError: (_) {
          if (mounted) setState(() => _wsLive = false);
        },
        onDone: () {
          if (mounted) setState(() => _wsLive = false);
        },
        cancelOnError: false,
      );
    } catch (_) {
      if (mounted) setState(() => _wsLive = false);
    }
  }

  Future<void> _openSettings() async {
    final result = await Navigator.of(context).push<(String, String)?>(
      MaterialPageRoute<(String, String)?>(
        builder: (_) => const SettingsScreen(),
      ),
    );
    if (result == null || !mounted) return;
    setState(() {
      _client = FarmPulseClient(baseUrl: result.$1, apiKey: result.$2);
    });
    await _refresh();
    _connectWs();
  }

  Future<void> _logout() async {
    await _store.clearApiKey();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil<void>(
      MaterialPageRoute<void>(
        builder: (ctx) => SettingsScreen(
          onFirstLaunchSaved: (url, key) {
            Navigator.of(ctx).pushAndRemoveUntil<void>(
              MaterialPageRoute<void>(
                builder: (_) => FarmsScreen(baseUrl: url, apiKey: key),
              ),
              (_) => false,
            );
          },
        ),
      ),
      (_) => false,
    );
  }

  @override
  void dispose() {
    _wsSub?.cancel();
    _channel?.sink.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('FarmPulse'),
        actions: [
          IconButton(
            icon: Icon(_wsLive ? Icons.cloud_done : Icons.cloud_off),
            tooltip: _wsLive ? 'WebSocket активен' : 'WebSocket нет',
            onPressed: _connectWs,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _refresh,
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
              const Text('Нет ферм в памяти сервиса (или пустой список).'),
              const SizedBox(height: 8),
              TextButton(onPressed: _logout, child: const Text('Сменить ключ')),
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
              title: Text(_error != null ? 'Ошибка: $_error' : 'Поток: ${_wsLive ? "live" : "offline"}'),
              subtitle: Text('Всего: ${_farms.length}'),
            );
          }
          final f = _farms[i - 1];
          return Card(
            child: ListTile(
              title: Text(f.name?.isNotEmpty == true ? f.name! : 'Ферма ${f.id}'),
              subtitle: Text(
                '${f.status} · GPUs ${f.gpuCount} · last: ${f.lastSeenAt ?? "—"}\n'
                'temps: ${f.gpuTemps.isEmpty ? "—" : f.gpuTemps.map((t) => t.toStringAsFixed(0)).join(", ")}',
              ),
              isThreeLine: true,
            ),
          );
        },
      ),
    );
  }
}
