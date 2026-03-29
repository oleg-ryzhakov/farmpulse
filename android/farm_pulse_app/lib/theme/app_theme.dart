import 'package:flutter/material.dart';

/// Палитра в духе Hive OS mobile (тёмный фон, оранжевый акцент вкладок).
abstract final class AppColors {
  static const Color bg = Color(0xFF121212);
  static const Color surface = Color(0xFF1E1E1E);
  static const Color surfaceVariant = Color(0xFF2C2C2C);
  static const Color accent = Color(0xFFFF9800);
  static const Color accentDim = Color(0xFFE65100);
  static const Color greenHive = Color(0xFF66BB6A);
  static const Color redHive = Color(0xFFEF5350);
  static const Color purpleHash = Color(0xFFAB47BC);
  static const Color blueInfo = Color(0xFF42A5F5);
  static const Color textSecondary = Color(0xFF9E9E9E);
}

ThemeData buildFarmPulseTheme() {
  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
  );
  return base.copyWith(
    scaffoldBackgroundColor: AppColors.bg,
    colorScheme: ColorScheme.dark(
      surface: AppColors.surface,
      primary: AppColors.accent,
      secondary: AppColors.greenHive,
      error: AppColors.redHive,
      onSurface: Colors.white,
      onPrimary: Colors.black,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: AppColors.bg,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(
        fontSize: 18,
        fontWeight: FontWeight.w600,
        color: Colors.white,
      ),
    ),
    cardTheme: CardThemeData(
      color: AppColors.surface,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFF333333)),
      ),
    ),
    dividerTheme: const DividerThemeData(color: Color(0xFF333333)),
    listTileTheme: const ListTileThemeData(
      iconColor: AppColors.textSecondary,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      backgroundColor: AppColors.surface,
      selectedItemColor: AppColors.accent,
      unselectedItemColor: AppColors.textSecondary,
      type: BottomNavigationBarType.fixed,
      elevation: 8,
    ),
  );
}
