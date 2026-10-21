<?php
// Helpers around ZKLib (zklib/, a copy of php_zklib adapted to run without the sockets extension).
require_once __DIR__ . '/zklib/ZKLib.php';

// The machines keep local time; this PHP runs in UTC, so all clock work is done in Pakistan time.
date_default_timezone_set('Asia/Karachi');

const ZK_CMD_GET_FREE_SIZES = 50;
const ZK_CMD_RESTART        = 1004;
const ZK_CMD_POWEROFF       = 1005;
const ZK_CMD_REFRESHDATA    = 1013;
// Table write used for adding a punch to the attendance log. Not in the ZKTeco SDK or php_zklib; the
// payload format comes from taha-rafi/php-zkteco and was confirmed on main1 (MB460, ZMM220_TFT,
// Ver 6.60): written, then found in a fresh read through both this library and the ZKTeco SDK.
const ZK_CMD_SET_DATA       = 3001;
// Delete the attendance records between two times (same source; confirmed on main1 for a day range).
const ZK_CMD_TIME_ATTLOG_DELE = 10005;

// How long a downloaded attendance log is reused before the machine is asked again (it is slow:
// about a minute per 60,000 records).
const ZK_ATTENDANCE_CACHE_SECONDS = 600;

const ZK_ROLES = [0 => 'User', 2 => 'Enroller', 6 => 'Admin', 14 => 'Super Admin'];
const ZK_PUNCH = [0 => 'Check-in', 1 => 'Check-out', 2 => 'Break-out', 3 => 'Break-in', 4 => 'Overtime-in', 5 => 'Overtime-out'];
// Verify modes of an attendance record (ZK\Util::ATT_STATE_*).
const ZK_VERIFY = [1 => 'Fingerprint', 0 => 'Password', 2 => 'Card'];

// Connected ZKLib for a device IP, or null when it does not answer. A busy machine can ignore
// requests for half a minute or more (seen on New Lab Bld), so keep trying for about 45 seconds.
function zk_open(string $ip): ?ZKLib
{
    $until = time() + 45;
    do {
        $zk = new ZKLib($ip);
        if ($zk->connect()) {
            return $zk;
        }
        sleep(1);
    } while (time() < $until);

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

// The whole attendance log as [time, userid, punch, verify] rows, or an error message when the
// download comes back short (the machine's own record count tells a real empty log from a failed one).
function zk_records(ZKLib $zk)
{
    $expected = zk_sizes($zk)['records'] ?? null;
    $records  = [];
    foreach ($zk->getAttendance() as $a) {
        $records[] = [$a['timestamp'], trim((string) $a['id']), (int) $a['type'], (int) $a['state']];
    }
    if ($expected !== null && count($records) < $expected) {
        return 'The device sent ' . count($records) . " of {$expected} attendance records; it may be busy. Try again in a moment.";
    }

    return $records;
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
    $names = [];
    foreach (zk_users($zk) as $u) {
        $names[$u['userid']] = $u['name'];
    }
    $records = zk_records($zk);
    zk_close($zk);

    if (is_string($records)) {
        return ['error' => $records];
    }
    $data = ['fetched' => date('Y-m-d H:i:s'), 'records' => $records, 'names' => $names];
    @file_put_contents($file, json_encode($data));

    return $data;
}

function zk_forget_attendance(string $ip): void
{
    @unlink(zk_cache_file($ip));
}

// ---------------------------------------------------------------------------------------------
// Adding a punch to the machine's own attendance log (e.g. a missed or historical punch).
// ---------------------------------------------------------------------------------------------

// Form input as a punch ['userid', 'time' (Y-m-d H:i:s), 'punch', 'verify'], or an error message.
// Values the machine would not understand are refused, never mapped to something else.
function zk_punch_input(array $in)
{
    $userid = trim((string) ($in['userid'] ?? ''));
    $time   = str_replace('T', ' ', trim((string) ($in['time'] ?? '')));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $time)) {
        $time .= ':00';
    }
    $dt     = DateTime::createFromFormat('!Y-m-d H:i:s', $time);
    $punch  = (string) ($in['punch'] ?? '');
    $verify = (string) ($in['verify'] ?? '');

    if (!preg_match('/^\d{1,9}$/', $userid)) {
        return 'User ID must be 1 to 9 digits';
    }
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $time) {
        return 'Pick a valid date and time';
    }
    // The machine stores the year as two digits from 2000.
    if ($time < '2000-01-01 00:00:00' || $time > date('Y-m-d H:i:s')) {
        return 'The time must be between 2000 and now';
    }
    if (!ctype_digit($punch) || !isset(ZK_PUNCH[(int) $punch])) {
        return 'Unknown punch type';
    }
    if (!ctype_digit($verify) || !isset(ZK_VERIFY[(int) $verify])) {
        return 'Unknown verify mode';
    }

    return ['userid' => $userid, 'time' => $time, 'punch' => (int) $punch, 'verify' => (int) $verify];
}

