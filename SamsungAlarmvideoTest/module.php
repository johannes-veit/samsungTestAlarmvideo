<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const SAMSUNG_TIZEN_MODULE_GUID = '{65BF76B4-042C-4971-A5CC-292FA5E49C86}';
    private const HOOK_MP4 = 'samsung-alarmvideo-test.mp4';
    private const HOOK_TS = 'samsung-alarmvideo-test.ts';
    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';

    private const MP4_PROTOCOL = 'http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_HP_HD_AAC;DLNA.ORG_OP=01;DLNA.ORG_FLAGS=01700000000000000000000000000000';
    private const TS_PROTOCOL = 'http-get:*:video/mpeg:DLNA.ORG_PN=AVC_TS_HD_EU_ISO;DLNA.ORG_OP=10;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';

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
        $this->RegisterVariableString('LastRequest', 'Letzter Videoabruf', '', 20);
        $this->RegisterVariableInteger('RequestCount', 'Videoabrufe', '', 30);

        $this->RegisterAttributeInteger('WakeAttempts', 0);
        $this->RegisterAttributeInteger('VideoAttempts', 0);

        $this->RegisterTimer('WakeRetry', 0, 'SAVT_TimerWakeRetry($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoStart', 0, 'SAVT_TimerStart($_IPS[\'TARGET\']);');
        $this->RegisterTimer('VideoRetry', 0, 'SAVT_TimerVideoRetry($_IPS[\'TARGET\']);');

        $this->RegisterHook(self::HOOK_MP4);
        $this->RegisterHook(self::HOOK_TS);
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
        $this->SetValue('Status', $validation === '' ? 'Bereit' : 'Konfiguration: ' . $validation);
    }

    public function WakeTV(): string
    {
        $validation = $this->ValidateSamsungInstance();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $result = $this->SendMagicPacket();
        $this->SetValue('Status', $result['message']);
        if ($result['ok']) {
            $this->WriteAttributeInteger('WakeAttempts', 1);
        }
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

        $delay = max(0, $this->ReadPropertyInteger('StartDelayMs'));

        if ($this->TVIsOn()) {
            $this->SetValue('Status', sprintf('TV bereits EIN – Video in %.1f s', $delay / 1000));
            $this->SetTimerInterval('VideoStart', max(250, $delay));
            return (string) $this->GetValue('Status');
        }

        $wake = $this->SendMagicPacket();
        if (!$wake['ok']) {
            $this->SetValue('Status', $wake['message']);
            return $wake['message'];
        }

        $this->WriteAttributeInteger('WakeAttempts', 1);
        $this->SetTimerInterval('WakeRetry', 5000);
        $this->SetTimerInterval('VideoStart', max(250, $delay));

        $message = sprintf('WOL gesendet – Video in %.1f s', $delay / 1000);
        $this->SetValue('Status', $message);
        return $message;
    }

    public function TimerWakeRetry(): void
    {
        $this->SetTimerInterval('WakeRetry', 0);

        if ($this->TVIsOn()) {
            // TV ist jetzt erreichbar. Falls der Video-Start wegen noch AUS gemeldetem
            // Status vorher nicht ausgeführt wurde, unmittelbar nachholen.
            if ($this->ReadAttributeInteger('WakeAttempts') > 0 && $this->ReadAttributeInteger('VideoAttempts') === 0) {
                $this->SetTimerInterval('VideoRetry', 250);
            }
            return;
        }

        if ($this->ReadAttributeInteger('WakeAttempts') >= 2) {
            $this->SetValue('Status', 'TV bleibt AUS – WOL zweimal gesendet');
            return;
        }

        $result = $this->SendMagicPacket();
        if ($result['ok']) {
            $this->WriteAttributeInteger('WakeAttempts', 2);
            $delay = max(250, $this->ReadPropertyInteger('StartDelayMs'));
            $this->SetTimerInterval('VideoStart', $delay);
            $this->SetValue('Status', sprintf('TV noch AUS – 2. WOL gesendet, Video in %.1f s', $delay / 1000));
        } else {
            $this->SetValue('Status', $result['message']);
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

        if (!$this->TVIsOn()) {
            if ($this->ReadAttributeInteger('WakeAttempts') > 0) {
                $this->SetTimerInterval('VideoRetry', 1000);
                $this->SetValue('Status', 'TV noch AUS – Video wartet auf TV-Start');
            } else {
                $this->SetValue('Status', 'TV-Status ist AUS – zuerst TV einschalten oder Kompletttest verwenden');
            }
            return (string) $this->GetValue('Status');
        }

        $attempt = $this->ReadAttributeInteger('VideoAttempts') + 1;
        $this->WriteAttributeInteger('VideoAttempts', $attempt);

        $formats = [
            [
                'name' => 'MP4/DLNA',
                'url' => $this->GetVideoURL('mp4'),
                'protocol' => self::MP4_PROTOCOL,
                'size' => $this->GetMediaSize('mp4'),
                'mime' => 'video/mp4'
            ],
            [
                'name' => 'MPEG-TS/DLNA',
                'url' => $this->GetVideoURL('ts'),
                'protocol' => self::TS_PROTOCOL,
                'size' => $this->GetMediaSize('ts'),
                'mime' => 'video/mpeg'
            ]
        ];

        $errors = [];
        foreach ($formats as $format) {
            $metadata = $this->BuildMetadata(
                $format['url'],
                $format['protocol'],
                (int) $format['size']
            );

            $set = $this->SendAVTransport(
                'SetAVTransportURI',
                '<InstanceID>0</InstanceID>' .
                '<CurrentURI>' . $this->XmlEscape($format['url']) . '</CurrentURI>' .
                '<CurrentURIMetaData>' . $this->XmlEscape($metadata) . '</CurrentURIMetaData>'
            );

            if (!$set['ok']) {
                $errors[] = $format['name'] . ': ' . $set['message'];
                $this->SendDebug('SetAVTransportURI ' . $format['name'], $set['message'] . ' | ' . $set['body'], 0);
                continue;
            }

            $play = $this->SendAVTransport('Play', '<InstanceID>0</InstanceID><Speed>1</Speed>');
            if (!$play['ok']) {
                $errors[] = $format['name'] . ' Play: ' . $play['message'];
                $this->SendDebug('Play ' . $format['name'], $play['message'] . ' | ' . $play['body'], 0);
                continue;
            }

            $loop = $this->SendAVTransport('SetPlayMode', '<InstanceID>0</InstanceID><NewPlayMode>REPEAT_ONE</NewPlayMode>');
            if (!$loop['ok']) {
                $this->SendDebug('SetPlayMode', $loop['message'] . ' | ' . $loop['body'], 0);
            }

            $this->SetTimerInterval('VideoRetry', 0);
            $message = 'Video gestartet über ' . $format['name'] . ($loop['ok'] ? ' – Loop angefordert' : ' – Loop später separat');
            $this->SetValue('Status', $message);
            return $message;
        }

        $message = 'Video nicht gestartet – ' . implode(' | ', $errors);
        if ($attempt < 8) {
            $this->SetTimerInterval('VideoRetry', 1000);
            $message .= sprintf(' – neuer Versuch %d/8', $attempt + 1);
        }
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

    public function GetVideoURL(string $format = 'mp4'): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = $this->ReadPropertyInteger('WebPort');
        $hook = strtolower($format) === 'ts' ? self::HOOK_TS : self::HOOK_MP4;
        return sprintf('http://%s:%d/hook/%s', $host, $port, $hook);
    }

    protected function ProcessHookData(): void
    {
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $isTS = str_contains($uri, self::HOOK_TS);
        $format = $isTS ? 'ts' : 'mp4';
        $file = __DIR__ . DIRECTORY_SEPARATOR . ($isTS ? 'ALARM_DLNA.ts' : 'ALARM_DLNA.mp4');
        $mime = $isTS ? 'video/mpeg' : 'video/mp4';
        $protocol = $isTS ? self::TS_PROTOCOL : self::MP4_PROTOCOL;

        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Alarmvideo fehlt';
            return;
        }

        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false || $size <= 0) {
            http_response_code(500);
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
        $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        $count = (int) $this->GetValue('RequestCount') + 1;
        $this->SetValue('RequestCount', $count);
        $this->SetValue('LastRequest', sprintf('%s – %s – %s – %s%s', date('d.m.Y H:i:s'), $remote, strtoupper($format), $method, $rangeHeader !== '' ? ' – ' . $rangeHeader : ''));

        if ($remote === trim($this->ReadPropertyString('TVIP'))) {
            $this->SetValue('Status', 'Samsung TV ruft Alarmvideo ab (' . strtoupper($format) . ')');
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches) === 1) {
            if ($matches[1] !== '') {
                $start = (int) $matches[1];
            }
            if ($matches[2] !== '') {
                $end = (int) $matches[2];
            }
            if ($start < 0 || $start >= $size || $end < $start) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $end = min($end, $size - 1);
            $status = 206;
        }

        $length = $end - $start + 1;
        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        header('Content-Disposition: inline');
        header('transferMode.dlna.org: Streaming');
        header('contentFeatures.dlna.org: ' . substr($protocol, strrpos($protocol, ':') + 1));

        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        if ($method === 'HEAD') {
            return;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            http_response_code(500);
            return;
        }

        try {
            if ($start > 0) {
                fseek($handle, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                if (function_exists('flush')) {
                    flush();
                }
            }
        } finally {
            fclose($handle);
        }
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

    private function SendMagicPacket(): array
    {
        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        $broadcast = trim((string) IPS_GetProperty($instanceID, 'BroadcastAddress'));
        $mac = trim((string) IPS_GetProperty($instanceID, 'MACAddress'));

        if ($broadcast === '' || $mac === '') {
            return ['ok' => false, 'message' => 'WOL nicht möglich: BroadcastAddress oder MACAddress fehlt in SamsungTizen'];
        }

        $macHex = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
        if (!is_string($macHex) || strlen($macHex) !== 12) {
            return ['ok' => false, 'message' => 'WOL nicht möglich: MACAddress ungültig'];
        }

        $macBytes = pack('H*', $macHex);
        $packet = str_repeat(chr(0xFF), 6) . str_repeat($macBytes, 16);

        if (!function_exists('socket_create')) {
            if (function_exists('SamsungTizen_WakeUp')) {
                try {
                    SamsungTizen_WakeUp($instanceID);
                    return ['ok' => true, 'message' => 'WOL über SamsungTizen_WakeUp() gesendet'];
                } catch (Throwable $e) {
                    return ['ok' => false, 'message' => 'WOL fehlgeschlagen: ' . $e->getMessage()];
                }
            }
            return ['ok' => false, 'message' => 'WOL fehlgeschlagen: Socket-Funktion nicht verfügbar'];
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return ['ok' => false, 'message' => 'WOL fehlgeschlagen: UDP-Socket konnte nicht erstellt werden'];
        }

        try {
            @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $sent = @socket_sendto($socket, $packet, strlen($packet), 0, $broadcast, 2050);
            if ($sent === false || $sent <= 0) {
                return ['ok' => false, 'message' => 'WOL fehlgeschlagen: Magic Packet konnte nicht gesendet werden'];
            }
        } finally {
            @socket_close($socket);
        }

        return ['ok' => true, 'message' => sprintf('WOL Magic Packet gesendet (%s → %s:2050)', $mac, $broadcast)];
    }

    private function GetMediaSize(string $format): int
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . (strtolower($format) === 'ts' ? 'ALARM_DLNA.ts' : 'ALARM_DLNA.mp4');
        $size = @filesize($file);
        return $size === false ? 0 : (int) $size;
    }

    private function BuildMetadata(string $url, string $protocolInfo, int $size): string
    {
        $bitrate = $size > 0 ? (int) round($size / 60.0) : 0;
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" ' .
            'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">' .
            '<item id="0" parentID="0" restricted="1">' .
            '<dc:title>ALARM</dc:title>' .
            '<res size="' . $size . '" duration="0:01:00.000" bitrate="' . $bitrate . '" resolution="1280x720" ' .
            'protocolInfo="' . $this->XmlEscape($protocolInfo) . '" sampleFrequency="48000" nrAudioChannels="2">' .
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
                CURLOPT_TIMEOUT => 4,
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
                    'timeout' => 4,
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
