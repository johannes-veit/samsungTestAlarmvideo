Samsung Alarmvideo Test v0.1.6

Testmodul für Samsung Tizen + IP-Symcon.
Ab v0.1.6 wird DLNA/AVTransport nicht mehr zum Starten des Videos verwendet.
Stattdessen wird über den vorhandenen SamsungTizen-WebSocket die Internet-App direkt mit einem lokalen HTML-Vollbildplayer geöffnet.
WakeUp bleibt über SamsungTizen_WakeUp.
Die bestehende Instanz kann aktualisiert werden; keine neue Instanz anlegen.
