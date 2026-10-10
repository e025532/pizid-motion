package org.pizid.healthconnectsync

import android.app.Activity
import android.app.AlarmManager
import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.health.connect.HealthConnectManager
import android.health.connect.datatypes.ExerciseRoute
import android.health.connect.datatypes.ExerciseSessionRecord
import android.os.Bundle
import android.provider.Settings
import android.net.Uri
import android.provider.OpenableColumns
import android.text.method.ScrollingMovementMethod
import android.view.ViewGroup
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit

class MainActivity : ComponentActivity() {
    private lateinit var status: TextView
    private lateinit var lastSync: TextView
    private lateinit var syncProgress: ProgressBar
    private lateinit var syncButton: Button
    private lateinit var routesButton: Button
    private lateinit var priorityButton: Button
    private val routeQueue = ArrayDeque<ExerciseSessionRecord>()
    private var currentRouteSession: ExerciseSessionRecord? = null
    private var importedRoutes = 0

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { refreshStatus("Autorisations mises à jour") }

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { requestExactAlarmAccess() }

    private val routeConsentLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val session = currentRouteSession
        val route = if (result.resultCode == Activity.RESULT_OK) {
            result.data?.getParcelableExtra(HealthConnectManager.EXTRA_EXERCISE_ROUTE, ExerciseRoute::class.java)
        } else null
        if (session == null || route == null) {
            currentRouteSession = null
            routesButton.isEnabled = true
            status.text = "Import des tracés interrompu. ${routeQueue.size + if (session == null) 0 else 1} sortie(s) restent à autoriser."
        } else {
            lifecycleScope.launch {
                runCatching { ExerciseRouteImporter(this@MainActivity).upload(session, route) }
                    .onSuccess {
                        importedRoutes++
                        currentRouteSession = null
                        requestNextRoute()
                    }
                    .onFailure { error ->
                        currentRouteSession = null
                        routesButton.isEnabled = true
                        status.text = "Échec de l’envoi du tracé : ${error.message}"
                    }
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        provisionTokenIfPresent()
        if (BuildConfig.DEBUG && intent.getBooleanExtra("run_worker_test", false)) {
            WorkManager.getInstance(this).enqueue(OneTimeWorkRequestBuilder<SyncWorker>().build())
            finish()
            return
        }
        if (BuildConfig.DEBUG && intent.getBooleanExtra("run_priority_test", false)) {
            ContextCompat.startForegroundService(this, Intent(this, PrioritySyncService::class.java))
            finish()
            return
        }
        if (BuildConfig.DEBUG && intent.getBooleanExtra("run_alarm_test", false)) {
            PrioritySyncScheduler.scheduleNext(this, 2_000L)
            finish()
            return
        }
        setContentView(buildUi())
        scheduleHourly()
        schedulePrioritySync()
        refreshStatus()
        lifecycleScope.launch {
            SyncMonitor.progress.collectLatest { progress ->
                progress?.let(::renderSyncProgress)
            }
        }
        if (savedInstanceState == null) handleIncomingShare(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIncomingShare(intent)
    }

    private fun buildUi(): ScrollView {
        val density = resources.displayMetrics.density
        fun dp(value: Int) = (value * density).toInt()
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(32), dp(24), dp(32))
        }
        content.addView(TextView(this).apply {
            text = "Health Sync Pizid"
            textSize = 28f
            setTextColor(Color.rgb(23, 105, 170))
        })
        content.addView(TextView(this).apply {
            text = "Health Connect → health.home.pizid.org"
            textSize = 15f
            setPadding(0, dp(8), 0, dp(24))
        })
        status = TextView(this).apply {
            textSize = 16f
            movementMethod = ScrollingMovementMethod()
            setPadding(dp(16), dp(16), dp(16), dp(16))
            setBackgroundColor(Color.rgb(245, 247, 250))
        }
        content.addView(status, ViewGroup.LayoutParams.MATCH_PARENT, dp(170))
        lastSync = TextView(this).apply {
            textSize = 14f
            setTextColor(Color.rgb(70, 82, 92))
            setPadding(dp(4), dp(14), dp(4), dp(6))
        }
        content.addView(lastSync)
        syncProgress = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply {
            max = 100
            progress = 0
            isIndeterminate = false
        }
        content.addView(syncProgress, LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            dp(18)
        ))
        content.addView(Button(this).apply {
            text = "Autoriser toutes les données"
            setOnClickListener { permissionLauncher.launch(PermissionCatalog.requestedPermissions) }
        }, linearParams(dp(16)))
        routesButton = Button(this).apply {
            text = "Importer les 5 dernières sorties"
            setOnClickListener { importRoutes() }
        }
        content.addView(routesButton, linearParams(dp(8)))
        syncButton = Button(this).apply {
            text = "Synchroniser maintenant"
            setOnClickListener { synchronize() }
        }
        content.addView(syncButton, linearParams(dp(8)))
        priorityButton = Button(this).apply {
            setOnClickListener { enablePrioritySync() }
        }
        content.addView(priorityButton, linearParams(dp(8)))
        content.addView(TextView(this).apply {
            text = "La synchronisation prioritaire réveille brièvement l’application chaque heure. Une notification silencieuse apparaît uniquement pendant l’envoi."
            textSize = 13f
            setPadding(0, dp(20), 0, 0)
        })
        return ScrollView(this).apply { addView(content) }
    }

    private fun linearParams(topMargin: Int) = LinearLayout.LayoutParams(
        ViewGroup.LayoutParams.MATCH_PARENT,
        ViewGroup.LayoutParams.WRAP_CONTENT
    ).apply { this.topMargin = topMargin }

    private fun provisionTokenIfPresent() {
        if (!BuildConfig.DEBUG) return
        intent.getStringExtra("api_token")?.takeIf { it.isNotBlank() }?.let {
            TokenStore(this).save(it)
            intent.removeExtra("api_token")
        }
    }

    private fun refreshStatus(prefix: String? = null) {
        val token = TokenStore(this).hasToken()
        val granted = PermissionCatalog.requestedPermissions.count {
            ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
        }
        val lines = mutableListOf<String>()
        prefix?.let(lines::add)
        lines += if (token) "✓ Jeton API installé et chiffré" else "✗ Jeton API absent"
        lines += "$granted/${PermissionCatalog.requestedPermissions.size} autorisations accordées"
        lines += if (granted > 0) "Prêt pour la synchronisation" else "Appuyez d’abord sur Autoriser"
        status.text = lines.joinToString("\n")
        refreshLastSync()
        refreshPriorityButton()
        syncButton.isEnabled = token && granted > 0
    }

    override fun onResume() {
        super.onResume()
        if (::priorityButton.isInitialized) {
            schedulePrioritySync()
            refreshPriorityButton()
        }
    }

    private fun refreshLastSync() {
        val timestamp = getSharedPreferences(SyncStateStore.PREFS_NAME, MODE_PRIVATE)
            .getLong(SyncEngine.PREF_LAST_SUCCESS, 0L)
        lastSync.text = if (timestamp > 0L) {
            LAST_SYNC_FORMAT.format(Instant.ofEpochMilli(timestamp))
        } else {
            "Dernière synchronisation réussie : jamais"
        }
    }

    private fun handleIncomingShare(incoming: Intent) {
        if (incoming.action != Intent.ACTION_SEND) return
        val uri = incoming.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)
        incoming.action = null
        incoming.removeExtra(Intent.EXTRA_STREAM)
        if (uri == null) {
            status.text = "Échec import Renpho : aucun fichier reçu"
            return
        }
        status.text = "Lecture de l’export Renpho…"
        syncProgress.isIndeterminate = true
        lifecycleScope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    val token = TokenStore(this@MainActivity).load() ?: error("Jeton API absent")
                    val filename = sharedFilename(uri)
                    val bytes = contentResolver.openInputStream(uri)?.use { input ->
                        input.readBytes()
                    } ?: error("Impossible de lire le fichier partagé")
                    if (bytes.size > 5_000_000) error("Le fichier Renpho dépasse 5 Mo")
                    ApiClient(token).uploadRenpho(filename, contentResolver.getType(uri), bytes)
                }
            }.onSuccess { result ->
                val measurements = result.optInt("measurements")
                val inserted = result.optInt("inserted")
                val updated = result.optInt("updated")
                status.text = "✓ Import Renpho terminé\n$measurements mesure(s) traitée(s) · $inserted nouvelle(s) · $updated mise(s) à jour"
                syncProgress.isIndeterminate = false
                syncProgress.progress = 100
            }.onFailure { error ->
                status.text = "Échec import Renpho : ${error.message ?: error.javaClass.simpleName}"
                syncProgress.isIndeterminate = false
                syncProgress.progress = 0
            }
        }
    }

    private fun sharedFilename(uri: Uri): String {
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { cursor ->
            if (cursor.moveToFirst()) {
                val index = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (index >= 0) cursor.getString(index)?.takeIf { it.isNotBlank() }?.let { return it }
            }
        }
        return uri.lastPathSegment?.substringAfterLast('/')?.takeIf { it.isNotBlank() } ?: "RENPHO-export"
    }

    private fun renderSyncProgress(progress: SyncProgress) {
        status.text = progress.message
        syncProgress.isIndeterminate = progress.indeterminate
        if (!progress.indeterminate) syncProgress.progress = progress.percent
        syncButton.isEnabled = !progress.active && TokenStore(this).hasToken()
        if (!progress.active) refreshLastSync()
    }

    private fun synchronize() {
        syncButton.isEnabled = false
        status.text = "Connexion au serveur…"
        lifecycleScope.launch {
            runCatching {
                SyncEngine(this@MainActivity).run()
            }.onSuccess { result ->
                scheduleHourly()
                schedulePrioritySync()
                status.text = "✓ ${result.message}\n✓ Synchronisation horaire activée"
                syncProgress.isIndeterminate = false
                syncProgress.progress = 100
                refreshLastSync()
            }.onFailure { error ->
                status.text = "Échec : ${error.message ?: error.javaClass.simpleName}"
                syncProgress.isIndeterminate = false
                syncProgress.progress = 0
            }
            syncButton.isEnabled = true
        }
    }

    private fun importRoutes() {
        routesButton.isEnabled = false
        importedRoutes = 0
        routeQueue.clear()
        status.text = "Recherche des 5 dernières sorties…"
        lifecycleScope.launch {
            runCatching {
                ExerciseRouteImporter(this@MainActivity).scanAndImportAccessible(
                    force = true,
                    maxSessions = FORCED_ROUTE_IMPORT_LIMIT
                ) { message ->
                    runOnUiThread { status.text = message }
                }
            }.onSuccess { scan ->
                importedRoutes = scan.importedDirectly
                routeQueue.addAll(scan.pendingConsent)
                if (routeQueue.isEmpty()) {
                    routesButton.isEnabled = true
                    status.text = "✓ $importedRoutes nouveau(x) tracé(s) importé(s)\n" +
                        "${scan.alreadyImported} déjà importé(s), ${scan.sessionsWithoutRoute} sans tracé GPS\n" +
                        "${scan.olderRestrictedRoutes} ancien(s) tracé(s) conservé(s) dans l’export complet"
                } else {
                    status.text = "${routeQueue.size} tracé(s) nécessitent l’autorisation Android…\n" +
                        "${scan.olderRestrictedRoutes} plus ancien(s) seront repris depuis l’export complet"
                    requestNextRoute()
                }
            }.onFailure { error ->
                routesButton.isEnabled = true
                status.text = "Échec de la recherche : ${error.message}"
            }
        }
    }

    private fun requestNextRoute() {
        val session = routeQueue.removeFirstOrNull()
        if (session == null) {
            routesButton.isEnabled = true
            status.text = "✓ $importedRoutes tracé(s) GPS importé(s)"
            return
        }
        currentRouteSession = session
        val title = session.title?.toString()?.takeIf { it.isNotBlank() } ?: "Sortie"
        status.text = "$title du ${session.startTime}\nAutorisation du tracé ${importedRoutes + 1}…"
        routeConsentLauncher.launch(
            Intent(HealthConnectManager.ACTION_REQUEST_EXERCISE_ROUTE)
                .putExtra(HealthConnectManager.EXTRA_SESSION_ID, session.metadata.id)
        )
    }

    private fun scheduleHourly() {
        val request = PeriodicWorkRequestBuilder<SyncWorker>(1, TimeUnit.HOURS, 15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            "health-connect-hourly-sync",
            ExistingPeriodicWorkPolicy.UPDATE,
            request
        )
    }

    private fun enablePrioritySync() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        } else {
            requestExactAlarmAccess()
        }
    }

    private fun requestExactAlarmAccess() {
        val alarmManager = getSystemService(AlarmManager::class.java)
        if (alarmManager.canScheduleExactAlarms()) {
            schedulePrioritySync()
            refreshPriorityButton()
            return
        }
        startActivity(Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM).apply {
            data = Uri.parse("package:$packageName")
        })
    }

    private fun schedulePrioritySync() {
        PrioritySyncScheduler.scheduleNext(this)
    }

    private fun refreshPriorityButton() {
        val enabled = getSystemService(AlarmManager::class.java).canScheduleExactAlarms()
        priorityButton.text = if (enabled) {
            "✓ SYNCHRONISATION PRIORITAIRE ACTIVÉE"
        } else {
            "AUTORISER LA SYNCHRONISATION PRIORITAIRE"
        }
        priorityButton.isEnabled = !enabled
    }

    companion object {
        private const val FORCED_ROUTE_IMPORT_LIMIT = 5
        private val LAST_SYNC_FORMAT = DateTimeFormatter
            .ofPattern("'Dernière synchronisation réussie : 'dd/MM/yyyy 'à' HH:mm")
            .withZone(ZoneId.systemDefault())
    }
}
