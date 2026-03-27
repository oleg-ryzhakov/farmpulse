import 'package:flutter/material.dart';

import 'config/app_config.dart';
import 'screens/farms_screen.dart';
import 'screens/settings_screen.dart';
import 'services/credential_store.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const FarmPulseApp());
}

class FarmPulseApp extends StatelessWidget {
  const FarmPulseApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FarmPulse',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.teal),
        useMaterial3: true,
      ),
      home: const _Bootstrap(),
    );
  }
}

class _Bootstrap extends StatefulWidget {
  const _Bootstrap();

  @override
  State<_Bootstrap> createState() => _BootstrapState();
}

class _BootstrapState extends State<_Bootstrap> {
  final _store = CredentialStore();

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<({String? baseUrl, String? apiKey})>(
      future: _load(),
      builder: (context, snap) {
        if (!snap.hasData) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }
        final baseUrl = snap.data!.baseUrl?.trim();
        final apiKey = snap.data!.apiKey?.trim();
        final url = (baseUrl != null && baseUrl.isNotEmpty)
            ? baseUrl
            : AppConfig.defaultBaseUrl;
        if (apiKey == null || apiKey.isEmpty) {
          return SettingsScreen(
            onFirstLaunchSaved: (u, k) {
              Navigator.of(context).pushReplacement<void, void>(
                MaterialPageRoute<void>(
                  builder: (_) => FarmsScreen(baseUrl: u, apiKey: k),
                ),
              );
            },
          );
        }
        return FarmsScreen(baseUrl: url, apiKey: apiKey);
      },
    );
  }

  Future<({String? baseUrl, String? apiKey})> _load() async {
    final baseUrl = await _store.readBaseUrl();
    final apiKey = await _store.readApiKey();
    return (baseUrl: baseUrl, apiKey: apiKey);
  }
}
