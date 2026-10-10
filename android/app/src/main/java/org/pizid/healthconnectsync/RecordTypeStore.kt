package org.pizid.healthconnectsync

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

/**
 * Disk-backed index used to recover the record type attached to a Health
 * Connect deletion. Deletion log entries only contain the record id.
 *
 * This used to live in SharedPreferences. That made each new record copy the
 * full in-memory preferences map and eventually exhausted the app heap.
 */
class RecordTypeStore(context: Context) : SQLiteOpenHelper(
    context.applicationContext,
    DATABASE_NAME,
    null,
    DATABASE_VERSION
) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE record_types (
                record_id TEXT PRIMARY KEY,
                record_type TEXT NOT NULL
            )
            """.trimIndent()
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) = Unit

    fun rememberAll(records: Collection<Pair<String, String>>) {
        if (records.isEmpty()) return
        writableDatabase.inTransaction {
            val values = ContentValues(2)
            records.forEach { (id, type) ->
                values.clear()
                values.put("record_id", id)
                values.put("record_type", type)
                insertWithOnConflict("record_types", null, values, SQLiteDatabase.CONFLICT_REPLACE)
            }
        }
    }

    fun recall(id: String): String? = readableDatabase.query(
        "record_types",
        arrayOf("record_type"),
        "record_id = ?",
        arrayOf(id),
        null,
        null,
        null,
        "1"
    ).use { cursor ->
        if (cursor.moveToFirst()) cursor.getString(0) else null
    }

    private inline fun SQLiteDatabase.inTransaction(block: SQLiteDatabase.() -> Unit) {
        beginTransaction()
        try {
            block()
            setTransactionSuccessful()
        } finally {
            endTransaction()
        }
    }

    companion object {
        private const val DATABASE_NAME = "record_types.db"
        private const val DATABASE_VERSION = 1
    }
}
