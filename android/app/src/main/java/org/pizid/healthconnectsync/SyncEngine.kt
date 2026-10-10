package org.pizid.healthconnectsync

import android.content.Context
import android.content.pm.PackageManager
import android.health.connect.HealthConnectException
import android.health.connect.HealthConnectManager
import android.health.connect.ReadRecordsRequestUsingFilters
import android.health.connect.ReadRecordsResponse
import android.health.connect.TimeInstantRangeFilter
import android.health.connect.changelog.ChangeLogTokenRequest
import android.health.connect.changelog.ChangeLogTokenResponse
import android.health.connect.changelog.ChangeLogsRequest
import android.health.connect.changelog.ChangeLogsResponse
import android.health.connect.datatypes.Record
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.time.Instant
import java.util.concurrent.Executor
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

data class SyncResult(val records: Int, val deletions: Int, val message: String)

class SyncEngine(private val context: Context) {
    private val manager = context.getSystemService(HealthConnectManager::class.java)
        ?: error("Health Connect indisponible")
    private val prefs = SyncStateStore.preferences(context)
    private val recordTypes = RecordTypeStore(context)
    private val executor = Executor { command -> command.run() }

    suspend fun run(onProgress: (SyncProgress) -> Unit = {}): SyncResult {
        val requestedAt = System.currentTimeMillis()
        return RUN_MUTEX.withLock {
        withContext(Dispatchers.IO) {
        SyncStateStore.migrateLegacy(context, recordTypes)
        fun report(message: String, percent: Int, active: Boolean = true, indeterminate: Boolean = false) {
            val progress = SyncProgress(message, percent.coerceIn(0, 100), active, indeterminate)
            SyncMonitor.publish(progress)
            onProgress(progress)
        }

        try {
        if (prefs.getLong(PREF_LAST_SUCCESS, 0L) >= requestedAt) {
            report("✓ La tâche horaire vient de terminer la synchronisation", 100, active = false)
            return@withContext SyncResult(0, 0, "Déjà synchronisé par la tâche horaire")
        }

        report("Connexion au serveur…", 3, indeterminate = true)
        val token = TokenStore(context).load() ?: error("Jeton API absent")
        val api = ApiClient(token)
        api.status()
        report("Connexion établie · Lecture des autorisations…", 8)
        val specs = RecordCatalog.all.filter(::canRead)
        if (specs.isEmpty()) error("Aucune autorisation Health Connect accordée")

        var cursor = prefs.getString("change_token", null)
        if (cursor == null) {
            report("Création du point de reprise…", 12, indeterminate = true)
            cursor = newChangeToken(specs)
            prefs.edit().putString("change_token", cursor).apply()
        }

        var recordCount = 0
        if (!prefs.getBoolean("baseline_complete", false)) {
            var completedTypes = specs.count { prefs.getBoolean("baseline_complete_${it.type}", false) }
            for ((index, spec) in specs.withIndex()) {
                val checkpoint = "baseline_complete_${spec.type}"
                if (prefs.getBoolean(checkpoint, false)) continue
                val percent = 15 + (completedTypes * 55 / specs.size.coerceAtLeast(1))
                report("Import initial ${index + 1}/${specs.size} · ${spec.type}", percent)
                recordCount += readBaseline(spec, api)
                // A full baseline may exceed Android's execution window. Persist
                // each completed type so a retry resumes instead of starting over.
                prefs.edit().putBoolean(checkpoint, true).commit()
                completedTypes++
            }
            prefs.edit().putBoolean("baseline_complete", true).commit()
        }

        report("Recherche des nouvelles données…", 75, indeterminate = true)
        var changePage = 0
        val changes = readChanges(cursor, api) { records, deletions, hasMore ->
            changePage++
            val percent = if (hasMore) minOf(96, 75 + changePage) else 98
            report(
                "Synchronisation · $records donnée(s), $deletions suppression(s)",
                percent,
                indeterminate = hasMore && changePage == 1
            )
        }
        recordCount += changes.first

        // Version 0.4.1 fixed stale Health Connect deletions, but sleep sessions
        // that had already been deleted server-side must be read once from the
        // current Health Connect state to restore their stages. Include oxygen
        // saturation in the same lightweight repair so any currently available
        // SpO2 records are recovered as well.
        if (!prefs.getBoolean(PREF_SLEEP_OXYGEN_REPAIR_V1, false)) {
            report("Réparation du sommeil et de l’oxygène sanguin…", 98, indeterminate = true)
            val repairTypes = setOf("sleep_session", "oxygen_saturation")
            for (spec in specs.filter { it.type in repairTypes }) {
                recordCount += readBaseline(spec, api, REPAIR_START)
            }
            prefs.edit().putBoolean(PREF_SLEEP_OXYGEN_REPAIR_V1, true).commit()
        }
        prefs.edit().putLong(PREF_LAST_SUCCESS, System.currentTimeMillis()).commit()
        report("✓ Synchronisation terminée · $recordCount donnée(s), ${changes.second} suppression(s)", 100, active = false)
        SyncResult(recordCount, changes.second, "$recordCount enregistrements, ${changes.second} suppressions")
        } catch (error: Throwable) {
            report("Échec : ${error.message ?: error.javaClass.simpleName}", 0, active = false)
            throw error
        }
        }
        }
    }

    private fun canRead(spec: RecordSpec): Boolean {
        if (spec.type == "symptom") {
            return PermissionCatalog.symptomReadPermissions.any {
                ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED
            }
        }
        return ContextCompat.checkSelfPermission(context, spec.permission) == PackageManager.PERMISSION_GRANTED
    }

