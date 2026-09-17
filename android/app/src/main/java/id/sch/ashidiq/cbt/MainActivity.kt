package id.sch.ashidiq.cbt

import android.annotation.SuppressLint
import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.http.SslError
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.SslErrorHandler
import android.webkit.JsPromptResult
import android.webkit.JsResult
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {
  private lateinit var web: WebView
  private var examMode = false
  private lateinit var dpm: DevicePolicyManager
  private lateinit var admin: ComponentName
  private val guard = Handler(Looper.getMainLooper())
  private val guardRun = object : Runnable {
    override fun run() {
      if (examMode && hasWindowFocus()) immersive()
      guard.postDelayed(this, 500)
    }
  }
  private val allowed = listOf("docs.google.com", "drive.google.com", "accounts.google.com", "myaccount.google.com", "apis.google.com", "gstatic.com", "ssl.gstatic.com", "fonts.gstatic.com", "cdnjs.cloudflare.com", "cdn.tailwindcss.com", "cdn.sheetjs.com", "smpmuashidiq.sch.id", "images.unsplash.com", "script.google.com", "script.googleusercontent.com")

  inner class Bridge(private val act: Activity) {
    @JavascriptInterface
    fun setExamMode(on: Boolean) {
      act.runOnUiThread { setExamModeInternal(on) }
    }

    // Re-entry lock: true = app pernah keluar saat examMode → web wajib minta kode unlock admin
    @JavascriptInterface
    fun shouldRequireReentryCode(): Boolean = try {
      act.getSharedPreferences(PREFS, MODE_PRIVATE).getBoolean("escape_pending", false)
    } catch (e: Exception) { false }

    @JavascriptInterface
    fun clearReentryFlag() {
      try { act.getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("escape_pending", false).apply() } catch (e: Exception) { }
    }
  }

  companion object { private const val PREFS = "cbt_lock" }

  private fun isOwner(): Boolean = try { dpm.isDeviceOwnerApp(packageName) } catch (e: Exception) { false }

  @SuppressLint("SetJavaScriptEnabled")
  override fun onCreate(b: Bundle?) {
    super.onCreate(b)
    setContentView(R.layout.activity_main)
    dpm = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
    admin = ComponentName(this, AdminReceiver::class.java)
    // ponytail: non-owner = best-effort pin + foreground guard; upgrade = dpm set-device-owner per HP untuk lock penuh.
    try { if (isOwner()) dpm.setLockTaskPackages(admin, arrayOf(packageName)) } catch (e: Exception) { }
    window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
    web = findViewById(R.id.web)
    web.settings.apply {
      javaScriptEnabled = true
      domStorageEnabled = true
      allowFileAccess = false
      allowContentAccess = false
      allowUniversalAccessFromFileURLs = false
      setSupportMultipleWindows(true)
      javaScriptCanOpenWindowsAutomatically = true
      mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
      cacheMode = WebSettings.LOAD_DEFAULT
    }
    web.webChromeClient = object : WebChromeClient() {
      override fun onJsAlert(v: WebView, url: String, msg: String, res: JsResult): Boolean {
        try { android.app.AlertDialog.Builder(v.context).setMessage(msg).setPositiveButton("OK") { d, _ -> res.confirm(); d.dismiss() }.setCancelable(false).show() } catch (e: Exception) { res.confirm() }
        return true
      }
      override fun onJsConfirm(v: WebView, url: String, msg: String, res: JsResult): Boolean {
        try { android.app.AlertDialog.Builder(v.context).setMessage(msg).setPositiveButton("OK") { d, _ -> res.confirm(); d.dismiss() }.setNegativeButton("Batal") { d, _ -> res.cancel(); d.dismiss() }.setCancelable(false).show() } catch (e: Exception) { res.cancel() }
        return true
      }
      override fun onJsPrompt(v: WebView, url: String, msg: String, def: String, res: JsPromptResult): Boolean {
        try {
          val inp = EditText(v.context)
          android.app.AlertDialog.Builder(v.context).setMessage(msg).setView(inp).setPositiveButton("OK") { d, _ -> res.confirm(inp.text.toString()); d.dismiss() }.setNegativeButton("Batal") { d, _ -> res.cancel(); d.dismiss() }.setCancelable(false).show()
        } catch (e: Exception) { res.cancel() }
        return true
      }
      override fun onCreateWindow(v: WebView, dia: Boolean, user: Boolean, res: android.os.Message): Boolean {
        // Popup login Google: dialog fullscreen + settings lengkap agar tidak blank/kecil
        val nv = WebView(v.context)
        nv.settings.apply {
          javaScriptEnabled = true
          domStorageEnabled = true
          setSupportMultipleWindows(true)
          javaScriptCanOpenWindowsAutomatically = true
          loadWithOverviewMode = true
          useWideViewPort = true
          builtInZoomControls = true
          displayZoomControls = false
        }
        nv.webViewClient = v.webViewClient
        val d = android.app.Dialog(v.context, android.R.style.Theme_NoTitleBar_Fullscreen)
        d.setContentView(nv)
        nv.webChromeClient = object : WebChromeClient() {
          override fun onCloseWindow(w: WebView) { try { d.dismiss() } catch (e: Exception) { } }
        }
        d.show()
        (res.obj as WebView.WebViewTransport).webView = nv
        res.sendToTarget()
        return true
      }
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
    if (b == null) {
      val webUrl = BuildConfig.WEB_URL
      if (webUrl.contains("HOSTING-KAMU")) web.loadUrl("file:///android_asset/index.html?api=" + BuildConfig.API_URL)
      else web.loadUrl(webUrl)
    } else web.restoreState(b)
    guard.post(guardRun)
  }

  private fun setExcludeRecents(ex: Boolean) {
    try {
      val am = getSystemService(Context.ACTIVITY_SERVICE) as android.app.ActivityManager
      am.appTasks.forEach { it.setExcludeFromRecents(ex) }
    } catch (e: Exception) { }
  }

  override fun onStop() {
    // App keluar dari layar saat examMode (home/overview/app lain/screen off) → tandai re-entry lock
    if (examMode) {
      try { getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("escape_pending", true).apply() } catch (e: Exception) { }
    }
    super.onStop()
  }

  override fun onSaveInstanceState(o: Bundle) { try { web.saveState(o) } catch (e: Exception) { }; super.onSaveInstanceState(o) }

  private fun setExamModeInternal(on: Boolean) {
    examMode = on
    setExcludeRecents(on) // sembunyikan dari Recent Apps selama ujian
    try {
      if (!on) { try { getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("escape_pending", false).apply() } catch (e: Exception) { } }
      if (on) {
        if (isOwner()) { try { dpm.setStatusBarDisabled(admin, true) } catch (e: Exception) { }; try { dpm.setKeyguardDisabled(admin, true) } catch (e: Exception) { } }
        try { startLockTask() } catch (e: Exception) { }
      } else {
        try { stopLockTask() } catch (e: Exception) { }
        if (isOwner()) { try { dpm.setStatusBarDisabled(admin, false) } catch (e: Exception) { }; try { dpm.setKeyguardDisabled(admin, false) } catch (e: Exception) { } }
      }
    } catch (e: Exception) { }
    immersive()
  }

  private fun bringBack() {
    try {
      val i = Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP)
      startActivity(i)
    } catch (e: Exception) { }
  }

  private fun immersive() {
    @Suppress("DEPRECATION")
    window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_FULLSCREEN or View.SYSTEM_UI_FLAG_LAYOUT_STABLE or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN)
  }

  override fun onWindowFocusChanged(f: Boolean) {
    super.onWindowFocusChanged(f)
    if (f) immersive()
  }

  override fun onResume() {
    super.onResume()
    immersive()
  }

  override fun onPause() {
    if (examMode) bringBack()
    super.onPause()
  }

  override fun onUserLeaveHint() {
    if (examMode) bringBack()
  }

  override fun onBackPressed() {
    if (examMode) return
    if (::web.isInitialized && web.canGoBack()) web.goBack() else super.onBackPressed()
  }

  override fun onDestroy() {
    try { guard.removeCallbacks(guardRun) } catch (e: Exception) { }
    super.onDestroy()
  }
}
