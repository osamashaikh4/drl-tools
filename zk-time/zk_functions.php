<?php
// Helpers around ZKLib (zklib/, a copy of php_zklib adapted to run without the sockets extension).
require_once __DIR__ . '/zklib/ZKLib.php';

// The machines keep local time; this PHP runs in UTC, so all clock work is done in Pakistan time.
date_default_timezone_set('Asia/Karachi');

const ZK_CMD_GET_FREE_SIZES = 50;
const ZK_CMD_RESTART        = 1004;
const ZK_CMD_POWEROFF       = 1005;
const ZK_CMD_REFRESHDATA    = 1013;

// How long a downloaded attendance log is reused before the machine is asked again (it is slow:
// about a minute per 60,000 records).
const ZK_ATTENDANCE_CACHE_SECONDS = 600;

const ZK_ROLES = [0 => 'User', 2 => 'Enroller', 6 => 'Admin', 14 => 'Super Admin'];
const ZK_PUNCH = [0 => 'Check-in', 1 => 'Check-out', 2 => 'Break-out', 3 => 'Break-in', 4 => 'Overtime-in', 5 => 'Overtime-out'];

// Connected ZKLib for a device IP, or null when it does not answer. A machine that has just
// served another request can ignore the next one for a few seconds, so try twice.
function zk_open(string $ip): ?ZKLib
{
    for ($try = 0; $try < 2; $try++) {
        $zk = new ZKLib($ip);
        if ($zk->connect()) {
            return $zk;
        }
        sleep(2);
    }

    return null;
}

function zk_close(ZKLib $zk): void
{
    @$zk->disconnect();
}

// The machine's answers look like "~SerialNumber=QWC5251100457\0"; keep just the value.
function zk_value($raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw, "\0 \t\r\n");
    $eq  = strpos($raw, '=');

    return $eq === false ? $raw : substr($raw, $eq + 1);
}

// Whether the last command was acknowledged (CMD_ACK_OK) by the machine.
function zk_ack(ZKLib $zk): bool
{
    return strlen($zk->_data_recv) >= 8 && ZK\Util::checkValid($zk->_data_recv);
}

// Used / capacity counts (CMD_GET_FREE_SIZES), or null.
function zk_sizes(ZKLib $zk): ?array
{
    $ret = $zk->_command(ZK_CMD_GET_FREE_SIZES, '');
    if (!is_string($ret) || strlen($ret) < 80) {
        return null;
    }
    $f = array_values(unpack('V20', substr($ret, 0, 80)));

    return [
        'users'        => $f[4],
        'fingerprints' => $f[6],
        'records'      => $f[8],
        'cards'        => $f[12],
        'usersCap'     => $f[15],
        'fingersCap'   => $f[14],
        'recordsCap'   => $f[16],
    ];
}

// Users as clean rows, keyed by uid.
function zk_users(ZKLib $zk): array
{
    $rows = [];
    foreach ($zk->getUser() as $u) {
        $card = ltrim(trim((string) $u['cardno']), '0');
        $rows[(int) $u['uid']] = [
            'uid'      => (int) $u['uid'],
            'userid'   => trim((string) $u['userid']),
            'name'     => trim((string) $u['name']),
            'role'     => (int) $u['role'],
            'roleName' => ZK_ROLES[(int) $u['role']] ?? ('Level ' . (int) $u['role']),
            'card'     => $card,
            'hasPass'  => trim((string) $u['password']) !== '',
        ];
    }
    ksort($rows);

    return $rows;
}

function zk_cache_file(string $ip): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drl-zk-attendance-' . preg_replace('/[^0-9.]/', '', $ip) . '.json';
}

// Attendance log of a device (and its user names), from the cache when fresh enough.
// Returns ['fetched' => 'Y-m-d H:i:s', 'records' => [...], 'names' => [userid => name]],
// or ['error' => '...'] when the machine does not answer or the download comes back short.
function zk_attendance(string $ip, bool $refresh): array
{
    $file = zk_cache_file($ip);
    if (!$refresh && is_file($file) && filemtime($file) > time() - ZK_ATTENDANCE_CACHE_SECONDS) {
        $cached = json_decode((string) file_get_contents($file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $zk = zk_open($ip);
    if (!$zk) {
        return ['error' => "The device at {$ip} did not answer (UDP 4370)."];
    }
    // The machine's own record count tells a real empty log from a failed download.
    $expected = zk_sizes($zk)['records'] ?? null;
    $names = [];
    foreach (zk_users($zk) as $u) {
        $names[$u['userid']] = $u['name'];
    }
    $records = [];
    foreach ($zk->getAttendance() as $a) {
        $records[] = [$a['timestamp'], trim((string) $a['id']), (int) $a['type'], (int) $a['state']];
    }
    zk_close($zk);

    if ($expected !== null && count($records) < $expected) {
        return ['error' => 'The device sent ' . count($records) . " of {$expected} attendance records; it may be busy. Try again in a moment."];
    }
    $data = ['fetched' => date('Y-m-d H:i:s'), 'records' => $records, 'names' => $names];
    @file_put_contents($file, json_encode($data));

    return $data;
}

function zk_forget_attendance(string $ip): void
{
    @unlink(zk_cache_file($ip));
}
