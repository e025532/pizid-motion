package org.pizid.healthconnectsync

import android.util.Base64
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.time.Instant
import java.util.UUID

class ApiClient(private val token: String) {
    fun status(): JSONObject = request("GET", "/sync/status", null)

    fun uploadRenpho(filename: String, mimeType: String?, content: ByteArray): JSONObject = request(
        "POST",
        "/sync/renpho",
        JSONObject()
            .put("filename", filename)
            .put("mime_type", mimeType ?: "application/octet-stream")
            .put("content_base64", Base64.encodeToString(content, Base64.NO_WRAP))
    )

    fun send(
        records: List<JSONObject>,
        deletions: List<JSONObject> = emptyList(),
        cursor: String? = null
    ): JSONObject {
        val body = JSONObject()
            .put("batch_id", UUID.randomUUID().toString())
            .put("sent_at", Instant.now().toString())
            .put("records", JSONArray(records))
            .put("deletions", JSONArray(deletions))
        cursor?.let { body.put("cursors", JSONObject().put("all_records", it)) }
        return request("POST", "/sync/batches", body)
    }

    private fun request(method: String, path: String, body: JSONObject?): JSONObject {
        var lastError: Throwable? = null
        repeat(6) { attempt ->
            try {
                val connection = URL(BuildConfig.API_BASE_URL + path).openConnection() as HttpURLConnection
                connection.requestMethod = method
                connection.connectTimeout = 20_000
                connection.readTimeout = 120_000
                connection.setRequestProperty("Authorization", "Bearer $token")
                connection.setRequestProperty("Accept", "application/json")
                if (body != null) {
                    connection.doOutput = true
                    connection.setRequestProperty("Content-Type", "application/json")
                    connection.outputStream.bufferedWriter().use { it.write(body.toString()) }
                }
                val code = connection.responseCode
                val text = (if (code in 200..299) connection.inputStream else connection.errorStream)
                    ?.bufferedReader()?.use { it.readText() }.orEmpty()
                connection.disconnect()
                if (code !in 200..299) error("API HTTP $code: ${text.take(500)}")
                return if (text.isBlank()) JSONObject() else JSONObject(text)
            } catch (error: Throwable) {
                lastError = error
                if (attempt < 5) Thread.sleep(minOf(15_000L, 1_000L shl attempt))
            }
        }
        throw lastError ?: IllegalStateException("Echec API")
    }
}
