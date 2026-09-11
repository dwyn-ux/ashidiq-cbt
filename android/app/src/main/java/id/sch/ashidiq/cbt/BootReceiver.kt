package id.sch.ashidiq.cbt

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class BootReceiver : BroadcastReceiver() {
  override fun onReceive(c: Context, i: Intent) {
    if (Intent.ACTION_BOOT_COMPLETED == i.action) {
      val l = Intent(c, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
      try { c.startActivity(l) } catch (e: Exception) { }
    }
  }
}
