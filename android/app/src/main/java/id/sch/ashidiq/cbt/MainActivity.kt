package id.sch.ashidiq.cbt

import android.annotation.SuppressLint
import android.app.Activity
import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.JsPromptResult
import android.webkit.JsResult
import android.webkit.JavascriptInterface
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.browser.customtabs.CustomTabsClient
import androidx.browser.customtabs.CustomTabsIntent
import androidx.browser.customtabs.TrustedWebUtils

/**
 * Dua mode, dipilih lewat BuildConfig.LAUNCH_MODE:
 *
 * - "twa"     → halaman ujian dibuka Chrome sebagai Trusted Web Activity. Profil & cookie Chrome
 *               ikut terpakai, jadi siswa yang sudah login Google di Chrome tidak diminta login/2FA
 *               lagi. WebView bawaan app tidak bisa begini: cookie store-nya terpisah per-app, dan
 *               Google memblokir sign-in di WebView (disallowed_useragent).
 * - "webview" → mode lama, halaman dimuat di WebView dalam app. Lockdown penuh (FLAG_SECURE,
 *               bridge JS kunci ujian, re-entry code) tapi login Google tidak bisa diandalkan.
 *
 * Kalau assetlinks.json belum terpasang atau Chrome tidak ada, TWA otomatis turun ke Custom Tab
 * (masih profil Chrome, muncul address bar) → browser default HP → terakhir WebView bawaan app.
 */
class MainActivity : AppCompatActivity() {
  private lateinit var web: WebView
  private lateinit var splash: View
  private lateinit var splashInfo: TextView
  private var floatView: View? = null
  private var floatWm: WindowManager? = null
  private var lastFormUrl: String = ""
  private val twaMode = "twa".equals(BuildConfig.LAUNCH_MODE, ignoreCase = true)
  private var tabLaunched = false
  // true = siswa sedang mengerjakan soal di Chrome Custom Tab; selama ini kunci ujian & bringBack dimatikan
  private var formTab = false
  private var openingTab = false
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

    // Dipakai web untuk menampilkan status kunci: device owner = Home/Recents/app lain diblokir penuh
    @JavascriptInterface
    fun isDeviceOwner(): Boolean = isOwner()

    @JavascriptInterface
    fun clearReentryFlag() {
      try { act.getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("escape_pending", false).apply() } catch (e: Exception) { }
    }

