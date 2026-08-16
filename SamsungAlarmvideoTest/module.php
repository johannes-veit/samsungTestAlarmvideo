<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const SAMSUNG_TIZEN_MODULE_GUID = '{65BF76B4-042C-4971-A5CC-292FA5E49C86}';
    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const USER_SUBDIR = 'samsung-alarmvideo';
    private const USER_FILENAME = 'ALARM.mp4';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SamsungInstanceID', 48488);
        $this->RegisterPropertyInteger('TVStatusVariableID', 16319);
        $this->RegisterPropertyString('TVIP', '192.168.103.54');
        $this->RegisterPropertyString('SymconIP', '192.168.103.59');
        $this->RegisterPropertyInteger('WebPort', 3777);
        $this->RegisterPropertyInteger('StartDelayMs', 4000);

        $this->RegisterVariableString('Status', 'Status', '', 10);
        // Bestehende Variablen aus 0.1.2 bewusst erhalten, damit das Update
        // keine Objektbaum-Unruhe erzeugt. Sie werden ab 0.1.3 nicht mehr benutzt.
        $this->RegisterVariableString('LastRequest', 'Letzter Videoabruf', '', 20);
        $this->RegisterVariableInteger('RequestCount', 'Videoabrufe', '', 30);

        $this->RegisterAttributeInteger('WakeAttempts', 0);
        $this->RegisterAttributeInteger('VideoAttempts', 0);

        $this->RegisterTimer('WakeRetry', 0, 'SAVT_TimerWakeRetry($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoStart', 0, 'SAVT_TimerStart($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoRetry', 0, 'SAVT_TimerVideoRetry($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('WakeRetry', 0);
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);
        $this->WriteAttributeInteger('WakeAttempts', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', 'Konfiguration: ' . $validation);
            return;
        }

        $prepared = $this->PrepareStaticVideo();
        $this->SetValue('Status', $prepared['ok'] ? 'Bereit – statische Video-Datei angelegt' : $prepared['message']);
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
        $this->SetTimerInterval('WakeRetry', 0);
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);
        $this->WriteAttributeInteger('WakeAttempts', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $prepared = $this->PrepareStaticVideo();
        if (!$prepared['ok']) {
            $this->SetValue('Status', $prepared['message']);
            return $prepared['message'];
        }

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
        $message = sprintf('Video-Start in %.1f s – TV-WakeUp über SamsungTizen', $delay / 1000);
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
        $this->StartVideoNow();
    }

    public function TimerVideoRetry(): void
    {
        $this->SetTimerInterval('VideoRetry', 0);
        $this->StartVideoNow();
    }

    public function StartVideoNow(): string
    {
        $this->SetTimerInterval('VideoStart', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $prepared = $this->PrepareStaticVideo();
        if (!$prepared['ok']) {
            $this->SetValue('Status', $prepared['message']);
            return $prepared['message'];
        }

        $attempt = $this->ReadAttributeInteger('VideoAttempts') + 1;
        $this->WriteAttributeInteger('VideoAttempts', $attempt);

        $url = $this->GetVideoURL();
        $size = @filesize($this->GetStaticVideoPath());
        $metadata = $this->BuildMetadata($url, $size === false ? 0 : (int) $size);

        $set = $this->SendAVTransport(
            'SetAVTransportURI',
            '<InstanceID>0</InstanceID>' .
            '<CurrentURI>' . $this->XmlEscape($url) . '</CurrentURI>' .
            '<CurrentURIMetaData>' . $this->XmlEscape($metadata) . '</CurrentURIMetaData>'
        );

        if (!$set['ok']) {
            $message = 'Videoquelle abgelehnt: ' . $set['message'];
            if ($attempt < 3) {
                $this->SetTimerInterval('VideoRetry', 2000);
                $message .= sprintf(' – Retry %d/3 in 2 s', $attempt + 1);
            }
            $this->SetValue('Status', $message);
            $this->SendDebug('SetAVTransportURI', $set['message'] . ' | ' . $set['body'], 0);
            return $message;
        }

        $play = $this->SendAVTransport('Play', '<InstanceID>0</InstanceID><Speed>1</Speed>');
        if (!$play['ok']) {
            $message = 'Videoquelle gesetzt, Play fehlgeschlagen: ' . $play['message'];
            $this->SetValue('Status', $message);
            $this->SendDebug('Play', $play['message'] . ' | ' . $play['body'], 0);
            return $message;
        }

        // Loop ist optional; ein Fehler darf den erfolgreichen Start nicht zunichtemachen.
        $loop = $this->SendAVTransport('SetPlayMode', '<InstanceID>0</InstanceID><NewPlayMode>REPEAT_ONE</NewPlayMode>');
        if (!$loop['ok']) {
            $this->SendDebug('SetPlayMode', $loop['message'] . ' | ' . $loop['body'], 0);
        }

        $this->SetTimerInterval('VideoRetry', 0);
        $message = 'Alarmvideo gestartet' . ($loop['ok'] ? ' – Loop angefordert' : '');
        $this->SetValue('Status', $message);
        return $message;
    }

    public function StopVideo(): string
    {
        $this->SetTimerInterval('WakeRetry', 0);
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);

        $stop = $this->SendAVTransport('Stop', '<InstanceID>0</InstanceID>');
        if (!$stop['ok']) {
            $message = 'Stop: ' . $stop['message'];
            $this->SetValue('Status', $message);
            return $message;
        }

        $this->SetValue('Status', 'Video gestoppt');
        return 'Video gestoppt';
    }

    public function PrepareVideo(): string
    {
        $prepared = $this->PrepareStaticVideo(true);
        $this->SetValue('Status', $prepared['message']);
        return $prepared['message'];
    }

    public function GetVideoURL(): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = $this->ReadPropertyInteger('WebPort');
        return sprintf('http://%s:%d/user/%s/%s', $host, $port, self::USER_SUBDIR, self::USER_FILENAME);
    }

    private function PrepareStaticVideo(bool $force = false): array
    {
        $source = __DIR__ . DIRECTORY_SEPARATOR . self::USER_FILENAME;
        if (!is_file($source)) {
            return ['ok' => false, 'message' => 'ALARM.mp4 fehlt im Modul'];
        }

        $dir = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . self::USER_SUBDIR;
        $target = $dir . DIRECTORY_SEPARATOR . self::USER_FILENAME;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'User-Verzeichnis konnte nicht angelegt werden: ' . $dir];
        }

        clearstatcache(true, $source);
        clearstatcache(true, $target);
        $sourceSize = @filesize($source);
        $targetSize = @filesize($target);

        if (!$force && is_file($target) && $sourceSize !== false && $targetSize === $sourceSize) {
            return ['ok' => true, 'message' => 'Video bereit: ' . $this->GetVideoURL()];
        }

        if (!@copy($source, $target)) {
            return ['ok' => false, 'message' => 'ALARM.mp4 konnte nicht in den Symcon-user-Ordner kopiert werden'];
        }

        @chmod($target, 0644);
        clearstatcache(true, $target);
        $copiedSize = @filesize($target);
        if ($sourceSize === false || $copiedSize !== $sourceSize) {
            return ['ok' => false, 'message' => 'Video-Kopie unvollständig'];
        }

        return ['ok' => true, 'message' => 'Video bereit: ' . $this->GetVideoURL()];
    }

    private function GetStaticVideoPath(): string
    {
        return IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . self::USER_SUBDIR . DIRECTORY_SEPARATOR . self::USER_FILENAME;
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

    private function BuildMetadata(string $url, int $size): string
    {
        $protocol = 'http-get:*:video/mp4:*';
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" ' .
            'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">' .
            '<item id="0" parentID="0" restricted="1">' .
            '<dc:title>ALARM</dc:title>' .
            '<res size="' . max(0, $size) . '" duration="0:01:00.000" protocolInfo="' . $protocol . '">' .
            $this->XmlEscape($url) . '</res>' .
            '<upnp:class>object.item.videoItem</upnp:class>' .
            '</item></DIDL-Lite>';
    }

    private function SendAVTransport(string $action, string $arguments): array
    {
        $tvIP = trim($this->ReadPropertyString('TVIP'));
        $url = 'http://' . $tvIP . ':9197/upnp/control/AVTransport1';
        $soap = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body><u:' . $action . ' xmlns:u="' . self::AVT_SERVICE . '">' . $arguments . '</u:' . $action . '></s:Body></s:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset="utf-8"',
            'SOAPACTION: "' . self::AVT_SERVICE . '#' . $action . '"',
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
                CURLOPT_TIMEOUT => 5,
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
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $transportError = 'HTTP-Verbindung fehlgeschlagen';
            } else {
                $body = (string) $response;
            }
            if (isset($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'OK', 'body' => $body];
        }

        $upnpCode = '';
        $upnpDescription = '';
        if (preg_match('/<errorCode>([^<]+)<\/errorCode>/i', $body, $m) === 1) {
            $upnpCode = trim($m[1]);
        }
        if (preg_match('/<errorDescription>([^<]+)<\/errorDescription>/i', $body, $m) === 1) {
            $upnpDescription = trim($m[1]);
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

    private function XmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
