package ru.itsgood.farmpulse.ui.home

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import ru.itsgood.farmpulse.data.FarmSummary
import ru.itsgood.farmpulse.prefs.PrefsRepository

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    prefs: PrefsRepository,
    onOpenFarm: (String) -> Unit,
    viewModel: HomeViewModel = viewModel(factory = HomeViewModelFactory(prefs)),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("FarmPulse") },
                actions = {
                    IconButton(onClick = { viewModel.refresh() }, enabled = !state.loading) {
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
                .padding(16.dp),
        ) {
            Text(
                "Базовый URL API (как у веба: хост + /api)",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            OutlinedTextField(
                value = state.apiBaseInput,
                onValueChange = viewModel::onApiBaseChange,
                modifier = Modifier.fillMaxWidth(),
                placeholder = { Text("https://farmpulse.its-good.ru") },
                singleLine = true,
            )
            Button(
                onClick = {
                    viewModel.saveApiBase()
                    viewModel.refresh()
                },
                modifier = Modifier.padding(top = 8.dp),
                enabled = !state.loading,
            ) {
                Text("Сохранить и загрузить фермы")
            }

            state.error?.let { err ->
                Text(
                    err,
                    color = MaterialTheme.colorScheme.error,
                    modifier = Modifier.padding(top = 8.dp),
                )
            }

            if (state.loading) {
                CircularProgressIndicator(modifier = Modifier.padding(top = 24.dp))
            } else {
                LazyColumn(
                    contentPadding = PaddingValues(top = 16.dp, bottom = 32.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(state.farms, key = { it.id }) { farm ->
                        FarmSummaryCard(farm, onClick = { onOpenFarm(farm.id) })
                    }
                }
            }
        }
    }
}

@Composable
private fun FarmSummaryCard(farm: FarmSummary, onClick: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(farm.name ?: ("Ферма " + farm.id), style = MaterialTheme.typography.titleMedium)
            Text(
                "Статус: ${farm.status ?: "—"} · GPU: ${farm.gpuCount ?: "—"}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            farm.totalKhs?.let {
                Text(
                    "Hashrate: ${String.format("%.2f", it)} kH/s",
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
    }
}
