Samsung Alarmvideo Test v0.2.6

Direktes Update der bestehenden v0.1.6/v0.2.x Instanz. Keine neue Benutzerinstanz anlegen.

Änderungen v0.2.6:
- Medienserver sendet Binärdaten bevorzugt direkt per SSCK_SendPacket an den anfragenden Client.
- Kein hartes Trennen der TCP-Verbindung unmittelbar nach dem Videostream.
- Kleinere 16-KiB-Datenpakete mit kurzem Yield zur stabilen Übertragung großer Dateien.
- Transferstatus (Bytes/komplett) wird intern protokolliert.
- 60-s-Loop-Fallback startet erst nach bestätigtem Videoabruf; keine wiederholten TV-Fehlversuche bei defektem Stream.
- Status unterscheidet nun zwischen akzeptiertem Startbefehl und tatsächlichem Videoabruf.

Bestehende GUIDs, Modulname und Prefix bleiben erhalten.

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


v0.2.2: Bibliotheksstruktur korrigiert. Keine libs/media-Unterstruktur mehr; die Mediendateien liegen direkt im internen MediaServer-Modulordner, damit IP-Symcon keinen Ordner 'media' als Modul interpretiert.


v0.2.4 Update-Sicherheit:
- Der historische Root-Ordner 'media' aus v0.2.0 wird absichtlich als gueltiges Kompatibilitaetsmodul mitgeliefert.
- Damit funktioniert auch ein reines Ueberschreiben eines bestehenden GitHub-Ordners, ohne dass der alte media-Ordner vorher geloescht werden muss.
- Technische Helper-/ServerSocket-Instanzen werden nicht mehr per IPS_SetParent umgehaengt; dadurch entfallen Root-Warnungen.


v0.2.4: Korrektur der programmgesteuerten MediaServer-Erzeugung fuer IP-Symcon 9. Der Server Socket wird jetzt zwingend zuerst erstellt/aktiviert; der technische MediaServer-Handler verwendet bewusst IPSModule statt IPSModuleStrict und wird danach explizit verbunden. Modulpruefung erfolgt ueber IPS_ModuleExists().


v0.2.5: Korrigiert die ReceiveData-Signatur des internen MediaServer-Moduls, damit sie mit IPSModule kompatibel ist und das Hilfsmodul von IP-Symcon geladen wird.
