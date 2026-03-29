package ru.itsgood.farmpulse.data

import kotlin.math.roundToInt

fun formatHashrateKhs(khs: Double?): String {
    if (khs == null || khs.isNaN() || khs <= 0) return "—"
    val v = khs
    return when {
        v >= 1_000_000 -> String.format("%.3f GH", v / 1_000_000.0)
        v >= 1_000 -> String.format("%.2f MH", v / 1_000.0)
        else -> "${v.roundToInt()} kH"
    }
}

fun formatUptimeSec(sec: Int?): String {
    if (sec == null || sec < 0) return "—"
    val d = sec / 86400
    val h = (sec % 86400) / 3600
    val m = (sec % 3600) / 60
    return when {
        d > 0 -> "${d}d ${h}h"
        h > 0 -> "${h}h ${m}m"
        else -> "${m}m"
    }
}

fun normalizeApiBase(input: String): String {
    var s = input.trim().trimEnd('/')
    if (s.isEmpty()) return ""
    if (!s.endsWith("/api", ignoreCase = true)) {
        s = "$s/api"
    }
    return "$s/"
}