    // Link ujian dibuka di Chrome Custom Tab. Semua host HTTPS diizinkan agar aplikasi
    // dapat dipakai sebagai pengunci untuk Google Form, LMS, atau platform ujian lain.
    // Return false = JS pakai fallback (window.open / navigasi penuh).
    // Header REFRESH + SELESAI milik web ketutup Chrome fullscreen, jadi pasang 2 lapis:
    // 1) floating overlay native (muncul di atas Chrome) 2) tombol aksi + menu di Custom Tab.
    @JavascriptInterface
    fun openForm(url: String): Boolean {
      val u = try { android.net.Uri.parse(url) } catch (e: Exception) { null } ?: return false
      if (u.host.isNullOrBlank()) return false
      if ((u.scheme ?: "") != "https") return false
      val pkg = try { CustomTabsClient.getPackageName(act, tabPackages) } catch (e: Exception) { null } ?: return false
      val main = act as MainActivity
      return try {
        try { main.lastFormUrl = url } catch (e: Exception) { }
        main.openingTab = true
        main.formTab = true
        act.runOnUiThread {
          try {
            val refreshIntent = Intent(act, MainActivity::class.java).setAction(ACTION_REFRESH_FORM).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            val refreshPi = PendingIntent.getActivity(act, 1002, refreshIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val doneIntent = Intent(act, MainActivity::class.java).setAction(ACTION_SHOW_DONE).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            val donePi = PendingIntent.getActivity(act, 1003, doneIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val icon = try {
              val size = (48 * act.resources.displayMetrics.density).toInt()
              val bmp = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888)
              val c = android.graphics.Canvas(bmp)
              c.drawColor(0xFF15803D.toInt())
              val paint = android.graphics.Paint().apply { color = android.graphics.Color.WHITE; textSize = size * 0.6f; textAlign = android.graphics.Paint.Align.CENTER; isAntiAlias = true }
              c.drawText("✓", size / 2f, size * 0.72f, paint)
              bmp
            } catch (e: Exception) { null }
            val builder = CustomTabsIntent.Builder()
              .setShowTitle(true)
              .setUrlBarHidingEnabled(false)
              .setToolbarColor(android.graphics.Color.WHITE)
            builder.addMenuItem("REFRESH SOAL", refreshPi)
            builder.addMenuItem("SELESAI - KEMBALI KE APP", donePi)
            if (icon != null) builder.setActionButton(icon, "SELESAI", donePi, true)
            val tabs = builder.build()
            tabs.intent.setPackage(pkg)
            Handler(Looper.getMainLooper()).postDelayed({
              try {
                main.formTab = true
                main.openingTab = false
                tabs.launchUrl(act, u)
                try { main.showFloatButtons() } catch (e: Exception) { }
              } catch (e: Exception) { main.formTab = false; main.openingTab = false }
            }, 600)
          } catch (e: Exception) { main.formTab = false; main.openingTab = false }
        }
        true
      } catch (e: Exception) { main.formTab = false; main.openingTab = false; false }
    }

    // Tombol "BUKA LOGIN GOOGLE": dibuka di Chrome PENUH (bukan Custom Tab) supaya
    // halaman login Google pasti render (Custom Tab kadang tampil hitam/blank untuk
    // myaccount.google.com). Profil & cookie Chrome tetap sama, jadi login terbawa ke soal.
    // WebView.loadUrl TIDAK dipakai: cookie store WebView terpisah + Google blokir
    // sign-in di WebView (disallowed_useragent).
    @JavascriptInterface
    fun openLogin(url: String): Boolean {
      val u = try { android.net.Uri.parse(url) } catch (e: Exception) { null } ?: return false
      val host = u.host ?: return false
      if ((u.scheme ?: "") != "https") return false
      if (loginHosts.none { host == it || host.endsWith(".$it") }) return false
      val main = act as MainActivity
      return try {
        main.openingTab = true
        main.formTab = true
        act.runOnUiThread {
          // Jeda: guard bringBack/immersive jalan tiap 500ms, beri waktu Chrome tampil dulu.
          Handler(Looper.getMainLooper()).postDelayed({
            try {
              val i = Intent(Intent.ACTION_VIEW, u).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
              try {
                val pkg = CustomTabsClient.getPackageName(act, tabPackages)
                if (pkg != null) i.setPackage(pkg)
              } catch (e: Exception) { }
              act.startActivity(i)
            } catch (e: Exception) {
              main.formTab = false
              main.openingTab = false
            }
          }, 600)
        }
        true
      } catch (e: Exception) { main.formTab = false; main.openingTab = false; false }
    }
  }

  companion object {
    private const val PREFS = "cbt_lock"
    private const val CHROME_PKG = "com.android.chrome"
    const val ACTION_REFRESH_FORM = "id.sch.ashidiq.cbt.REFRESH_FORM"
    const val ACTION_SHOW_DONE = "id.sch.ashidiq.cbt.SHOW_DONE"
    private val loginHosts = listOf("myaccount.google.com", "accounts.google.com")
    // Urutan preferensi browser yang mendukung TWA / Custom Tabs
    private val tabPackages = listOf("com.android.chrome", "com.chrome.beta", "com.android.chrome.beta", "com.chrome.dev")
  }

  private fun isOwner(): Boolean = try { dpm.isDeviceOwnerApp(packageName) } catch (e: Exception) { false }

