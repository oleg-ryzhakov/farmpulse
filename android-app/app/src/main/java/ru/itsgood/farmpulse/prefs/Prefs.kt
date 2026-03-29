package ru.itsgood.farmpulse.prefs

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore(name = "farmpulse")

object PrefsKeys {
    val API_BASE = stringPreferencesKey("api_base")
}

class PrefsRepository(private val context: Context) {
    val apiBaseFlow: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[PrefsKeys.API_BASE].orEmpty()
    }

    suspend fun setApiBase(value: String) {
        context.dataStore.edit { it[PrefsKeys.API_BASE] = value.trim() }
    }
}
