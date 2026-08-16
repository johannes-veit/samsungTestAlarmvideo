<?php

declare(strict_types=1);

class SamsungAlarmvideoMediaServer extends IPSModuleStrict
{
    private const SOCKET_TX_GUID = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';
    private const MPEG_FEATURES = 'DLNA.ORG_PN=AVC_TS_MP_HD_AAC_MULT5_ISO;DLNA.ORG_OP=10;DLNA.ORG_CI=1;DLNA.ORG_FLAGS=01700000000000000000000000000000';
    private const MP4_FEATURES = 'DLNA.ORG_PN=AVC_MP4_HP_HD_AAC;DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000';
    private const MPEG_DURATION = 60.053;
    private const MP4_DURATION = 60.010;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeString('ClientBuffers', '{}');
        $this->RegisterAttributeInteger('RequestCount', 0);
        $this->RegisterAttributeString('LastRequest', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->WriteAttributeString('ClientBuffers', '{}');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'moduleIDs' => ['{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}']
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        $clientIP = (string) ($data['ClientIP'] ?? '');
        $clientPort = (int) ($data['ClientPort'] ?? 0);
        $type = (int) ($data['Type'] ?? 0);
        $key = $clientIP . ':' . $clientPort;

        if ($clientIP === '' || $clientPort <= 0) {
            return '';
        }

        $buffers = $this->ReadClientBuffers();
        if ($type === 1) {
            $buffers[$key] = '';
            $this->WriteClientBuffers($buffers);
            return '';
        }
        if ($type === 2) {
            unset($buffers[$key]);
            $this->WriteClientBuffers($buffers);
            return '';
        }

        $incoming = $this->DecodeSocketBuffer((string) ($data['Buffer'] ?? ''));
        $buffer = (string) ($buffers[$key] ?? '') . $incoming;
        if (strlen($buffer) > 131072) {
            unset($buffers[$key]);
            $this->WriteClientBuffers($buffers);
            $this->SendHttpError($clientIP, $clientPort, 431, 'Request Header Fields Too Large');
            return '';
        }

        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            $buffers[$key] = $buffer;
            $this->WriteClientBuffers($buffers);
            return '';
        }

