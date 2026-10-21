<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/tracker.php';

$config  = rt_config();
$routers = $config['routers'];

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
    session_write_close();
    set_time_limit(120);

    $key = (string) ($_GET['r'] ?? '');
    if (!isset($routers[$key])) {
        $reply(['ok' => false, 'error' => 'Unknown router'], 400);
    }
    $labels = $config['labels'];
    $iv     = $config['interval'];
    $db     = rt_db();
    $label  = fn(string $mac, ?string $name) => $labels[$mac] ?? ($name !== null && $name !== '' && strcasecmp($name, 'Unknown') !== 0 ? $name : null);

    // When the background collector (or this page) last read the router.
    $lastPoll = $db->query('SELECT ts, ok, error FROM polls WHERE router = ' . $db->quote($key) . ' ORDER BY ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;

    switch ((string) $_GET['api']) {
        // Devices online now. The reading is also stored when the last stored sample is old enough,
        // so history builds up while this page is open even without the background collector.
        case 'live':
            $r = rt_sample($key, $routers[$key], $iv - 2);
            if (isset($r['error'])) {
                $reply(['ok' => false, 'error' => $r['error'], 'lastPoll' => $lastPoll]);
            }
            foreach ($r['devices'] as &$d) {
                $d['label'] = $label($d['mac'], $d['name']);
            }
            unset($d);
            usort($r['devices'], fn($a, $b) => ($b['down'] + $b['up']) <=> ($a['down'] + $a['up']));
            // Blocked devices and the Wi-Fi name, for the management panels.
            $blocked = [];
            $wifi = null;
            try {
                foreach (rt_qos_state($routers[$key]) as $d) {
                    if (!$d['allowed']) {
                        $blocked[] = ['mac' => $d['mac'], 'name' => $d['remark'] ?: $d['hostname'], 'label' => $label($d['mac'], $d['remark'] ?: $d['hostname']), 'online' => $d['online']];
                    }
                }
                $wifi = rt_wifi($routers[$key]);
            } catch (Throwable $e) {
                // The device list above is what matters.
            }
            $reply(['ok' => true, 'devices' => $r['devices'], 'blocked' => $blocked, 'wifi' => $wifi, 'wanSeconds' => $r['wanSeconds'], 'ts' => $r['ts'], 'lastPoll' => $lastPoll]);

        // Changes on the router (POST): block / unblock, speed limits, name, reboot.
        case 'manage':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $reply(['ok' => false, 'error' => 'Wrong request method'], 405);
            }
            $action = (string) ($_POST['action'] ?? '');
            $mac    = strtolower(trim((string) ($_POST['mac'] ?? '')));
            try {
                if ($action === 'reboot') {
                    if (($_POST['confirm'] ?? '') !== 'REBOOT') {
                        $reply(['ok' => false, 'error' => 'Confirmation did not match']);
                    }
                    $ok = rt_reboot($routers[$key]);
                    $reply(['ok' => $ok, 'error' => $ok ? null : 'The router did not start rebooting']);
                }
                if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                    $reply(['ok' => false, 'error' => 'Invalid MAC address'], 400);
                }
                // Speeds in KB/s: blank or 0 means no limit.
                $kb = function ($v) {
                    $v = trim((string) $v);
                    if ($v === '') {
                        return null;
                    }
                    if (!ctype_digit($v) || (int) $v > 38400) {
                        throw new InvalidArgumentException('Limits are whole KB/s from 1 to 38400 (blank for no limit)');
                    }
                    return (int) $v === 0 ? null : (int) $v;
                };
                $change = match ($action) {
                    'block'   => ['allowed' => false],
                    'unblock' => ['allowed' => true, 'up' => null, 'down' => null],
                    'limit'   => ['down' => $kb($_POST['down'] ?? ''), 'up' => $kb($_POST['up'] ?? '')],
                    'rename'  => ['remark' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 63)],
                    default   => null,
                };
                if ($change === null) {
                    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
                }
                $now = rt_qos_change($routers[$key], $mac, $change);
                $reply(['ok' => true, 'device' => $now]);
            } catch (Throwable $e) {
                $reply(['ok' => false, 'error' => $e->getMessage()]);
            }

        // History between two Unix times: bandwidth over time, per-device totals, connect / disconnect events.
        case 'history':
            $from = (int) ($_GET['from'] ?? time() - 3600);
            $to   = (int) ($_GET['to'] ?? time());
            $mac  = strtolower(trim((string) ($_GET['mac'] ?? '')));
            if ($to <= $from || $to - $from > 92 * 86400) {
                $reply(['ok' => false, 'error' => 'Pick a period of up to 92 days'], 400);
            }

            // Bandwidth over time, averaged into at most ~300 buckets. Polls with no devices count as 0.
            $bucket = max($iv, (int) ceil(($to - $from) / 300 / 60) * 60);
            $polls  = $db->prepare('SELECT ts FROM polls WHERE router = ? AND ok = 1 AND ts BETWEEN ? AND ?');
            $polls->execute([$key, $from, $to]);
            $series = [];
            foreach ($polls->fetchAll(PDO::FETCH_COLUMN) as $ts) {
                $b = intdiv($ts - $from, $bucket);
                $series[$b] ??= ['n' => 0, 'down' => 0.0, 'up' => 0.0];
                $series[$b]['n']++;
            }
            $sql = 'SELECT ts, SUM(down) d, SUM(up) u FROM samples WHERE router = ? AND ts BETWEEN ? AND ?' . ($mac !== '' ? ' AND mac = ?' : '') . ' GROUP BY ts';
            $st  = $db->prepare($sql);
            $st->execute($mac !== '' ? [$key, $from, $to, $mac] : [$key, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $b = intdiv((int) $row['ts'] - $from, $bucket);
                $series[$b] ??= ['n' => 1, 'down' => 0.0, 'up' => 0.0];
                $series[$b]['down'] += (float) $row['d'];
                $series[$b]['up']   += (float) $row['u'];
            }
            ksort($series);
            $points = [];
            foreach ($series as $b => $s) {
                $points[] = [$from + $b * $bucket, round($s['down'] / max(1, $s['n']), 1), round($s['up'] / max(1, $s['n']), 1)];
            }

            // Per device: estimated data (speed x sample interval), peaks, online time, first / last seen.
            $st = $db->prepare('SELECT mac, COUNT(*) n, SUM(down) sd, SUM(up) su, MAX(down) pd, MAX(up) pu, MIN(ts) first, MAX(ts) last
                FROM samples WHERE router = ? AND ts BETWEEN ? AND ? GROUP BY mac');
            $st->execute([$key, $from, $to]);
            $devices = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $devices[$row['mac']] = [
                    'mac'       => $row['mac'],
                    'downKB'    => round((float) $row['sd'] * $iv),
                    'upKB'      => round((float) $row['su'] * $iv),
                    'peakDown'  => (float) $row['pd'],
                    'peakUp'    => (float) $row['pu'],
                    'onlineSec' => (int) $row['n'] * $iv,
                    'first'     => (int) $row['first'],
                    'last'      => (int) $row['last'],
                ];
            }
            // Latest name / IP / connection type of each device.
            $st = $db->prepare('SELECT s.mac, s.ip, s.name, s.conn FROM samples s JOIN (SELECT mac, MAX(ts) t FROM samples WHERE router = ? AND ts BETWEEN ? AND ? GROUP BY mac) m
                ON s.mac = m.mac AND s.ts = m.t WHERE s.router = ?');
            $st->execute([$key, $from, $to, $key]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($devices[$row['mac']])) {
                    $devices[$row['mac']] += ['ip' => $row['ip'], 'name' => $row['name'], 'conn' => $row['conn'], 'label' => $label($row['mac'], $row['name'])];
                }
            }
            $devices = array_values($devices);
            usort($devices, fn($a, $b) => ($b['downKB'] + $b['upKB']) <=> ($a['downKB'] + $a['upKB']));

            // Connect / disconnect: a device missing for more than three samples in a row has left.
            // Only judged inside the stretch the router was actually read, so the start of the
            // history does not look like everyone joining.
            $gap = $iv * 3 + 5;
            $span = $db->prepare('SELECT MIN(ts), MAX(ts) FROM polls WHERE router = ? AND ok = 1 AND ts BETWEEN ? AND ?');
            $span->execute([$key, $from, $to]);
            [$firstPoll, $lastPollTs] = array_map('intval', $span->fetch(PDO::FETCH_NUM));
            $st  = $db->prepare('SELECT mac, ts FROM samples WHERE router = ? AND ts BETWEEN ? AND ? ORDER BY mac, ts');
            $st->execute([$key, $from, $to]);
            $events = [];
            $prevMac = null;
            $prevTs = 0;
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                [$m, $ts] = [$row[0], (int) $row[1]];
                if ($m !== $prevMac) {
                    if ($prevMac !== null && $lastPollTs - $prevTs > $gap) {
                        $events[] = [$prevTs, $prevMac, 'left'];
                    }
                    if ($ts - $firstPoll > $gap) {
                        $events[] = [$ts, $m, 'joined'];
                    }
                } elseif ($ts - $prevTs > $gap) {
                    $events[] = [$prevTs, $m, 'left'];
                    $events[] = [$ts, $m, 'joined'];
                }
                [$prevMac, $prevTs] = [$m, $ts];
            }
            if ($prevMac !== null && $lastPollTs - $prevTs > $gap) {
                $events[] = [$prevTs, $prevMac, 'left'];
            }
            usort($events, fn($a, $b) => $b[0] <=> $a[0]);

            $fails = $db->prepare('SELECT COUNT(*) FROM polls WHERE router = ? AND ok = 0 AND ts BETWEEN ? AND ?');
            $fails->execute([$key, $from, $to]);
            $reply([
                'ok'       => true,
                'bucket'   => $bucket,
                'points'   => $points,
                'devices'  => $devices,
                'events'   => array_slice($events, 0, 300),
                'failures' => (int) $fails->fetchColumn(),
                'lastPoll' => $lastPoll,
                'interval' => $iv,
            ]);
    }
    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

