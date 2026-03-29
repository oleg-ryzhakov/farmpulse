import 'package:flutter/material.dart';

import '../models/farm.dart';
import '../theme/app_theme.dart';

class WorkerListTile extends StatelessWidget {
  const WorkerListTile({
    super.key,
    required this.farm,
    required this.onTap,
  });

  final Farm farm;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final name = farm.name?.isNotEmpty == true ? farm.name! : 'Ферма ${farm.id}';
    final online = farm.status == 'online';
    final temps = farm.gpuTemps;
    final tempStr = temps.isEmpty ? '—' : temps.map((t) => t.toStringAsFixed(0)).join(' ');
    final khs = farm.totalKhs;
    final hashStr = khs != null ? '${(khs / 1000).toStringAsFixed(2)} MH' : '—';

    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.star_border,
                size: 22,
                color: AppColors.textSecondary,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontWeight: FontWeight.w600,
                              fontSize: 15,
                            ),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.redHive.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            '${farm.gpuCount}',
                            style: const TextStyle(
                              fontSize: 11,
                              color: AppColors.redHive,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: (online ? AppColors.greenHive : AppColors.textSecondary)
                                .withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            online ? 'ON' : 'OFF',
                            style: TextStyle(
                              fontSize: 11,
                              color: online ? AppColors.greenHive : AppColors.textSecondary,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      '${farm.summaryAlgo ?? "—"} · $hashStr',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondary,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'GPU °C: $tempStr',
                      style: const TextStyle(fontSize: 11, color: AppColors.textSecondary),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _HealthBarSegments(
                online: online,
                heat: farm.heatWarning == true,
                gpuCount: farm.gpuCount,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Упрощённая полоска «здоровья» как в Hive (сегменты).
class _HealthBarSegments extends StatelessWidget {
  const _HealthBarSegments({
    required this.online,
    required this.heat,
    required this.gpuCount,
  });

  final bool online;
  final bool heat;
  final int gpuCount;

  @override
  Widget build(BuildContext context) {
    const total = 7;
    final okSeg = online ? (gpuCount.clamp(0, 6)) : 0;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: List.generate(total, (i) {
        late Color c;
        late bool outline;
        if (i < okSeg) {
          c = AppColors.greenHive;
          outline = false;
        } else if (i == okSeg && heat) {
          c = Colors.transparent;
          outline = true;
        } else {
          c = AppColors.surfaceVariant;
          outline = false;
        }
        return Container(
          width: 5,
          height: 18,
          margin: const EdgeInsets.only(left: 2),
          decoration: BoxDecoration(
            color: outline ? null : c,
            borderRadius: BorderRadius.circular(2),
            border: outline ? Border.all(color: AppColors.redHive, width: 1.5) : null,
          ),
        );
      }),
    );
  }
}
