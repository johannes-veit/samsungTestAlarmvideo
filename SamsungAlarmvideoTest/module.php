<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const HOOK = 'samsung-alarmvideo-test.mp4';
    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SamsungInstanceID', 48488);
        $this->RegisterPropertyString('TVIP', '192.168.103.54');
        $this->RegisterPropertyString('SymconIP', '192.168.103.59');
        $this->RegisterPropertyInteger('WebPort', 3777);
        $this->RegisterPropertyInteger('StartDelayMs', 4000);

        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableString('LastRequest', 'Letzter Videoabruf', '', 20);
        $this->RegisterVariableInteger('RequestCount', 'Videoabrufe', '', 30);

        $this->RegisterTimer('VideoStart', 0, 'SAVT_TimerStart($_IPS[\'TARGET\']);');
        $this->RegisterHook(self::HOOK);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetValue('Status', 'Bereit');
    }

    public function TestVideo(): string
    {
        $this->SetTimerInterval('VideoStart', 0);

        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        $delay = max(0, $this->ReadPropertyInteger('StartDelayMs'));

        if ($instanceID <= 0) {
            $this->SetValue('Status', 'Fehler: SamsungTizen Instanz nicht gewählt');
            return 'SamsungTizen Instanz nicht gewählt.';
        }

        try {
            if (!function_exists('SamsungTizen_WakeUp')) {
                throw new RuntimeException('SamsungTizen_WakeUp() ist nicht verfügbar.');
            }

            SamsungTizen_WakeUp($instanceID);
            $this->SetValue('Status', sprintf('TV-Start gesendet – Video in %.1f s', $delay / 1000));
        } catch (Throwable $e) {
            $this->SetValue('Status', 'TV-Start fehlgeschlagen: ' . $e->getMessage());
            return 'TV-Start fehlgeschlagen: ' . $e->getMessage();
        }

        if ($delay === 0) {
            return $this->StartVideoNow();
        }

        $this->SetTimerInterval('VideoStart', $delay);
        return sprintf('TV-Start gesendet. Alarmvideo startet nach %.1f Sekunden.', $delay / 1000);
    }

    public function TimerStart(): void
    {
        $this->SetTimerInterval('VideoStart', 0);
        $this->StartVideoNow();
    }

    public function StartVideoNow(): string
    {
        $this->SetTimerInterval('VideoStart', 0);

        $videoPath = __DIR__ . DIRECTORY_SEPARATOR . 'ALARM.mp4';
        if (!is_file($videoPath)) {
            $this->SetValue('Status', 'Fehler: ALARM.mp4 fehlt im Modul');
            return 'ALARM.mp4 fehlt im Modul.';
        }

        $url = $this->GetVideoURL();
        $this->SetValue('Status', 'Setze Videoquelle: ' . $url);

        $metadata = $this->BuildMetadata($url);
        $result = $this->SendAVTransport('SetAVTransportURI',
            '<InstanceID>0</InstanceID>' .
            '<CurrentURI>' . $this->XmlEscape($url) . '</CurrentURI>' .
            '<CurrentURIMetaData>' . $this->XmlEscape($metadata) . '</CurrentURIMetaData>'
        );

        if (!$result['ok']) {
            $message = 'SetAVTransportURI fehlgeschlagen: ' . $result['message'];
            $this->SetValue('Status', $message);
            $this->SendDebug('SetAVTransportURI', $result['body'], 0);
            return $message;
        }

        // Loop ist optional. Ein Fehler hier darf die Wiedergabe nicht verhindern.
        $loop = $this->SendAVTransport('SetPlayMode',
            '<InstanceID>0</InstanceID><NewPlayMode>REPEAT_ONE</NewPlayMode>'
        );
        if (!$loop['ok']) {
            $this->SendDebug('SetPlayMode', $loop['message'] . ' | ' . $loop['body'], 0);
        }

        $play = $this->SendAVTransport('Play',
            '<InstanceID>0</InstanceID><Speed>1</Speed>'
        );

        if (!$play['ok']) {
            $message = 'Play fehlgeschlagen: ' . $play['message'];
            $this->SetValue('Status', $message);
            $this->SendDebug('Play', $play['body'], 0);
            return $message;
        }

        $loopText = $loop['ok'] ? 'Loop angefordert' : 'Loop nicht bestätigt';
        $message = 'Wiedergabe gestartet – ' . $loopText;
        $this->SetValue('Status', $message);
        return $message;
    }

    public function StopVideo(): string
    {
        $this->SetTimerInterval('VideoStart', 0);

        $stop = $this->SendAVTransport('Stop', '<InstanceID>0</InstanceID>');
        if (!$stop['ok']) {
            $message = 'Stop: ' . $stop['message'];
            $this->SetValue('Status', $message);
            return $message;
        }

        $this->SetValue('Status', 'Video gestoppt');
        return 'Video gestoppt.';
    }

    public function GetVideoURL(): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = $this->ReadPropertyInteger('WebPort');
        return sprintf('http://%s:%d/hook/%s', $host, $port, self::HOOK);
    }

    protected function ProcessHookData(): void
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'ALARM.mp4';
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'ALARM.mp4 not found';
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

        $countID = $this->GetIDForIdent('RequestCount');
        $count = GetValueInteger($countID) + 1;
        SetValueInteger($countID, $count);
        $requestText = sprintf('%s – %s – %s%s', date('d.m.Y H:i:s'), $remote, $method, $rangeHeader !== '' ? ' – ' . $rangeHeader : '');
        $this->SetValue('LastRequest', $requestText);

        if ($remote === $this->ReadPropertyString('TVIP')) {
            $this->SetValue('Status', 'Samsung TV ruft ALARM.mp4 ab');
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
        header('Content-Type: video/mp4');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');
        header('Connection: close');
        header('transferMode.dlna.org: Streaming');
        header('contentFeatures.dlna.org: DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000');

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

    private function BuildMetadata(string $url): string
    {
        $protocolInfo = 'http-get:*:video/mp4:DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
        return '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" ' .
            'xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">' .
            '<item id="0" parentID="0" restricted="1">' .
            '<dc:title>ALARM</dc:title>' .
            '<upnp:class>object.item.videoItem</upnp:class>' .
            '<res protocolInfo="' . $protocolInfo . '">' . $this->XmlEscape($url) . '</res>' .
            '</item></DIDL-Lite>';
    }

    private function SendAVTransport(string $action, string $arguments): array
    {
        $tvIP = trim($this->ReadPropertyString('TVIP'));
        if ($tvIP === '') {
            return ['ok' => false, 'message' => 'TV-IP fehlt', 'body' => ''];
        }

        $url = 'http://' . $tvIP . ':9197/upnp/control/AVTransport1';
        $soap = '<?xml version="1.0" encoding="utf-8"?>' .
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" ' .
            's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body><u:' . $action . ' xmlns:u="' . self::AVT_SERVICE . '">' .
            $arguments .
            '</u:' . $action . '></s:Body></s:Envelope>';

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
                CURLOPT_CONNECTTIMEOUT => 3,
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