// An existing record picked on the page, as [time, userid, punch, verify], or an error message. Only
// the shape is checked: the record has to match one in the device's log exactly anyway.
function zk_record_input(array $in)
{
    $time = trim((string) ($in['time'] ?? ''));
    $dt   = DateTime::createFromFormat('!Y-m-d H:i:s', $time);
    foreach (['punch', 'verify'] as $f) {
        if (!ctype_digit((string) ($in[$f] ?? '')) || (int) $in[$f] > 255) {
            return 'Pick the punch again';
        }
    }
    if (!preg_match('/^\d{1,9}$/', trim((string) ($in['userid'] ?? ''))) || !$dt || $dt->format('Y-m-d H:i:s') !== $time) {
        return 'Pick the punch again';
    }

    return [$time, trim((string) $in['userid']), (int) $in['punch'], (int) $in['verify']];
}

// Write one [time, userid, punch, verify] record (CMD_SET_DATA); true when acknowledged.
function zk_set_record(ZKLib $zk, array $r): bool
{
    [$time, $userid, $punch, $verify] = $r;
    $zk->_command(ZK_CMD_SET_DATA, "TableName=ATT_LOG\r\nUser_PIN={$userid}\tVerify_Type={$verify}\tVerify_Time="
        . str_replace(' ', 'T', $time) . "\tStatus={$punch}\tWork_Code_ID=0\0");

    return zk_ack($zk);
}

// Records of $a that are not in $b, counting repeats (a record twice in $a and once in $b is one).
function zk_records_minus(array $a, array $b): array
{
    $left = array_count_values(array_map(fn($r) => json_encode($r), $b));
    $out  = [];
    foreach ($a as $r) {
        $k = json_encode($r);
        if (($left[$k] ?? 0) > 0) {
            $left[$k]--;
        } else {
            $out[] = $r;
        }
    }

    return $out;
}