  fun showFloatButtons() {
    hideFloatButtons()
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !Settings.canDrawOverlays(this)) {
      try {
        val prefs = getSharedPreferences(PREFS, MODE_PRIVATE)
        if (!prefs.getBoolean("float_perm_asked", false)) {
          prefs.edit().putBoolean("float_perm_asked", true).apply()
          startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$packageName")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
      } catch (e: Exception) { }
      return
    }
    try {
      val wm = getSystemService(Context.WINDOW_SERVICE) as WindowManager
      floatWm = wm
      val dp = resources.displayMetrics.density
      val bar = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
      fun btn(label: String, color: Int, fn: () -> Unit): Button {
        return Button(this).apply {
          text = label
          setTextColor(android.graphics.Color.WHITE)
          setBackgroundColor(color)
          textSize = 14f
          setPadding((14 * dp).toInt(), (12 * dp).toInt(), (14 * dp).toInt(), (12 * dp).toInt())
          setOnClickListener { fn() }
        }
      }
      bar.addView(btn("REFRESH", 0xFFB45309.toInt()) {
        val url = lastFormUrl
        if (url.isNotEmpty()) { try { Bridge(this).openForm(url) } catch (e: Exception) { } }
      })
      bar.addView(btn("SELESAI UJIAN", 0xFF15803D.toInt()) {
        hideFloatButtons()
        bringBack()
        try { web.evaluateJavascript("if (typeof tampilkanOverlaySelesai === 'function') tampilkanOverlaySelesai();", null) } catch (e: Exception) { }
      })
      val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY else WindowManager.LayoutParams.TYPE_PHONE
      val params = WindowManager.LayoutParams(
        WindowManager.LayoutParams.WRAP_CONTENT,
        WindowManager.LayoutParams.WRAP_CONTENT,
        type,
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
        PixelFormat.TRANSLUCENT
      ).apply { gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL; y = (24 * dp).toInt() }
      wm.addView(bar, params)
      floatView = bar
    } catch (e: Exception) { }
  }

  fun hideFloatButtons() {
    try {
      val v = floatView
      if (v != null) floatWm?.removeView(v)
    } catch (e: Exception) { }
    floatView = null
  }

  @SuppressLint("SetJavaScriptEnabled")
  override fun onCreate(b: Bundle?) {
    super.onCreate(b)
    setContentView(R.layout.activity_main)
    dpm = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
    admin = ComponentName(this, AdminReceiver::class.java)
    // Kiosk: whitelist app + Chrome supaya lock task mode tetap menahan siswa walau halaman dibuka Chrome
    try { if (isOwner()) dpm.setLockTaskPackages(admin, arrayOf(packageName, CHROME_PKG)) } catch (e: Exception) { }
    window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
    web = findViewById(R.id.web)
    splash = findViewById(R.id.splash)
    splashInfo = findViewById(R.id.splash_info)
    findViewById<View>(R.id.splash_retry).setOnClickListener {
      tabLaunched = false
      launchTab()
    }
    if (twaMode) launchTab() else setupWebView(b)
  }

  // ===================== MODE TWA =====================
  private fun launchTab() {
    web.visibility = View.GONE
    splash.visibility = View.VISIBLE
    splashInfo.text = "Membuka halaman ujian di Chrome..."
    val uri = Uri.parse(BuildConfig.WEB_URL)
    val pkg = try { CustomTabsClient.getPackageName(this, tabPackages) } catch (e: Exception) { null }
    if (pkg == null) { openExternal(uri); return }
    val tabs = try { CustomTabsIntent.Builder().setShowTitle(false).setUrlBarHidingEnabled(true).build() } catch (e: Exception) { null }
    if (tabs == null) { openExternal(uri); return }
    val ok = try {
      // TWA: tanpa address bar kalau domain terverifikasi lewat .well-known/assetlinks.json.
      // Belum terverifikasi → Chrome otomatis menampilkannya sebagai Custom Tab, tetap profil Chrome.
      TrustedWebUtils.launchAsTrustedWebActivity(this, tabs, uri)
      true
    } catch (e: Exception) {
      try { tabs.launchUrl(this, uri); true } catch (e2: Exception) { false }
    }
    if (ok) tabLaunched = true else openExternal(uri)
  }

