package org.pizid.healthconnectsync

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import androidx.core.content.ContextCompat

object PrioritySyncScheduler {
    private const val REQUEST_CODE = 6201
    private const val INTERVAL_MS = 60L * 60L * 1000L

    fun scheduleNext(context: Context, delayMs: Long = INTERVAL_MS): Boolean {
        val alarmManager = context.getSystemService(AlarmManager::class.java)
        if (!alarmManager.canScheduleExactAlarms()) return false

        alarmManager.setExactAndAllowWhileIdle(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + delayMs,
            alarmIntent(context)
        )
        return true
    }

    private fun alarmIntent(context: Context): PendingIntent = PendingIntent.getBroadcast(
        context,
        REQUEST_CODE,
        Intent(context, HourlySyncReceiver::class.java).setAction("org.pizid.healthconnectsync.HOURLY_SYNC"),
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
    )
}

class HourlySyncReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        PrioritySyncScheduler.scheduleNext(context)
        ContextCompat.startForegroundService(context, Intent(context, PrioritySyncService::class.java))
    }
}

class SyncBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        PrioritySyncScheduler.scheduleNext(context)
    }
}
