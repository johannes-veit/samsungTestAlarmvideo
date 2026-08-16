<?php

declare(strict_types=1);

class SamsungAlarmvideoTest extends IPSModuleStrict
{
    private const SAMSUNG_TIZEN_MODULE_GUID = '{65BF76B4-042C-4971-A5CC-292FA5E49C86}';
    private const AVT_SERVICE = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const MP4_FILENAME = 'ALARM.mp4';
    private const TS_FILENAME = 'ALARM_DLNA.ts';
    private const HOOK_MP4 = 'samsung-alarmvideo-dlna.mp4';
    private const HOOK_MPEG = 'samsung-alarmvideo-dlna.mpeg';
    private const HOOK_PLAYER = 'samsung-alarmvideo-player.html';
    private const WEBSOCKET_CLIENT_GUID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SamsungInstanceID', 48488);
        $this->RegisterPropertyInteger('TVStatusVariableID', 16319);
        $this->RegisterPropertyString('TVIP', '192.168.103.54');
        $this->RegisterPropertyString('SymconIP', '192.168.103.59');
        $this->RegisterPropertyInteger('WebPort', 3777);
        $this->RegisterPropertyInteger('StartDelayMs', 4000);

        $this->RegisterHook(self::HOOK_MP4);
        $this->RegisterHook(self::HOOK_MPEG);
        $this->RegisterHook(self::HOOK_PLAYER);

        $this->RegisterVariableString('Status', 'Status', '', 10);
        // Bestehende Variablen aus 0.1.2 bewusst erhalten, damit das Update
        // keine Objektbaum-Unruhe erzeugt. Sie werden ab 0.1.3 nicht mehr benutzt.
        $this->RegisterVariableString('LastRequest', 'Letzter Videoabruf', '', 20);
        $this->RegisterVariableInteger('RequestCount', 'Videoabrufe', '', 30);

        $this->RegisterAttributeInteger('WakeAttempts', 0);
        $this->RegisterAttributeInteger('VideoAttempts', 0);
        $this->RegisterAttributeInteger('RequestCountBeforeBrowser', 0);

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
        $this->WriteAttributeInteger('RequestCountBeforeBrowser', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', 'Konfiguration: ' . $validation);
            return;
        }

