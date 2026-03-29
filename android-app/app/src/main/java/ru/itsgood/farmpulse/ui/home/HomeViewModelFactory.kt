package ru.itsgood.farmpulse.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import ru.itsgood.farmpulse.prefs.PrefsRepository

class HomeViewModelFactory(
    private val prefs: PrefsRepository,
) : ViewModelProvider.Factory {
    @Suppress("UNCHECKED_CAST")
    override fun <T : ViewModel> create(modelClass: Class<T>): T {
        if (modelClass != HomeViewModel::class.java) {
            throw IllegalArgumentException("Unknown VM")
        }
        return HomeViewModel(prefs) as T
    }
}
