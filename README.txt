Samsung Alarmvideo Test 0.1.2
==============================

Zweck
-----
Eigenständiger Test für Samsung-TV + Alarmvideo. Keine PowerShell nötig.
Die bestehende LCN Alarmanlage wird NICHT verändert.

Voreinstellungen
----------------
SamsungTizen Instanz: 48488
TV-Statusvariable:    16319
Samsung TV:           192.168.103.54
SymBox:                192.168.103.59
WebServer:             3777
Startverzögerung:      4000 ms

Änderungen 0.1.2
-----------------
- TV-Start und Video-Test getrennt, damit Fehler sofort zuzuordnen sind.
- Wake-on-LAN wird als echtes Magic Packet direkt aus den in der SamsungTizen-Instanz
  gespeicherten Werten BroadcastAddress und MACAddress gesendet.
- Die vom Nutzer gelieferte ALARM.mp4 wurde einmalig in ein Samsung/DLNA-freundliches
  H.264-High/AAC-Profil (1280x720, 48 kHz Stereo) konvertiert.
- Zusätzlich ist eine MPEG-TS-Fallbackdatei enthalten.
- AVTransport versucht zuerst das passende MP4-DLNA-Profil und bei Ablehnung automatisch
  MPEG-TS als Fallback.
- WebHook liefert passende DLNA-Header und Byte-Range-Antworten.
