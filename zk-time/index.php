<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/zk_functions.php';

$devices = require __DIR__ . '/devices.php';

// ---------------------------------------------------------------------------------------------
// JSON API: ?api=<action>&d=<device key>. Reads are GET, changes are POST.
// ---------------------------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $reply = function (array $data, int $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        exit();
    };

    if (!is_logged_in()) {
        $reply(['ok' => false, 'error' => 'Logged out'], 401);
    }
    // Release the session lock so several devices can be worked on at once.
    session_write_close();
    set_time_limit(300);

    $api = (string) $_GET['api'];
    $key = (string) ($_REQUEST['d'] ?? '');
    if (!isset($devices[$key])) {
        $reply(['ok' => false, 'error' => 'Unknown device'], 400);
    }
    $ip     = $devices[$key]['ip'];
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $writes = ['settime', 'saveuser', 'deluser', 'addpunch', 'editpunch', 'delpunch', 'danger'];
    if (in_array($api, $writes, true) !== $isPost) {
        $reply(['ok' => false, 'error' => 'Wrong request method'], 405);
    }
    $offline = ['ok' => false, 'error' => "The device at {$ip} did not answer (UDP 4370)."];

    // Changes run with the machine disabled (its screen shows it is busy), then re-enabled.
    $write = function (callable $op) use ($ip, $offline, $reply) {
        $zk = zk_open($ip);
        if (!$zk) {
            $reply($offline);
        }
        $zk->disableDevice();
        try {
            $result = $op($zk);
        } finally {
            $zk->enableDevice();
            zk_close($zk);
        }
        return $result;
    };

    switch ($api) {
        // Device details, capacity and clock.
        case 'status':
            $started = microtime(true);
            $zk = zk_open($ip);
            if (!$zk) {
                $reply($offline + ['ms' => round((microtime(true) - $started) * 1000)]);
            }
            $ms   = round((microtime(true) - $started) * 1000);
            $info = [
                'deviceName'   => zk_value($zk->deviceName()),
                'serialNumber' => zk_value($zk->serialNumber()),
                'platform'     => zk_value($zk->platform()),
                'firmware'     => zk_value($zk->version()),
                'fpVersion'    => zk_value($zk->fmVersion()),
                'os'           => zk_value($zk->osVersion()),
                'workCode'     => zk_value($zk->workCode()),
                'ssr'          => zk_value($zk->ssr()),
                'pinWidth'     => zk_value($zk->pinWidth()),
                'face'         => zk_value($zk->faceFunctionOn()),
            ];
            $sizes      = zk_sizes($zk);
            $deviceTime = $zk->getTime();
            $serverTime = date('Y-m-d H:i:s');
            zk_close($zk);
            $reply([
                'ok'         => true,
                'ms'         => $ms,
                'info'       => $info,
                'sizes'      => $sizes,
                'deviceTime' => $deviceTime ?: null,
                'serverTime' => $serverTime,
                'driftSec'   => $deviceTime ? strtotime($deviceTime) - strtotime($serverTime) : null,
            ]);

        case 'users':
            $zk = zk_open($ip);
            if (!$zk) {
                $reply($offline);
            }
            $users = array_values(zk_users($zk));
            zk_close($zk);
            $reply(['ok' => true, 'users' => $users]);

        // Attendance between two dates, optionally for one person / punch type.
        case 'attendance':
            $data = zk_attendance($ip, !empty($_GET['refresh']));
            if (isset($data['error'])) {
                $reply(['ok' => false, 'error' => $data['error']]);
            }
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] . ' 00:00:00' : '0000';
            $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] . ' 23:59:59' : '9999';
            $q    = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
            $type = ($_GET['type'] ?? '') === '' ? null : (int) $_GET['type'];
            $rows = [];
            foreach ($data['records'] as [$time, $userid, $punch, $verify]) {
                if ($time < $from || $time > $to || ($type !== null && $punch !== $type)) {
                    continue;
                }
                $name = $data['names'][$userid] ?? '';
                if ($q !== '' && mb_strpos(mb_strtolower($userid . ' ' . $name), $q) === false) {
                    continue;
                }
                // The last two (punch and verify codes) let the page pick this exact record to edit or delete.
                $rows[] = [$time, $userid, $name, ZK_PUNCH[$punch] ?? ('Type ' . $punch), $punch, $verify];
            }
            // Newest first.
            usort($rows, fn($a, $b) => strcmp($b[0], $a[0]));
            $reply([
                'ok'      => true,
                'fetched' => $data['fetched'],
                'total'   => count($data['records']),
                'oldest'  => $data['records'] ? min(array_column($data['records'], 0)) : null,
                'rows'    => $rows,
            ]);

        // Set the clock: to this server's time (Pakistan time) or to a given time.
        case 'settime':
            if (($_POST['mode'] ?? '') === 'sync') {
                $t = date('Y-m-d H:i:s');
            } else {
                $ts = strtotime((string) ($_POST['time'] ?? ''));
                if (!$ts || date('Y', $ts) < 2000 || date('Y', $ts) > 2099) {
                    $reply(['ok' => false, 'error' => 'Pick a valid date and time']);
                }
                $t = date('Y-m-d H:i:s', $ts);
            }
            $ok = $write(function ($zk) use ($t) {
                $zk->setTime($t);
                return zk_ack($zk);
            });
            $reply(['ok' => $ok, 'set' => $t, 'error' => $ok ? null : 'The device did not accept the new time']);

        // Add a user, or update one (same UID). A blank password keeps the current one.
        case 'saveuser':
            $uid    = (int) ($_POST['uid'] ?? 0);
            $userid = trim((string) ($_POST['userid'] ?? ''));
            $name   = trim((string) ($_POST['name'] ?? ''));
            $pass   = trim((string) ($_POST['password'] ?? ''));
            $role   = (int) ($_POST['role'] ?? 0);
            $card   = trim((string) ($_POST['card'] ?? ''));
            $card   = $card === '' ? '0' : $card;
            if ($uid < 1 || $uid > 65535) {
                $reply(['ok' => false, 'error' => 'UID must be between 1 and 65535']);
            } elseif (!preg_match('/^\d{1,9}$/', $userid)) {
                $reply(['ok' => false, 'error' => 'User ID must be 1 to 9 digits']);
            } elseif ($name === '' || strlen($name) > 24) {
                $reply(['ok' => false, 'error' => 'Name is required, at most 24 characters']);
            } elseif ($pass !== '' && !preg_match('/^\d{1,8}$/', $pass)) {
                $reply(['ok' => false, 'error' => 'Password must be 1 to 8 digits']);
            } elseif (!isset(ZK_ROLES[$role])) {
                $reply(['ok' => false, 'error' => 'Unknown role']);
            } elseif (!preg_match('/^\d{1,10}$/', $card) || (int) $card > 4294967295) {
                $reply(['ok' => false, 'error' => 'Card number must be digits (up to 4294967295)']);
            }
            $res = $write(function ($zk) use ($uid, $userid, $name, $pass, $role, $card) {
                $users = zk_users($zk);
                foreach ($users as $u) {
                    if ($u['userid'] === $userid && $u['uid'] !== $uid) {
                        return ['ok' => false, 'error' => "User ID {$userid} already belongs to UID {$u['uid']} ({$u['name']})"];
                    }
                }
                if ($pass === '' && isset($users[$uid]) && $users[$uid]['hasPass']) {
                    // Keep the existing password: read it back from the raw list.
                    foreach ($zk->getUser() as $raw) {
                        if ((int) $raw['uid'] === $uid) {
                            $pass = trim((string) $raw['password']);
                        }
                    }
                }
                $zk->setUser($uid, $userid, $name, $pass, $role, (int) $card);
                $ok = zk_ack($zk);
                $zk->_command(ZK_CMD_REFRESHDATA, '');
                return ['ok' => $ok, 'error' => $ok ? null : 'The device did not accept the user', 'existed' => isset($users[$uid])];
            });
            $reply($res);

        case 'deluser':
            $uid = (int) ($_POST['uid'] ?? 0);
            if ($uid < 1 || $uid > 65535) {
                $reply(['ok' => false, 'error' => 'Invalid UID']);
            }
            $ok = $write(function ($zk) use ($uid) {
                $zk->removeUser($uid);
                $ok = zk_ack($zk);
                $zk->_command(ZK_CMD_REFRESHDATA, '');
                return $ok;
            });
            $reply(['ok' => $ok, 'error' => $ok ? null : 'The device did not remove the user']);

        // Add a punch (e.g. a missed one) to the machine's own attendance log. dry=1 only checks.
        // Succeeds only when a fresh read of the log shows the exact record.
        case 'addpunch':
            $p = zk_punch_input($_POST);
            if (is_string($p)) {
                $reply(['ok' => false, 'error' => $p]);
            }
            $dry = !empty($_POST['dry']);
            $res = zk_add_punch(fn() => zk_open($ip), $p, $dry);
            if (!$dry) {
                zk_forget_attendance($ip);
            }
            zk_log_punch(['by' => current_user(), 'device' => $key, 'ip' => $ip, 'dry' => $dry] + $p + ['result' => $res]);
            $reply($res);

        // Change or delete a punch on the machine. The record is sent as it was listed (o_* fields).
        // Each reads the whole log several times, so a big log takes minutes.
        case 'editpunch':
        case 'delpunch':
            set_time_limit(900);
            $old = zk_record_input(['time' => $_POST['o_time'] ?? '', 'userid' => $_POST['o_userid'] ?? '', 'punch' => $_POST['o_punch'] ?? '', 'verify' => $_POST['o_verify'] ?? '']);
            if (is_string($old)) {
                $reply(['ok' => false, 'error' => $old]);
            }
            $dry = !empty($_POST['dry']);
            if ($api === 'delpunch') {
                $new = null;
                $res = zk_delete_punch(fn() => zk_open($ip), $old, $dry);
            } else {
                $new = zk_punch_input(['userid' => $old[1]] + $_POST);
                if (is_string($new)) {
                    $reply(['ok' => false, 'error' => $new]);
                }
                $res = zk_edit_punch(fn() => zk_open($ip), $old, $new, $dry);
            }
            if (!$dry) {
                zk_forget_attendance($ip);
            }
            zk_log_punch(['by' => current_user(), 'device' => $key, 'ip' => $ip, 'action' => $api, 'dry' => $dry, 'old' => $old, 'new' => $new, 'result' => $res]);
            $reply($res);

        // Destructive / maintenance actions. The page makes the operator type the device key first.
        case 'danger':
            $op = (string) ($_POST['op'] ?? '');
            if (($_POST['confirm'] ?? '') !== $key) {
                $reply(['ok' => false, 'error' => 'Confirmation did not match']);
            }
            if (in_array($op, ['restart', 'poweroff'], true)) {
                // The machine goes away right after acknowledging, so no disable / enable round.
                $zk = zk_open($ip);
                if (!$zk) {
                    $reply($offline);
                }
                $zk->_command($op === 'restart' ? ZK_CMD_RESTART : ZK_CMD_POWEROFF, '');
                $ok = zk_ack($zk);
                $reply(['ok' => $ok, 'error' => $ok ? null : 'The device did not acknowledge']);
            }
            $ops = [
                'clearatt'   => fn($zk) => $zk->clearAttendance(),
                'clearadmin' => fn($zk) => $zk->clearAdmin(),
                'cleardata'  => fn($zk) => $zk->clearUsers(), // CMD_CLEAR_DATA: users, fingerprints and attendance
            ];
            if (!isset($ops[$op])) {
                $reply(['ok' => false, 'error' => 'Unknown action'], 400);
            }
            $ok = $write(function ($zk) use ($ops, $op) {
                $ops[$op]($zk);
                $ok = zk_ack($zk);
                $zk->_command(ZK_CMD_REFRESHDATA, '');
                return $ok;
            });
            if ($ok) {
                zk_forget_attendance($ip);
            }
            $reply(['ok' => $ok, 'error' => $ok ? null : 'The device did not acknowledge']);
    }
    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

