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
