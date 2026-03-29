package ru.itsgood.farmpulse.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import ru.itsgood.farmpulse.api.FarmApiFactory
import ru.itsgood.farmpulse.data.FarmSummary
import ru.itsgood.farmpulse.prefs.PrefsRepository

data class HomeUiState(
    val apiBaseInput: String = "",
    val farms: List<FarmSummary> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
)

class HomeViewModel(
    private val prefs: PrefsRepository,
) : ViewModel() {
    private val _state = MutableStateFlow(HomeUiState())
    val state: StateFlow<HomeUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            val saved = prefs.apiBaseFlow.first()
            _state.update { it.copy(apiBaseInput = saved) }
        }
    }

    fun onApiBaseChange(value: String) {
        _state.update { it.copy(apiBaseInput = value, error = null) }
    }

    fun saveApiBase() {
        viewModelScope.launch {
            prefs.setApiBase(_state.value.apiBaseInput)
        }
    }

    fun refresh() {
        val base = _state.value.apiBaseInput.trim()
        if (base.isEmpty()) {
            _state.update { it.copy(error = "Укажите URL сервера (например https://farmpulse.its-good.ru)") }
            return
        }
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            try {
                val api = FarmApiFactory.create(base)
                val res = api.listFarms()
                val list = res.farms.orEmpty()
                _state.update { it.copy(farms = list, loading = false) }
            } catch (e: Exception) {
                _state.update {
                    it.copy(
                        loading = false,
                        error = e.message ?: e.toString(),
                    )
                }
            }
        }
    }
}
