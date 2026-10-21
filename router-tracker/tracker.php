<?php
// Router Activity Tracker: reading a Tenda router and keeping its samples in SQLite.
date_default_timezone_set('Asia/Karachi');

const RT_DATA = __DIR__ . '/data';

function rt_config(): array
{
    $file = __DIR__ . '/config.php';
    $c = is_file($file) ? require $file : [];
    $c['interval'] = max(5, (int) ($c['interval'] ?? 15));
    $c['keepDays'] = max(1, (int) ($c['keepDays'] ?? 90));
    $c['routers']  = $c['routers'] ?? [];
    $c['labels']   = array_change_key_case($c['labels'] ?? [], CASE_LOWER);

    return $c;
}

function rt_db(): PDO
{
    static $db = null;
    if ($db) {
        return $db;
    }
    if (!is_dir(RT_DATA)) {
        mkdir(RT_DATA, 0775, true);
    }
    $db = new PDO('sqlite:' . RT_DATA . '/tracker.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    // One row per device per sample; speeds in KB/s as the router reports them.
    $db->exec('CREATE TABLE IF NOT EXISTS samples (
        router TEXT NOT NULL, ts INTEGER NOT NULL, mac TEXT NOT NULL,
        ip TEXT, name TEXT, conn TEXT, down REAL, up REAL)');
    $db->exec('CREATE INDEX IF NOT EXISTS samples_router_ts ON samples (router, ts)');
    $db->exec('CREATE INDEX IF NOT EXISTS samples_router_mac_ts ON samples (router, mac, ts)');
    // One row per sample: when the router was read and whether it answered.
    $db->exec('CREATE TABLE IF NOT EXISTS polls (
        router TEXT NOT NULL, ts INTEGER NOT NULL, ok INTEGER NOT NULL, devices INTEGER, wan_seconds INTEGER, error TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS polls_router_ts ON polls (router, ts)');

    return $db;
}

// One HTTP request to the router, with the session cookie kept in data/ between requests.
function rt_http(array $router, string $method, string $path, ?string $body = null): string
{
    $ch = curl_init(rtrim($router['url'], '/') . '/' . ltrim($path, '/'));
    $cookies = RT_DATA . '/cookies-' . md5($router['url']) . '.txt';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $err = $res === false ? curl_error($ch) : null;
    curl_close($ch);
    if ($err) {
        throw new RuntimeException("Router did not answer: {$err}");
    }

    return (string) $res;
}

// Same as the router's login page: user admin, password Base64-encoded.
function rt_login(array $router): void
{
    rt_http($router, 'POST', 'login/Auth', http_build_query(['username' => 'admin', 'password' => base64_encode((string) ($router['password'] ?? ''))]));
}

// A login page (HTML) or an empty object means the session is missing or expired.
function rt_json(string $body): ?array
{
    $data = json_decode($body, true);
    return is_array($data) && $data !== [] && !str_contains($body, '<!DOCTYPE') ? $data : null;
}

// GET a Tenda goform JSON document, logging in (and retrying once) when needed.
function rt_tenda_get(array $router, string $path): array
{
    $path .= (str_contains($path, '?') ? '&' : '?') . 'r=' . mt_rand();
    for ($try = 0; $try < 2; $try++) {
        if ($data = rt_json(rt_http($router, 'GET', $path))) {
            return $data;
        }
        if ($try === 0) {
            rt_login($router);
        }
    }
    throw new RuntimeException('The router refused the login (check the password in router-tracker/config.php)');
}

// POST a change, as the router's own pages do. Returns the router's errCode.
function rt_tenda_post(array $router, string $path, array $fields): int
{
    for ($try = 0; $try < 2; $try++) {
        $data = rt_json(rt_http($router, 'POST', $path, http_build_query($fields)));
        if ($data !== null) {
            return (int) ($data['errCode'] ?? -1);
        }
        if ($try === 0) {
            rt_login($router);
        }
    }
    throw new RuntimeException('The router refused the login (check the password in router-tracker/config.php)');
}

// Devices online right now, plus WAN uptime.
function rt_read(array $router): array
{
    $qos = rt_tenda_get($router, 'goform/getQos?modules=onlineList');
    $devices = [];
    foreach ($qos['onlineList'] ?? [] as $d) {
        $mac = strtolower(trim((string) ($d['qosListMac'] ?? '')));
        if ($mac === '') {
            continue;
        }
        $devices[$mac] = [
            'mac'  => $mac,
            'ip'   => trim((string) ($d['qosListIP'] ?? '')),
            'name' => trim((string) ($d['qosListRemark'] ?? '')) ?: trim((string) ($d['qosListHostname'] ?? '')),
            'conn' => trim((string) ($d['qosListConnectType'] ?? '')),
            'down' => (float) ($d['qosListDownSpeed'] ?? 0),
            'up'   => (float) ($d['qosListUpSpeed'] ?? 0),
            'downLimit' => rt_limit($d['qosListDownLimit'] ?? null),
            'upLimit'   => rt_limit($d['qosListUpLimit'] ?? null),
        ];
    }
    $wan = null;
    try {
        $st  = rt_tenda_get($router, 'goform/getStatus?modules=systemInfo');
        $wan = isset($st['systemInfo']['wanConnectTime']) ? (int) $st['systemInfo']['wanConnectTime'] : null;
    } catch (Throwable $e) {
        // Device list is what matters; WAN uptime is a bonus.
    }

    return ['devices' => array_values($devices), 'wanSeconds' => $wan];
}

// --- Management (Bandwidth Control / Administration in the router's own pages) -----------------

// The router's "no limit" value, in KB/s; anything from 38401 up means unlimited.
const RT_NO_LIMIT = 38528;

// A reported limit as KB/s, or null for "no limit".
function rt_limit($v): ?int
{
    return $v === null || (float) $v >= 38401 ? null : (int) $v;
}

// Every device the router has QoS / access entries for: online ones and blocked ones, by MAC.
function rt_qos_state(array $router): array
{
    $q = rt_tenda_get($router, 'goform/getQos?modules=onlineList,blackList');
    $list = [];
    foreach ($q['onlineList'] ?? [] as $d) {
        $mac = strtolower(trim((string) $d['qosListMac']));
        $list[$mac] = [
            'mac' => $mac, 'hostname' => (string) ($d['qosListHostname'] ?? ''), 'remark' => (string) ($d['qosListRemark'] ?? ''),
            'up' => rt_limit($d['qosListUpLimit'] ?? null), 'down' => rt_limit($d['qosListDownLimit'] ?? null),
            'allowed' => ($d['qosListAccess'] ?? 'true') !== 'false', 'online' => true, 'ip' => (string) ($d['qosListIP'] ?? ''),
        ];
    }
    foreach ($q['blackList'] ?? [] as $d) {
        $mac = strtolower(trim((string) $d['qosListMac']));
        $list[$mac] = ($list[$mac] ?? [
            'mac' => $mac, 'hostname' => (string) ($d['qosListHostname'] ?? ''), 'remark' => (string) ($d['qosListRemark'] ?? ''),
            'up' => null, 'down' => null, 'online' => false, 'ip' => (string) ($d['qosListIP'] ?? ''),
        ]);
        $list[$mac]['allowed'] = false;
    }

    return $list;
}

// Change one device and send the whole list back: the router replaces its table with what it is
// given, so every other device goes back exactly as it was. $change keys: allowed, up, down, remark.
function rt_qos_change(array $router, string $mac, array $change): array
{
    $mac   = strtolower($mac);
    $state = rt_qos_state($router);
    if (!isset($state[$mac])) {
        throw new RuntimeException('That device is not in the router\'s list (it may have disconnected)');
    }
    $state[$mac] = array_merge($state[$mac], $change);

    // One line per device: hostname, remark ('' when same as hostname), MAC, up limit, down limit, access.
    $clean = fn(string $s) => str_replace(["\t", "\n", "\r"], ' ', $s);
    $lines = [];
    foreach ($state as $d) {
        $remark = $d['remark'] === $d['hostname'] ? '' : $d['remark'];
        $up     = $d['allowed'] ? min(RT_NO_LIMIT, $d['up'] ?? RT_NO_LIMIT) : 0;
        $down   = $d['allowed'] ? min(RT_NO_LIMIT, $d['down'] ?? RT_NO_LIMIT) : 0;
        $lines[] = implode("\t", [$clean($d['hostname']), $clean($remark), strtoupper($d['mac']), $up, $down, $d['allowed'] ? 'true' : 'false']);
    }
    $code = rt_tenda_post($router, 'goform/setQos', ['module1' => 'qosList', 'qosList' => implode("\n", $lines)]);
    if ($code !== 0) {
        throw new RuntimeException("The router did not accept the change (code {$code})");
    }

    return rt_qos_state($router)[$mac] ?? [];
}

// Reboot (the router answers code 100 when it starts rebooting).
function rt_reboot(array $router): bool
{
    return rt_tenda_post($router, 'goform/sysReboot', ['module1' => 'sysOperate', 'action' => 'reboot']) === 100;
}

// Wi-Fi name and security, never the password.
function rt_wifi(array $router): array
{
    $w = rt_tenda_get($router, 'goform/getWifi?modules=wifiEn,wifiBasicCfg')['wifiBasicCfg'] ?? [];

    return [
        'ssid'     => $w['wifiSSID'] ?? null,
        'security' => $w['wifiSecurityMode'] ?? null,
        'hidden'   => ($w['wifiHideSSID'] ?? 'false') === 'true',
    ];
}

// Read a router and store the sample, unless one was stored less than $minGap seconds ago.
// Returns the reading (or ['error' => ...]).
function rt_sample(string $key, array $router, int $minGap = 0): array
{
    $db  = rt_db();
    $now = time();
    try {
        $r = rt_read($router);
    } catch (Throwable $e) {
        $db->prepare('INSERT INTO polls (router, ts, ok, error) VALUES (?, ?, 0, ?)')->execute([$key, $now, $e->getMessage()]);
        return ['error' => $e->getMessage()];
    }
    $last = (int) $db->query('SELECT MAX(ts) FROM polls WHERE ok = 1 AND router = ' . $db->quote($key))->fetchColumn();
    if ($now - $last >= $minGap) {
        $db->beginTransaction();
        $ins = $db->prepare('INSERT INTO samples (router, ts, mac, ip, name, conn, down, up) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($r['devices'] as $d) {
            $ins->execute([$key, $now, $d['mac'], $d['ip'], $d['name'], $d['conn'], $d['down'], $d['up']]);
        }
        $db->prepare('INSERT INTO polls (router, ts, ok, devices, wan_seconds) VALUES (?, ?, 1, ?, ?)')->execute([$key, $now, count($r['devices']), $r['wanSeconds']]);
        $db->commit();
        $r['stored'] = true;
    }
    $r['ts'] = $now;

    return $r;
}

function rt_prune(int $keepDays): void
{
    $cut = time() - $keepDays * 86400;
    $db = rt_db();
    $db->prepare('DELETE FROM samples WHERE ts < ?')->execute([$cut]);
    $db->prepare('DELETE FROM polls WHERE ts < ?')->execute([$cut]);
}