        $prepared = $this->PrepareStaticVideo();
        $this->SetValue('Status', $prepared['ok'] ? 'Bereit – Browser-Wiedergabe über Samsung WebSocket' : $prepared['message']);
    }

    protected function ProcessHookData(): void
    {
        $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

        if (str_contains($requestUri, self::HOOK_PLAYER)) {
            $this->ServePlayerPage();
            return;
        }

        $format = str_contains($requestUri, self::HOOK_MPEG) ? 'ts' : 'mp4';
        $this->ServeVideoFile($format);
    }

    private function ServePlayerPage(): void
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->RegisterHttpRequest($remote, $method, 'PLAYER', '');

        $tvIP = trim($this->ReadPropertyString('TVIP'));
        if ($remote === $tvIP) {
            $this->SetValue('Status', 'Samsung-Browser hat den Alarm-Player geladen');
        }

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }

        $mp4 = '/hook/' . self::HOOK_MP4;
        $html = '<!doctype html><html><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">' .
            '<style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#000}' .
            'video{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;background:#000}</style></head>' .
            '<body><video id="v" autoplay loop playsinline preload="auto" src="' . $mp4 . '"></video>' .
            '<script>(function(){var v=document.getElementById("v");v.muted=false;v.volume=1;' .
            'function p(){try{var r=v.play();if(r&&r.catch){r.catch(function(){})}}catch(e){}}' .
            'v.addEventListener("loadeddata",p);v.addEventListener("canplay",p);' .
            'v.addEventListener("ended",function(){v.currentTime=0;p()});' .
            'setTimeout(p,100);setInterval(function(){if(v.paused)p()},750);})();</script></body></html>';

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Length: ' . strlen($html));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        if ($method !== 'HEAD') {
            echo $html;
        }
    }

    private function ServeVideoFile(string $format): void
    {
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
        $this->RegisterHttpRequest($remote, $method, strtoupper($format), $rangeHeader);

        $tvIP = trim($this->ReadPropertyString('TVIP'));
        if ($remote === $tvIP) {
            $this->SetValue('Status', 'Samsung lädt das Alarmvideo');
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

        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Connection: close');
        if ($partial) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        if ($method === 'HEAD') {
            return;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
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

    private function RegisterHttpRequest(string $remote, string $method, string $kind, string $rangeHeader): void
    {
        try {
            $countID = $this->GetIDForIdent('RequestCount');
            $current = $countID > 0 ? (int) GetValue($countID) : 0;
            $this->SetValue('RequestCount', $current + 1);
            $requestText = sprintf(
                '%s | %s | %s | %s%s',
                date('d.m.Y H:i:s'),
                $remote,
                $method,
                $kind,
                $rangeHeader !== '' ? ' | ' . $rangeHeader : ''
            );
            $this->SetValue('LastRequest', $requestText);
            $this->SendDebug('HTTP', $requestText, 0);
        } catch (Throwable $e) {
            $this->SendDebug('HTTP', 'Logging fehlgeschlagen: ' . $e->getMessage(), 0);
        }
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

        $countID = $this->GetIDForIdent('RequestCount');
        $current = $countID > 0 ? (int) GetValue($countID) : 0;
        $before = $this->ReadAttributeInteger('RequestCountBeforeBrowser');
        if ($current > $before) {
            return;
        }

        $attempt = $this->ReadAttributeInteger('VideoAttempts');
        if ($attempt >= 3) {
            $this->SetValue('Status', 'Browserstart versucht, aber Samsung hat den Player nicht abgerufen');
            return;
        }

        // Zweiter Versuch mit der 2020er Internet-App-ID, dritter wieder mit dem generischen Browser-ID.
        $nextAttempt = $attempt + 1;
        $appID = $nextAttempt === 2 ? '3202010022079' : 'org.tizen.browser';
        $launch = $this->LaunchBrowserPlayer($appID);
        $this->WriteAttributeInteger('VideoAttempts', $nextAttempt);

        if (!$launch['ok']) {
            if ($nextAttempt < 3) {
                $this->SetTimerInterval('VideoRetry', 2500);
                $this->SetValue('Status', 'WebSocket noch nicht bereit – Browser-Retry folgt automatisch');
                return;
            }
            $this->SetValue('Status', 'Browserstart fehlgeschlagen: ' . $launch['message']);
            return;
        }

        $this->SetTimerInterval('VideoRetry', 2500);
        $this->SetValue('Status', 'Browser-Retry gesendet – warte auf Playerabruf');
    }

    public function StartVideoNow(): string
    {
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);

        $validation = $this->ValidateConfiguration();
        if ($validation !== '') {
            $this->SetValue('Status', $validation);
            return $validation;
        }

        $countID = $this->GetIDForIdent('RequestCount');
        $count = $countID > 0 ? (int) GetValue($countID) : 0;
        $this->WriteAttributeInteger('RequestCountBeforeBrowser', $count);
        $this->WriteAttributeInteger('VideoAttempts', 1);

        $launch = $this->LaunchBrowserPlayer('org.tizen.browser');
        $this->SetTimerInterval('VideoRetry', 2500);
        if (!$launch['ok']) {
            $message = 'WebSocket noch nicht bereit – Browser-Retry folgt automatisch';
            $this->SetValue('Status', $message);
            return $message;
        }

        $message = 'Samsung-Browserstart gesendet – Alarmvideo wird automatisch gestartet';
        $this->SetValue('Status', $message);
        return $message;
    }

    public function StopVideo(): string
    {
        $this->SetTimerInterval('WakeRetry', 0);
        $this->SetTimerInterval('VideoStart', 0);
        $this->SetTimerInterval('VideoRetry', 0);
        $this->WriteAttributeInteger('VideoAttempts', 0);

        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        try {
            if (function_exists('SamsungTizen_SendKeys')) {
                SamsungTizen_SendKeys($instanceID, 'KEY_RETURN');
                $this->SetValue('Status', 'Browser/Video beenden gesendet');
                return 'Browser/Video beenden gesendet';
            }
        } catch (Throwable $e) {
            $this->SendDebug('Stop', $e->getMessage(), 0);
        }

        $message = 'Browser konnte nicht beendet werden – SamsungTizen_SendKeys nicht verfügbar';
        $this->SetValue('Status', $message);
        return $message;
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

    public function GetPlayerURL(): string
    {
        $host = trim($this->ReadPropertyString('SymconIP'));
        $port = $this->ReadPropertyInteger('WebPort');
        return sprintf('http://%s:%d/hook/%s', $host, $port, self::HOOK_PLAYER);
    }

    private function PrepareStaticVideo(bool $force = false): array
    {
        $mp4 = __DIR__ . DIRECTORY_SEPARATOR . self::MP4_FILENAME;
        if (!is_file($mp4)) {
            return ['ok' => false, 'message' => 'ALARM.mp4 fehlt im Modul'];
        }

        return [
            'ok' => true,
            'message' => 'Alarmvideo bereit – Browser-Player und MP4-Endpunkt aktiv'
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
            'http://%s:%d/hook/%s',
            $host,
            $port,
            $format === 'ts' ? self::HOOK_MPEG : self::HOOK_MP4
        );
    }

    private function GetDLNAProtocol(string $format): string
    {
        if ($format === 'ts') {
            return 'http-get:*:video/mpeg:DLNA.ORG_PN=AVC_TS_MP_HD_AAC_MULT5_ISO;DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
        }
        return 'http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_HP_HD_AAC;DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
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

    private function LaunchBrowserPlayer(string $appID): array
    {
        $wsID = $this->FindSamsungWebSocketClient();
        if ($wsID <= 0) {
            return ['ok' => false, 'message' => 'WebSocket-Client der SamsungTizen-Instanz nicht gefunden'];
        }
        if (!function_exists('WSC_SendMessage')) {
            return ['ok' => false, 'message' => 'WSC_SendMessage ist nicht verfügbar'];
        }

        $payload = json_encode([
            'method' => 'ms.channel.emit',
            'params' => [
                'event' => 'ed.apps.launch',
                'to' => 'host',
                'data' => [
                    'action_type' => 'NATIVE_LAUNCH',
                    'appId' => $appID,
                    'metaTag' => $this->GetPlayerURL()
                ]
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return ['ok' => false, 'message' => 'Browser-Befehl konnte nicht erzeugt werden'];
        }

        try {
            $ok = WSC_SendMessage($wsID, $payload);
            $this->SendDebug('BrowserLaunch', 'WS #' . $wsID . ' app=' . $appID . ' url=' . $this->GetPlayerURL() . ' result=' . ($ok ? 'true' : 'false'), 0);
            return $ok
                ? ['ok' => true, 'message' => 'Browser-Befehl gesendet']
                : ['ok' => false, 'message' => 'WebSocket hat Browser-Befehl nicht angenommen'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Browser-Befehl fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    private function FindSamsungWebSocketClient(): int
    {
        $instanceID = $this->ReadPropertyInteger('SamsungInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return 0;
        }

        $wsInstances = IPS_GetInstanceListByModuleID(self::WEBSOCKET_CLIENT_GUID);
        $current = $instanceID;
        for ($i = 0; $i < 5; $i++) {
            $instance = IPS_GetInstance($current);
            $parentID = (int) ($instance['ConnectionID'] ?? 0);
            if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
                return 0;
            }
            if (in_array($parentID, $wsInstances, true)) {
                return $parentID;
            }
            $current = $parentID;
        }
        return 0;
    }

    private function BuildMetadata(string $url, int $size, string $format): string
    {
        $protocol = $this->GetDLNAProtocol($format);
        $mime = $format === 'ts' ? 'video/mpeg' : 'video/mp4';
        $resolution = '1280x720';

        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
            'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" ' .
            'xmlns:sec="http://www.sec.co.kr/" ' .
            'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">' .
            '<item id="1000" parentID="0" restricted="1">' .
            '<dc:title>ALARM</dc:title>' .
            '<res size="' . max(0, $size) . '" duration="0:01:00.000" resolution="' . $resolution . '" ' .
            'protocolInfo="' . $this->XmlEscape($protocol) . '" sampleFrequency="48000" nrAudioChannels="2">' .
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
