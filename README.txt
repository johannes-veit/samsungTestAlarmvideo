Samsung Alarmvideo Test v0.2.1

Direktes Update von v0.1.6. Keine neue Benutzerinstanz anlegen.
Library-GUID {6DC070DF-7EBE-42F5-B86A-94C14ED15E2D}, Hauptmodul-GUID {08AF3A16-C63E-4405-B203-29262047C871} und Prefix SAVT bleiben unverändert.

Architektur v0.2.1:
- Hauptinstanz bleibt technisch ohne Parent, damit die vorhandene Instanz beim Update nicht umgehängt wird.
- WakeUp weiterhin ausschließlich über SamsungTizen_WakeUp.
- Die Hauptinstanz erzeugt automatisch zwei verborgene technische IP-Symcon-Instanzen:
  1. einen internen MediaServer-Helper aus derselben Bibliothek,
  2. einen nativen IP-Symcon Server Socket (Standard-Port 8090).
- Der Helper läuft getrennt von der Hauptinstanz. Dadurch kann der Samsung die Videoquelle bereits während SetAVTransportURI abrufen, ohne dass die SOAP-Steuerung denselben Modulkontext blockiert.
- HTTP-Pfad/Metadaten sind an die bei Windows "Wiedergabe auf Gerät" beobachtete MDE/DLNA-Struktur angelehnt.
- Primärformat: MPEG-TS/H.264 Main, 1280x720, AAC 5.1/44,1 kHz, Profil AVC_TS_MP_HD_AAC_MULT5_ISO.
- Fallback: MP4/H.264 High, 1280x720, AAC Stereo.
- GET, HEAD, Byte-Range und DLNA-TimeSeekRange werden unterstützt.
- Loop bevorzugt über AVTransport SetPlayMode REPEAT_ONE; falls der TV dies ablehnt, erfolgt ein einzelner Neustart pro Videoende ohne Polling.

Test:
1. Bibliothek aktualisieren; bestehende Instanz behalten.
2. Instanz einmal Übernehmen.
3. "DLNA-Medienserver prüfen/einrichten" drücken.
4. TV eingeschaltet lassen.
5. "2. Nur Alarmvideo starten (TV EIN)" drücken.
