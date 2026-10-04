package org.pizid.healthconnectsync

import android.content.Context
import android.content.pm.PackageManager
import android.health.connect.HealthConnectException
import android.health.connect.HealthConnectManager
import android.health.connect.HealthPermissions
import android.health.connect.ReadRecordsRequestUsingFilters
import android.health.connect.ReadRecordsResponse
import android.health.connect.TimeInstantRangeFilter
import android.health.connect.datatypes.ExerciseSessionRecord
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.time.Instant
import java.util.concurrent.Executor
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

data class RouteScan(
    val importedDirectly: Int,
    val pendingConsent: List<ExerciseSessionRecord>,
    val alreadyImported: Int,
    val sessionsWithoutRoute: Int,
    val olderRestrictedRoutes: Int
)

class ExerciseRouteImporter(private val context: Context) {
    private val manager = context.getSystemService(HealthConnectManager::class.java)
        ?: error("Health Connect indisponible")
    private val prefs = context.getSharedPreferences("route_imports", Context.MODE_PRIVATE)
    private val executor = Executor { command -> command.run() }

    suspend fun scanAndImportAccessible(
        force: Boolean = false,
        maxSessions: Int? = null,
        onProgress: (String) -> Unit
    ): RouteScan = withContext(Dispatchers.IO) {
        val sessions = readSessions(maxSessions)
        onProgress("${sessions.size} sorties trouvées, vérification des tracés…")
        val pending = mutableListOf<ExerciseSessionRecord>()
        var imported = 0
        var already = 0
        var withoutRoute = 0
        var restricted = 0
        val allRoutesGranted = context.checkSelfPermission(HealthPermissions.READ_EXERCISE_ROUTES) ==
            PackageManager.PERMISSION_GRANTED
        sessions.forEach { session ->
            when {
                // Check our lightweight local marker first. With route access granted,
                // hasRoute() can materialize a very large route even when it was already sent.
                !force && isImported(session.metadata.id) -> already++
                !session.hasRoute() -> withoutRoute++
                session.route != null -> {
                    upload(session, session.route!!)
                    imported++
                }
                allRoutesGranted -> restricted++
                session.startTime.isBefore(ROUTE_CONSENT_FROM) -> restricted++
                else -> pending += session
            }
        }
        RouteScan(imported, pending.sortedByDescending { it.startTime }, already, withoutRoute, restricted).also {
            Log.i(TAG, "Route scan complete: imported=$imported already=$already withoutRoute=$withoutRoute restricted=$restricted pending=${pending.size}")
        }
    }

    suspend fun upload(session: ExerciseSessionRecord, route: android.health.connect.datatypes.ExerciseRoute) =
        withContext(Dispatchers.IO) {
            val token = TokenStore(context).load() ?: error("Jeton API absent")
            ApiClient(token).send(listOf(RecordJson.encode(session, "exercise_session", route)))
            prefs.edit().putBoolean(key(session.metadata.id), true).apply()
        }

    private fun isImported(id: String): Boolean = prefs.getBoolean(key(id), false)
    private fun key(id: String) = "route_$id"

    private suspend fun readSessions(maxSessions: Int?): List<ExerciseSessionRecord> {
        val sessions = mutableListOf<ExerciseSessionRecord>()
        var pageToken = -1L
        do {
            val builder = ReadRecordsRequestUsingFilters.Builder(ExerciseSessionRecord::class.java)
                .setTimeRangeFilter(TimeInstantRangeFilter.Builder()
                    .setStartTime(IMPORT_FROM)
                    .setEndTime(Instant.now().plusSeconds(60))
                    .build())
                .setAscending(maxSessions == null)
                .setPageSize(maxSessions ?: 500)
            if (pageToken != -1L) builder.setPageToken(pageToken)
            val response = read(builder.build())
            sessions += response.records
            pageToken = response.nextPageToken
        } while (maxSessions == null && pageToken != -1L)
        return sessions
    }

    private suspend fun read(request: ReadRecordsRequestUsingFilters<ExerciseSessionRecord>): ReadRecordsResponse<ExerciseSessionRecord> =
        suspendCancellableCoroutine { continuation ->
            manager.readRecords(request, executor,
                object : android.os.OutcomeReceiver<ReadRecordsResponse<ExerciseSessionRecord>, HealthConnectException> {
                    override fun onResult(result: ReadRecordsResponse<ExerciseSessionRecord>) = continuation.resume(result)
                    override fun onError(error: HealthConnectException) = continuation.resumeWithException(error)
                })
        }

    companion object {
        private const val TAG = "ExerciseRouteImporter"
        private val IMPORT_FROM = Instant.parse("2026-06-15T00:00:00Z")
        // Health Connect currently reports the route-access boundary as 2026-08-13.
        // Use the following UTC day to avoid sessions that straddle the exact boundary.
        private val ROUTE_CONSENT_FROM = Instant.parse("2026-08-14T00:00:00Z")
    }
}