// Delete one record from a machine's attendance log. The machine can only delete a time range
// (CMD_TIME_ATTLOG_DELE), which takes everyone's punches in it, so: delete that one second, read
// the log again, write back every record that went except the one asked for, and read once more to
// prove only that record is gone. $r is [time, userid, punch, verify] as in zk_records().
function zk_delete_punch(callable $open, array $r, bool $dryRun, int $settleSeconds = 2): array
{
    $fail = fn(string $stage, string $error, array $extra = []) => ['ok' => false, 'stage' => $stage, 'error' => $error] + $extra;

    $zk = $open();
    if (!$zk) {
        return $fail('connect', 'The device did not answer.');
    }
    try {
        $before = zk_records($zk);
        if (is_string($before)) {
            return $fail('read', $before);
        }
        if (!in_array($r, $before, true)) {
            return $fail('validate', 'That punch is not on the device (any more); reload the attendance log');
        }
        // Everyone else's punches in the same second go too, and are written back.
        $others = zk_records_minus(array_values(array_filter($before, fn($x) => $x[0] === $r[0])), [$r]);
        if ($dryRun) {
            return ['ok' => true, 'dryRun' => true, 'rewrite' => count($others), 'records' => count($before)];
        }
        $t = ZK\Util::encodeTime($r[0]);
        $zk->disableDevice();
        try {
            $zk->_command(ZK_CMD_TIME_ATTLOG_DELE, pack('VV', $t, $t));
            $acked = zk_ack($zk);
            if ($acked) {
                $zk->_command(ZK_CMD_REFRESHDATA, '');
            }
        } finally {
            $zk->enableDevice();
        }
    } finally {
        zk_close($zk);
    }
    if (!$acked) {
        return $fail('delete', 'The device refused the delete; nothing was changed');
    }

    $read = function () use ($open, $settleSeconds) {
        sleep($settleSeconds);
        $zk = $open();
        if (!$zk) {
            return 'the device did not answer';
        }
        $records = zk_records($zk);
        zk_close($zk);
        return $records;
    };
    $unsure = 'The device took the delete but the check read failed. Look in the attendance log before doing anything else';

    $after = $read();
    if (is_string($after)) {
        return $fail('verify', "{$unsure}: {$after}", ['changed' => true]);
    }
    $gone = zk_records_minus($before, $after);
    if (!in_array($r, $gone, true)) {
        return $fail('verify', 'The device acknowledged the delete but the punch is still there', ['changed' => (bool) $gone]);
    }
    // Write back everything that went, other than the one record asked for.
    $restore = zk_records_minus($gone, [$r]);
    if ($restore) {
        $zk = $open();
        if (!$zk) {
            return $fail('restore', 'The punch was deleted, but these records went with it and could not be written back (the device did not answer): ' . json_encode($restore), ['changed' => true, 'lost' => $restore]);
        }
        $zk->disableDevice();
        try {
            foreach ($restore as $x) {
                zk_set_record($zk, $x);
            }
            $zk->_command(ZK_CMD_REFRESHDATA, '');
        } finally {
            $zk->enableDevice();
            zk_close($zk);
        }
        $after = $read();
        if (is_string($after)) {
            return $fail('verify', "{$unsure}: {$after}", ['changed' => true]);
        }
    }
    $lost = zk_records_minus(zk_records_minus($before, [$r]), $after);
    if ($lost) {
        return $fail('restore', 'The punch was deleted, but these records went with it and are still missing: ' . json_encode($lost), ['changed' => true, 'lost' => $lost]);
    }
    if (in_array($r, zk_records_minus($after, zk_records_minus($before, [$r])), true)) {
        return $fail('verify', 'The punch came back after the other records were written back', ['changed' => true]);
    }

    return ['ok' => true, 'dryRun' => false, 'verified' => true, 'rewritten' => count($restore), 'records' => count($after)];
}

// Change a punch's time, type or verify mode (same person): delete the old record, add the new
// one, and put the old one back if the new one cannot be added. $old is [time, userid, punch,
// verify] from the log, $new a zk_punch_input() punch for the same user ID.
function zk_edit_punch(callable $open, array $old, array $new, bool $dryRun, int $settleSeconds = 2): array
{
    $fail = fn(string $stage, string $error, array $extra = []) => ['ok' => false, 'stage' => $stage, 'error' => $error] + $extra;
    $newRecord = [$new['time'], $new['userid'], $new['punch'], $new['verify']];

    if ($new['userid'] !== $old[1]) {
        return $fail('validate', 'A punch cannot be moved to another person; delete it and add a new one');
    }
    if ($newRecord === $old) {
        return $fail('validate', 'Nothing changed');
    }

    // Every check that can stop the edit runs before anything is deleted.
    $zk = $open();
    if (!$zk) {
        return $fail('connect', 'The device did not answer.');
    }
    try {
        $known = false;
        foreach (zk_users($zk) as $u) {
            $known = $known || $u['userid'] === $new['userid'];
        }
        $before = zk_records($zk);
    } finally {
        zk_close($zk);
    }
    if (!$known) {
        return $fail('validate', "User ID {$new['userid']} is not on this device, so the punch could not be put back if the edit failed");
    }
    if (is_string($before)) {
        return $fail('read', $before);
    }
    if (!in_array($old, $before, true)) {
        return $fail('validate', 'That punch is not on the device (any more); reload the attendance log');
    }
    foreach (zk_records_minus($before, [$old]) as $x) {
        if ($x[1] === $new['userid'] && $x[0] === $new['time']) {
            return $fail('duplicate', "This person already has a punch at {$new['time']}; nothing was changed");
        }
    }
    if ($dryRun) {
        return ['ok' => true, 'dryRun' => true, 'records' => count($before)];
    }

    $del = zk_delete_punch($open, $old, false, $settleSeconds);
    if (!$del['ok']) {
        return $del + ['step' => 'delete'];
    }
    $add = zk_add_punch($open, $new, false, $settleSeconds);
    if ($add['ok']) {
        return ['ok' => true, 'dryRun' => false, 'verified' => true, 'rewritten' => $del['rewritten'], 'records' => $add['records']];
    }
    if (!empty($add['written'])) {
        return $add + ['step' => 'add', 'error' => 'The old punch was deleted; the new one may or may not be there: ' . $add['error']];
    }
    // Put the old punch back as it was.
    $back = zk_add_punch($open, ['time' => $old[0], 'userid' => $old[1], 'punch' => $old[2], 'verify' => $old[3]], false, $settleSeconds);

    return $fail('add', 'The new punch could not be added (' . $add['error'] . '); ' . ($back['ok']
        ? 'the old punch was put back as it was'
        : 'and putting the old punch back failed too: ' . $back['error'] . '. Old punch: ' . json_encode($old)), ['restored' => $back['ok']]);
}

