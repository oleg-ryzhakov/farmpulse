import 'package:flutter/material.dart';

import '../config/app_config.dart';
import '../services/credential_store.dart';

/// Если открыт поверх другого экрана — по сохранению делает `pop((url, key))`.
/// Если первый экран (нельзя pop) — вызывает [onFirstLaunchSaved].
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, this.onFirstLaunchSaved});

  final void Function(String baseUrl, String apiKey)? onFirstLaunchSaved;

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
        _urlCtrl.text = url?.trim().isNotEmpty == true ? url! : AppConfig.defaultBaseUrl;
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
    final url = _urlCtrl.text.trim().replaceAll(RegExp(r'/$'), '');
    final key = _keyCtrl.text.trim();
    await _store.write(baseUrl: url, apiKey: key);
    if (!mounted) return;

    if (Navigator.of(context).canPop()) {
      Navigator.of(context).pop<(String, String)>((url, key));
    } else {
      widget.onFirstLaunchSaved?.call(url, key);
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
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                controller: _urlCtrl,
                decoration: const InputDecoration(
                  labelText: 'Базовый URL API',
                  hintText: AppConfig.defaultBaseUrl,
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
                  labelText: 'X-Api-Key (как FARMPULSE_APP_API_KEY на сервере)',
                  border: OutlineInputBorder(),
                ),
                obscureText: true,
                validator: (v) => (v == null || v.trim().isEmpty) ? 'Введите ключ' : null,
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
