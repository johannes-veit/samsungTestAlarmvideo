<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const SAMSUNG_TIZEN_MODULE_GUID = '{65BF76B4-042C-4971-A5CC-292FA5E49C86}';
    private const SERVER_SOCKET_MODULE_GUID = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    private const MEDIA_HELPER_MODULE_GUID = '{FDD19319-C635-4347-890E-14E5F5FDF420}';

    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const CM_SERVICE = 'urn:schemas-upnp-org:service:ConnectionManager:1';

    private const MEDIA_TOKEN = 'F0F30B1D-B2BC-4657-8E63-D8E46E1E425F';
    private const FORMAT_ID_MPEG = '00000061-A9AF-4584-84E2-55BFEF0A7D7E';
    private const FORMAT_ID_MP4 = '00000041-A9AF-4584-84E2-55BFEF0A7D7E';

    private const MPEG_FEATURES = 'DLNA.ORG_PN=AVC_TS_MP_HD_AAC_MULT5_ISO;DLNA.ORG_OP=10;DLNA.ORG_CI=1;DLNA.ORG_FLAGS=01700000000000000000000000000000';
    private const MP4_FEATURES = 'DLNA.ORG_PN=AVC_MP4_HP_HD_AAC;DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';

    private const MPEG_DURATION = 60.053;
    private const MP4_DURATION = 60.010;

    public function Create(): void
    {
        parent::Create();

        // Alle bestehenden v0.1.6 Properties bleiben erhalten.
        $this->RegisterPropertyInteger('SamsungInstanceID', 48488);
        $this->RegisterPropertyInteger('TVStatusVariableID', 16319);
        $this->RegisterPropertyString('TVIP', '192.168.103.54');
        $this->RegisterPropertyString('SymconIP', '192.168.103.59');
        $this->RegisterPropertyInteger('WebPort', 3777); // Altbestand; ab v0.2.0 nicht mehr benutzt.
        $this->RegisterPropertyInteger('StartDelayMs', 4000);

        // Neu in v0.2.0.
        $this->RegisterPropertyInteger('MediaServerPort', 8090);
        $this->RegisterPropertyBoolean('LoopVideo', true);

        // Bestehende Variablen/Idents erhalten.
        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableString('LastRequest', 'Letzter Videoabruf', '', 20);
        $this->RegisterVariableInteger('RequestCount', 'Videoabrufe', '', 30);

        // Bestehende Attribute erhalten.
        $this->RegisterAttributeInteger('WakeAttempts', 0);
        $this->RegisterAttributeInteger('VideoAttempts', 0);
        $this->RegisterAttributeInteger('RequestCountBeforeBrowser', 0);

        // Interne v0.2.0 Instanzen. Beides wird automatisch erzeugt und versteckt.
        $this->RegisterAttributeInteger('MediaHelperID', 0);
        $this->RegisterAttributeInteger('MediaServerID', 0);
        $this->RegisterAttributeInteger('VideoActive', 0);
        $this->RegisterAttributeString('LastMediaMode', '');
        $this->RegisterAttributeInteger('LastHelperRequestCount', 0);
        $this->RegisterAttributeString('LastMediaServerError', '');

        // Bestehende Timer-Namen erhalten.
        $this->RegisterTimer('WakeRetry', 0, 'SAVT_TimerWakeRetry($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoStart', 0, 'SAVT_TimerStart($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoRetry', 0, 'SAVT_TimerVideoRetry($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LoopGuard', 0, 'SAVT_TimerLoopGuard($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StatsSync', 0, 'SAVT_TimerStatsSync($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->StopAllTimers();
        $this->WriteAttributeInteger('WakeAttempts', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);
        $this->WriteAttributeInteger('VideoActive', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', 'Konfiguration: ' . $validation);
            return;
        }

        $media = $this->EnsureMediaServer();
        $this->SetValue('Status', $media['message']);
    }

    /**
     * Alte v0.1.x Hooks können bis zum nächsten Symcon-Neustart noch registriert sein.
     * Die Videowiedergabe läuft ab v0.2.0 nicht mehr darüber.
     */
    protected function ProcessHookData(): void
    {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Samsung Alarmvideo Test v0.2.5 uses the internal DLNA media server.';
    }

    public function WakeTV(): string
    {
        $validation = $this->ValidateSamsungInstance();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $result = $this->SendSamsungWakeUp('manueller Test');
        $this->SetValue('Status', $result['message']);
        return $result['message'];
    }

    public function TestVideo(): string
    {
        $this->StopAllTimers();
        $this->WriteAttributeInteger('WakeAttempts', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);
        $this->WriteAttributeInteger('VideoActive', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $media = $this->EnsureMediaServer();
        if (!$media['ok']) {
            $this->SetValue('Status', $media['message']);
            return $media['message'];
        }

        $this->ResetMediaStats();
        $delay = max(0, $this->ReadPropertyInteger('StartDelayMs'));

        if (!$this->TVIsOn()) {
            $wake = $this->SendSamsungWakeUp('Kompletttest');
            if (!$wake['ok']) {
                $this->SetValue('Status', $wake['message']);
                return $wake['message'];
            }
            $this->WriteAttributeInteger('WakeAttempts', 1);
            $this->SetTimerInterval('WakeRetry', 5000);
        }

        $this->SetTimerInterval('VideoStart', max(250, $delay));
        $message = sprintf('TV-WakeUp gesendet – Alarmvideo startet in %.1f s', $delay / 1000);
        $this->SetValue('Status', $message);
        return $message;
    }

    public function TimerWakeRetry(): void
    {
        $this->SetTimerInterval('WakeRetry', 0);

        if ($this->TVIsOn()) {
            return;
        }

        if ($this->ReadAttributeInteger('WakeAttempts') >= 2) {
            $this->SetValue('Status', 'TV bleibt AUS – SamsungTizen_WakeUp zweimal gesendet');
            return;
        }

        $wake = $this->SendSamsungWakeUp('Retry nach 5 s');
        if ($wake['ok']) {
            $this->WriteAttributeInteger('WakeAttempts', 2);
        }
    }

    public function TimerStart(): void
    {
        $this->SetTimerInterval('VideoStart', 0);
        $this->StartVideoNowInternal(false);
    }

    public function TimerVideoRetry(): void
    {
        $this->SetTimerInterval('VideoRetry', 0);

        if ($this->ReadAttributeInteger('VideoActive') === 1) {
            return;
        }

        if ($this->ReadAttributeInteger('VideoAttempts') >= 3) {
            $this->SetValue('Status', 'Videostart nach 3 Versuchen fehlgeschlagen');
            return;
        }

        $this->StartVideoNowInternal(true);
    }

    public function TimerLoopGuard(): void
    {
        $this->SetTimerInterval('LoopGuard', 0);
        if ($this->ReadAttributeInteger('VideoActive') !== 1 || !$this->ReadPropertyBoolean('LoopVideo')) {
            return;
        }

        // Nur Fallback, falls REPEAT_ONE vom TV abgelehnt wird. Kein zyklisches Polling.
        $mode = $this->ReadAttributeString('LastMediaMode');
        $restart = $this->StartMediaMode($mode !== '' ? $mode : 'mpeg', true);
        if ($restart['ok']) {
            $this->SetTimerInterval('LoopGuard', 60250);
            $this->SetTimerInterval('StatsSync', 1000);
        } else {
            $this->SetValue('Status', 'Loop-Neustart fehlgeschlagen: ' . $restart['message']);
        }
    }

    public function TimerStatsSync(): void
    {
        $this->SetTimerInterval('StatsSync', 0);
        $this->SyncMediaStats();
    }

    public function StartVideoNow(): string
    {
        $this->StopAllTimers();
        $this->WriteAttributeInteger('VideoAttempts', 0);
        $this->WriteAttributeInteger('VideoActive', 0);
        $this->ResetMediaStats();
        return $this->StartVideoNowInternal(false);
    }

    public function StopVideo(): string
    {
        $this->StopAllTimers();
        $this->WriteAttributeInteger('VideoActive', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);

        $stop = $this->SendAVTransport('Stop', '<InstanceID>0</InstanceID>');
        $this->SetTimerInterval('StatsSync', 500);

        $message = $stop['ok'] ? 'Alarmvideo gestoppt' : 'Video-Stopp: ' . $stop['message'];
        $this->SetValue('Status', $message);
        return $message;
    }

    public function PrepareVideo(): string
    {
        $media = $this->EnsureMediaServer();
        if (!$media['ok']) {
            $this->SetValue('Status', $media['message']);
            return $media['message'];
        }

        $helper = $this->GetMediaHelperStatus();
        $probe = $this->ProbeMediaServer();
        $message = 'DLNA-Medienserver bereit – ' . $this->GetMediaURL('mpeg');
        if ($helper !== '') {
            $message .= ' | ' . $helper;
        }
        $message .= $probe['ok'] ? ' | Selbsttest OK' : ' | Selbsttest nicht bestätigt: ' . $probe['message'];
        $this->SetValue('Status', $message);
        return $message;
    }

    public function GetVideoURL(): string
    {
        return $this->GetMediaURL('mpeg');
    }

    private function StartVideoNowInternal(bool $isRetry): string
    {
        $this->SetTimerInterval('VideoStart', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $media = $this->EnsureMediaServer();
        if (!$media['ok']) {
            $this->SetValue('Status', $media['message']);
            return $media['message'];
        }

        $attempt = $this->ReadAttributeInteger('VideoAttempts') + 1;
        $this->WriteAttributeInteger('VideoAttempts', $attempt);

        $preferred = $this->DetectPreferredMediaMode();
        $order = $preferred === 'mp4' ? ['mp4', 'mpeg'] : ['mpeg', 'mp4'];
        $errors = [];

        foreach ($order as $mode) {
            $result = $this->StartMediaMode($mode, false);
            if ($result['ok']) {
                $this->WriteAttributeInteger('VideoActive', 1);
                $this->WriteAttributeString('LastMediaMode', $mode);
                $this->WriteAttributeInteger('VideoAttempts', 0);
                $this->SetTimerInterval('VideoRetry', 0);
                $this->SetTimerInterval('StatsSync', 1000);

                $loopText = '';
                if ($this->ReadPropertyBoolean('LoopVideo')) {
                    $repeat = $this->SendAVTransport(
                        'SetPlayMode',
                        '<InstanceID>0</InstanceID><NewPlayMode>REPEAT_ONE</NewPlayMode>'
                    );
                    if ($repeat['ok']) {
                        $loopText = ' – Loop REPEAT_ONE aktiv';
                        $this->SetTimerInterval('LoopGuard', 0);
                    } else {
                        $loopText = ' – Loop per 60-s-Fallback';
                        $this->SetTimerInterval('LoopGuard', 60250);
                    }
                }

                $message = 'Alarmvideo gestartet (' . strtoupper($mode) . ')' . $loopText;
                $this->SetValue('Status', $message);
                return $message;
            }
            $errors[] = strtoupper($mode) . ': ' . $result['message'];
        }

        $this->SetTimerInterval('StatsSync', 500);
        $message = 'Videostart fehlgeschlagen: ' . implode(' | ', $errors);

        if ($attempt < 3 && ($isRetry || !$this->TVIsOn() || $attempt === 1)) {
            $this->SetTimerInterval('VideoRetry', 2000);
            $message .= ' – Retry ' . ($attempt + 1) . '/3 in 2 s';
        }

        $this->SetValue('Status', $message);
        return $message;
    }

    private function StartMediaMode(string $mode, bool $loopRestart): array
    {
        $url = $this->GetMediaURL($mode);
        $metadata = $this->BuildWindowsLikeMetadata($mode, $url);

        $set = $this->SendAVTransport(
            'SetAVTransportURI',
            '<InstanceID>0</InstanceID>' .
            '<CurrentURI>' . $this->XmlEscape($url) . '</CurrentURI>' .
            '<CurrentURIMetaData>' . $this->XmlEscape($metadata) . '</CurrentURIMetaData>'
        );
        if (!$set['ok']) {
            $this->SendDebug('SetAVTransportURI ' . strtoupper($mode), $set['message'] . ' | ' . $set['body'], 0);
            return $set;
        }

        if ($this->ReadPropertyBoolean('LoopVideo')) {
            // Nicht kritisch: der Samsung unterstützt diese Action laut eigener AVTransport-SCPD.
            $this->SendAVTransport(
                'SetNextAVTransportURI',
                '<InstanceID>0</InstanceID>' .
                '<NextURI>' . $this->XmlEscape($url) . '</NextURI>' .
                '<NextURIMetaData>' . $this->XmlEscape($metadata) . '</NextURIMetaData>'
            );
        }

        $play = $this->SendAVTransport('Play', '<InstanceID>0</InstanceID><Speed>1</Speed>');
        if (!$play['ok']) {
            $this->SendDebug('Play ' . strtoupper($mode), $play['message'] . ' | ' . $play['body'], 0);
            return $play;
        }

        if ($loopRestart) {
            $this->SendDebug('Loop', 'Video neu gestartet: ' . strtoupper($mode), 0);
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    private function DetectPreferredMediaMode(): string
    {
        $protocols = $this->GetRendererSinkProtocols();
        if ($protocols === '') {
            return 'mpeg';
        }
        if (stripos($protocols, 'AVC_TS_MP_HD_AAC_MULT5_ISO') !== false) {
            return 'mpeg';
        }
        if (stripos($protocols, 'AVC_MP4_HP_HD_AAC') !== false) {
            return 'mp4';
        }
        return 'mpeg';
    }

    private function GetRendererSinkProtocols(): string
    {
        $result = $this->SendSOAP(
            self::CM_SERVICE,
            'http://' . trim($this->ReadPropertyString('TVIP')) . ':9197/upnp/control/ConnectionManager1',
            'GetProtocolInfo',
            ''
        );
        if (!$result['ok']) {
            $this->SendDebug('GetProtocolInfo', $result['message'], 0);
            return '';
        }
        if (preg_match('/<Sink>(.*?)<\/Sink>/is', $result['body'], $m) !== 1) {
            return '';
        }
        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function BuildWindowsLikeMetadata(string $mode, string $url): string
    {
        $path = $this->GetSharedMediaPath($mode);
        $size = is_file($path) ? (int) filesize($path) : 0;
        $duration = $mode === 'mpeg' ? self::MPEG_DURATION : self::MP4_DURATION;
        $bitrate = $duration > 0 ? (int) round(($size * 8) / $duration) : 0;

        if ($mode === 'mpeg') {
            $protocol = 'http-get:*:video/mpeg:' . self::MPEG_FEATURES;
            $sampleRate = 44100;
            $channels = 6;
            $trackID = 3;
        } else {
            $protocol = 'http-get:*:video/mp4:' . self::MP4_FEATURES;
            $sampleRate = 48000;
            $channels = 2;
            $trackID = 2;
        }

        // Struktur bewusst an den beim funktionierenden Windows "Wiedergabe auf Gerät"
        // beobachteten DIDL-Lite Datensatz angelehnt.
        return '<DIDL-Lite ' .
            'xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" ' .
            'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" ' .
            'xmlns:microsoft="urn:schemas-microsoft-com:WMPNSS-1-0/" ' .
            'xmlns:dlna="urn:schemas-dlna-org:metadata-1-0/">' .
            '<item id="1000" restricted="1" parentID="0" ' .
            'microsoft:cpId="{9B7D1343-41ED-433D-B7CA-C5F305F4E181}" microsoft:trackId="' . $trackID . '">' .
            '<dc:title>ALARM</dc:title>' .
            '<res size="' . $size . '" duration="0:01:00.000" bitrate="' . $bitrate . '" resolution="1280x720" ' .
            'protocolInfo="' . $this->XmlEscape($protocol) . '" sampleFrequency="' . $sampleRate . '" nrAudioChannels="' . $channels . '" ' .
            'microsoft:codec="{34363248-0000-0010-8000-00AA00389B71}">' . $this->XmlEscape($url) . '</res>' .
            '<upnp:class>object.item.videoItem</upnp:class>' .
            '</item></DIDL-Lite>';
    }

    private function EnsureMediaServer(): array
    {
        $this->WriteAttributeString('LastMediaServerError', '');
        $port = max(1025, min(65535, $this->ReadPropertyInteger('MediaServerPort')));

        if (!is_file($this->GetSharedMediaPath('mpeg')) || !is_file($this->GetSharedMediaPath('mp4'))) {
            return ['ok' => false, 'message' => 'Alarmvideo-Dateien fehlen in der Bibliothek'];
        }

        // IP-Symcon 9 / IPSModuleStrict: Bei programmgesteuert erzeugten Instanzen
        // muss die physikalisch uebergeordnete I/O-Instanz zuerst existieren.
        // Deshalb immer zuerst den Server Socket anlegen und aktivieren und erst
        // danach den internen MediaServer-Handler erzeugen.
        $serverID = $this->FindOrCreateOwnedInstance(
            'MediaServerID',
            self::SERVER_SOCKET_MODULE_GUID,
            'Samsung Alarmvideo HTTP',
            'SAVT_SOCKET_OWNER:' . $this->InstanceID
        );
        if ($serverID <= 0) {
            return ['ok' => false, 'message' => 'Interner Server Socket konnte nicht erstellt werden'];
        }

        if (!$this->ConfigureServerSocket($serverID, $port)) {
            return ['ok' => false, 'message' => 'Server Socket auf Port ' . $port . ' konnte nicht geöffnet werden'];
        }

        $helperID = $this->FindOrCreateOwnedInstance(
            'MediaHelperID',
            self::MEDIA_HELPER_MODULE_GUID,
            'Samsung Alarmvideo MediaServer Helper',
            'SAVT_HELPER_OWNER:' . $this->InstanceID
        );
        if ($helperID <= 0) {
            $detail = trim($this->ReadAttributeString('LastMediaServerError'));
            return ['ok' => false, 'message' => 'Interner MediaServer-Helper konnte nicht erstellt werden' . ($detail !== '' ? ': ' . $detail : '')];
        }

        try {
            $helper = IPS_GetInstance($helperID);
            $currentParent = (int) ($helper['ConnectionID'] ?? 0);
            if ($currentParent !== $serverID) {
                if ($currentParent > 0) {
                    IPS_DisconnectInstance($helperID);
                }
                IPS_ConnectInstance($helperID, $serverID);
            }
            IPS_ApplyChanges($helperID);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'MediaServer-Helper konnte nicht mit Server Socket verbunden werden: ' . $e->getMessage()];
        }

        $helperStatus = $this->GetMediaHelperStatus();
        $suffix = $helperStatus !== '' ? ' | ' . $helperStatus : '';
        return ['ok' => true, 'message' => 'Bereit – interner DLNA-Medienserver auf Port ' . $port . $suffix];
    }

    private function FindOrCreateOwnedInstance(string $attributeName, string $moduleGUID, string $name, string $ownerInfo): int
    {
        $id = $this->ReadAttributeInteger($attributeName);
        if ($this->InstanceHasModule($id, $moduleGUID)) {
            return $id;
        }

        foreach (IPS_GetInstanceListByModuleID($moduleGUID) as $candidate) {
            try {
                $object = IPS_GetObject($candidate);
                if ((string) ($object['ObjectInfo'] ?? '') === $ownerInfo) {
                    $this->WriteAttributeInteger($attributeName, $candidate);
                    return $candidate;
                }
            } catch (Throwable $e) {
                // Weiter suchen.
            }
        }

        try {
            // Vor dem Erzeugen sicherstellen, dass Symcon das Modul wirklich geladen hat.
            // So wird bei einer fehlerhaften Bibliotheksstruktur niemals mit Objekt-ID 0 weitergearbeitet.
            if (!IPS_ModuleExists($moduleGUID)) {
                $error = 'Modul nicht geladen: ' . $moduleGUID . ' (' . $name . ')';
                $this->WriteAttributeString('LastMediaServerError', $error);
                $this->SendDebug('MediaServer', $error, 0);
                return 0;
            }

            $id = IPS_CreateInstance($moduleGUID);
            if ($id <= 0 || !IPS_InstanceExists($id)) {
                $error = 'Instanz konnte nicht erzeugt werden: ' . $name;
                $this->WriteAttributeString('LastMediaServerError', $error);
                $this->SendDebug('MediaServer', $error, 0);
                return 0;
            }

            IPS_SetName($id, $name);
            IPS_SetInfo($id, $ownerInfo);
            // Technische Instanzen bewusst nicht umhaengen. Sie bleiben am Root und werden nur versteckt.
            // Dadurch gibt es auch bei Update-/Migrationsresten keine 'Root kann nicht geaendert werden'-Warnung.
            if (function_exists('IPS_SetHidden')) {
                IPS_SetHidden($id, true);
            }
            $this->WriteAttributeInteger($attributeName, $id);
            return $id;
        } catch (Throwable $e) {
            $error = 'Instanz ' . $name . ' konnte nicht erstellt werden: ' . $e->getMessage();
            $this->WriteAttributeString('LastMediaServerError', $error);
            $this->SendDebug('MediaServer', $error, 0);
            return 0;
        }
    }

    private function InstanceHasModule(int $instanceID, string $moduleGUID): bool
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return false;
        }
        try {
            $instance = IPS_GetInstance($instanceID);
            return (string) ($instance['ModuleInfo']['ModuleID'] ?? '') === $moduleGUID;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ConfigureServerSocket(int $serverID, int $port): bool
    {
        try {
            $configuration = json_decode(IPS_GetConfiguration($serverID), true);
            if (!is_array($configuration)) {
                $configuration = [];
            }
            if (array_key_exists('Port', $configuration)) {
                IPS_SetProperty($serverID, 'Port', $port);
            }
            if (array_key_exists('Open', $configuration)) {
                IPS_SetProperty($serverID, 'Open', true);
            }
            IPS_ApplyChanges($serverID);
            return (int) (IPS_GetInstance($serverID)['InstanceStatus'] ?? 0) < 200;
        } catch (Throwable $e) {
            $this->SendDebug('MediaServer', 'Server Socket Konfiguration fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function ResetMediaStats(): void
    {
        $helperID = $this->ReadAttributeInteger('MediaHelperID');
        if ($helperID > 0 && IPS_InstanceExists($helperID) && function_exists('SAVTMS_ResetStats')) {
            try {
                SAVTMS_ResetStats($helperID);
            } catch (Throwable $e) {
                $this->SendDebug('MediaStats', $e->getMessage(), 0);
            }
        }
        $this->SetValue('RequestCount', 0);
        $this->SetValue('LastRequest', '');
        $this->WriteAttributeInteger('LastHelperRequestCount', 0);
    }

    private function SyncMediaStats(): void
    {
        $helperID = $this->ReadAttributeInteger('MediaHelperID');
        if ($helperID <= 0 || !IPS_InstanceExists($helperID) || !function_exists('SAVTMS_GetStats')) {
            return;
        }

        try {
            $json = SAVTMS_GetStats($helperID);
            $stats = json_decode($json, true);
            if (!is_array($stats)) {
                return;
            }
            $count = (int) ($stats['count'] ?? 0);
            $last = (string) ($stats['last'] ?? '');
            $this->SetValue('RequestCount', $count);
            $this->SetValue('LastRequest', $last);
            $this->WriteAttributeInteger('LastHelperRequestCount', $count);

            if ($count > 0 && $this->ReadAttributeInteger('VideoActive') === 1) {
                $this->SetValue('Status', 'Alarmvideo läuft – Samsung lädt vom DLNA-Medienserver');
            }
        } catch (Throwable $e) {
            $this->SendDebug('MediaStats', $e->getMessage(), 0);
        }
    }

    private function ProbeMediaServer(): array
    {
        $url = sprintf(
            'http://%s:%d/status',
            trim($this->ReadPropertyString('SymconIP')),
            max(1025, min(65535, $this->ReadPropertyInteger('MediaServerPort')))
        );

        $status = 0;
        $body = '';
        $error = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_FAILONERROR => false
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
            } else {
                $body = (string) $response;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2,
                    'ignore_errors' => true
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $error = 'HTTP-Verbindung fehlgeschlagen';
            } else {
                $body = (string) $response;
            }
            if (isset($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($status === 200 && str_contains($body, 'Samsung Alarmvideo DLNA MediaServer')) {
            return ['ok' => true, 'message' => 'OK'];
        }
        if ($error !== '') {
            return ['ok' => false, 'message' => $error];
        }
        return ['ok' => false, 'message' => 'HTTP ' . $status];
    }

    private function GetMediaHelperStatus(): string
    {
        $helperID = $this->ReadAttributeInteger('MediaHelperID');
        if ($helperID <= 0 || !IPS_InstanceExists($helperID) || !function_exists('SAVTMS_GetStatus')) {
            return '';
        }
        try {
            return SAVTMS_GetStatus($helperID);
        } catch (Throwable $e) {
            return 'Helper-Status nicht lesbar';
        }
    }

    private function GetMediaURL(string $mode): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = max(1025, min(65535, $this->ReadPropertyInteger('MediaServerPort')));
        $file = $mode === 'mp4' ? '1000.mp4' : '1000.mpeg';
        $formatID = $mode === 'mp4' ? self::FORMAT_ID_MP4 : self::FORMAT_ID_MPEG;

        return sprintf(
            'http://%s:%d/MDEServer/%s/%s?formatID=%s',
            $host,
            $port,
            self::MEDIA_TOKEN,
            $file,
            $formatID
        );
    }

    private function GetSharedMediaPath(string $mode): string
    {
        $root = dirname(__DIR__);
        return $root . DIRECTORY_SEPARATOR . 'SamsungAlarmvideoMediaServer' . DIRECTORY_SEPARATOR . ($mode === 'mp4' ? 'ALARM.mp4' : 'ALARM_DLNA.mpeg');
    }

    private function ValidateConfiguration(): string
    {
        $instanceError = $this->ValidateSamsungInstance();
        if ($instanceError !== '') {
            return $instanceError;
        }

        $statusID = $this->ReadPropertyInteger('TVStatusVariableID');
        if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
            return 'TV-Statusvariable ungültig';
        }
        $variable = IPS_GetVariable($statusID);
        if ((int) ($variable['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
            return 'TV-Statusvariable ist nicht Boolean';
        }
        if (trim($this->ReadPropertyString('TVIP')) === '') {
            return 'TV-IP fehlt';
        }
        if (trim($this->ReadPropertyString('SymconIP')) === '') {
            return 'SymBox-IP fehlt';
        }
        $port = $this->ReadPropertyInteger('MediaServerPort');
        if ($port < 1025 || $port > 65535) {
            return 'DLNA-Medienserver-Port muss zwischen 1025 und 65535 liegen';
        }
        if (!is_file($this->GetSharedMediaPath('mpeg')) || !is_file($this->GetSharedMediaPath('mp4'))) {
            return 'Alarmvideo-Dateien fehlen in der Bibliothek';
        }
        return '';
    }

    private function ValidateSamsungInstance(): string
    {
        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return 'SamsungTizen Instanz ungültig';
        }
        if (!in_array($instanceID, IPS_GetInstanceListByModuleID(self::SAMSUNG_TIZEN_MODULE_GUID), true)) {
            return 'Ausgewählte Instanz ist keine SamsungTizen-Instanz';
        }
        if (!function_exists('SamsungTizen_WakeUp')) {
            return 'SamsungTizen_WakeUp ist nicht verfügbar';
        }
        return '';
    }

    private function TVIsOn(): bool
    {
        $statusID = $this->ReadPropertyInteger('TVStatusVariableID');
        if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
            return false;
        }
        try {
            return (bool) GetValue($statusID);
        } catch (Throwable $e) {
            $this->SendDebug('TVStatus', $e->getMessage(), 0);
            return false;
        }
    }

    private function SendSamsungWakeUp(string $reason): array
    {
        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return ['ok' => false, 'message' => 'SamsungTizen Instanz ungültig'];
        }

        try {
            SamsungTizen_WakeUp($instanceID);
            $message = 'SamsungTizen_WakeUp gesendet (' . $reason . ')';
            $this->SendDebug('TV', $message, 0);
            return ['ok' => true, 'message' => $message];
        } catch (Throwable $e) {
            $message = 'SamsungTizen_WakeUp fehlgeschlagen: ' . $e->getMessage();
            $this->SendDebug('TV', $message, 0);
            return ['ok' => false, 'message' => $message];
        }
    }

    private function SendAVTransport(string $action, string $arguments): array
    {
        return $this->SendSOAP(
            self::AVT_SERVICE,
            'http://' . trim($this->ReadPropertyString('TVIP')) . ':9197/upnp/control/AVTransport1',
            $action,
            $arguments
        );
    }

    private function SendSOAP(string $service, string $url, string $action, string $arguments): array
    {
        $soap = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body><u:' . $action . ' xmlns:u="' . $service . '">' . $arguments . '</u:' . $action . '></s:Body></s:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset="utf-8"',
            'SOAPACTION: "' . $service . '#' . $action . '"',
            'Connection: close'
        ];

        $status = 0;
        $body = '';
        $transportError = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $soap,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_FAILONERROR => false
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $transportError = curl_error($ch);
            } else {
                $body = (string) $response;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $soap,
                    'timeout' => 6,
                    'ignore_errors' => true
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $transportError = 'HTTP-Verbindung fehlgeschlagen';
            } else {
                $body = (string) $response;
            }
            if (isset($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $hm) === 1) {
                $status = (int) $hm[1];
            }
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'OK', 'body' => $body];
        }

        $upnpCode = '';
        $upnpDescription = '';
        if (preg_match('/<errorCode>([^<]+)<\/errorCode>/i', $body, $em) === 1) {
            $upnpCode = trim($em[1]);
        }
        if (preg_match('/<errorDescription>([^<]+)<\/errorDescription>/i', $body, $dm) === 1) {
            $upnpDescription = trim($dm[1]);
        }

        $message = $transportError !== '' ? $transportError : ('HTTP ' . $status);
        if ($upnpCode !== '') {
            $message .= ' / UPnP ' . $upnpCode;
        }
        if ($upnpDescription !== '') {
            $message .= ' ' . $upnpDescription;
        }
        return ['ok' => false, 'message' => $message, 'body' => $body];
    }

    private function StopAllTimers(): void
    {
        $this->SetTimerInterval('WakeRetry', 0);
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);
        $this->SetTimerInterval('LoopGuard', 0);
        $this->SetTimerInterval('StatsSync', 0);
    }

    private function XmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
