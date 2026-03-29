package ru.itsgood.farmpulse.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val FpDark = darkColorScheme(
    primary = Color(0xFF4CAF50),
    onPrimary = Color(0xFF0D1A0D),
    background = Color(0xFF121212),
    surface = Color(0xFF1E1E1E),
    onBackground = Color(0xFFE0E0E0),
    onSurface = Color(0xFFE0E0E0),
    error = Color(0xFFCF6679),
)

@Composable
fun FarmPulseTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = FpDark,
        content = content
    )
}
