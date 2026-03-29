package ru.itsgood.farmpulse.api

import com.google.gson.Gson
import com.google.gson.GsonBuilder
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import ru.itsgood.farmpulse.data.normalizeApiBase
import java.util.concurrent.TimeUnit

object FarmApiFactory {
    private val gson: Gson = GsonBuilder().create()

    fun create(baseUrlInput: String): FarmApi {
        val base = normalizeApiBase(baseUrlInput)
        require(base.isNotEmpty()) { "base URL is empty" }

        val log = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }
        val client = OkHttpClient.Builder()
            .addInterceptor(log)
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .build()

        return Retrofit.Builder()
            .baseUrl(base)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(FarmApi::class.java)
    }
}
