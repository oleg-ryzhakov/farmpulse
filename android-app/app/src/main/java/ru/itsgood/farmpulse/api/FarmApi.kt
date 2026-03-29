package ru.itsgood.farmpulse.api

import retrofit2.http.GET
import retrofit2.http.Query
import ru.itsgood.farmpulse.data.FarmsResponse
import ru.itsgood.farmpulse.data.WorkersResponse

interface FarmApi {
    @GET("v2/farms/farms.php")
    suspend fun listFarms(): FarmsResponse

    @GET("v2/farms/workers.php")
    suspend fun getFarm(@Query("farm_id") farmId: String): WorkersResponse
}
