import 'package:flutter/material.dart';

import 'screens/farms_screen.dart';
import 'screens/settings_screen.dart';
import 'services/credential_store.dart';
import 'theme/app_theme.dart';

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
      theme: buildFarmPulseTheme(),
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
    return FutureBuilder<String?>(
      future: _store.readBaseUrl(),
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }
        final raw = snap.data?.trim();
        if (raw == null || raw.isEmpty) {
          return SettingsScreen(
            onFirstLaunchSaved: (u, _) {
              Navigator.of(context).pushReplacement<void, void>(
                MaterialPageRoute<void>(
                  builder: (_) => FarmsScreen(baseUrl: u),
                ),
              );
            },
          );
        }
        return FarmsScreen(baseUrl: raw);
      },
    );
  }
}