  private fun openExternal(uri: Uri) {
    val ok = try {
      startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
      tabLaunched = true
      true
    } catch (e: Exception) { false }
    if (!ok) {
      // Tidak ada browser sama sekali → pakai WebView dalam app supaya ujian tetap bisa jalan
      splash.visibility = View.GONE
      web.visibility = View.VISIBLE
      splashInfo.text = "Chrome tidak tersedia, memakai tampilan bawaan app."
      setupWebView(null)
    }
  }

  // ===================== MODE WEBVIEW =====================
  @SuppressLint("SetJavaScriptEnabled")
  private fun setupWebView(b: Bundle?) {
    web.visibility = View.VISIBLE
    splash.visibility = View.GONE
    // Cookie Gmail harus ikut terkirim ke iframe Google Form, kalau tidak siswa diminta login lagi di tengah ujian
    try {
      val cm = CookieManager.getInstance()
      cm.setAcceptCookie(true)
      cm.setAcceptThirdPartyCookies(web, true)
    } catch (e: Exception) { }
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
    // App keluar dari layar saat examMode (home/overview/app lain/screen off) → tandai re-entry lock.
    // formTab dikecualikan: siswa memang sengaja keluar ke Chrome untuk mengisi soal.
    if (examMode && !formTab) {
      try { getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("escape_pending", true).apply() } catch (e: Exception) { }
    }
    super.onStop()
  }

  override fun onSaveInstanceState(o: Bundle) { try { web.saveState(o) } catch (e: Exception) { }; super.onSaveInstanceState(o) }

  private fun setExamModeInternal(on: Boolean) {
    examMode = on
    setExcludeRecents(on) // sembunyikan dari Recent Apps selama ujian
    if (!on) { try { hideFloatButtons() } catch (e: Exception) { } }
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
    // Aksi dari tombol Custom Tab (menu/tombol aksi di Chrome)
    try {
      when (intent?.action) {
        ACTION_REFRESH_FORM -> {
          setIntent(Intent(intent).setAction(null))
          try { web.evaluateJavascript("if (typeof refreshSoalUjian === 'function') refreshSoalUjian();", null) } catch (e: Exception) { }
        }
        ACTION_SHOW_DONE -> {
          setIntent(Intent(intent).setAction(null))
          try { hideFloatButtons() } catch (e: Exception) { }
          try { web.evaluateJavascript("if (typeof tampilkanOverlaySelesai === 'function') tampilkanOverlaySelesai();", null) } catch (e: Exception) { }
        }
      }
    } catch (e: Exception) { }
    // Siswa kembali dari Chrome → buka kunci pelanggaran & minta fullscreen lagi
    if (formTab) {
      formTab = false
      try { hideFloatButtons() } catch (e: Exception) { }
      try { web.evaluateJavascript("if (typeof kembaliDariFormChrome === 'function') kembaliDariFormChrome();", null) } catch (e: Exception) { }
    }
  }

  override fun onNewIntent(i: Intent) {
    super.onNewIntent(i)
    try { setIntent(i) } catch (e: Exception) { }
    try { onResume() } catch (e: Exception) { }
  }

  override fun onPause() {
    if (!twaMode && examMode && !formTab && !openingTab) bringBack()
    super.onPause()
  }

  override fun onUserLeaveHint() {
    if (!twaMode && examMode && !formTab && !openingTab) bringBack()
  }

  override fun onBackPressed() {
    // Mode TWA: Back tidak boleh keluar app, halaman dibuka ulang
    if (twaMode) {
      if (tabLaunched) launchTab() else super.onBackPressed()
      return
    }
    if (examMode) return
    if (::web.isInitialized && web.canGoBack()) web.goBack() else super.onBackPressed()
  }

  override fun onDestroy() {
    try { guard.removeCallbacks(guardRun) } catch (e: Exception) { }
    try { hideFloatButtons() } catch (e: Exception) { }
    super.onDestroy()
  }
}
