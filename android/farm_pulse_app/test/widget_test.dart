import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:farm_pulse_app/main.dart';

void main() {
  testWidgets('FarmPulseApp стартует', (WidgetTester tester) async {
    await tester.pumpWidget(const FarmPulseApp());
    await tester.pump();
    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
