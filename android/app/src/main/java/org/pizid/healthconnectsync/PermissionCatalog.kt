package org.pizid.healthconnectsync

import android.health.connect.HealthPermissions

object PermissionCatalog {
    val symptomReadPermissions: Set<String> by lazy {
        HealthPermissions::class.java.fields
            .filter { it.name.startsWith("READ_SYMPTOM_") }
            .mapNotNull { runCatching { it.get(null) as String }.getOrNull() }
            .toSet()
    }

    val recordReadPermissions: Set<String> by lazy {
        HealthPermissions::class.java.fields
            .filter {
                it.name.startsWith("READ_") &&
                    !it.name.startsWith("READ_MEDICAL_DATA_") &&
                    it.name !in setOf(
                        "READ_HEALTH_DATA_IN_BACKGROUND",
                        "READ_HEALTH_DATA_HISTORY",
                        "READ_EXERCISE_ROUTE",
                        "READ_EXERCISE_ROUTES"
                    )
            }
            .mapNotNull { runCatching { it.get(null) as String }.getOrNull() }
            .toSet()
    }

    val requestedPermissions: Array<String> by lazy {
        (recordReadPermissions + symptomReadPermissions + setOf(
            HealthPermissions.READ_HEALTH_DATA_IN_BACKGROUND,
            HealthPermissions.READ_HEALTH_DATA_HISTORY,
            HealthPermissions.READ_EXERCISE_ROUTES
        )).sorted().toTypedArray()
    }
}
