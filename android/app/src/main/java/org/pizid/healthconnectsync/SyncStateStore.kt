package org.pizid.healthconnectsync

import android.content.Context
import android.content.SharedPreferences
import android.util.Xml
import org.xmlpull.v1.XmlPullParser
import java.io.File
import java.io.FileInputStream

/**
 * Keeps the small synchronization state separate from the record type index.
 *
 * Version 1 stored both in sync_state.xml. migrateLegacy() streams that file
 * so the 20+ MB map is never materialized in memory, imports record mappings
 * into SQLite, then preserves the old XML as a private recovery copy.
 */
object SyncStateStore {
    const val PREFS_NAME = "sync_state_v2"
    private const val LEGACY_PREFS_NAME = "sync_state"
    private const val PREF_MIGRATED = "legacy_state_migrated"
    private const val TYPE_PREFIX = "record_type_"
    private const val IMPORT_BATCH_SIZE = 1_000

    fun preferences(context: Context): SharedPreferences =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    @Synchronized
    fun migrateLegacy(context: Context, recordTypes: RecordTypeStore) {
        val appContext = context.applicationContext
        val prefs = preferences(appContext)
        if (prefs.getBoolean(PREF_MIGRATED, false)) return

        val legacy = File(appContext.applicationInfo.dataDir, "shared_prefs/$LEGACY_PREFS_NAME.xml")
        if (!legacy.isFile) {
            check(prefs.edit().putBoolean(PREF_MIGRATED, true).commit()) {
                "Impossible d'initialiser l'état de synchronisation"
            }
            return
        }

        val editor = prefs.edit()
        val mappings = ArrayList<Pair<String, String>>(IMPORT_BATCH_SIZE)
        FileInputStream(legacy).use { input ->
            val parser = Xml.newPullParser().apply {
                setInput(input, Charsets.UTF_8.name())
            }
            while (parser.next() != XmlPullParser.END_DOCUMENT) {
                if (parser.eventType != XmlPullParser.START_TAG || parser.name == "map") continue
                val key = parser.getAttributeValue(null, "name") ?: continue
                when (parser.name) {
                    "string" -> {
                        val value = parser.nextText()
                        if (key.startsWith(TYPE_PREFIX)) {
                            mappings += key.removePrefix(TYPE_PREFIX) to value
                            if (mappings.size >= IMPORT_BATCH_SIZE) {
                                recordTypes.rememberAll(mappings)
                                mappings.clear()
                            }
                        } else if (isSyncStateKey(key)) {
                            editor.putString(key, value)
                        }
                    }
                    "boolean" -> if (isSyncStateKey(key)) {
                        editor.putBoolean(key, parser.getAttributeValue(null, "value").toBoolean())
                    }
                    "long" -> if (isSyncStateKey(key)) {
                        parser.getAttributeValue(null, "value")?.toLongOrNull()?.let { editor.putLong(key, it) }
                    }
                    "int" -> if (isSyncStateKey(key)) {
                        parser.getAttributeValue(null, "value")?.toIntOrNull()?.let { editor.putInt(key, it) }
                    }
                    "float" -> if (isSyncStateKey(key)) {
                        parser.getAttributeValue(null, "value")?.toFloatOrNull()?.let { editor.putFloat(key, it) }
                    }
                }
            }
        }
        recordTypes.rememberAll(mappings)
        editor.putBoolean(PREF_MIGRATED, true)
        check(editor.commit()) { "Impossible de migrer l'état de synchronisation" }

        val backup = File(appContext.filesDir, "sync_state_legacy_backup.xml")
        if (!backup.exists()) legacy.renameTo(backup)
        File(legacy.parentFile, "$LEGACY_PREFS_NAME.xml.bak").takeIf(File::exists)?.let { legacyBackup ->
            val backupOfBackup = File(appContext.filesDir, "sync_state_legacy_backup.xml.bak")
            if (!backupOfBackup.exists()) legacyBackup.renameTo(backupOfBackup)
        }
    }

    private fun isSyncStateKey(key: String): Boolean =
        key == "change_token" ||
            key == "baseline_complete" ||
            key.startsWith("baseline_complete_") ||
            key == "repair_sleep_oxygen_v1" ||
            key == SyncEngine.PREF_LAST_SUCCESS
}
