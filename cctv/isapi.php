<?php
// Talking to Hikvision cameras and NVRs over ISAPI (HTTP with digest login).
date_default_timezone_set('Asia/Karachi');

$configFile = __DIR__ . '/config.php';
$CCTV = is_file($configFile) ? require $configFile : ['user' => '', 'password' => '', 'devices' => []];

// Device by its index in config.php, with its login filled in.
function cctv_device(int $i): ?array
{
    global $CCTV;
    $d = $CCTV['devices'][$i] ?? null;
    if (!$d) {
        return null;
    }
    $d['user']     ??= $CCTV['user'] ?? '';
    $d['password'] ??= $CCTV['password'] ?? '';
    $d['name']     ??= $d['ip'];

    return $d;
}

// One ISAPI request. Returns ['code' => HTTP status, 'body' => string, 'type' => content type, 'error' => ?string].
function isapi(array $d, string $method, string $path, ?string $body = null, int $timeout = 8): array
{
    $ch = curl_init('http://' . $d['ip'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST | CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $d['user'] . ':' . $d['password'],
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/xml'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = $res === false ? curl_error($ch) : null;
    curl_close($ch);

    if ($err === null && $code === 401) {
        $err = 'Login refused (check the user / password for this device)';
    } elseif ($err === null && $code >= 400) {
        $err = "HTTP {$code}";
    }

    return ['code' => $code, 'body' => (string) $res, 'type' => $type, 'error' => $err];
}

// GET an ISAPI XML document as a SimpleXML element (namespaces stripped), or null.
function isapi_xml(array $d, string $path, ?string &$error = null): ?SimpleXMLElement
{
    $r = isapi($d, 'GET', $path);
    if ($r['error']) {
        $error = $r['error'];
        return null;
    }
    $xml = @simplexml_load_string(preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $r['body']));
    if (!$xml) {
        $error = 'Unreadable answer';
        return null;
    }

    return $xml;
}

// Text of a child element, or null.
function xv(?SimpleXMLElement $x, string $name): ?string
{
    return $x !== null && isset($x->{$name}) ? trim((string) $x->{$name}) : null;
}

// Whether a device answer describes a recorder (NVR / DVR) rather than a single camera.
function cctv_is_recorder(?SimpleXMLElement $info): bool
{
    $type = strtolower((string) xv($info, 'deviceType'));
    return $type !== '' && !str_contains($type, 'ipcamera') && !str_contains($type, 'ipdome') && !str_contains($type, 'camera');
}

// ISAPI ISO time ("2026-10-21T12:58:46+05:00") as "Y-m-d H:i:s" in Pakistan time.
function cctv_time(?string $iso): ?string
{
    if (!$iso) {
        return null;
    }
    $ts = strtotime($iso);
    return $ts ? date('Y-m-d H:i:s', $ts) : $iso;
}

// --- Camera settings ---------------------------------------------------------------------------

// The settings the Controls panel can change on a camera: where each lives and which element holds it.
// Each change reads the camera's current document, sets that one element and writes it back.
const CCTV_SETTINGS = [
    'brightness'  => ['/ISAPI/Image/channels/1/color', 'brightnessLevel', 'level'],
    'contrast'    => ['/ISAPI/Image/channels/1/color', 'contrastLevel', 'level'],
    'saturation'  => ['/ISAPI/Image/channels/1/color', 'saturationLevel', 'level'],
    'sharpness'   => ['/ISAPI/Image/channels/1/sharpness', 'SharpnessLevel', 'level'],
    'dayNight'    => ['/ISAPI/Image/channels/1/IrcutFilter', 'IrcutFilterType', ['auto', 'day', 'night']],
    'name'        => ['/ISAPI/System/Video/inputs/channels/1', 'name', 'text'],
    'osdDate'     => ['/ISAPI/System/Video/inputs/channels/1/overlays', 'DateTimeOverlay/enabled', 'bool'],
    'osdName'     => ['/ISAPI/System/Video/inputs/channels/1/overlays', 'channelNameOverlay/enabled', 'bool'],
    'motion'      => ['/ISAPI/System/Video/inputs/channels/1/motionDetection', 'enabled', 'bool'],
    'sensitivity' => ['/ISAPI/System/Video/inputs/channels/1/motionDetection', 'MotionDetectionLayout/sensitivityLevel', 'level'],
];

// First element matching a slash-separated path of local names (namespaces ignored), or null.
function cctv_find(DOMDocument $doc, string $path): ?DOMElement
{
    $xp = new DOMXPath($doc);
    $q  = '/*/' . implode('/', array_map(fn($n) => "*[local-name()='{$n}']", explode('/', $path)));
    $n  = $xp->query($q)->item(0);

    return $n instanceof DOMElement ? $n : null;
}

// Current values of all settings (null where the camera has no such setting).
function cctv_settings(array $d): array
{
    $docs = [];
    $out  = [];
    foreach (CCTV_SETTINGS as $key => [$path, $el]) {
        if (!array_key_exists($path, $docs)) {
            $r = isapi($d, 'GET', $path);
            $doc = new DOMDocument();
            $docs[$path] = !$r['error'] && @$doc->loadXML($r['body']) ? $doc : null;
        }
        $n = $docs[$path] ? cctv_find($docs[$path], $el) : null;
        $out[$key] = $n ? trim($n->textContent) : null;
    }

    return $out;
}

// Change one setting. Returns null on success or an error message.
function cctv_set(array $d, string $key, string $value): ?string
{
    if (!isset(CCTV_SETTINGS[$key])) {
        return 'Unknown setting';
    }
    [$path, $el, $kind] = CCTV_SETTINGS[$key];
    if ($kind === 'level' && (!ctype_digit($value) || (int) $value > 100)) {
        return 'Use a whole number from 0 to 100';
    }
    if ($kind === 'bool' && !in_array($value, ['true', 'false'], true)) {
        return 'Use true or false';
    }
    if (is_array($kind) && !in_array($value, $kind, true)) {
        return 'Use one of: ' . implode(', ', $kind);
    }
    if ($kind === 'text' && ($value === '' || mb_strlen($value) > 32)) {
        return 'Name must be 1 to 32 characters';
    }

    $r = isapi($d, 'GET', $path);
    if ($r['error']) {
        return $r['error'];
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($r['body']) || !($n = cctv_find($doc, $el))) {
        return 'This camera does not have that setting';
    }
    $n->textContent = $value;
    $put = isapi($d, 'PUT', $path, $doc->saveXML());

    return $put['error'];
}

// Main and sub stream settings, read only.
function cctv_streams(array $d): array
{
    $out = [];
    foreach (['101' => 'Main', '102' => 'Sub'] as $id => $label) {
        $x = isapi_xml($d, "/ISAPI/Streaming/channels/{$id}");
        if (!$x || !isset($x->Video)) {
            continue;
        }
        $v = $x->Video;
        $fps = (int) ($v->maxFrameRate ?? 0);
        $out[] = [
            'stream'     => $label,
            'codec'      => trim((string) ($v->videoCodecType ?? '')),
            'resolution' => trim((string) ($v->videoResolutionWidth ?? '')) . '×' . trim((string) ($v->videoResolutionHeight ?? '')),
            'fps'        => $fps ? $fps / 100 : null,
            'bitrate'    => trim((string) ($v->vbrUpperCap ?? $v->constantBitRate ?? '')),
            'mode'       => trim((string) ($v->videoQualityControlType ?? '')),
        ];
    }

    return $out;
}

// Recorded clips of one NVR channel on one day (device local time). Track 101 is channel 1's main stream.
function cctv_recordings(array $d, int $channel, string $date): array
{
    $clips = [];
    $pos   = 0;
    do {
        $body = '<?xml version="1.0" encoding="UTF-8"?><CMSearchDescription><searchID>' . strtoupper(bin2hex(random_bytes(4))) . '-0000-0000-0000-' . strtoupper(bin2hex(random_bytes(6))) . '</searchID>'
            . '<trackList><trackID>' . ($channel * 100 + 1) . '</trackID></trackList>'
            . '<timeSpanList><timeSpan><startTime>' . $date . 'T00:00:00Z</startTime><endTime>' . $date . 'T23:59:59Z</endTime></timeSpan></timeSpanList>'
            . '<maxResults>100</maxResults><searchResultPostion>' . $pos . '</searchResultPostion>'
            . '<metadataList><metadataDescriptor>//recordType.meta.std-cgi.com</metadataDescriptor></metadataList></CMSearchDescription>';
        $r = isapi($d, 'POST', '/ISAPI/ContentMgmt/search', $body, 20);
        if ($r['error']) {
            throw new RuntimeException($r['error']);
        }
        $x = @simplexml_load_string(preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $r['body']));
        if (!$x) {
            break;
        }
        foreach ($x->matchList->searchMatchItem ?? [] as $m) {
            $md   = $m->xpath('.//metadataDescriptor');
            $type = $md ? (string) $md[0] : '';
            $clips[] = [
                substr(str_replace('T', ' ', (string) $m->timeSpan->startTime), 0, 19),
                substr(str_replace('T', ' ', (string) $m->timeSpan->endTime), 0, 19),
                ucfirst(substr($type, strrpos($type, '/') + 1)) ?: 'Recording',
            ];
        }
        $pos += 100;
    } while ((string) ($x->responseStatusStrg ?? '') === 'MORE' && $pos < 2000);

    return $clips;
}