require_login();
$pageTitle = 'Router Activity';
include __DIR__ . '/../header.php';
?>
<style>
    .viz-root { --series-1: #2a78d6; --series-2: #eb6834; --grid: #e7e5e4; --text-secondary: #52514e; --text-muted: #8a8985; }
</style>

<div class="viz-root max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Router Activity Tracker</h1>
            <p class="text-sm text-slate-500">Who is connected to the router and how much bandwidth each device uses, live and over time. Speeds are as the router reports them; data volumes are estimated from them.</p>
        </div>
        <?php if (count($routers) > 1): ?>
            <select id="router" class="rounded-lg border border-slate-300 text-sm p-2 bg-white">
                <?php foreach ($routers as $k => $r): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($r['name'] ?? $k) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <?php if (!$routers): ?>
        <div class="p-5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">No routers configured. Copy <span class="font-mono">router-tracker/config.example.php</span> to <span class="font-mono">config.php</span>.</div>
    <?php else: ?>
        <div id="collector" class="hidden mb-4 p-4 rounded-xl text-sm"></div>
        <div id="tiles" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6"></div>

        <!-- Router: Wi-Fi name and reboot -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6 px-5 py-3 flex flex-wrap items-center justify-between gap-3">
            <p id="routerInfo" class="text-sm text-slate-600"></p>
            <button type="button" id="reboot" class="px-4 py-2 rounded-lg border border-red-200 bg-white text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-60">Reboot router</button>
        </section>

        <!-- Live -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3 border-b border-slate-100">
                <h2 class="text-base font-bold text-slate-900">Connected now</h2>
                <p id="liveStatus" class="text-xs text-slate-500 tabular-nums"></p>
            </div>
            <div id="live" class="overflow-x-auto"></div>
            <div id="blocked" class="hidden border-t border-slate-100 px-5 py-3"></div>
        </section>

        <!-- History -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
                <h2 class="text-base font-bold text-slate-900">Bandwidth <span id="chartFor" class="font-normal text-sm text-slate-500">all devices</span></h2>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex gap-1 rounded-lg bg-slate-100 p-1" id="ranges">
                        <?php foreach (['1h' => 'Hour', 'today' => 'Today', '24h' => '24 h', '7d' => '7 days', '30d' => '30 days'] as $k => $l): ?>
                            <button type="button" class="range px-3 py-1 rounded-md text-xs font-semibold" data-r="<?= $k ?>"><?= $l ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="clearDevice" class="hidden text-xs font-semibold text-slate-600 underline underline-offset-2">Show all devices</button>
                </div>
            </div>
            <div class="px-5 pt-3 flex items-center gap-4 text-xs text-slate-600" aria-label="Legend">
                <span class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" style="background:var(--series-1)"></span>Download</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" style="background:var(--series-2)"></span>Upload</span>
            </div>
            <div id="chart" class="relative px-2 pb-4"></div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <section class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-base font-bold text-slate-900">Usage by device</h2>
                    <p class="text-xs text-slate-500">Click a device to chart only its traffic.</p>
                </div>
                <div id="usage" class="overflow-x-auto"></div>
            </section>
            <section class="bg-white rounded-2xl border border-slate-200 shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100"><h2 class="text-base font-bold text-slate-900">Joined / left</h2></div>
                <div id="events" class="max-h-[28rem] overflow-y-auto text-sm"></div>
            </section>
        </div>
    <?php endif; ?>
</div>

<script>
    $(function() {
        if (!<?= $routers ? 'true' : 'false' ?>) return;
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const router = () => $('#router').val() || <?= json_encode(array_key_first($routers)) ?>;
        let range = '24h', deviceFilter = '', liveTimer = null, history = null, live = null;

        const speed = (kb) => kb == null ? '—' : kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB/s' : Math.round(kb) + ' KB/s';
        const size = (kb) => kb >= 1048576 ? (kb / 1048576).toFixed(2) + ' GB' : kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB' : Math.round(kb) + ' KB';
        const dur = (s) => s >= 3600 ? Math.floor(s / 3600) + ' h ' + Math.floor(s % 3600 / 60) + ' min' : Math.round(s / 60) + ' min';
        const time = (ts) => { const d = new Date(ts * 1000); return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
        const devName = (d) => d.label || d.name || d.mac;

        function period() {
            const now = Math.floor(Date.now() / 1000);
            if (range === 'today') { const d = new Date(); d.setHours(0, 0, 0, 0); return [Math.floor(d / 1000), now]; }
            return [now - { '1h': 3600, '24h': 86400, '7d': 7 * 86400, '30d': 30 * 86400 }[range], now];
        }

        // ---- Live -------------------------------------------------------------------------------
        function loadLive() {
            if ($('.manage-row').length) return;
            $.getJSON('index.php', { api: 'live', r: router() }).done((r) => {
                live = r;
                if (!r.ok) {
                    $('#liveStatus').html('<span class="text-red-600">' + esc(r.error) + '</span>');
                    return renderCollector(r.lastPoll);
                }
                $('#liveStatus').text('Updated ' + new Date(r.ts * 1000).toLocaleTimeString('en-GB') + (r.wanSeconds != null ? ' · internet up ' + dur(r.wanSeconds) : '') + ' · refreshes every 5 s');
                const max = Math.max(1, ...r.devices.map((d) => Math.max(d.down, d.up)));
                const bar = (v, c) => `<div class="flex items-center gap-2 justify-end"><span class="tabular-nums">${speed(v)}</span><span class="w-24 h-1.5 rounded-full bg-slate-100 overflow-hidden"><span class="block h-full rounded-full" style="width:${Math.round(v / max * 100)}%;background:${c}"></span></span></div>`;
                $('#live').html(`<table class="w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr>
                    <th class="px-5 py-2 text-left">Device</th><th class="px-3 py-2 text-left">IP</th><th class="px-3 py-2 text-left">MAC</th><th class="px-3 py-2 text-left">Link</th>
                    <th class="px-3 py-2 text-right">Download</th><th class="px-3 py-2 text-right">Upload</th><th class="px-5 py-2"></th></tr></thead>
                    <tbody>${r.devices.map((d) => `<tr class="dev-row border-t border-slate-100" data-mac="${esc(d.mac)}">
                        <td class="px-5 py-2 font-semibold text-slate-900">${esc(d.label || d.name || 'Unknown')}${d.label && d.name && d.label !== d.name ? ' <span class="font-normal text-xs text-slate-400">' + esc(d.name) + '</span>' : ''}</td>
                        <td class="px-3 py-2 font-mono text-xs">${esc(d.ip)}</td><td class="px-3 py-2 font-mono text-xs text-slate-500">${esc(d.mac)}</td>
                        <td class="px-3 py-2 text-xs text-slate-600">${d.conn === 'wifi' ? 'Wi-Fi' : esc(d.conn || '—')}</td>
                        <td class="px-3 py-2 text-right">${bar(d.down, 'var(--series-1)')}${d.downLimit ? '<div class="text-[11px] text-slate-500">limit ' + speed(d.downLimit) + '</div>' : ''}</td>
                        <td class="px-3 py-2 text-right">${bar(d.up, 'var(--series-2)')}${d.upLimit ? '<div class="text-[11px] text-slate-500">limit ' + speed(d.upLimit) + '</div>' : ''}</td>
                        <td class="px-5 py-2 text-right"><button type="button" class="manage text-xs font-semibold text-slate-700 hover:text-slate-900 underline underline-offset-2">Manage</button></td>
                    </tr>`).join('') || '<tr><td colspan="7" class="px-5 py-6 text-center text-slate-400">No devices connected</td></tr>'}</tbody></table>`);
                renderBlocked(r);
                if (r.wifi) $('#routerInfo').html('Wi-Fi <span class="font-semibold text-slate-900">' + esc(r.wifi.ssid) + '</span> · ' + esc(r.wifi.security) + (r.wifi.hidden ? ' · hidden' : ''));
                renderTiles();
                renderCollector(r.lastPoll);
            }).fail(() => $('#liveStatus').html('<span class="text-red-600">Could not reach the server</span>'));
        }

        // ---- Management --------------------------------------------------------------------------
        const toast = (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' });
        const manage = (data) => $.post('index.php?api=manage&r=' + encodeURIComponent(router()), data, null, 'json');

        $(document).on('click', '.manage', function() {
            const $row = $(this).closest('.dev-row'), mac = $row.data('mac');
            if ($row.next().hasClass('manage-row')) { $row.next().remove(); return loadLive(); }
            $('.manage-row').remove();
            const d = live.devices.find((x) => x.mac === mac) || {};
            $row.after(`<tr class="manage-row bg-slate-50"><td colspan="7" class="px-5 py-4">
                <div class="flex flex-wrap items-end gap-4">
                    <label class="text-xs font-semibold text-slate-600">Name on router
                        <span class="flex gap-2 mt-1"><input class="m-name w-48 rounded-lg border border-slate-300 text-sm p-2" maxlength="63" value="${esc(d.name || '')}">
                        <button type="button" class="m-rename px-3 py-2 rounded-lg border border-slate-300 bg-white text-xs font-semibold hover:bg-slate-100">Rename</button></span></label>
                    <label class="text-xs font-semibold text-slate-600">Download limit (KB/s)
                        <input class="m-down mt-1 block w-32 rounded-lg border border-slate-300 text-sm p-2" inputmode="numeric" placeholder="No limit" value="${d.downLimit || ''}"></label>
                    <label class="text-xs font-semibold text-slate-600">Upload limit (KB/s)
                        <input class="m-up mt-1 block w-32 rounded-lg border border-slate-300 text-sm p-2" inputmode="numeric" placeholder="No limit" value="${d.upLimit || ''}"></label>
                    <button type="button" class="m-limit px-3 py-2 rounded-lg border border-slate-300 bg-white text-xs font-semibold hover:bg-slate-100">Set limits</button>
                    <button type="button" class="m-block ml-auto px-4 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-xs font-semibold text-white">Block internet access</button>
                </div>
                <p class="mt-2 text-xs text-slate-500">Blank limits mean no limit. Blocking keeps the device on Wi-Fi but cuts its internet; unblock it from the Blocked list.</p>
            </td></tr>`);
        });

        function after(r, message) {
            toast(r.ok, r.ok ? message : r.error);
            if (r.ok) { $('.manage-row').remove(); loadLive(); }
        }
        $(document).on('click', '.m-rename', function() {
            const mac = $(this).closest('.manage-row').prev().data('mac');
            manage({ action: 'rename', mac, name: $(this).closest('.manage-row').find('.m-name').val() }).done((r) => after(r, 'Name saved on the router')).fail(() => toast(false, 'Request failed'));
        });
        $(document).on('click', '.m-limit', function() {
            const $m = $(this).closest('.manage-row'), mac = $m.prev().data('mac');
            manage({ action: 'limit', mac, down: $m.find('.m-down').val(), up: $m.find('.m-up').val() }).done((r) => after(r, 'Limits set')).fail(() => toast(false, 'Request failed'));
        });
        $(document).on('click', '.m-block', function() {
            const mac = $(this).closest('.manage-row').prev().data('mac'), d = live.devices.find((x) => x.mac === mac) || {};
            if (!confirm(`Block internet access for ${d.label || d.name || mac} (${mac})?`)) return;
            manage({ action: 'block', mac }).done((r) => after(r, 'Device blocked')).fail(() => toast(false, 'Request failed'));
        });

        function renderBlocked(r) {
            const list = r.blocked || [];
            $('#blocked').toggleClass('hidden', !list.length).html(list.length ? `<h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">Blocked (${list.length})</h3>` +
                list.map((b) => `<div class="flex items-center gap-3 py-1 text-sm"><span class="w-2 h-2 rounded-full bg-red-500"></span>
                    <span class="font-semibold text-slate-900">${esc(b.label || b.name || 'Unknown')}</span><span class="font-mono text-xs text-slate-500">${esc(b.mac)}</span>
                    <span class="text-xs text-slate-400">${b.online ? 'connected' : 'not connected'}</span>
                    <button type="button" class="unblock ml-auto text-xs font-semibold text-emerald-700 hover:text-emerald-600 underline underline-offset-2" data-mac="${esc(b.mac)}">Unblock</button></div>`).join('') : '');
        }
        $(document).on('click', '.unblock', function() {
            manage({ action: 'unblock', mac: $(this).data('mac') }).done((r) => after(r, 'Device unblocked')).fail(() => toast(false, 'Request failed'));
        });
        $('#reboot').on('click', function() {
            const typed = prompt('Reboot the router? Everyone on it loses the connection for about a minute.\n\nType REBOOT to confirm:');
            if (typed === null) return;
            if (typed !== 'REBOOT') return toast(false, 'Confirmation did not match; nothing was done');
            manage({ action: 'reboot', confirm: typed }).done((r) => toast(r.ok, r.ok ? 'The router is rebooting' : r.error)).fail(() => toast(false, 'Request failed'));
        });

        // The page records while open; without the scheduled collector there are gaps when it is closed.
        function renderCollector(lastPoll) {
            const age = lastPoll ? Math.floor(Date.now() / 1000) - lastPoll.ts : null;
            const $c = $('#collector');
            if (lastPoll && !+lastPoll.ok) {
                $c.attr('class', 'mb-4 p-4 rounded-xl text-sm bg-red-50 border border-red-200 text-red-800').html('The last reading of the router failed: ' + esc(lastPoll.error)).removeClass('hidden');
            } else if (history && history.points.length && age != null && age < 120) {
                $c.addClass('hidden');
            }
        }

        function renderTiles() {
            const tile = (label, v, sub) => `<div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">${label}</div><div class="text-2xl font-bold tabular-nums text-slate-800">${v}</div>${sub ? '<div class="text-xs text-slate-500 mt-0.5">' + sub + '</div>' : ''}</div>`;
            const devs = live && live.ok ? live.devices : [];
            const h = history && history.ok ? history : null;
            const down = h ? h.devices.reduce((s, d) => s + d.downKB, 0) : 0, up = h ? h.devices.reduce((s, d) => s + d.upKB, 0) : 0;
            const label = $(`.range[data-r="${range}"]`).text();
            $('#tiles').html(
                tile('Connected now', devs.length) +
                tile('Download now', speed(devs.reduce((s, d) => s + d.down, 0))) +
                tile('Upload now', speed(devs.reduce((s, d) => s + d.up, 0))) +
                tile('Devices seen', h ? h.devices.length : '—', label) +
                tile('Downloaded', h ? size(down) : '—', label + ' · estimated') +
                tile('Uploaded', h ? size(up) : '—', label + ' · estimated'));
        }

        // ---- History ----------------------------------------------------------------------------
        function loadHistory() {
            const [from, to] = period();
            $('#chart').html('<p class="px-3 py-10 text-sm text-slate-500">' + SPINNER + ' Loading…</p>');
            $.getJSON('index.php', { api: 'history', r: router(), from, to, mac: deviceFilter }).done((r) => {
                history = r;
                if (!r.ok) return $('#chart').html('<p class="px-3 py-10 text-sm text-red-600">' + esc(r.error) + '</p>');
                drawChart(r, from, to);
                renderUsage(r);
                renderEvents(r);
                renderTiles();
                const $c = $('#collector');
                const lastAge = r.lastPoll ? Math.floor(Date.now() / 1000) - r.lastPoll.ts : null;
                if (!r.points.length || lastAge == null || lastAge > 120) {
                    $c.attr('class', 'mb-4 p-4 rounded-xl text-sm bg-amber-50 border border-amber-200 text-amber-900')
                        .html('History is recorded while this page is open. For round-the-clock history, run the collector once a minute with Windows Task Scheduler: <span class="font-mono text-xs">C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\drl-tools\\router-tracker\\collect.php</span>').removeClass('hidden');
                }
            }).fail(() => $('#chart').html('<p class="px-3 py-10 text-sm text-red-600">Could not load history.</p>'));
        }

        // Two series (download, upload) on one KB/s axis; crosshair tooltip on hover.
        function drawChart(r, from, to) {
            const W = $('#chart').width() || 900, H = 260, L = 64, R = 16, T = 12, B = 28;
            const pts = r.points;
            if (!pts.length) return $('#chart').html('<p class="px-3 py-10 text-sm text-slate-500">No samples in this period yet.</p>');
            const maxV = Math.max(1, ...pts.map((p) => Math.max(p[1], p[2])));
            const step = niceStep(maxV / 4), top = Math.ceil(maxV / step) * step;
            const x = (ts) => L + (ts - from) / (to - from) * (W - L - R), y = (v) => T + (1 - v / top) * (H - T - B);
            // Break the line where samples are missing (more than 3 buckets apart).
            const path = (i) => { let d = '', prev = null; pts.forEach((p) => { d += (prev === null || p[0] - prev > r.bucket * 3 ? 'M' : 'L') + x(p[0]).toFixed(1) + ',' + y(p[i]).toFixed(1); prev = p[0]; }); return d; };
            let grid = '';
            for (let v = 0; v <= top + 1e-9; v += step) grid += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" stroke="var(--grid)" stroke-width="1"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="var(--text-muted)">${speed(v)}</text>`;
            const ticks = 6;
            for (let k = 0; k <= ticks; k++) {
                const ts = from + (to - from) * k / ticks, d = new Date(ts * 1000);
                const txt = to - from > 2 * 86400 ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                grid += `<text x="${x(ts)}" y="${H - 8}" text-anchor="${k === 0 ? 'start' : k === ticks ? 'end' : 'middle'}" font-size="11" fill="var(--text-muted)">${txt}</text>`;
            }
            $('#chart').html(`<svg width="${W}" height="${H}" role="img" aria-label="Download and upload speed over time" style="display:block">
                ${grid}
                <path d="${path(1)}" fill="none" stroke="var(--series-1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                <path d="${path(2)}" fill="none" stroke="var(--series-2)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                <line id="xhair" y1="${T}" y2="${H - B}" stroke="var(--text-muted)" stroke-width="1" visibility="hidden"/>
                <circle id="dot1" r="4" fill="var(--series-1)" stroke="#fff" stroke-width="2" visibility="hidden"/>
                <circle id="dot2" r="4" fill="var(--series-2)" stroke="#fff" stroke-width="2" visibility="hidden"/>
                <rect x="${L}" y="${T}" width="${W - L - R}" height="${H - T - B}" fill="transparent" id="hit"/>
            </svg><div id="tip" class="hidden absolute pointer-events-none bg-white border border-slate-200 shadow-lg rounded-lg px-3 py-2 text-xs"></div>`);
            $('#hit').on('mousemove', function(e) {
                const mx = e.offsetX;
                let best = pts[0];
                pts.forEach((p) => { if (Math.abs(x(p[0]) - mx) < Math.abs(x(best[0]) - mx)) best = p; });
                const px = x(best[0]);
                $('#xhair').attr({ x1: px, x2: px, visibility: 'visible' });
                $('#dot1').attr({ cx: px, cy: y(best[1]), visibility: 'visible' });
                $('#dot2').attr({ cx: px, cy: y(best[2]), visibility: 'visible' });
                $('#tip').removeClass('hidden').css({ left: Math.min(px + 14, W - 170), top: 10 }).html(
                    `<div class="font-semibold text-slate-900 mb-1">${time(best[0])}</div>
                     <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full" style="background:var(--series-1)"></span><span class="text-slate-600">Download</span><span class="ml-auto tabular-nums text-slate-900">${speed(best[1])}</span></div>
                     <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full" style="background:var(--series-2)"></span><span class="text-slate-600">Upload</span><span class="ml-auto tabular-nums text-slate-900">${speed(best[2])}</span></div>`);
            }).on('mouseleave', () => { $('#xhair, #dot1, #dot2').attr('visibility', 'hidden'); $('#tip').addClass('hidden'); });
        }
        function niceStep(raw) { const p = Math.pow(10, Math.floor(Math.log10(raw || 1))); return [1, 2, 5, 10].map((m) => m * p).find((s) => s >= raw) || 10 * p; }

        function renderUsage(r) {
            const total = Math.max(1, ...r.devices.map((d) => d.downKB + d.upKB));
            $('#usage').html(`<table class="w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr>
                <th class="px-5 py-2 text-left">Device</th><th class="px-3 py-2 text-right">Downloaded</th><th class="px-3 py-2 text-right">Uploaded</th>
                <th class="px-3 py-2 text-right">Peak down / up</th><th class="px-3 py-2 text-right">Online</th><th class="px-5 py-2 text-right">Last seen</th></tr></thead>
                <tbody>${r.devices.map((d) => `<tr class="usage-row border-t border-slate-100 cursor-pointer hover:bg-slate-50 ${deviceFilter === d.mac ? 'bg-blue-50' : ''}" data-mac="${esc(d.mac)}">
                    <td class="px-5 py-2"><div class="font-semibold text-slate-900">${esc(devName(d))}</div><div class="text-xs text-slate-500 font-mono">${esc(d.ip || '')} · ${esc(d.mac)}</div>
                        <div class="mt-1 h-1 rounded-full bg-slate-100 overflow-hidden w-40"><div class="h-full rounded-full" style="width:${Math.round((d.downKB + d.upKB) / total * 100)}%;background:var(--series-1)"></div></div></td>
                    <td class="px-3 py-2 text-right tabular-nums">${size(d.downKB)}</td><td class="px-3 py-2 text-right tabular-nums">${size(d.upKB)}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs text-slate-600">${speed(d.peakDown)} / ${speed(d.peakUp)}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs text-slate-600">${dur(d.onlineSec)}</td>
                    <td class="px-5 py-2 text-right tabular-nums text-xs text-slate-600 whitespace-nowrap">${time(d.last)}</td></tr>`).join('')
                    || '<tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">No devices in this period</td></tr>'}</tbody></table>`);
        }

        function renderEvents(r) {
            const names = Object.fromEntries(r.devices.map((d) => [d.mac, devName(d)]));
            $('#events').html(r.events.map(([ts, mac, what]) => `<div class="flex items-center gap-3 px-5 py-2 border-t border-slate-100 first:border-t-0">
                <span class="w-2 h-2 rounded-full ${what === 'joined' ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                <span class="flex-1 min-w-0 truncate"><span class="font-semibold text-slate-900">${esc(names[mac] || mac)}</span> <span class="text-slate-500">${what}</span></span>
                <span class="text-xs tabular-nums text-slate-500 whitespace-nowrap">${time(ts)}</span></div>`).join('')
                || '<p class="px-5 py-6 text-center text-slate-400">No joins or leaves in this period</p>');
        }

        $('.range').on('click', function() {
            range = $(this).data('r');
            $('.range').removeClass('bg-white shadow text-slate-900').addClass('text-slate-500');
            $(this).addClass('bg-white shadow text-slate-900').removeClass('text-slate-500');
            loadHistory();
        });
        $(document).on('click', '.usage-row', function() {
            deviceFilter = deviceFilter === $(this).data('mac') ? '' : String($(this).data('mac'));
            const d = history && history.devices.find((x) => x.mac === deviceFilter);
            $('#chartFor').text(d ? devName(d) : 'all devices');
            $('#clearDevice').toggleClass('hidden', !deviceFilter);
            loadHistory();
        });
        $('#clearDevice').on('click', () => { deviceFilter = ''; $('#chartFor').text('all devices'); $('#clearDevice').addClass('hidden'); loadHistory(); });
        $('#router').on('change', () => { deviceFilter = ''; loadLive(); loadHistory(); });
        $(window).on('resize', () => history && history.ok && drawChart(history, ...period()));

        $(`.range[data-r="${range}"]`).trigger('click');
        loadLive();
        liveTimer = setInterval(loadLive, 5000);
        // Keep the chart current while the page stays open.
        setInterval(loadHistory, 60000);
    });
</script>
</body>

</html>