// Add one punch to a machine's attendance log and prove it is there by reading the log again on a
// fresh connection. $open returns a connected ZKLib or null. With $dryRun nothing is written: the
// checks run and the answer says what would be written.
// Refused: a user ID that is not on the machine, and a second punch for the same person at the
// same second (whatever its type), so a retry can never double a record.
function zk_add_punch(callable $open, array $p, bool $dryRun, int $settleSeconds = 2): array
{
    $fail = fn(string $stage, string $error, array $extra = []) => ['ok' => false, 'stage' => $stage, 'error' => $error] + $extra;

    $zk = $open();
    if (!$zk) {
        return $fail('connect', 'The device did not answer.');
    }
    try {
        $user = null;
        foreach (zk_users($zk) as $u) {
            if ($u['userid'] === $p['userid']) {
                $user = $u;
            }
        }
        if (!$user) {
            return $fail('validate', "User ID {$p['userid']} is not on this device");
        }
        $before = zk_records($zk);
        if (is_string($before)) {
            return $fail('read', $before);
        }
        foreach ($before as $r) {
            if ($r[1] === $p['userid'] && $r[0] === $p['time']) {
                return $fail('duplicate', "{$user['name']} already has a punch at {$p['time']} (" . (ZK_PUNCH[$r[2]] ?? 'type ' . $r[2]) . '); nothing was written');
            }
        }
        if ($dryRun) {
            return ['ok' => true, 'dryRun' => true, 'name' => $user['name'], 'records' => count($before)];
        }

        $zk->disableDevice();
        try {
            $acked = zk_set_record($zk, [$p['time'], $p['userid'], $p['punch'], $p['verify']]);
            if ($acked) {
                $zk->_command(ZK_CMD_REFRESHDATA, '');
            }
        } finally {
            $zk->enableDevice();
        }
    } finally {
        zk_close($zk);
    }
    if (!$acked) {
        return $fail('write', 'The device refused the record; nothing was written');
    }

    // An acknowledgement is not proof: read the log again over a new connection.
    $unverified = 'The device accepted the record but the check read failed. Look in the attendance log before trying again';
    sleep($settleSeconds);
    $zk = $open();
    if (!$zk) {
        return $fail('verify', $unverified . ' (the device did not answer).', ['written' => true]);
    }
    $after = zk_records($zk);
    zk_close($zk);
    if (is_string($after)) {
        return $fail('verify', $unverified . ': ' . $after, ['written' => true]);
    }
    $same = array_values(array_filter($after, fn($r) => $r[1] === $p['userid'] && $r[0] === $p['time']));
    $want = [$p['time'], $p['userid'], $p['punch'], $p['verify']];
    if (count($same) === 1 && $same[0] === $want) {
        return ['ok' => true, 'dryRun' => false, 'verified' => true, 'name' => $user['name'], 'records' => count($after)];
    }
    if (!$same) {
        return $fail('verify', 'The device acknowledged the record but it is not in the attendance log', ['written' => false]);
    }

    return $fail('verify', 'The device stored something different from what was sent: ' . json_encode($same), ['written' => true]);
}

// One JSON line per punch attempt in zk-time/data/punches.log (the folder is closed to the web).
function zk_log_punch(array $entry): void
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/punches.log', json_encode(['at' => date('Y-m-d H:i:s')] + $entry) . "\n", FILE_APPEND | LOCK_EX);
}