    private suspend fun readBaseline(
        spec: RecordSpec,
        api: ApiClient,
        start: Instant = BASELINE_START
    ): Int {
        var pageToken = -1L
        var total = 0
        do {
            val builder = ReadRecordsRequestUsingFilters.Builder(spec.recordClass)
                .setTimeRangeFilter(TimeInstantRangeFilter.Builder()
                    .setStartTime(start)
                    .setEndTime(Instant.now().plusSeconds(60))
                    .build())
                .setPageSize(500)
            if (pageToken != -1L) builder.setPageToken(pageToken)
            val response = readRecords(builder.build())
            recordTypes.rememberAll(response.records.map { it.metadata.id to spec.type })
            val encoded = response.records.map { record ->
                RecordJson.encode(record, spec.type)
            }
            sendChunked(api, encoded)
            total += encoded.size
            pageToken = response.nextPageToken
        } while (pageToken != -1L)
        return total
    }

    private suspend fun readChanges(
        initialToken: String,
        api: ApiClient,
        onPage: (records: Int, deletions: Int, hasMore: Boolean) -> Unit
    ): Pair<Int, Int> {
        var cursor = initialToken
        var records = 0
        var deletions = 0
        do {
            val response = getChangeLogs(ChangeLogsRequest.Builder(cursor).setPageSize(500).build())
            val typedRecords = response.upsertedRecords.map { record ->
                val type = RecordCatalog.all.firstOrNull { it.recordClass == record.javaClass }?.type
                    ?: classToType(record.javaClass.simpleName)
                record to type
            }
            recordTypes.rememberAll(typedRecords.map { (record, type) -> record.metadata.id to type })
            val encoded = typedRecords.map { (record, type) -> RecordJson.encode(record, type) }
            // Some Health Connect providers emit an old deletion and the current
            // version of the same logical record in one change page. The upsert is
            // authoritative; forwarding both lets the later deletion hide it again.
            val upsertedIds = response.upsertedRecords.mapTo(HashSet()) { it.metadata.id }
            val deleted = response.deletedLogs
                .filterNot { it.deletedRecordId in upsertedIds }
                .map { deletedLog ->
                JSONObject()
                    .put("type", recordTypes.recall(deletedLog.deletedRecordId) ?: "unknown")
                    .put("id", deletedLog.deletedRecordId)
                    .put("deleted_at", deletedLog.deletedTime.toString())
                }
            val next = response.nextChangesToken
            if (encoded.isEmpty() && deleted.isEmpty()) {
                api.send(emptyList(), cursor = next)
            } else {
                sendChunked(api, encoded, deleted, if (!response.hasMorePages()) next else null)
            }
            records += encoded.size
            deletions += deleted.size
            cursor = next
            prefs.edit().putString("change_token", cursor).apply()
            onPage(records, deletions, response.hasMorePages())
        } while (response.hasMorePages())
        return records to deletions
    }

    private fun sendChunked(
        api: ApiClient,
        records: List<JSONObject>,
        deletions: List<JSONObject> = emptyList(),
        finalCursor: String? = null
    ) {
        if (records.isEmpty() && deletions.isEmpty() && finalCursor == null) return
        val recordChunks = records.chunked(100).ifEmpty { listOf(emptyList()) }
        val deletionChunks = deletions.chunked(500).ifEmpty { listOf(emptyList()) }
        val count = maxOf(recordChunks.size, deletionChunks.size)
        repeat(count) { index ->
            api.send(
                recordChunks.getOrElse(index) { emptyList() },
                deletionChunks.getOrElse(index) { emptyList() },
                if (index == count - 1) finalCursor else null
            )
        }
    }

    private suspend fun newChangeToken(specs: List<RecordSpec>): String =
        suspendCancellableCoroutine { continuation ->
            val request = ChangeLogTokenRequest.Builder().also { builder ->
                specs.forEach { builder.addRecordType(it.recordClass) }
            }.build()
            manager.getChangeLogToken(request, executor, outcome(
                { continuation.resume(it.token) },
                { continuation.resumeWithException(it) }
            ))
        }

    private suspend fun readRecords(request: ReadRecordsRequestUsingFilters<out Record>): ReadRecordsResponse<out Record> =
        suspendCancellableCoroutine { continuation ->
            @Suppress("UNCHECKED_CAST")
            manager.readRecords(request as ReadRecordsRequestUsingFilters<Record>, executor, outcome(
                { continuation.resume(it) },
                { continuation.resumeWithException(it) }
            ))
        }

    private suspend fun getChangeLogs(request: ChangeLogsRequest): ChangeLogsResponse =
        suspendCancellableCoroutine { continuation ->
            manager.getChangeLogs(request, executor, outcome(
                { continuation.resume(it) },
                { continuation.resumeWithException(it) }
            ))
        }

    private fun <T> outcome(success: (T) -> Unit, failure: (HealthConnectException) -> Unit) =
        object : android.os.OutcomeReceiver<T, HealthConnectException> {
            override fun onResult(result: T) = success(result)
            override fun onError(error: HealthConnectException) = failure(error)
        }

    private fun classToType(simpleName: String): String = simpleName.removeSuffix("Record")
        .replace(Regex("([a-z0-9])([A-Z])"), "$1_$2").lowercase()

    companion object {
        const val PREF_LAST_SUCCESS = "last_success_epoch_ms"
        private const val PREF_SLEEP_OXYGEN_REPAIR_V1 = "repair_sleep_oxygen_v1"
        private val RUN_MUTEX = Mutex()
        private val BASELINE_START: Instant = Instant.parse("2026-09-11T00:00:00Z")
        private val REPAIR_START: Instant = Instant.parse("2026-09-10T00:00:00Z")
    }
}
