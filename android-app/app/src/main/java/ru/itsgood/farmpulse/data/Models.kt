package ru.itsgood.farmpulse.data

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

data class FarmsResponse(
    @SerializedName("status") val status: String?,
    @SerializedName("farms") val farms: List<FarmSummary>?,
)

data class FarmSummary(
    @SerializedName("id") val id: String,
    @SerializedName("name") val name: String?,
    @SerializedName("status") val status: String?,
    @SerializedName("last_seen_at") val lastSeenAt: String?,
    @SerializedName("gpu_count") val gpuCount: Int?,
    @SerializedName("gpu_temps") val gpuTemps: List<Double>?,
    @SerializedName("total_khs") val totalKhs: Double?,
    @SerializedName("total_power_w") val totalPowerW: Double?,
    @SerializedName("summary_miner") val summaryMiner: String?,
    @SerializedName("summary_algo") val summaryAlgo: String?,
    @SerializedName("heat_warning") val heatWarning: Boolean?,
)

data class WorkersResponse(
    @SerializedName("status") val status: String?,
    @SerializedName("farm") val farm: FarmDetail?,
)

data class FarmDetail(
    @SerializedName("id") val id: String?,
    @SerializedName("name") val name: String?,
    @SerializedName("status") val status: String?,
    @SerializedName("last_seen_at") val lastSeenAt: String?,
    @SerializedName("gpu_count") val gpuCount: Int?,
    @SerializedName("gpu_temps") val gpuTemps: List<Double>?,
    @SerializedName("total_khs") val totalKhs: Double?,
    @SerializedName("total_power_w") val totalPowerW: Double?,
    @SerializedName("last_stats") val lastStats: JsonObject?,
    @SerializedName("rig_info") val rigInfo: JsonObject?,
    @SerializedName("summary_miner") val summaryMiner: String?,
    @SerializedName("summary_algo") val summaryAlgo: String?,
    @SerializedName("summary_uptime_sec") val summaryUptimeSec: Int?,
    @SerializedName("summary_net_ips") val summaryNetIps: List<String>?,
    @SerializedName("heat_warning") val heatWarning: Boolean?,
)

data class GpuCard(
    @SerializedName("index") val index: Int?,
    @SerializedName("name") val name: String?,
    @SerializedName("bus_id") val busId: String?,
    @SerializedName("vbios") val vbios: String?,
    @SerializedName("mem_total") val memTotal: String?,
    @SerializedName("core_mhz") val coreMhz: Int?,
    @SerializedName("mem_mhz") val memMhz: Int?,
    @SerializedName("temp") val temp: Int?,
    @SerializedName("fan") val fan: Int?,
    @SerializedName("w") val w: Int?,
    @SerializedName("brand") val brand: String?,
    @SerializedName("mem_type") val memType: String?,
    @SerializedName("plim_min") val plimMin: String?,
    @SerializedName("plim_def") val plimDef: String?,
    @SerializedName("plim_max") val plimMax: String?,
)
