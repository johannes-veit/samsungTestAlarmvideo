Samsung Alarmvideo Test 0.1.1
==============================

Zweck
-----
Eigenständiger Test für die Alarmvideo-Wiedergabe auf dem Samsung TV.
Die bestehende LCN Alarmanlage wird NICHT verändert.

Voreinstellungen für das aktuelle System
----------------------------------------
SamsungTizen Instanz: 48488
Samsung TV:           192.168.103.54
SymBox:                192.168.103.59
WebServer Port:        3777
Startverzögerung:      4000 ms
Video:                 ALARM.mp4 (60 s, H.264 + AAC)

Test
----
1. Modul installieren und eine Instanz "Samsung Alarmvideo Test" anlegen.
2. Konfiguration prüfen und "Übernehmen".
3. Unter "Testumgebung" auf "TV + Alarmvideo testen" klicken.
4. Im Objektbaum die Variablen "Status", "Letzter Videoabruf" und "Videoabrufe" beobachten.
5. Mit "Video stoppen" beenden.

Technik
-------
Das Modul stellt ALARM.mp4 selbst über einen Symcon-WebHook bereit und steuert
den Samsung direkt über UPnP AVTransport. Windows/PowerShell ist nicht nötig.

Aenderungen 0.1.1
-----------------
- TV-WakeUp wie in der bewaehrten Alarmanlagen-Logik: sofort, Statuspruefung nach 5 s, maximal ein zweiter WakeUp.
- Video startet nach einstellbarer Verzoegerung; AVTransport wird bei bereits eingeschaltetem TV begrenzt erneut versucht.
- Keine Endlosschleifen, keine PowerShell.
