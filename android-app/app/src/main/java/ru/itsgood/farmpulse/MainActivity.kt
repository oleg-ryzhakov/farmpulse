package ru.itsgood.farmpulse

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import ru.itsgood.farmpulse.prefs.PrefsRepository
import ru.itsgood.farmpulse.ui.farm.FarmDetailScreen
import ru.itsgood.farmpulse.ui.home.HomeScreen
import ru.itsgood.farmpulse.ui.theme.FarmPulseTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        val prefs = PrefsRepository(applicationContext)
        setContent {
            FarmPulseTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    val navController = rememberNavController()
                    NavHost(navController = navController, startDestination = "home") {
                        composable("home") {
                            HomeScreen(
                                prefs = prefs,
                                onOpenFarm = { id -> navController.navigate("farm/$id") },
                            )
                        }
                        composable(
                            route = "farm/{farmId}",
                            arguments = listOf(navArgument("farmId") { type = NavType.StringType }),
                        ) { entry ->
                            val farmId = entry.arguments?.getString("farmId") ?: return@composable
                            FarmDetailScreen(
                                farmId = farmId,
                                prefs = prefs,
                                onBack = { navController.popBackStack() },
                            )
                        }
                    }
                }
            }
        }
    }
}
