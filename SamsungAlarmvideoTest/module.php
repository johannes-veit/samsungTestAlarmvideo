<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const SAMSUNG_TIZEN_MODULE_GUID = '{65BF76B4-042C-4971-A5CC-292FA5E49C86}';
    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const MP4_FILENAME = 'ALARM.mp4';
    private const TS_FILENAME = 'ALARM_DLNA.ts';
    private const HOOK_ADDRESS = 'samsung-alarmvideo-dlna';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SamsungInstanceID', 48488);
        $this->RegisterPropertyInteger('TVStatusVariableID', 16319);
        $this->RegisterPropertyString('TVIP', '192.168.103.54');
        $this->RegisterPropertyString('SymconIP', '192.168.103.59');
        $this->RegisterPropertyInteger('WebPort', 3777);
        $this->RegisterPropertyInteger('StartDelayMs', 4000);

        $this->RegisterHook(self::HOOK_ADDRESS);

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
        $this->SetValue('Status', $prepared['ok'] ? 'Bereit – DLNA-HTTP-Endpunkt aktiv' : $prepared['message']);
    }

    protected function ProcessHookData(): void
    {
        $format = strtolower((string) ($_GET['format'] ?? 'mp4'));
        if (!in_array($format, ['mp4', 'ts'], true)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }

        $path = $this->GetVideoPath($format);
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Video missing';
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }

        $rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        try {
            $countID = $this->GetIDForIdent('RequestCount');
            $current = $countID > 0 ? (int) GetValue($countID) : 0;
            $this->SetValue('RequestCount', $current + 1);
            $requestText = sprintf(
                '%s | %s | %s | %s%s',
                date('d.m.Y H:i:s'),
                $remote,
                $method,
                strtoupper($format),
                $rangeHeader !== '' ? ' | ' . $rangeHeader : ''
            );
            $this->SetValue('LastRequest', $requestText);
            $this->SendDebug('VideoHTTP', $requestText . ($userAgent !== '' ? ' | UA=' . $userAgent : ''), 0);
        } catch (Throwable $e) {
            $this->SendDebug('VideoHTTP', 'Logging fehlgeschlagen: ' . $e->getMessage(), 0);
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            http_response_code(500);
            return;
        }
        $size = (int) $size;

        $start = 0;
        $end = $size - 1;
        $partial = false;

        if ($rangeHeader !== '') {
            if (strpos($rangeHeader, ',') !== false || preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $m) !== 1) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }

            $from = $m[1];
            $to = $m[2];

            if ($from === '' && $to === '') {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }

            if ($from === '') {
                $suffixLength = max(0, (int) $to);
                if ($suffixLength <= 0) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    return;
                }
                $start = max(0, $size - $suffixLength);
            } else {
                $start = (int) $from;
                if ($to !== '') {
                    $end = min($size - 1, (int) $to);
                }
            }

            if ($start < 0 || $start >= $size || $end < $start) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }

            $partial = true;
        }

        $length = $end - $start + 1;
        $mime = $format === 'ts' ? 'video/mpeg' : 'video/mp4';
        $features = $format === 'ts'
            ? 'DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000'
            : 'DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';

        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        header('transferMode.dlna.org: Streaming');
        header('contentFeatures.dlna.org: ' . $features);
        header('Connection: close');
        if ($partial) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        if ($method === 'HEAD') {
            return;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        if ($start > 0) {
            @fseek($handle, $start, SEEK_SET);
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunkSize = min(262144, $remaining);
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
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
        $this->SetTimerInterval('VideoRetry', 0);
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

        $countID = $this->GetIDForIdent('RequestCount');
        $requestCountBefore = $countID > 0 ? (int) GetValue($countID) : 0;
        $errors = [];

        // Erst MP4 direkt, danach MPEG-TS als DLNA-Fallback.
        foreach (['mp4', 'ts'] as $format) {
            $url = $this->GetVideoURLFor($format);
            $path = $this->GetVideoPath($format);
            $size = @filesize($path);
            $metadata = $this->BuildMetadata($url, $size === false ? 0 : (int) $size, $format);

            $set = $this->SendAVTransport(
                'SetAVTransportURI',
                '<InstanceID>0</InstanceID>' .
                '<CurrentURI>' . $this->XmlEscape($url) . '</CurrentURI>' .
                '<CurrentURIMetaData>' . $this->XmlEscape($metadata) . '</CurrentURIMetaData>'
            );

            if (!$set['ok']) {
                $errors[] = strtoupper($format) . ': ' . $set['message'];
                $this->SendDebug('SetAVTransportURI ' . strtoupper($format), $set['message'] . ' | ' . $set['body'], 0);
                continue;
            }

            $play = $this->SendAVTransport('Play', '<InstanceID>0</InstanceID><Speed>1</Speed>');
            if (!$play['ok']) {
                $errors[] = strtoupper($format) . ' Play: ' . $play['message'];
                $this->SendDebug('Play ' . strtoupper($format), $play['message'] . ' | ' . $play['body'], 0);
                continue;
            }

            // Loop ist optional; der erfolgreiche Videostart hat Vorrang.
            $loop = $this->SendAVTransport('SetPlayMode', '<InstanceID>0</InstanceID><NewPlayMode>REPEAT_ONE</NewPlayMode>');
            if (!$loop['ok']) {
                $this->SendDebug('SetPlayMode', $loop['message'] . ' | ' . $loop['body'], 0);
            }

            $message = 'Alarmvideo gestartet (' . strtoupper($format) . ')' . ($loop['ok'] ? ' – Loop angefordert' : '');
            $this->SetValue('Status', $message);
            return $message;
        }

        $requestCountAfter = $countID > 0 ? (int) GetValue($countID) : $requestCountBefore;
        $httpHint = $requestCountAfter > $requestCountBefore
            ? ' | Samsung hat den DLNA-Videoendpunkt abgerufen'
            : ' | noch kein Videoabruf am DLNA-Endpunkt registriert';

        $message = 'Videostart fehlgeschlagen: ' . implode(' | ', $errors) . $httpHint;
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
        return $this->GetVideoURLFor('mp4');
    }

    private function PrepareStaticVideo(bool $force = false): array
    {
        $mp4 = __DIR__ . DIRECTORY_SEPARATOR . self::MP4_FILENAME;
        $ts = __DIR__ . DIRECTORY_SEPARATOR . self::TS_FILENAME;

        if (!is_file($mp4)) {
            return ['ok' => false, 'message' => 'ALARM.mp4 fehlt im Modul'];
        }
        if (!is_file($ts)) {
            return ['ok' => false, 'message' => 'ALARM_DLNA.ts fehlt im Modul'];
        }

        return [
            'ok' => true,
            'message' => 'DLNA-Videoquelle bereit – MP4 + MPEG-TS im Modul'
        ];
    }

    private function GetVideoPath(string $format): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . ($format === 'ts' ? self::TS_FILENAME : self::MP4_FILENAME);
    }

    private function GetVideoURLFor(string $format): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = $this->ReadPropertyInteger('WebPort');
        return sprintf(
            'http://%s:%d/hook/%s?format=%s',
            $host,
            $port,
            self::HOOK_ADDRESS,
            $format === 'ts' ? 'ts' : 'mp4'
        );
    }

    private function GetDLNAProtocol(string $format): string
    {
        if ($format === 'ts') {
            return 'http-get:*:video/mpeg:DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
        }
        return 'http-get:*:video/mp4:DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
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

    private function BuildMetadata(string $url, int $size, string $format): string
    {
        $protocol = $this->GetDLNAProtocol($format);
        $mime = $format === 'ts' ? 'video/mpeg' : 'video/mp4';
        $resolution = $format === 'ts' ? '960x540' : '960x540';

        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" ' .
            'xmlns:sec="http://www.sec.co.kr/" ' .
            'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">' .
            '<item id="0" parentID="0" restricted="0">' .
            '<dc:title>ALARM</dc:title>' .
            '<res size="' . max(0, $size) . '" duration="0:01:00.000" resolution="' . $resolution . '" ' .
            'protocolInfo="' . $this->XmlEscape($protocol) . '" sec:URIType="public">' .
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