        unset($buffers[$key]);
        $this->WriteClientBuffers($buffers);
        $this->HandleHttpRequest($clientIP, $clientPort, substr($buffer, 0, $headerEnd + 4));
        return '';
    }

    public function ResetStats(): void
    {
        $this->WriteAttributeInteger('RequestCount', 0);
        $this->WriteAttributeString('LastRequest', '');
    }

    public function GetStats(): string
    {
        return json_encode([
            'count' => $this->ReadAttributeInteger('RequestCount'),
            'last' => $this->ReadAttributeString('LastRequest')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function GetStatus(): string
    {
        $mpeg = is_file($this->GetMediaPath('mpeg'));
        $mp4 = is_file($this->GetMediaPath('mp4'));
        $parentID = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        return sprintf(
            'Helper #%d, Socket #%d, MPEG %s, MP4 %s',
            $this->InstanceID,
            $parentID,
            $mpeg ? 'OK' : 'FEHLT',
            $mp4 ? 'OK' : 'FEHLT'
        );
    }

    private function HandleHttpRequest(string $clientIP, int $clientPort, string $header): void
    {
        $lines = preg_split('/\r\n/', trim($header));
        if (!is_array($lines) || count($lines) === 0) {
            $this->SendHttpError($clientIP, $clientPort, 400, 'Bad Request');
            return;
        }

        $requestLine = array_shift($lines);
        if (!is_string($requestLine) || preg_match('#^(GET|HEAD)\s+(\S+)\s+HTTP/1\.[01]$#i', $requestLine, $m) !== 1) {
            $this->SendHttpError($clientIP, $clientPort, 405, 'Method Not Allowed');
            return;
        }

        $method = strtoupper($m[1]);
        $target = $m[2];
        $headers = [];
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $parts = parse_url($target);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $mode = '';
        if (preg_match('#^/MDEServer/[A-Za-z0-9-]+/1000\.mpeg$#i', $path) === 1) {
            $mode = 'mpeg';
        } elseif (preg_match('#^/MDEServer/[A-Za-z0-9-]+/1000\.mp4$#i', $path) === 1) {
            $mode = 'mp4';
        }

        $this->RegisterRequest($clientIP, $clientPort, $method, $target, $headers);

        if ($mode === '') {
            if ($path === '/' || $path === '/status') {
                $this->SendHttpText($clientIP, $clientPort, $method, 200, 'OK', "Samsung Alarmvideo DLNA MediaServer v0.2.0\n");
                return;
            }
            $this->SendHttpError($clientIP, $clientPort, 404, 'Not Found');
            return;
        }

        $this->ServeMediaFile($clientIP, $clientPort, $method, $mode, $headers);
    }

    private function ServeMediaFile(string $clientIP, int $clientPort, string $method, string $mode, array $headers): void
    {
        $path = $this->GetMediaPath($mode);
        if (!is_file($path)) {
            $this->SendHttpError($clientIP, $clientPort, 404, 'Not Found');
            return;
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size <= 0) {
            $this->SendHttpError($clientIP, $clientPort, 500, 'Internal Server Error');
            return;
        }

        $duration = $mode === 'mpeg' ? self::MPEG_DURATION : self::MP4_DURATION;
        $start = 0;
        $end = $size - 1;
        $partial = false;
        $timeStart = 0.0;

        $range = trim((string) ($headers['range'] ?? ''));
        if ($range !== '') {
            if (preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $rm) !== 1 || ($rm[1] === '' && $rm[2] === '')) {
                $this->SendRangeNotSatisfiable($clientIP, $clientPort, $size);
                return;
            }
            if ($rm[1] === '') {
                $suffix = max(1, (int) $rm[2]);
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $rm[1];
                if ($rm[2] !== '') {
                    $end = min($size - 1, (int) $rm[2]);
                }
            }
            if ($start >= $size || $end < $start) {
                $this->SendRangeNotSatisfiable($clientIP, $clientPort, $size);
                return;
            }
            $partial = true;
            $timeStart = ($start / $size) * $duration;
        } else {
            $timeSeek = trim((string) ($headers['timeseekrange.dlna.org'] ?? ''));
            if ($timeSeek !== '' && preg_match('/npt=([0-9.]+)-/i', $timeSeek, $tm) === 1) {
                $timeStart = max(0.0, min($duration - 0.01, (float) $tm[1]));
                $start = max(0, min($size - 1, (int) floor(($timeStart / $duration) * $size)));
                $partial = $start > 0;
            }
        }

        $length = $end - $start + 1;
        $mime = $mode === 'mpeg' ? 'video/mpeg' : 'video/mp4';
        $features = $mode === 'mpeg' ? self::MPEG_FEATURES : self::MP4_FEATURES;
        $responseHeaders = [
            $partial ? 'HTTP/1.1 206 Partial Content' : 'HTTP/1.1 200 OK',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
            'Server: Microsoft-HTTPAPI/2.0',
            'Content-Type: ' . $mime,
            'Content-Length: ' . $length,
            'Accept-Ranges: bytes',
            'transferMode.dlna.org: Streaming',
            'contentFeatures.dlna.org: ' . $features,
            'Connection: close'
        ];
        if ($partial) {
            $responseHeaders[] = sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size);
        }
        if (isset($headers['timeseekrange.dlna.org'])) {
            $responseHeaders[] = sprintf(
                'TimeSeekRange.dlna.org: npt=%.3f-%.3f/%.3f bytes=%d-%d/%d',
                $timeStart,
                $duration,
                $duration,
                $start,
                $end,
                $size
            );
        }

        if (!$this->SendSocketRaw($clientIP, $clientPort, implode("\r\n", $responseHeaders) . "\r\n\r\n")) {
            $this->CloseSocketClient($clientIP, $clientPort);
            return;
        }
        if ($method === 'HEAD') {
            $this->CloseSocketClient($clientIP, $clientPort);
            return;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->CloseSocketClient($clientIP, $clientPort);
            return;
        }
        if ($start > 0) {
            @fseek($handle, $start, SEEK_SET);
        }

        $remaining = $length;
        try {
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if (!$this->SendSocketRaw($clientIP, $clientPort, $chunk)) {
                    break;
                }
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
            $this->CloseSocketClient($clientIP, $clientPort);
        }
    }

    private function RegisterRequest(string $clientIP, int $clientPort, string $method, string $target, array $headers): void
    {
        $count = $this->ReadAttributeInteger('RequestCount') + 1;
        $range = (string) ($headers['range'] ?? $headers['timeseekrange.dlna.org'] ?? '');
        $text = sprintf(
            '%s | %s:%d | %s %s%s',
            date('d.m.Y H:i:s'),
            $clientIP,
            $clientPort,
            $method,
            $target,
            $range !== '' ? ' | ' . $range : ''
        );
        $this->WriteAttributeInteger('RequestCount', $count);
        $this->WriteAttributeString('LastRequest', $text);
        $this->SendDebug('HTTP', $text, 0);
    }

    private function SendHttpText(string $clientIP, int $clientPort, string $method, int $status, string $reason, string $body): void
    {
        $raw = 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n" .
            'Content-Type: text/plain; charset=utf-8' . "\r\n" .
            'Content-Length: ' . strlen($body) . "\r\n" .
            'Connection: close' . "\r\n\r\n";
        if ($method !== 'HEAD') {
            $raw .= $body;
        }
        $this->SendSocketRaw($clientIP, $clientPort, $raw);
        $this->CloseSocketClient($clientIP, $clientPort);
    }

    private function SendHttpError(string $clientIP, int $clientPort, int $status, string $reason): void
    {
        $this->SendHttpText($clientIP, $clientPort, 'GET', $status, $reason, $status . ' ' . $reason . "\n");
    }

    private function SendRangeNotSatisfiable(string $clientIP, int $clientPort, int $size): void
    {
        $raw = "HTTP/1.1 416 Range Not Satisfiable\r\n" .
            'Content-Range: bytes */' . $size . "\r\n" .
            "Content-Length: 0\r\nConnection: close\r\n\r\n";
        $this->SendSocketRaw($clientIP, $clientPort, $raw);
        $this->CloseSocketClient($clientIP, $clientPort);
    }

    private function SendSocketRaw(string $clientIP, int $clientPort, string $raw): bool
    {
        $payload = [
            'DataID' => self::SOCKET_TX_GUID,
            'Buffer' => $this->EncodeSocketBuffer($raw),
            'Type' => 0,
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        try {
            $this->SendDataToParent($json);
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('HTTP', 'SendDataToParent: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function CloseSocketClient(string $clientIP, int $clientPort): void
    {
        $json = json_encode([
            'DataID' => self::SOCKET_TX_GUID,
            'Buffer' => '',
            'Type' => 2,
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        try {
            $this->SendDataToParent($json);
        } catch (Throwable $e) {
            $this->SendDebug('HTTP', 'Close: ' . $e->getMessage(), 0);
        }
    }

    private function EncodeSocketBuffer(string $raw): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }
        if (function_exists('utf8_encode')) {
            return utf8_encode($raw);
        }
        return $raw;
    }

    private function DecodeSocketBuffer(string $encoded): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($encoded, 'ISO-8859-1', 'UTF-8');
        }
        if (function_exists('utf8_decode')) {
            return utf8_decode($encoded);
        }
        return $encoded;
    }

    private function ReadClientBuffers(): array
    {
        $buffers = json_decode($this->ReadAttributeString('ClientBuffers'), true);
        return is_array($buffers) ? $buffers : [];
    }

    private function WriteClientBuffers(array $buffers): void
    {
        $json = json_encode($buffers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->WriteAttributeString('ClientBuffers', $json !== false ? $json : '{}');
    }

    private function GetMediaPath(string $mode): string
    {
        $root = dirname(__DIR__);
        return $root . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . ($mode === 'mp4' ? 'ALARM.mp4' : 'ALARM_DLNA.mpeg');
    }
}
