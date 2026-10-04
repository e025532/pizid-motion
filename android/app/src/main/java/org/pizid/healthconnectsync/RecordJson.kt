package org.pizid.healthconnectsync

import android.health.connect.datatypes.InstantRecord
import android.health.connect.datatypes.IntervalRecord
import android.health.connect.datatypes.ExerciseRoute
import android.health.connect.datatypes.Record
import org.json.JSONArray
import org.json.JSONObject
import java.lang.reflect.Method
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.util.Locale

object RecordJson {
    private val collectionProperties = setOf("samples", "stages", "deltas", "laps", "segments")

    fun encode(record: Record, type: String, route: ExerciseRoute? = null): JSONObject {
        val metadata = record.metadata
        val json = JSONObject()
            .put("type", type)
            .put("id", metadata.id)
            .put("last_modified_at", metadata.lastModifiedTime.toString())
            .put("recording_method", metadata.recordingMethod)
            .put("client_record_version", metadata.clientRecordVersion.toString())
            .put("source", JSONObject()
                .put("package_name", metadata.dataOrigin.packageName)
                .put("device", encodeAny(metadata.device, 0)))

        metadata.clientRecordId?.let { json.put("client_record_id", it) }
        when (record) {
            is InstantRecord -> json
                .put("kind", "instant")
                .put("start_at", record.time.toString())
                .put("end_at", record.time.toString())
                .put("start_zone_offset_seconds", record.zoneOffset.totalSeconds)
                .put("end_zone_offset_seconds", record.zoneOffset.totalSeconds)
            is IntervalRecord -> json
                .put("kind", "interval")
                .put("start_at", record.startTime.toString())
                .put("end_at", record.endTime.toString())
                .put("start_zone_offset_seconds", record.startZoneOffset.totalSeconds)
                .put("end_zone_offset_seconds", record.endZoneOffset.totalSeconds)
        }

        getter(record, "localDate")?.let { if (it is LocalDate) json.put("local_date", it.toString()) }
        json.put("payload", encodeObject(record, setOf(
            "metadata", "recordType", "time", "zoneOffset", "startTime", "endTime",
            "startZoneOffset", "endZoneOffset", "localDate"
        ) + collectionProperties, 0))

        val samples = JSONArray()
        for (property in collectionProperties) {
            val values = getter(record, property) as? Collection<*> ?: continue
            values.filterNotNull().forEach { samples.put(encodeSample(it, type, property)) }
        }
        route?.routeLocations?.forEach { samples.put(encodeSample(it, type, "route")) }
        if (samples.length() > 0) json.put("samples", samples)
        return json
    }

    private fun encodeSample(sample: Any, parentType: String, property: String): JSONObject {
        val sampleType = when (property) {
            "stages" -> "sleep_stage"
            "deltas" -> "skin_temperature_delta"
            "laps" -> "exercise_lap"
            "segments" -> "exercise_segment"
            "route" -> "exercise_route_location"
            else -> parentType
        }
        val json = encodeObject(sample, emptySet(), 0).put("type", sampleType)
        (getter(sample, "time") as? Instant)?.let { json.put("at", it.toString()) }
        (getter(sample, "startTime") as? Instant)?.let { json.put("start_at", it.toString()) }
        (getter(sample, "endTime") as? Instant)?.let { json.put("end_at", it.toString()) }
        listOf("beatsPerMinute", "rate", "revolutionsPerMinute", "power", "speed", "temperatureDelta")
            .firstNotNullOfOrNull { numeric(getter(sample, it)) }
            ?.let { json.put("value", it) }
        listOf("stage", "type").firstNotNullOfOrNull { (getter(sample, it) as? Number)?.toInt() }
            ?.let { json.put("category", it) }
        (getter(sample, "latitude") as? Number)?.let { json.put("latitude", it.toDouble()) }
        (getter(sample, "longitude") as? Number)?.let { json.put("longitude", it.toDouble()) }
        numeric(getter(sample, "altitude"))?.let { json.put("altitude_m", it) }
        return json
    }

    private fun encodeObject(value: Any, excluded: Set<String>, depth: Int): JSONObject {
        val json = JSONObject()
        if (depth > 5) return json.put("text", value.toString())
        value.javaClass.methods
            .asSequence()
            .filter(::isGetter)
            .map { method -> propertyName(method) to method }
            .filter { (name, _) -> name !in excluded && name != "class" }
            .sortedBy { it.first }
            .forEach { (name, method) ->
                runCatching { method.invoke(value) }.getOrNull()?.let {
                    json.put(name, encodeAny(it, depth + 1))
                }
            }
        return json
    }

    private fun encodeAny(value: Any?, depth: Int): Any = when (value) {
        null -> JSONObject.NULL
        is String, is Boolean, is Int, is Long, is Float, is Double -> value
        is Number -> value.toDouble()
        is Instant, is LocalDate, is ZoneOffset, is Enum<*> -> value.toString()
        is Collection<*> -> JSONArray().also { array -> value.forEach { array.put(encodeAny(it, depth + 1)) } }
        is Map<*, *> -> JSONObject().also { obj -> value.forEach { (k, v) -> obj.put(k.toString(), encodeAny(v, depth + 1)) } }
        else -> if (depth > 5 || value.javaClass.name.startsWith("java.")) value.toString()
                else encodeObject(value, emptySet(), depth)
    }

    private fun numeric(value: Any?): Double? {
        if (value is Number) return value.toDouble()
        value ?: return null
        val preferred = listOf("getInWatts", "getInMetersPerSecond", "getInMeters", "getInCelsius", "getInGrams", "getInLiters")
        for (name in preferred) {
            val result = runCatching { value.javaClass.getMethod(name).invoke(value) }.getOrNull()
            if (result is Number) return result.toDouble()
        }
        return null
    }

    private fun getter(target: Any, property: String): Any? {
        val capitalized = property.replaceFirstChar { it.titlecase(Locale.US) }
        return listOf("get$capitalized", "is$capitalized")
            .firstNotNullOfOrNull { name -> runCatching { target.javaClass.getMethod(name).invoke(target) }.getOrNull() }
    }

    private fun isGetter(method: Method): Boolean =
        method.parameterCount == 0 && method.returnType != Void.TYPE &&
            (method.name.startsWith("get") || method.name.startsWith("is")) &&
            method.name !in setOf("getClass", "getMetadata", "getRecordType", "getRoute")

    private fun propertyName(method: Method): String {
        val raw = if (method.name.startsWith("get")) method.name.drop(3) else method.name.drop(2)
        val camel = raw.replaceFirstChar { it.lowercase(Locale.US) }
        return camel.replace(Regex("([a-z0-9])([A-Z])"), "$1_$2").lowercase(Locale.US)
    }
}
