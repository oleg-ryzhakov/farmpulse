import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

class SummaryMetricCard extends StatelessWidget {
  const SummaryMetricCard({
    super.key,
    required this.title,
    required this.primary,
    this.secondary,
    this.icon,
    this.primaryColor,
    this.secondaryColor,
  });

  final String title;
  final String primary;
  final String? secondary;
  final IconData? icon;
  final Color? primaryColor;
  final Color? secondaryColor;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFF333333)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title.toUpperCase(),
              style: const TextStyle(
                fontSize: 10,
                letterSpacing: 0.5,
                color: AppColors.textSecondary,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 6),
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                if (icon != null) ...[
                  Icon(icon, size: 16, color: AppColors.blueInfo),
                  const SizedBox(width: 4),
                ],
                Text(
                  primary,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: primaryColor ?? AppColors.greenHive,
                  ),
                ),
                if (secondary != null) ...[
                  const SizedBox(width: 6),
                  Text(
                    secondary!,
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: secondaryColor ?? AppColors.accent,
                    ),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}
