package id.sch.ashidiq.cbt

import android.annotation.SuppressLint
import android.app.Activity
import android.net.http.SslError
import android.os.Bundle
import android.view.View
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.SslErrorHandler
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {
  private lateinit var web: WebView
  private var examMode = false
  private val allowed = listOf("docs.google.com", "drive.google.com", "accounts.google.com", "ssl.gstatic.com", "fonts.gstatic.com", "cdnjs.cloudflare.com", "cdn.tailwindcss.com", "smpmuashidiq.sch.id", "images.unsplash.com", "script.google.com", "script.googleusercontent.com")

  inner class Bridge(private val act: Activity) {
    @JavascriptInterface
    fun setExamMode(on: Boolean) {
      act.runOnUiThread { setExamModeInternal(on) }
    }
  }

  @SuppressLint("SetJavaScriptEnabled")
  override fun onCreate(b: Bundle?) {
    super.onCreate(b)
    setContentView(R.layout.activity_main)
    window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
    web = findViewById(R.id.web)
    web.settings.apply {
      javaScriptEnabled = true
      domStorageEnabled = true
      allowFileAccess = false
      allowContentAccess = false
      allowUniversalAccessFromFileURLs = false
      mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
      cacheMode = WebSettings.LOAD_DEFAULT
    }
    web.addJavascriptInterface(Bridge(this), "Android")
    web.webViewClient = object : WebViewClient() {
      override fun onReceivedSslError(v: WebView, h: SslErrorHandler, e: SslError) { h.cancel() }
      override fun shouldOverrideUrlLoading(v: WebView, r: WebResourceRequest): Boolean {
        val host = r.url.host ?: return true
        if (allowed.none { host == it || host.endsWith(".$it") }) return true
        if ((r.url.scheme ?: "") != "https") return true
        return false
      }
    }
    val webUrl = BuildConfig.WEB_URL
    if (webUrl.contains("HOSTING-KAMU")) web.loadUrl("file:///android_asset/index.html?api=" + BuildConfig.API_URL)
    else web.loadUrl(webUrl)
  }

  private fun setExamModeInternal(on: Boolean) {
    examMode = on
    try { if (on) startLockTask() else stopLockTask() } catch (e: Exception) { }
    immersive()
  }

  private fun immersive() {
    @Suppress("DEPRECATION")
    window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_FULLSCREEN)
  }

  override fun onWindowFocusChanged(f: Boolean) {
    super.onWindowFocusChanged(f)
    if (f) immersive()
  }

  override fun onBackPressed() {
    if (examMode) return
    if (web.canGoBack()) web.goBack() else super.onBackPressed()
  }
}