require_login();
$pageTitle = 'ZK Devices';
include __DIR__ . '/../header.php';
?>

<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">ZK Devices</h1>
        <p class="text-sm text-slate-500">ZKTeco attendance machines: clock, users, attendance log and maintenance (UDP 4370). Times are Pakistan time.</p>
    </div>

    <!-- Device picker: each shows whether it answers. -->
    <div id="devices" class="flex flex-wrap gap-2 mb-6">
        <?php foreach ($devices as $k => $dev): ?>
            <button type="button" class="device-tab px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-left hover:border-slate-400" data-d="<?= htmlspecialchars($k) ?>">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900"><span class="dot w-2 h-2 rounded-full bg-slate-300"></span><?= htmlspecialchars($dev['name']) ?></span>
                <span class="block text-xs font-mono text-slate-500"><?= htmlspecialchars($dev['ip']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <div id="panel" class="hidden">
        <!-- Section tabs -->
        <div class="flex flex-wrap gap-1 border-b border-slate-200 mb-6">
            <?php foreach (['overview' => 'Overview & clock', 'users' => 'Users', 'attendance' => 'Attendance', 'maintenance' => 'Maintenance'] as $s => $label): ?>
                <button type="button" class="section-tab px-4 py-2 text-sm font-semibold border-b-2 -mb-px border-transparent text-slate-500 hover:text-slate-900" data-s="<?= $s ?>"><?= $label ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Overview & clock -->
        <section class="section" data-s="overview">
            <div id="overviewBody" class="text-sm text-slate-500"></div>
        </section>

        <!-- Users -->
        <section class="section hidden" data-s="users">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <input id="userSearch" type="search" placeholder="Search name, user ID, card…" class="w-full sm:w-72 rounded-lg border border-slate-300 text-sm p-2.5">
                <div class="flex gap-2">
                    <button type="button" id="reloadUsers" class="px-4 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-100">Reload</button>
                    <button type="button" id="addUser" class="px-4 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white">Add user</button>
                </div>
            </div>
            <form id="userForm" class="hidden bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-4">
                <h3 id="userFormTitle" class="text-sm font-bold text-slate-900 mb-4">Add user</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <label class="text-xs font-semibold text-slate-600">UID <input name="uid" type="number" min="1" max="65535" required class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                    <label class="text-xs font-semibold text-slate-600">User ID <input name="userid" required maxlength="9" pattern="\d{1,9}" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 font-mono"></label>
                    <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Name <input name="name" required maxlength="24" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                    <label class="text-xs font-semibold text-slate-600">Role
                        <select name="role" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 bg-white">
                            <?php foreach (ZK_ROLES as $r => $label): ?><option value="<?= $r ?>"><?= $label ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-600">Card no. <input name="card" maxlength="10" pattern="\d*" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 font-mono"></label>
                    <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Password (digits) <input name="password" maxlength="8" pattern="\d*" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 font-mono" placeholder=""></label>
                </div>
                <p class="text-xs text-slate-500 mt-3">Fingerprints and faces are enrolled on the machine itself. Saving a user with an existing UID updates that user.</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" id="cancelUser" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white disabled:opacity-60">Save to device</button>
                </div>
            </form>
            <div id="usersBody" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-x-auto text-sm"></div>
        </section>

        <!-- Attendance -->
        <section class="section hidden" data-s="attendance">
            <form id="attForm" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
                <label class="text-xs font-semibold text-slate-600">From <input name="from" type="date" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                <label class="text-xs font-semibold text-slate-600">To <input name="to" type="date" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                <label class="text-xs font-semibold text-slate-600 lg:col-span-2">Person <input name="q" type="search" placeholder="Name or user ID" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                <label class="text-xs font-semibold text-slate-600">Punch
                    <select name="type" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 bg-white">
                        <option value="">All</option>
                        <?php foreach (ZK_PUNCH as $t => $label): ?><option value="<?= $t ?>"><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="px-4 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white disabled:opacity-60">Show</button>
            </form>
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <p id="attInfo" class="text-xs text-slate-500 tabular-nums"></p>
                <div class="flex gap-2">
                    <button type="button" id="addPunch" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-100">Add punch</button>
                    <button type="button" id="attRefresh" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60">Download again from device</button>
                    <button type="button" id="attExport" disabled class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">Export Excel</button>
                </div>
            </div>
            <form id="punchForm" class="hidden bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-4">
                <h3 id="punchFormTitle" class="text-sm font-bold text-slate-900 mb-4">Add a punch to the device's attendance log</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <label class="text-xs font-semibold text-slate-600">User ID <input name="userid" required maxlength="9" pattern="\d{1,9}" list="punchUsers" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 font-mono"></label>
                    <datalist id="punchUsers"></datalist>
                    <label class="text-xs font-semibold text-slate-600 lg:col-span-2">Date and time <input name="time" type="datetime-local" step="1" required class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2"></label>
                    <label class="text-xs font-semibold text-slate-600">Punch
                        <select name="punch" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 bg-white">
                            <?php foreach (ZK_PUNCH as $t => $label): ?><option value="<?= $t ?>"><?= $label ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-600">Verified by
                        <select name="verify" class="mt-1 w-full rounded-lg border border-slate-300 text-sm p-2 bg-white">
                            <?php foreach (ZK_VERIFY as $v => $label): ?><option value="<?= $v ?>"><?= $label ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p id="punchUser" class="text-xs text-slate-700 mt-3 min-h-[1rem]"></p>
                <p class="text-xs text-slate-500 mt-1">The punch is written into the machine itself, then the log is read again to confirm it is there. A second punch for the same person at the same second is refused. Editing deletes the old punch and adds the new one (the old one is put back if that fails).</p>
                <p id="punchResult" class="text-xs mt-3"></p>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" id="cancelPunch" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <button type="button" id="checkPunch" class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60">Check (dry run)</button>
                    <button type="submit" id="savePunch" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white disabled:opacity-60">Add to device</button>
                </div>
            </form>
            <div id="attBody" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-x-auto text-sm"></div>
        </section>

        <!-- Maintenance -->
        <section class="section hidden" data-s="maintenance">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $danger = [
                    ['restart', 'Restart device', 'Reboots the machine. It is unavailable for about a minute.', 'amber'],
                    ['poweroff', 'Power off device', 'Turns the machine off. Someone has to switch it on again at the device.', 'amber'],
                    ['clearatt', 'Clear attendance log', 'Deletes every attendance record on the machine. Export it first.', 'red'],
                    ['clearadmin', 'Clear administrators', 'Removes admin rights from all users, so anyone can open the machine menu.', 'red'],
                    ['cleardata', 'Clear ALL data', 'Deletes all users, fingerprints, faces and attendance records on the machine.', 'red'],
                ];
                foreach ($danger as [$op, $title, $note, $c]): ?>
                    <div class="bg-white rounded-2xl border border-<?= $c ?>-200 shadow-sm p-5 flex flex-col">
                        <h3 class="text-sm font-bold text-slate-900"><?= $title ?></h3>
                        <p class="text-xs text-slate-500 mt-1 flex-1"><?= $note ?></p>
                        <button type="button" class="danger mt-4 self-start px-4 py-2 rounded-lg bg-<?= $c ?>-600 hover:bg-<?= $c ?>-500 text-sm font-semibold text-white disabled:opacity-60" data-op="<?= $op ?>" data-title="<?= htmlspecialchars($title) ?>"><?= $title ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<!-- SheetJS build that also writes cell styles (xlsx-js-style 1.2.0). -->
<script src="../assets/js/xlsx-js-style.min.js"></script>
<script>
    $(function() {
        const DEVICES = <?= json_encode($devices) ?>;
        const ROLES = <?= json_encode(ZK_ROLES) ?>;
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const fmt = (n) => Number(n).toLocaleString('en-US');
        const toast = (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' });

        let current = null; // device key
        let users = [];     // users of the current device
        let attRows = [];   // attendance rows on screen

        const api = (action, data, method) => $.ajax({
            url: 'index.php?api=' + action,
            method: method || 'GET',
            data: Object.assign({ d: current }, data || {}),
            dataType: 'json',
            timeout: 300000,
        });

        // ---- Device picker -------------------------------------------------------------------
        function setDot(key, state) {
            const cls = { up: 'bg-emerald-500', down: 'bg-red-500', busy: 'bg-amber-400 animate-pulse' }[state] || 'bg-slate-300';
            $(`.device-tab[data-d="${key}"] .dot`).attr('class', 'dot w-2 h-2 rounded-full ' + cls);
        }
        function selectDevice(key) {
            current = key;
            $('.device-tab').removeClass('ring-2 ring-slate-900 border-slate-900').filter(`[data-d="${key}"]`).addClass('ring-2 ring-slate-900 border-slate-900');
            $('#panel').removeClass('hidden');
            users = []; attRows = [];
            $('#usersBody, #attBody').empty(); $('#attInfo').text(''); $('#attExport').prop('disabled', true);
            $('#userForm, #punchForm').addClass('hidden');
            loadOverview();
            showSection($('.section-tab.border-slate-900').data('s') || 'overview');
        }
        $('.device-tab').on('click', function() { selectDevice($(this).data('d')); });

        // ---- Section tabs ----------------------------------------------------------------------
        function showSection(s) {
            $('.section-tab').removeClass('border-slate-900 text-slate-900').addClass('border-transparent text-slate-500')
                .filter(`[data-s="${s}"]`).addClass('border-slate-900 text-slate-900').removeClass('border-transparent text-slate-500');
            $('.section').addClass('hidden').filter(`[data-s="${s}"]`).removeClass('hidden');
            if (s === 'users' && !users.length) loadUsers();
            if (s === 'attendance' && !attRows.length && !$('#attInfo').text()) loadAttendance(false);
        }
        $('.section-tab').on('click', function() { showSection($(this).data('s')); });

        // ---- Overview & clock ------------------------------------------------------------------
        const bar = (label, used, cap) => {
            const pct = cap ? Math.min(100, Math.round(used / cap * 100)) : 0;
            const color = pct >= 90 ? 'bg-red-500' : pct >= 75 ? 'bg-amber-500' : 'bg-emerald-500';
            return `<div><div class="flex justify-between text-xs mb-1"><span class="font-semibold text-slate-700">${label}</span><span class="tabular-nums text-slate-500">${fmt(used)}${cap ? ' / ' + fmt(cap) + ' · ' + pct + '%' : ''}</span></div>
                <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full ${color}" style="width:${pct}%"></div></div></div>`;
        };
        function drift(sec) {
            if (sec == null) return '';
            const a = Math.abs(sec), txt = a < 60 ? a + ' s' : Math.floor(a / 60) + ' min ' + (a % 60) + ' s';
            const cls = a <= 30 ? 'text-emerald-700' : a <= 120 ? 'text-amber-700' : 'text-red-700';
            return `<span class="${cls} font-semibold">${a === 0 ? 'In sync' : txt + (sec > 0 ? ' ahead' : ' behind')}</span>`;
        }
        function loadOverview() {
            const key = current;
            setDot(key, 'busy');
            $('#overviewBody').html(SPINNER + ' Connecting to ' + esc(DEVICES[key].ip) + '…');
            api('status').done((r) => {
                if (key !== current) return;
                setDot(key, r.ok ? 'up' : 'down');
                if (!r.ok) return $('#overviewBody').html('<p class="text-red-700">' + esc(r.error) + '</p>');
                const i = r.info, s = r.sizes || {};
                const row = (k, v) => v ? `<dt class="text-slate-500">${k}</dt><dd class="text-slate-900 font-mono text-xs break-all">${esc(v)}</dd>` : '';
                $('#overviewBody').html(`
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 lg:col-span-1">
                            <h3 class="text-sm font-bold text-slate-900 mb-3">Device</h3>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                                ${row('Name', i.deviceName)}${row('Serial', i.serialNumber)}${row('Platform', i.platform)}${row('Firmware', i.firmware)}
                                ${row('FP algorithm', i.fpVersion)}${row('Face', i.face)}${row('Work code', i.workCode)}${row('SSR', i.ssr)}${row('PIN width', i.pinWidth)}${row('OS', i.os)}
                                ${row('Response', r.ms + ' ms')}
                            </dl>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
                            <h3 class="text-sm font-bold text-slate-900">Storage</h3>
                            ${r.sizes ? bar('Users', s.users, s.usersCap) + bar('Fingerprints', s.fingerprints, s.fingersCap) + bar('Attendance records', s.records, s.recordsCap) +
                                (s.cards ? `<p class="text-xs text-slate-500">Cards: ${fmt(s.cards)}</p>` : '') : '<p class="text-slate-500">Not reported.</p>'}
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                            <h3 class="text-sm font-bold text-slate-900 mb-3">Clock</h3>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm mb-4">
                                <dt class="text-slate-500">Device</dt><dd class="tabular-nums font-semibold text-slate-900">${esc(r.deviceTime || '—')}</dd>
                                <dt class="text-slate-500">Server</dt><dd class="tabular-nums text-slate-700">${esc(r.serverTime)}</dd>
                                <dt class="text-slate-500">Difference</dt><dd>${drift(r.driftSec)}</dd>
                            </dl>
                            <button type="button" id="syncTime" class="w-full px-4 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white disabled:opacity-60">Sync to server time</button>
                            <div class="flex gap-2 mt-3">
                                <input id="customTime" type="datetime-local" step="1" class="flex-1 min-w-0 rounded-lg border border-slate-300 text-sm p-2">
                                <button type="button" id="setTime" class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60">Set</button>
                            </div>
                            <button type="button" id="reloadOverview" class="mt-4 text-xs font-semibold text-slate-600 hover:text-slate-900 underline underline-offset-2">Refresh</button>
                        </div>
                    </div>`);
            }).fail(() => { setDot(key, 'down'); $('#overviewBody').html('<p class="text-red-700">Could not reach the server.</p>'); });
        }
        function setClock(data, $btn) {
            $btn.prop('disabled', true).prepend(SPINNER + ' ');
            api('settime', data, 'POST').done((r) => {
                toast(r.ok, r.ok ? 'Device time set to ' + r.set : r.error);
                loadOverview();
            }).fail(() => { toast(false, 'Request failed'); $btn.prop('disabled', false).find('.spinner').remove(); });
        }
        $(document).on('click', '#syncTime', function() { setClock({ mode: 'sync' }, $(this)); });
        $(document).on('click', '#setTime', function() {
            const t = $('#customTime').val();
            if (!t) return toast(false, 'Pick a date and time first');
            setClock({ mode: 'custom', time: t.replace('T', ' ') }, $(this));
        });
        $(document).on('click', '#reloadOverview', loadOverview);

        // ---- Users -----------------------------------------------------------------------------
        function loadUsers() {
            const key = current;
            $('#usersBody').html('<p class="p-5">' + SPINNER + ' Loading users…</p>');
            api('users').done((r) => {
                if (key !== current) return;
                if (!r.ok) return $('#usersBody').html('<p class="p-5 text-red-700">' + esc(r.error) + '</p>');
                users = r.users;
                renderUsers();
            }).fail(() => $('#usersBody').html('<p class="p-5 text-red-700">Could not load users.</p>'));
        }
        function renderUsers() {
            const q = $('#userSearch').val().trim().toLowerCase();
            const list = users.filter((u) => !q || (u.name + ' ' + u.userid + ' ' + u.card + ' ' + u.uid).toLowerCase().includes(q));
            $('#usersBody').html(`<table class="w-full">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr>
                    <th class="px-4 py-2 text-left">UID</th><th class="px-4 py-2 text-left">User ID</th><th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">Role</th><th class="px-4 py-2 text-left">Card</th><th class="px-4 py-2 text-left">Password</th><th class="px-4 py-2"></th>
                </tr></thead>
                <tbody>${list.map((u) => `<tr class="border-t border-slate-100">
                    <td class="px-4 py-2 tabular-nums text-slate-500">${u.uid}</td>
                    <td class="px-4 py-2 font-mono">${esc(u.userid)}</td>
                    <td class="px-4 py-2 font-semibold text-slate-900">${esc(u.name)}</td>
                    <td class="px-4 py-2">${u.role ? '<span class="px-2 py-0.5 rounded-md bg-violet-50 text-violet-700 text-xs font-semibold">' + esc(u.roleName) + '</span>' : esc(u.roleName)}</td>
                    <td class="px-4 py-2 font-mono text-slate-600">${esc(u.card)}</td>
                    <td class="px-4 py-2 text-slate-500">${u.hasPass ? 'Set' : ''}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <button type="button" class="edit-user text-xs font-semibold text-slate-700 hover:text-slate-900 underline underline-offset-2" data-uid="${u.uid}">Edit</button>
                        <button type="button" class="del-user ml-3 text-xs font-semibold text-red-700 hover:text-red-500 underline underline-offset-2" data-uid="${u.uid}">Delete</button>
                    </td></tr>`).join('') || '<tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">No users</td></tr>'}</tbody>
                <tfoot><tr><td colspan="7" class="px-4 py-2 text-xs text-slate-500 border-t border-slate-100">${fmt(list.length)} of ${fmt(users.length)} users</td></tr></tfoot>
            </table>`);
        }
        $('#userSearch').on('input', renderUsers);
        $('#reloadUsers').on('click', loadUsers);

        const $uf = $('#userForm');
        function openUserForm(u) {
            $uf[0].reset();
            $('#userFormTitle').text(u ? 'Edit user ' + u.name : 'Add user');
            const nextUid = users.reduce((m, x) => Math.max(m, x.uid), 0) + 1;
            $uf.find('[name=uid]').val(u ? u.uid : nextUid).prop('readOnly', !!u);
            $uf.find('[name=userid]').val(u ? u.userid : '');
            $uf.find('[name=name]').val(u ? u.name : '');
            $uf.find('[name=role]').val(u ? u.role : 0);
            $uf.find('[name=card]').val(u ? u.card : '');
            $uf.find('[name=password]').attr('placeholder', u && u.hasPass ? 'Leave blank to keep' : '');
            $uf.removeClass('hidden');
            $uf.find(u ? '[name=name]' : '[name=userid]').trigger('focus');
        }
        $('#addUser').on('click', () => openUserForm(null));
        $('#cancelUser').on('click', () => $uf.addClass('hidden'));
        $(document).on('click', '.edit-user', function() { openUserForm(users.find((u) => u.uid === +$(this).data('uid'))); });
        $uf.on('submit', function(e) {
            e.preventDefault();
            const $btn = $uf.find('[type=submit]').prop('disabled', true);
            api('saveuser', Object.fromEntries(new FormData(this)), 'POST').done((r) => {
                toast(r.ok, r.ok ? (r.existed ? 'User updated' : 'User added') : r.error);
                if (r.ok) { $uf.addClass('hidden'); loadUsers(); }
            }).fail(() => toast(false, 'Request failed')).always(() => $btn.prop('disabled', false));
        });
        $(document).on('click', '.del-user', function() {
            const u = users.find((x) => x.uid === +$(this).data('uid'));
            if (!u || !confirm(`Delete ${u.name} (user ID ${u.userid}) from ${DEVICES[current].name}? Their fingerprints are removed too.`)) return;
            api('deluser', { uid: u.uid }, 'POST').done((r) => {
                toast(r.ok, r.ok ? 'User deleted' : r.error);
                if (r.ok) loadUsers();
            }).fail(() => toast(false, 'Request failed'));
        });

        // ---- Attendance ------------------------------------------------------------------------
        const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        const $af = $('#attForm');
        $af.find('[name=to]').val(ymd(new Date()));
        $af.find('[name=from]').val(ymd(new Date(Date.now() - 6 * 86400000)));

        function loadAttendance(refresh) {
            const key = current;
            const params = Object.fromEntries(new FormData($af[0]));
            if (refresh) params.refresh = 1;
            $af.find('[type=submit]').prop('disabled', true);
            $('#attRefresh').prop('disabled', true);
            const started = Date.now();
            const tick = setInterval(() => $('#attInfo').html(SPINNER + ' Reading the attendance log… ' + Math.round((Date.now() - started) / 1000) + 's (a large log takes about a minute)'), 1000);
            $('#attInfo').html(SPINNER + ' Reading the attendance log…');
            api('attendance', params).done((r) => {
                if (key !== current) return;
                if (!r.ok) { $('#attInfo').html('<span class="text-red-600">' + esc(r.error) + '</span>'); return; }
                attRows = r.rows;
                $('#attInfo').text(`${fmt(r.rows.length)} punches shown · ${fmt(r.total)} records on the device since ${r.oldest || '—'} · downloaded ${r.fetched}`);
                renderAttendance();
                $('#attExport').prop('disabled', !attRows.length);
            }).fail(() => $('#attInfo').html('<span class="text-red-600">Could not load attendance.</span>'))
              .always(() => { clearInterval(tick); $af.find('[type=submit]').prop('disabled', false); $('#attRefresh').prop('disabled', false); });
        }
        const SHOW = 1000;
        function renderAttendance() {
            const TYPE = { 'Check-in': 'text-emerald-700', 'Check-out': 'text-blue-700' };
            $('#attBody').html(`<table class="w-full">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr><th class="px-4 py-2 text-left">Time</th><th class="px-4 py-2 text-left">User ID</th><th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Punch</th><th class="px-4 py-2"></th></tr></thead>
                <tbody>${attRows.slice(0, SHOW).map((a, i) => `<tr class="border-t border-slate-100">
                    <td class="px-4 py-1.5 tabular-nums whitespace-nowrap">${esc(a[0])}</td><td class="px-4 py-1.5 font-mono">${esc(a[1])}</td>
                    <td class="px-4 py-1.5">${esc(a[2] || '—')}</td><td class="px-4 py-1.5 ${TYPE[a[3]] || 'text-slate-600'}">${esc(a[3])}</td>
                    <td class="px-4 py-1.5 text-right whitespace-nowrap">
                        <button type="button" class="edit-punch text-xs font-semibold text-slate-700 hover:text-slate-900 underline underline-offset-2" data-i="${i}">Edit</button>
                        <button type="button" class="del-punch ml-3 text-xs font-semibold text-red-700 hover:text-red-500 underline underline-offset-2" data-i="${i}">Delete</button>
                    </td></tr>`).join('')
                    || '<tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No punches in this range</td></tr>'}</tbody>
                ${attRows.length > SHOW ? `<tfoot><tr><td colspan="5" class="px-4 py-2 text-xs text-slate-500 border-t border-slate-100">Showing the newest ${fmt(SHOW)} of ${fmt(attRows.length)}; Export Excel has them all.</td></tr></tfoot>` : ''}
            </table>`);
        }
        $af.on('submit', (e) => { e.preventDefault(); loadAttendance(false); });
        $('#attRefresh').on('click', () => loadAttendance(true));
        $('#attExport').on('click', function() {
            const head = ['Time', 'User ID', 'Name', 'Punch'];
            const ws = XLSX.utils.aoa_to_sheet([head, ...attRows.map((a) => a.slice(0, 4))]);
            head.forEach((_, c) => { const cell = ws[XLSX.utils.encode_cell({ r: 0, c })]; cell.s = { font: { bold: true } }; });
            ws['!cols'] = [{ wch: 20 }, { wch: 10 }, { wch: 28 }, { wch: 14 }];
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Attendance');
            const p = Object.fromEntries(new FormData($af[0]));
            XLSX.writeFile(wb, `Attendance-${current}-${p.from || 'all'}_${p.to || 'all'}.xlsx`);
        });

        // ---- Add punch ---------------------------------------------------------------------------
        const PUNCH = <?= json_encode(ZK_PUNCH) ?>, VERIFY = <?= json_encode(ZK_VERIFY) ?>;
        const $pf = $('#punchForm');
        const punchUsers = () => $('#punchUsers').html(users.map((u) => `<option value="${esc(u.userid)}">${esc(u.name)}</option>`).join(''));
        function punchUserName() {
            const id = $pf.find('[name=userid]').val().trim(), u = users.find((x) => x.userid === id);
            $('#punchUser').html(!id ? '' : u ? esc(u.name) : '<span class="text-red-600">Not a user on this device</span>');
        }
        let editing = null; // attendance row being edited, or null when adding
        const recordFields = (a) => ({ o_time: a[0], o_userid: a[1], o_punch: a[4], o_verify: a[5] });
        function openPunchForm(a) {
            editing = a;
            $pf[0].reset(); $('#punchResult, #punchUser').empty(); $pf.removeClass('hidden');
            $('#punchFormTitle').text(a ? `Edit ${a[3]} of ${a[2] || 'user ID ' + a[1]} at ${a[0]}` : "Add a punch to the device's attendance log");
            $('#savePunch').text(a ? 'Save changes' : 'Add to device');
            $pf.find('[name=userid]').val(a ? a[1] : '').prop('readOnly', !!a);
            if (a) { $pf.find('[name=time]').val(a[0].replace(' ', 'T')); $pf.find('[name=punch]').val(a[4]); $pf.find('[name=verify]').val(a[5]); }
            if (users.length) { punchUsers(); punchUserName(); }
            else api('users').done((r) => { if (r.ok) { users = r.users; punchUsers(); punchUserName(); } });
            $pf.find(a ? '[name=time]' : '[name=userid]').trigger('focus');
        }
        $('#addPunch').on('click', () => openPunchForm(null));
        $(document).on('click', '.edit-punch', function() { openPunchForm(attRows[+$(this).data('i')]); });
        $('#cancelPunch').on('click', () => $pf.addClass('hidden'));
        $pf.find('[name=userid]').on('input', punchUserName);
        function sendPunch(dry) {
            if (!$pf[0].reportValidity()) return;
            const data = Object.fromEntries(new FormData($pf[0]));
            const what = `${PUNCH[data.punch]} for user ID ${data.userid} at ${data.time.replace('T', ' ')}`;
            if (!dry && !confirm(editing
                ? `Change ${editing[3]} at ${editing[0]} to ${what} on ${DEVICES[current].name}?`
                : `Write ${what} into ${DEVICES[current].name}'s attendance log?`)) return;
            const $btns = $pf.find('button').prop('disabled', true);
            $('#punchResult').html(SPINNER + (dry ? ' Checking against the device log…' : ' Writing, then reading the log again to confirm (a large log takes minutes)…'));
            if (editing) Object.assign(data, recordFields(editing));
            api(editing ? 'editpunch' : 'addpunch', Object.assign(data, dry ? { dry: 1 } : {}), 'POST').done((r) => {
                if (!r.ok) {
                    $('#punchResult').html('<span class="text-red-600">' + esc(r.error) + '</span>');
                    if (!dry && (r.written || r.changed || r.step)) loadAttendance(true);
                    return toast(false, r.error);
                }
                if (r.dryRun) return $('#punchResult').html(`<span class="text-emerald-700">Check passed: ${esc(what)} (${esc(VERIFY[data.verify])}) can be ${editing ? 'saved' : 'added'}. Nothing was written.</span>`);
                $('#punchResult').html(`<span class="text-emerald-700">${editing ? 'Changed' : 'Added'} and confirmed in the device log: ${esc(what)}.</span>`);
                toast(true, editing ? 'Punch changed and confirmed on the device' : 'Punch added and confirmed on the device');
                loadAttendance(true);
            }).fail(() => { $('#punchResult').html('<span class="text-red-600">Request failed. Check the attendance log before trying again.</span>'); toast(false, 'Request failed'); })
              .always(() => $btns.prop('disabled', false));
        }
        $(document).on('click', '.del-punch', function() {
            const a = attRows[+$(this).data('i')], $btn = $(this);
            if (!confirm(`Delete ${a[3]} of ${a[2] || 'user ID ' + a[1]} at ${a[0]} from ${DEVICES[current].name}?\n\nThe device can only delete a whole second, so any other punches in that second are written back afterwards and checked.`)) return;
            $btn.prop('disabled', true).html(SPINNER + ' Deleting…');
            api('delpunch', recordFields(a), 'POST').done((r) => {
                toast(r.ok, r.ok ? 'Punch deleted and confirmed' + (r.rewritten ? ` (${r.rewritten} other punch(es) in that second written back)` : '') : r.error);
                if (r.ok || r.changed) loadAttendance(true);
            }).fail(() => toast(false, 'Request failed. Check the attendance log before trying again'))
              .always(() => $btn.prop('disabled', false).text('Delete'));
        });
        $('#checkPunch').on('click', () => sendPunch(true));
        $pf.on('submit', (e) => { e.preventDefault(); sendPunch(false); });

        // ---- Maintenance ----------------------------------------------------------------------
        $('.danger').on('click', function() {
            const $btn = $(this), op = $btn.data('op');
            const typed = prompt(`${$btn.data('title')} on ${DEVICES[current].name} (${DEVICES[current].ip}).\n\nThis cannot be undone. Type the device key "${current}" to confirm:`);
            if (typed === null) return;
            if (typed !== current) return toast(false, 'Confirmation did not match; nothing was done');
            $btn.prop('disabled', true);
            api('danger', { op, confirm: typed }, 'POST').done((r) => {
                toast(r.ok, r.ok ? $btn.data('title') + ': done' : r.error);
                if (r.ok && ['clearatt', 'cleardata'].includes(op)) { attRows = []; $('#attBody').empty(); $('#attInfo').text(''); }
                if (r.ok && op === 'cleardata') users = [];
            }).fail(() => toast(false, 'Request failed')).always(() => $btn.prop('disabled', false));
        });

        // Check every device on load, then open the first one that answers.
        const keys = Object.keys(DEVICES);
        keys.forEach((k) => setDot(k, 'busy'));
        let opened = false;
        keys.forEach((k) => {
            $.ajax({ url: 'index.php?api=status', data: { d: k }, dataType: 'json', timeout: 120000 })
                .done((r) => { setDot(k, r.ok ? 'up' : 'down'); if (r.ok && !opened && !current) { opened = true; selectDevice(k); } })
                .fail(() => setDot(k, 'down'));
        });
    });
</script>
</body>

</html>
