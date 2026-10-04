package org.pizid.healthconnectsync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

class SyncWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result = runCatching {
        SyncEngine(applicationContext).run()
        Log.i("HealthSyncWorker", "Background sync completed")
        Result.success()
    }.getOrElse {
        Log.e("HealthSyncWorker", "Background sync failed", it)
        Result.retry()
    }
}
