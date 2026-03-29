import 'package:flutter/material.dart';

import '../config/app_config.dart';
import '../services/credential_store.dart';

/// Сохраняет базовый URL к PHP API (`/api/v2/farms/…`). Ключ app-api опционален (резерв).
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, this.onFirstLaunchSaved});

  final void Function(String baseUrl, String? apiKey)? onFirstLaunchSaved;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _urlCtrl;
  late final TextEditingController _keyCtrl;
  final _store = CredentialStore();
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _urlCtrl = TextEditingController();
    _keyCtrl = TextEditingController();
    _load();
  }

  Future<void> _load() async {
    final url = await _store.readBaseUrl();
    final key = await _store.readApiKey();
    if (mounted) {
      setState(() {
        _urlCtrl.text = url?.trim().isNotEmpty == true ? url! : AppConfig.defaultBaseUrlHint;
        _keyCtrl.text = key ?? '';
        _loading = false;
      });
    }
  }

  @override
  void dispose() {
    _urlCtrl.dispose();
    _keyCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final url = _urlCtrl.text.trim();
    final key = _keyCtrl.text.trim();
    await _store.write(baseUrl: url, apiKey: key.isEmpty ? null : key);
    if (!mounted) return;

    if (Navigator.of(context).canPop()) {
      Navigator.of(context).pop<(String, String?)>((url, key.isEmpty ? null : key));
    } else {
      widget.onFirstLaunchSaved?.call(url, key.isEmpty ? null : key);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Подключение'),
      ),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: ListView(
            children: [
              const Text(
                'Укажите хост веб-панели FarmPulse. К пути будет добавлен /api (как у сайта).',
                style: TextStyle(fontSize: 13),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _urlCtrl,
                decoration: const InputDecoration(
                  labelText: 'Базовый URL',
                  hintText: AppConfig.defaultBaseUrlHint,
                  border: OutlineInputBorder(),
                ),
                keyboardType: TextInputType.url,
                validator: (v) {
                  if (v == null || v.trim().isEmpty) return 'Укажите URL';
                  final u = Uri.tryParse(v.trim());
                  if (u == null || !u.hasScheme || u.host.isEmpty) return 'Некорректный URL';
                  return null;
                },
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _keyCtrl,
                decoration: const InputDecoration(
                  labelText: 'X-Api-Key (опционально, для будущего app-api)',
                  border: OutlineInputBorder(),
                ),
                obscureText: true,
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _save,
                child: const Text('Сохранить'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
