package id.sch.ashidiq.cbt

import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object Api {
  fun post(apiUrl: String, payload: JSONObject): JSONObject {
    val c = (URL(apiUrl).openConnection() as HttpURLConnection).apply {
      requestMethod = "POST"; connectTimeout = 15000; readTimeout = 20000
      setRequestProperty("Content-Type", "application/json")
      doOutput = true
    }
    c.outputStream.use { it.write(payload.toString().toByteArray()) }
    val txt = try { c.inputStream.bufferedReader().readText() } catch (e: Exception) { c.errorStream?.bufferedReader()?.readText() ?: throw e }
    return JSONObject(txt)
  }
}
