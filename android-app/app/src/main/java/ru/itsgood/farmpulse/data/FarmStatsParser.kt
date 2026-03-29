package ru.itsgood.farmpulse.data

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
object FarmStatsParser {
    private val gson = Gson()

    fun gpuCardsFromLastStats(lastStats: JsonObject?): List<GpuCard> {
        if (lastStats == null || !lastStats.has("gpu_cards")) return emptyList()
        val el = lastStats.get("gpu_cards") ?: return emptyList()
        if (!el.isJsonArray) return emptyList()
        val arr = el.asJsonArray
        val list = mutableListOf<GpuCard>()
        for (i in 0 until arr.size()) {
            val o = arr[i] as? JsonObject ?: continue
            list.add(gson.fromJson(o, GpuCard::class.java))
        }
        return list
    }

    /** Hive: [0, t1, t2] или без заглушки — берём значения как в вебе. */
    fun normalizeGpuArray(arr: JsonArray?): List<Double> {
        if (arr == null || arr.size() == 0) return emptyList()
        val values = mutableListOf<Double>()
        for (i in 0 until arr.size()) {
            val e = arr[i]
            if (e.isJsonPrimitive && e.asJsonPrimitive.isNumber) {
                values.add(e.asDouble)
            }
        }
        if (values.size > 1 && values[0] == 0.0) {
            return values.drop(1)
        }
        return values
    }

    fun doubleArrayFromStats(lastStats: JsonObject?, key: String): List<Double> {
        if (lastStats == null || !lastStats.has(key)) return emptyList()
        val el = lastStats.get(key) ?: return emptyList()
        if (!el.isJsonArray) return emptyList()
        return normalizeGpuArray(el.asJsonArray)
    }

    fun minerStatsHsKhs(lastStats: JsonObject?): DoubleArray {
        if (lastStats == null) return doubleArrayOf()
        for (key in listOf("miner_stats", "miner_stats2", "miner_stats3")) {
            if (!lastStats.has(key)) continue
            val raw = lastStats.get(key) ?: continue
            val obj = when {
                raw.isJsonObject -> raw.asJsonObject
                raw.isJsonPrimitive && raw.asJsonPrimitive.isString -> {
                    try {
                        gson.fromJson(raw.asString, JsonObject::class.java)
                    } catch (_: Exception) {
                        continue
                    }
                }
                else -> continue
            }
            if (!obj.has("hs") || !obj.get("hs").isJsonArray) continue
            val hs = obj.getAsJsonArray("hs")
            val units = obj.get("hs_units")?.asString?.lowercase() ?: "khs"
            val out = DoubleArray(hs.size())
            for (i in 0 until hs.size()) {
                val v = hs[i].asDouble
                out[i] = when (units) {
                    "h", "hs" -> v / 1000.0
                    "mhs", "mh" -> v * 1000.0
                    "ghs", "gh" -> v * 1_000_000.0
                    else -> v
                }
            }
            return out
        }
        return doubleArrayOf()
    }
}
