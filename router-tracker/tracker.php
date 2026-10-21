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

// GET a Tenda goform JSON document. Logs in (and retries once) when the session has expired;
// the session cookie is kept in data/ between requests.
function rt_tenda_get(array $router, string $path): array
{
    $cookies = RT_DATA . '/cookies-' . md5($router['url']) . '.txt';
    $request = function (string $method, string $url, ?string $body = null) use ($cookies) {
        $ch = curl_init($url);
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
        return [$res === false ? '' : (string) $res, $err];
    };
    $sep = str_contains($path, '?') ? '&' : '?';
    $url = rtrim($router['url'], '/') . '/' . ltrim($path, '/') . $sep . 'r=' . mt_rand();

    for ($try = 0; $try < 2; $try++) {
        [$body, $err] = $request('GET', $url);
        if ($err) {
            throw new RuntimeException("Router did not answer: {$err}");
        }
        $data = json_decode($body, true);
        // A login page (HTML) or an empty object means the session is missing or expired.
        if (is_array($data) && $data !== [] && !str_contains($body, '<!DOCTYPE')) {
            return $data;
        }
        if ($try === 0) {
            // Same as the router's login page: user admin, password Base64-encoded.
            [$res, $err] = $request('POST', rtrim($router['url'], '/') . '/login/Auth',
                http_build_query(['username' => 'admin', 'password' => base64_encode((string) ($router['password'] ?? ''))]));
            if ($err) {
                throw new RuntimeException("Router did not answer: {$err}");
            }
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
