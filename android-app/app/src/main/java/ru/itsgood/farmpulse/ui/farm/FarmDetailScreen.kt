package ru.itsgood.farmpulse.ui.farm

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import ru.itsgood.farmpulse.data.FarmDetail
import ru.itsgood.farmpulse.data.formatUptimeSec
import ru.itsgood.farmpulse.prefs.PrefsRepository

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FarmDetailScreen(
    farmId: String,
    prefs: PrefsRepository,
    onBack: () -> Unit,
    viewModel: FarmDetailViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()

    LaunchedEffect(farmId) {
        val base = prefs.apiBaseFlow.first()
        viewModel.load(base, farmId)
    }

    Scaffold(
        topBar = {
            TopAppBar(
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Назад")
                    }
                },
                title = { Text("Ферма #$farmId") },
                actions = {
                    IconButton(
                        onClick = {
                            scope.launch {
                                viewModel.load(prefs.apiBaseFlow.first(), farmId)
                            }
                        },
                        enabled = !state.loading,
                    ) {
                        Icon(Icons.Default.Refresh, contentDescription = "Обновить")
                    }
                },
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 16.dp),
        ) {
            if (state.loading && state.farm == null) {
                CircularProgressIndicator(Modifier.padding(24.dp))
            }
            state.error?.let {
                Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(8.dp))
            }
            LazyColumn(
                contentPadding = PaddingValues(bottom = 32.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                state.farm?.let { farm ->
                    item { FarmSummaryBlock(farm) }
                }
                item {
                    Text(
                        "GPU",
                        style = MaterialTheme.typography.titleMedium,
                        modifier = Modifier.padding(top = 4.dp, bottom = 4.dp),
                    )
                }
                items(state.gpuRows, key = { it.indexLabel + it.busLine }) { row ->
                    GpuRowCard(row)
                }
            }
        }
    }
}

@Composable
private fun FarmSummaryBlock(farm: FarmDetail) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 8.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(farm.name ?: "—", style = MaterialTheme.typography.titleMedium)
            Text("Статус: ${farm.status ?: "—"} · last: ${farm.lastSeenAt ?: "—"}")
            Text("GPU: ${farm.gpuCount ?: "—"} · Hash (kH/s): ${farm.totalKhs?.let { String.format("%.2f", it) } ?: "—"}")
            Text("Power Σ: ${farm.totalPowerW?.let { "${it.toInt()} W" } ?: "—"}")
            Text("Майнер: ${farm.summaryMiner ?: "—"} · algo: ${farm.summaryAlgo ?: "—"}")
            Text("Uptime: ${formatUptimeSec(farm.summaryUptimeSec)}")
            farm.summaryNetIps?.takeIf { it.isNotEmpty() }?.let { ips ->
                Text("IP: ${ips.joinToString(", ")}")
            }
        }
    }
}

@Composable
private fun GpuRowCard(row: GpuRowUi) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(12.dp)) {
            Text(row.indexLabel, color = Color(0xFF4FC3F7), fontWeight = FontWeight.SemiBold)
            Text(row.busLine, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    row.titleModel,
                    color = Color(0xFF66BB6A),
                    fontWeight = FontWeight.SemiBold,
                    style = MaterialTheme.typography.bodyMedium,
                )
                if (row.titleMemChip.isNotEmpty()) {
                    Text(
                        " ${row.titleMemChip}",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            Text(
                row.detailLine,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            LazyRow(
                modifier = Modifier.padding(top = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                item { StatCell("Hash", row.hashStr) }
                item { StatCell("TEMP", row.tempStr, hot = row.hot) }
                item { StatCell("Fan", row.fanStr) }
                item { StatCell("W", row.wStr) }
                item { StatCell("CORE", row.coreStr) }
                item { StatCell("MEM", row.memStr) }
            }
        }
    }
}

@Composable
private fun StatCell(label: String, value: String, hot: Boolean = false) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(2.dp)) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(
            value,
            style = MaterialTheme.typography.bodySmall,
            fontWeight = if (hot) FontWeight.Bold else FontWeight.Normal,
            color = if (hot) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
        )
    }
}
