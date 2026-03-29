import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Линия хешрейта (без истории с сервера — «псевдо»-график по текущему значению).
class HashrateSparkline extends StatelessWidget {
  const HashrateSparkline({super.key, required this.khsTotal, this.label});

  /// Суммарный kH/s (как total_khs в API).
  final double? khsTotal;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final base = ((khsTotal ?? 0) / 1000000).clamp(0.001, double.infinity); // GH/s для шкалы
    final spots = List.generate(14, (i) {
      final t = i / 13;
      final noise = 0.03 * (i % 3 - 1);
      return FlSpot(i.toDouble(), (base * (0.92 + 0.08 * t + noise)).clamp(0.0, double.infinity));
    });
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              label ?? 'HASHRATE',
              style: const TextStyle(
                fontSize: 11,
                color: AppColors.purpleHash,
                fontWeight: FontWeight.w600,
              ),
            ),
            Text(
              khsTotal != null ? '${(khsTotal! / 1000000).toStringAsFixed(3)} GH/s' : '—',
              style: const TextStyle(fontSize: 11, color: AppColors.textSecondary),
            ),
          ],
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 96,
          child: LineChart(
            LineChartData(
              minY: 0,
              gridData: const FlGridData(show: false),
              titlesData: const FlTitlesData(show: false),
              borderData: FlBorderData(show: false),
              lineBarsData: [
                LineChartBarData(
                  spots: spots,
                  color: AppColors.purpleHash,
                  barWidth: 2,
                  isCurved: true,
                  dotData: const FlDotData(show: false),
                  belowBarData: BarAreaData(
                    show: true,
                    color: AppColors.purpleHash.withValues(alpha: 0.12),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
