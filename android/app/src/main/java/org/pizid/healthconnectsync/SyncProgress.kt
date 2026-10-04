package org.pizid.healthconnectsync

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

data class SyncProgress(
    val message: String,
    val percent: Int,
    val active: Boolean,
    val indeterminate: Boolean = false
)

object SyncMonitor {
    private val mutableProgress = MutableStateFlow<SyncProgress?>(null)
    val progress: StateFlow<SyncProgress?> = mutableProgress.asStateFlow()

    fun publish(progress: SyncProgress) {
        mutableProgress.value = progress
    }
}
