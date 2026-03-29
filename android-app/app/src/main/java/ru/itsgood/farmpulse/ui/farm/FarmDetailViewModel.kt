package ru.itsgood.farmpulse.ui.farm

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import ru.itsgood.farmpulse.api.FarmApiFactory
import ru.itsgood.farmpulse.data.FarmDetail
import ru.itsgood.farmpulse.data.FarmStatsParser
import ru.itsgood.farmpulse.data.GpuCard
import ru.itsgood.farmpulse.data.formatHashrateKhs

data class GpuRowUi(
    val indexLabel: String,
    val busLine: String,
    /** Модель (зелёным). */
    val titleModel: String,
    /** VRAM · NVIDIA (серым). */
    val titleMemChip: String,
    val detailLine: String,
    val hashStr: String,
    val tempStr: String,
    val fanStr: String,
    val wStr: String,
    val coreStr: String,
    val memStr: String,
    val hot: Boolean,
)

data class FarmDetailUiState(
    val farm: FarmDetail? = null,
    val gpuRows: List<GpuRowUi> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
)

class FarmDetailViewModel : ViewModel() {
    private val _state = MutableStateFlow(FarmDetailUiState())
    val state: StateFlow<FarmDetailUiState> = _state.asStateFlow()

    fun load(apiBase: String, farmId: String) {
        if (apiBase.isBlank()) {
            _state.update { it.copy(error = "Нет base URL") }
            return
        }
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            try {
                val api = FarmApiFactory.create(apiBase)
                val res = api.getFarm(farmId)
                val farm = res.farm
                val rows = buildGpuRows(farm)
                _state.update { it.copy(farm = farm, gpuRows = rows, loading = false) }
            } catch (e: Exception) {
                _state.update {
                    it.copy(loading = false, error = e.message ?: e.toString())
                }
            }
        }
    }

    private fun buildGpuRows(farm: FarmDetail?): List<GpuRowUi> {
        if (farm == null) return emptyList()
        val last = farm.lastStats
        val cards = FarmStatsParser.gpuCardsFromLastStats(last)
        val hs = FarmStatsParser.minerStatsHsKhs(last)
        if (cards.isNotEmpty()) {
            return cards.mapIndexed { i, c ->
                val idx = c.index ?: i
                val hr = hs.getOrNull(i)?.let { formatHashrateKhs(it) } ?: "—"
                gpuRowFromCard(c, idx, hr)
            }
        }
        val temps = FarmStatsParser.doubleArrayFromStats(last, "temp")
        val fans = FarmStatsParser.doubleArrayFromStats(last, "fan")
        val powers = FarmStatsParser.doubleArrayFromStats(last, "power")
        val n = maxOf(temps.size, fans.size, powers.size)
        return List(n) { i ->
            val t = temps.getOrNull(i)
            val fan = fans.getOrNull(i)
            val pw = powers.getOrNull(i)
            val hot = t != null && t >= 80
            GpuRowUi(
                indexLabel = "GPU $i",
                busLine = "—",
                titleModel = "GPU $i",
                titleMemChip = "",
                detailLine = "—",
                hashStr = hs.getOrNull(i)?.let { formatHashrateKhs(it) } ?: "—",
                tempStr = if (t != null && t > 0) "${t.toInt()}°" else "—",
                fanStr = fan?.let { "${it.toInt()}%" } ?: "—",
                wStr = if (pw != null && pw > 0) "${pw.toInt()} W" else "—",
                coreStr = "—",
                memStr = "—",
                hot = hot,
            )
        }
    }

    private fun gpuRowFromCard(c: GpuCard, idx: Int, hashStr: String): GpuRowUi {
        val t = c.temp?.toDouble()
        val fan = c.fan?.toDouble()
        val hot = t != null && t >= 80
        val brand = (c.brand ?: "nvidia").uppercase()
        val name = c.name?.trim().orEmpty()
        val mem = c.memTotal?.trim().orEmpty()
        val model = name.ifEmpty { "GPU $idx" }
        val memChip = buildString {
            if (mem.isNotEmpty()) {
                append(mem).append(" · ")
            }
            append(brand)
        }
        val plParts = listOfNotNull(c.plimMin, c.plimDef, c.plimMax).filter { it.isNotBlank() }
        val plStr = if (plParts.isNotEmpty()) "PL " + plParts.joinToString(", ") else ""
        val dParts = listOfNotNull(c.memType, c.vbios, plStr).filter { it.isNotBlank() }
        val detail = if (dParts.isNotEmpty()) dParts.joinToString(" · ") else "—"
        return GpuRowUi(
            indexLabel = "GPU $idx",
            busLine = c.busId?.trim()?.takeIf { it.isNotBlank() } ?: "—",
            titleModel = model,
            titleMemChip = memChip,
            detailLine = detail,
            hashStr = hashStr,
            tempStr = t?.let { "${it.toInt()}°" } ?: "—",
            fanStr = fan?.let { "${it.toInt()}%" } ?: "—",
            wStr = c.w?.takeIf { it > 0 }?.let { "$it W" } ?: "—",
            coreStr = c.coreMhz?.toString() ?: "—",
            memStr = c.memMhz?.toString() ?: "—",
            hot = hot,
        )
    }
}
