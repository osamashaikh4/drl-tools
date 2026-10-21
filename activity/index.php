<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../cctv/isapi.php';
date_default_timezone_set('Asia/Karachi');

const ACT_DATA = __DIR__ . '/data';
const ACT_TASK = 'DRL Activity Worker';

function act_json(string $name, array $default): array
{
    $v = is_file(ACT_DATA . "/{$name}.json") ? json_decode((string) file_get_contents(ACT_DATA . "/{$name}.json"), true) : null;

    return is_array($v) ? $v : $default;
}

function act_settings(): array
{
    return act_json('settings', []) + ['enabled' => false, 'interval' => 60, 'threads' => 2, 'keepDays' => 90, 'cameras' => []];
}

if (isset($_GET['api'])) {
    $reply = function (array $data, int $code = 200) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data);
        exit();
    };
    if (!is_logged_in()) {
        $reply(['ok' => false, 'error' => 'Logged out'], 401);
    }
    session_write_close();
    $settings = act_settings();
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    switch ((string) $_GET['api']) {
        // Settings, worker heartbeat and the latest counts of every chosen camera.
        case 'status':
            $status = act_json('status', ['cams' => []]);
            $reply(['ok' => true, 'settings' => $settings, 'status' => $status, 'now' => time()]);

        // Last annotated picture of one camera (data/ is closed to the web, so it is served from here).
        case 'image':
            $cam = (string) ($_GET['cam'] ?? '');
            $file = ACT_DATA . "/latest/{$cam}.jpg";
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}-\d{1,2}$/', $cam) || !is_file($file)) {
                http_response_code(404);
                exit();
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            readfile($file);
            exit();

        // Every channel of every CCTV device, for choosing the cameras to watch.
        case 'channels':
            set_time_limit(120);
            $out = [];
            foreach (array_keys($CCTV['devices'] ?? []) as $i) {
                $d = cctv_device($i);
                $info = isapi_xml($d, '/ISAPI/System/deviceInfo', $err);
                if (!$info) {
                    $out[] = ['ip' => $d['ip'], 'name' => $d['name'], 'error' => $err, 'channels' => []];
                    continue;
                }
                $chs = [];
                if (cctv_is_recorder($info) && ($x = isapi_xml($d, '/ISAPI/ContentMgmt/InputProxy/channels'))) {
                    $online = [];
                    if ($st = isapi_xml($d, '/ISAPI/ContentMgmt/InputProxy/channels/status')) {
                        foreach ($st->InputProxyChannelStatus as $c) {
                            $online[(int) $c->id] = (string) $c->online === 'true';
                        }
                    }
                    foreach ($x->InputProxyChannel as $c) {
                        $chs[] = ['ch' => (int) $c->id, 'name' => trim((string) $c->name), 'online' => $online[(int) $c->id] ?? null];
                    }
                } else {
                    $chs[] = ['ch' => 1, 'name' => $d['name'], 'online' => true];
                }
                $out[] = ['ip' => $d['ip'], 'name' => $d['name'], 'recorder' => cctv_is_recorder($info), 'channels' => $chs];
            }
            $reply(['ok' => true, 'devices' => $out]);

        // Save settings (POST): on / off, how often, threads, which cameras.
        case 'settings':
            if (!$isPost) {
                $reply(['ok' => false, 'error' => 'Wrong request method'], 405);
            }
            $ips = array_column($CCTV['devices'] ?? [], 'ip');
            if (isset($_POST['enabled'])) {
                $settings['enabled'] = $_POST['enabled'] === '1';
            }
            if (isset($_POST['interval'])) {
                if (!in_array((int) $_POST['interval'], [30, 60, 120, 300, 600], true)) {
                    $reply(['ok' => false, 'error' => 'Unknown interval'], 400);
                }
                $settings['interval'] = (int) $_POST['interval'];
            }
            if (isset($_POST['threads'])) {
                $settings['threads'] = max(1, min(4, (int) $_POST['threads']));
            }
            if (isset($_POST['cameras'])) {
                $cams = [];
                foreach ((array) json_decode((string) $_POST['cameras'], true) as $c) {
                    $ch = (int) ($c['ch'] ?? 0);
                    if (!in_array($c['ip'] ?? null, $ips, true) || $ch < 1 || $ch > 64) {
                        $reply(['ok' => false, 'error' => 'Unknown camera'], 400);
                    }
                    $cams["{$c['ip']}-{$ch}"] = ['ip' => $c['ip'], 'ch' => $ch, 'name' => mb_substr(trim((string) ($c['name'] ?? '')), 0, 60) ?: "{$c['ip']} ch {$ch}"];
                }
                $settings['cameras'] = array_values($cams);
            }
            if (!is_dir(ACT_DATA)) {
                mkdir(ACT_DATA, 0775, true);
            }
            file_put_contents(ACT_DATA . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
            $reply(['ok' => true, 'settings' => $settings]);

        // Counts between two Unix times: people over time (all or one camera) and a summary per camera.
        case 'history':
            $from = (int) ($_GET['from'] ?? time() - 86400);
            $to   = (int) ($_GET['to'] ?? time());
            $cam  = (string) ($_GET['cam'] ?? '');
            if ($to <= $from || $to - $from > 92 * 86400) {
                $reply(['ok' => false, 'error' => 'Pick a period of up to 92 days'], 400);
            }
            if (!is_file(ACT_DATA . '/activity.sqlite')) {
                $reply(['ok' => true, 'points' => [], 'cams' => [], 'bucket' => 60]);
            }
            $db = new PDO('sqlite:' . ACT_DATA . '/activity.sqlite');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA busy_timeout = 5000');

            // Per cycle the cameras are added up, then cycles are averaged into at most ~300 buckets.
            $bucket = max(60, (int) ceil(($to - $from) / 300 / 60) * 60);
            $where = 'ts BETWEEN :f AND :t' . ($cam !== '' ? ' AND cam = :c' : '');
            $args = [':f' => $from, ':t' => $to] + ($cam !== '' ? [':c' => $cam] : []);
            $st = $db->prepare("SELECT (ts / {$bucket}) * {$bucket} b, AVG(p), AVG(st), AVG(si), AVG(w), AVG(ph), MAX(p) FROM (
                SELECT ts, SUM(people) p, SUM(standing) st, SUM(sitting) si, SUM(walking) w, SUM(phone) ph FROM samples WHERE {$where} GROUP BY ts
            ) GROUP BY b ORDER BY b");
            $st->execute($args);
            $points = array_map(fn($r) => array_map(fn($v) => round((float) $v, 2), $r), $st->fetchAll(PDO::FETCH_NUM));

            // Per camera: time with people in view, peak, and person-hours per activity.
            $st = $db->prepare('SELECT cam, COUNT(*) n, SUM(people > 0) busy, MAX(people) peak, AVG(people) avg,
                SUM(standing * secs) st, SUM(sitting * secs) si, SUM(walking * secs) w, SUM(phone * secs) ph, MAX(ts) last
                FROM samples WHERE ts BETWEEN ? AND ? GROUP BY cam');
            $st->execute([$from, $to]);
            $names = array_column(array_map(fn($c) => ['k' => "{$c['ip']}-{$c['ch']}", 'n' => $c['name']], $settings['cameras']), 'n', 'k');
            $cams = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cams[] = [
                    'cam'     => $r['cam'],
                    'name'    => $names[$r['cam']] ?? $r['cam'],
                    'samples' => (int) $r['n'],
                    'busyPct' => $r['n'] ? round($r['busy'] / $r['n'] * 100) : 0,
                    'peak'    => (int) $r['peak'],
                    'avg'     => round((float) $r['avg'], 2),
                    'standing' => (int) $r['st'],
                    'sitting' => (int) $r['si'],
                    'walking' => (int) $r['w'],
                    'phone'   => (int) $r['ph'],
                    'last'    => (int) $r['last'],
                ];
            }
            usort($cams, fn($a, $b) => $b['avg'] <=> $a['avg']);
            $reply(['ok' => true, 'bucket' => $bucket, 'points' => $points, 'cams' => $cams]);
    }
    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

require_login();
$pageTitle = 'Activity';
include __DIR__ . '/../header.php';
$modelsOk = is_file(__DIR__ . '/models/yolo11n.onnx') && is_file(__DIR__ . '/models/yolo11n-pose.onnx');
?>
<style>
    .viz-root { --series-1: #2a78d6; --series-2: #1baf7a; --series-3: #eb6834; --series-4: #9b51c9; --grid: #e7e5e4; --text-muted: #8a8985; }
    .switch { width: 2.75rem; height: 1.5rem; border-radius: 9999px; background: #cbd5e1; position: relative; transition: background .15s; }
    .switch::after { content: ''; position: absolute; top: .1875rem; left: .1875rem; width: 1.125rem; height: 1.125rem; border-radius: 9999px; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgb(0 0 0 / .2); }
    .switch[aria-checked="true"] { background: #059669; }
    .switch[aria-checked="true"]::after { transform: translateX(1.25rem); }
</style>

<div class="viz-root max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Activity</h1>
            <p class="text-sm text-slate-500">How many people each camera sees and whether they are standing, sitting, walking or on a phone. Counts only: no faces or identities, and only the latest picture per camera is kept.</p>
        </div>
        <div class="flex items-center gap-3 bg-white rounded-xl border border-slate-200 px-4 py-2">
            <span class="text-sm font-semibold text-slate-700" id="onLabel">Off</span>
            <button type="button" id="toggle" class="switch" role="switch" aria-checked="false" aria-label="Activity detection on or off"></button>
        </div>
    </div>

    <?php if (!$modelsOk): ?>
        <div class="mb-4 p-4 rounded-xl text-sm bg-red-50 border border-red-200 text-red-800">The models are missing: put <span class="font-mono">yolo11n.onnx</span> and <span class="font-mono">yolo11n-pose.onnx</span> in <span class="font-mono">activity/models/</span> (see the top of <span class="font-mono">activity/worker.py</span>).</div>
    <?php endif; ?>
    <div id="worker" class="hidden mb-4 p-4 rounded-xl text-sm"></div>
    <div id="tiles" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6"></div>

    <!-- Now -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">Now</h2>
            <p id="nowStatus" class="text-xs text-slate-500 tabular-nums"></p>
        </div>
        <div id="now" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 p-5"></div>
    </section>

    <!-- History -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">People over time <span id="chartFor" class="font-normal text-sm text-slate-500">all cameras</span></h2>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                    <?php foreach (['1h' => 'Hour', 'today' => 'Today', '24h' => '24 h', '7d' => '7 days', '30d' => '30 days'] as $k => $l): ?>
                        <button type="button" class="range px-3 py-1 rounded-md text-xs font-semibold" data-r="<?= $k ?>"><?= $l ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="clearCam" class="hidden text-xs font-semibold text-slate-600 underline underline-offset-2">Show all cameras</button>
            </div>
        </div>
        <div class="px-5 pt-3 flex flex-wrap items-center gap-4 text-xs text-slate-600" aria-label="Legend">
            <?php foreach ([1 => 'People', 2 => 'Sitting', 3 => 'Walking', 4 => 'On phone'] as $i => $l): ?>
                <span class="inline-flex items-center gap-1.5"><span class="w-4 h-0.5 rounded" style="background:var(--series-<?= $i ?>)"></span><?= $l ?></span>
            <?php endforeach; ?>
            <span class="text-slate-400">Average per period, all chosen cameras added up</span>
        </div>
        <div id="chart" class="relative px-2 pb-4"></div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-6">
        <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">By camera</h2>
            <p class="text-xs text-slate-500">Hours are person-hours (2 people for 1 hour = 2 h). Click a camera to chart only it.</p>
        </div>
        <div id="byCam" class="overflow-x-auto"></div>
    </section>

    <!-- Settings -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">Settings</h2>
            <p id="cost" class="text-xs text-slate-500"></p>
        </div>
        <div class="p-5 space-y-5">
            <div class="flex flex-wrap items-end gap-4">
                <label class="text-xs font-semibold text-slate-600">Check every
                    <select id="interval" class="mt-1 block rounded-lg border border-slate-300 text-sm p-2 bg-white">
                        <?php foreach ([30 => '30 seconds', 60 => '1 minute', 120 => '2 minutes', 300 => '5 minutes', 600 => '10 minutes'] as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
                    </select></label>
                <label class="text-xs font-semibold text-slate-600">CPU threads
                    <select id="threads" class="mt-1 block rounded-lg border border-slate-300 text-sm p-2 bg-white">
                        <?php foreach ([1, 2, 3, 4] as $v): ?><option value="<?= $v ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></label>
                <button type="button" id="saveTiming" class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold hover:bg-slate-50">Save</button>
            </div>
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <h3 class="text-sm font-bold text-slate-800">Cameras to watch <span id="camCount" class="font-normal text-slate-500"></span></h3>
                    <div class="flex gap-2">
                        <button type="button" id="loadChannels" class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-xs font-semibold hover:bg-slate-50">Choose cameras…</button>
                        <button type="button" id="saveCams" class="hidden px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-xs font-semibold text-white">Save cameras</button>
                    </div>
                </div>
                <div id="channels" class="text-sm"></div>
            </div>
            <div class="text-xs text-slate-500 border-t border-slate-100 pt-4">
                The work is done by <span class="font-mono">activity/worker.py</span>, started once a minute by the Windows task <span class="font-mono"><?= ACT_TASK ?></span>.
                Each camera takes about a second of CPU per check on this PC, so the number of cameras × the check interval decides the load.
            </div>
        </div>
    </section>
</div>

<script>
    $(function() {
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const toast = (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' });
        const hours = (s) => s >= 3600 ? (s / 3600).toFixed(1) + ' h' : Math.round(s / 60) + ' min';
        const ago = (s) => s < 90 ? s + ' s ago' : s < 5400 ? Math.round(s / 60) + ' min ago' : Math.round(s / 3600) + ' h ago';
        let st = null, history = null, range = 'today', camFilter = '';

        function period() {
            const now = Math.floor(Date.now() / 1000);
            if (range === 'today') { const d = new Date(); d.setHours(0, 0, 0, 0); return [Math.floor(d / 1000), now]; }
            return [now - { '1h': 3600, '24h': 86400, '7d': 7 * 86400, '30d': 30 * 86400 }[range], now];
        }
        const camName = (key) => ((st && st.settings.cameras.find((c) => c.ip + '-' + c.ch === key)) || {}).name || key;

        // ---- Status: switch, worker health, tiles, latest pictures ------------------------------
        function loadStatus() {
            $.getJSON('index.php', { api: 'status' }).done((r) => {
                if (!r.ok) return;
                st = r;
                const s = r.settings, w = r.status || {}, cams = w.cams || {};
                $('#toggle').attr('aria-checked', s.enabled ? 'true' : 'false');
                $('#onLabel').text(s.enabled ? 'On' : 'Off');
                $('#interval').val(s.interval);
                $('#threads').val(s.threads);
                $('#camCount').text('(' + s.cameras.length + ' chosen)');
                const perCycle = w.cycleSeconds && w.cycleCams ? w.cycleSeconds : s.cameras.length * 1.2;
                $('#cost').text(s.cameras.length ? `About ${Math.round(perCycle)} s of work every ${s.interval >= 60 ? s.interval / 60 + ' min' : s.interval + ' s'} — CPU busy roughly ${Math.min(100, Math.round(perCycle / s.interval * 100))}% of the time while on` : '');

                // Worker health: a heartbeat at least every minute or two when the task is running.
                const age = w.heartbeat ? r.now - w.heartbeat : null;
                const $w = $('#worker');
                if (w.error) {
                    $w.attr('class', 'mb-4 p-4 rounded-xl text-sm bg-red-50 border border-red-200 text-red-800').html('The worker stopped with an error: ' + esc(w.error));
                } else if (s.enabled && (age == null || age > 180)) {
                    $w.attr('class', 'mb-4 p-4 rounded-xl text-sm bg-amber-50 border border-amber-200 text-amber-900')
                        .html('Switched on, but the worker has not run ' + (age == null ? 'yet' : 'for ' + ago(age).replace(' ago', '')) + '. It is started once a minute by the Windows task <span class="font-mono"><?= ACT_TASK ?></span>.');
                } else if (s.enabled && !s.cameras.length) {
                    $w.attr('class', 'mb-4 p-4 rounded-xl text-sm bg-amber-50 border border-amber-200 text-amber-900').text('Switched on, but no cameras are chosen yet — see Settings below.');
                } else {
                    $w.attr('class', 'hidden');
                }

                const list = s.cameras.map((c) => ({ key: c.ip + '-' + c.ch, name: c.name, ...(cams[c.ip + '-' + c.ch] || {}) }));
                const okCams = list.filter((c) => c.ok);
                const sum = (k) => okCams.reduce((t, c) => t + (c[k] || 0), 0);
                const tile = (label, v, sub) => `<div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">${label}</div><div class="text-2xl font-bold tabular-nums text-slate-800">${v}</div>${sub ? '<div class="text-xs text-slate-500 mt-0.5">' + sub + '</div>' : ''}</div>`;
                $('#tiles').html(
                    tile('People now', sum('people'), okCams.length + ' of ' + list.length + ' cameras') +
                    tile('Standing', sum('standing')) + tile('Sitting', sum('sitting')) +
                    tile('Walking', sum('walking')) + tile('On phone', sum('phone')) +
                    tile('Last check', w.lastCycle ? ago(r.now - w.lastCycle) : '—', w.cycleSeconds ? 'took ' + w.cycleSeconds + ' s' : ''));

                $('#nowStatus').text(s.enabled ? 'Refreshes every 15 s' : 'Switched off — showing the last check');
                const t = Date.now();
                $('#now').html(list.map((c) => `<figure class="rounded-xl border ${c.ok === false ? 'border-red-200' : 'border-slate-200'} overflow-hidden">
                    ${c.ok ? `<img src="index.php?api=image&cam=${encodeURIComponent(c.key)}&t=${c.ts || t}" alt="Last picture from ${esc(c.name)}" class="w-full aspect-video object-cover bg-slate-100" loading="lazy">`
                           : `<div class="w-full aspect-video bg-slate-50 flex items-center justify-center px-4 text-center text-xs ${c.ok === false ? 'text-red-600' : 'text-slate-400'}">${c.ok === false ? esc(c.error) : 'Not checked yet'}</div>`}
                    <figcaption class="px-3 py-2 text-sm">
                        <div class="flex items-baseline justify-between gap-2"><span class="font-semibold text-slate-900 truncate">${esc(c.name)}</span>
                        <span class="text-xs text-slate-400 tabular-nums">${c.ts ? ago(r.now - c.ts) : ''}</span></div>
                        ${c.ok ? `<div class="text-xs text-slate-600 tabular-nums">${c.people ? `<span class="font-semibold text-slate-900">${c.people} ${c.people === 1 ? 'person' : 'people'}</span> · ${c.standing} standing · ${c.sitting} sitting · ${c.walking} walking${c.phone ? ' · ' + c.phone + ' on phone' : ''}` : 'Nobody in view'}</div>` : ''}
                    </figcaption></figure>`).join('') || '<p class="text-sm text-slate-500">No cameras chosen yet. Use <b>Choose cameras…</b> in Settings below.</p>');
            });
        }

        const save = (data) => $.post('index.php?api=settings', data, null, 'json');
        $('#toggle').on('click', function() {
            const on = $(this).attr('aria-checked') !== 'true';
            save({ enabled: on ? 1 : 0 }).done((r) => { toast(r.ok, r.ok ? (on ? 'Switched on — the next check starts within a minute' : 'Switched off') : r.error); loadStatus(); }).fail(() => toast(false, 'Request failed'));
        });
        $('#saveTiming').on('click', () => save({ interval: $('#interval').val(), threads: $('#threads').val() })
            .done((r) => { toast(r.ok, r.ok ? 'Saved' : r.error); loadStatus(); }).fail(() => toast(false, 'Request failed')));

        // ---- Choosing cameras ------------------------------------------------------------------
        $('#loadChannels').on('click', function() {
            $('#channels').html('<p class="text-slate-500">' + SPINNER + ' Asking every camera and NVR for its channels…</p>');
            $.getJSON('index.php', { api: 'channels' }).done((r) => {
                const chosen = new Set(st.settings.cameras.map((c) => c.ip + '-' + c.ch));
                $('#channels').html(r.devices.map((d) => `<div class="mb-3">
                    <div class="flex items-center gap-2 mb-1"><span class="font-semibold text-slate-800">${esc(d.name)}</span><span class="font-mono text-xs text-slate-400">${esc(d.ip)}</span>
                    ${d.channels.length > 1 ? `<button type="button" class="pick-all text-xs text-slate-600 underline underline-offset-2">all online</button>` : ''}</div>
                    ${d.error ? `<p class="text-xs text-red-600">${esc(d.error)}</p>` : `<div class="flex flex-wrap gap-x-4 gap-y-1">${d.channels.map((c) => `<label class="inline-flex items-center gap-1.5 text-sm ${c.online === false ? 'text-slate-400' : 'text-slate-700'}">
                        <input type="checkbox" class="cam-pick rounded border-slate-300" data-ip="${esc(d.ip)}" data-ch="${c.ch}" data-name="${esc(d.channels.length > 1 ? c.name + ' (' + d.ip.split('.').pop() + '/' + c.ch + ')' : d.name)}" data-online="${c.online !== false ? 1 : 0}" ${chosen.has(d.ip + '-' + c.ch) ? 'checked' : ''}>
                        ${d.channels.length > 1 ? c.ch + ' · ' : ''}${esc(c.name)}${c.online === false ? ' (offline)' : ''}</label>`).join('')}</div>`}
                </div>`).join(''));
                $('#saveCams').removeClass('hidden');
            }).fail(() => $('#channels').html('<p class="text-red-600">Could not load the channels.</p>'));
        });
        $(document).on('click', '.pick-all', function() {
            $(this).closest('div').next().find('.cam-pick').each(function() { this.checked = $(this).data('online') === 1; });
        });
        $('#saveCams').on('click', function() {
            const cameras = $('.cam-pick:checked').map(function() { return { ip: $(this).data('ip'), ch: $(this).data('ch'), name: $(this).data('name') }; }).get();
            save({ cameras: JSON.stringify(cameras) }).done((r) => {
                toast(r.ok, r.ok ? cameras.length + ' cameras saved' : r.error);
                if (r.ok) { $('#channels').empty(); $('#saveCams').addClass('hidden'); loadStatus(); }
            }).fail(() => toast(false, 'Request failed'));
        });

        // ---- History ---------------------------------------------------------------------------
        function loadHistory() {
            const [from, to] = period();
            $('.range').removeClass('bg-white shadow-sm text-slate-900').addClass('text-slate-600').filter(`[data-r="${range}"]`).addClass('bg-white shadow-sm text-slate-900').removeClass('text-slate-600');
            $('#chartFor').text(camFilter ? camName(camFilter) : 'all cameras');
            $('#clearCam').toggleClass('hidden', !camFilter);
            $('#chart').html('<p class="px-3 py-10 text-sm text-slate-500">' + SPINNER + ' Loading…</p>');
            $.getJSON('index.php', { api: 'history', from, to, cam: camFilter }).done((r) => {
                history = r;
                if (!r.ok) return $('#chart').html('<p class="px-3 py-10 text-sm text-red-600">' + esc(r.error) + '</p>');
                drawChart(r, from, to);
                $('#byCam').html(r.cams.length ? `<table class="w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr>
                    <th class="px-5 py-2 text-left">Camera</th><th class="px-3 py-2 text-right">People in view</th><th class="px-3 py-2 text-right">Average</th><th class="px-3 py-2 text-right">Peak</th>
                    <th class="px-3 py-2 text-right">Standing</th><th class="px-3 py-2 text-right">Sitting</th><th class="px-3 py-2 text-right">Walking</th><th class="px-5 py-2 text-right">On phone</th></tr></thead>
                    <tbody>${r.cams.map((c) => `<tr class="cam-row border-t border-slate-100 cursor-pointer hover:bg-slate-50 ${c.cam === camFilter ? 'bg-blue-50' : ''}" data-cam="${esc(c.cam)}">
                        <td class="px-5 py-2 font-semibold text-slate-900">${esc(camName(c.cam))}</td>
                        <td class="px-3 py-2 text-right tabular-nums">${c.busyPct}% of the time</td><td class="px-3 py-2 text-right tabular-nums">${c.avg}</td><td class="px-3 py-2 text-right tabular-nums">${c.peak}</td>
                        <td class="px-3 py-2 text-right tabular-nums">${hours(c.standing)}</td><td class="px-3 py-2 text-right tabular-nums">${hours(c.sitting)}</td>
                        <td class="px-3 py-2 text-right tabular-nums">${hours(c.walking)}</td><td class="px-5 py-2 text-right tabular-nums">${hours(c.phone)}</td></tr>`).join('')}</tbody></table>`
                    : '<p class="px-5 py-6 text-sm text-slate-500">No checks in this period yet.</p>');
            }).fail(() => $('#chart').html('<p class="px-3 py-10 text-sm text-red-600">Could not load history.</p>'));
        }
        $('.range').on('click', function() { range = $(this).data('r'); loadHistory(); });
        $(document).on('click', '.cam-row', function() { camFilter = $(this).data('cam') === camFilter ? '' : $(this).data('cam'); loadHistory(); });
        $('#clearCam').on('click', () => { camFilter = ''; loadHistory(); });

        function niceStep(v) {
            const p = Math.pow(10, Math.floor(Math.log10(Math.max(v, 0.25)))), n = v / p;
            return Math.max(1, (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p);
        }

        // Four series on one people axis: people, sitting, walking, on phone. Crosshair tooltip on hover.
        function drawChart(r, from, to) {
            const W = $('#chart').width() || 900, H = 260, L = 44, R = 16, T = 12, B = 28;
            const pts = r.points; // [ts, people, standing, sitting, walking, phone, peak]
            if (!pts.length) return $('#chart').html('<p class="px-3 py-10 text-sm text-slate-500">No checks in this period yet.</p>');
            const series = [[1, 'People'], [3, 'Sitting'], [4, 'Walking'], [5, 'On phone']];
            const maxV = Math.max(1, ...pts.map((p) => p[1]));
            const step = niceStep(maxV / 4), top = Math.ceil(maxV / step) * step;
            const x = (ts) => L + (ts - from) / (to - from) * (W - L - R), y = (v) => T + (1 - v / top) * (H - T - B);
            const path = (i) => { let d = '', prev = null; pts.forEach((p) => { d += (prev === null || p[0] - prev > r.bucket * 3 ? 'M' : 'L') + x(p[0]).toFixed(1) + ',' + y(p[i]).toFixed(1); prev = p[0]; }); return d; };
            let grid = '';
            for (let v = 0; v <= top + 1e-9; v += step) grid += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" stroke="var(--grid)" stroke-width="1"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="var(--text-muted)">${v}</text>`;
            const ticks = 6;
            for (let k = 0; k <= ticks; k++) {
                const ts = from + (to - from) * k / ticks, d = new Date(ts * 1000);
                const txt = to - from > 2 * 86400 ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                grid += `<text x="${x(ts)}" y="${H - 8}" text-anchor="${k === 0 ? 'start' : k === ticks ? 'end' : 'middle'}" font-size="11" fill="var(--text-muted)">${txt}</text>`;
            }
            $('#chart').html(`<svg width="${W}" height="${H}" role="img" aria-label="People seen over time" style="display:block">${grid}
                ${series.map(([i], n) => `<path d="${path(i)}" fill="none" stroke="var(--series-${n + 1})" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`).join('')}
                <line id="xhair" y1="${T}" y2="${H - B}" stroke="var(--text-muted)" stroke-width="1" visibility="hidden"/>
                <rect x="${L}" y="${T}" width="${W - L - R}" height="${H - T - B}" fill="transparent" id="hit"/>
            </svg><div id="tip" class="hidden absolute pointer-events-none bg-white border border-slate-200 shadow-lg rounded-lg px-3 py-2 text-xs"></div>`);
            $('#hit').on('mousemove', function(e) {
                let best = pts[0];
                pts.forEach((p) => { if (Math.abs(x(p[0]) - e.offsetX) < Math.abs(x(best[0]) - e.offsetX)) best = p; });
                const px = x(best[0]);
                $('#xhair').attr({ x1: px, x2: px, visibility: 'visible' });
                const d = new Date(best[0] * 1000).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                $('#tip').removeClass('hidden').css({ left: Math.min(px + 12, W - 170), top: 8 }).html(`<div class="font-semibold text-slate-900 mb-1">${d}</div>` +
                    series.map(([i, l], n) => `<div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full" style="background:var(--series-${n + 1})"></span>${l}<span class="ml-auto pl-3 tabular-nums font-semibold">${best[i]}</span></div>`).join('') +
                    `<div class="text-slate-500 mt-1">Peak ${best[6]}</div>`);
            }).on('mouseleave', () => { $('#xhair').attr('visibility', 'hidden'); $('#tip').addClass('hidden'); });
        }

        $(window).on('resize', () => history && history.ok && drawChart(history, ...period()));
        loadStatus();
        loadHistory();
        setInterval(() => { if (!document.hidden) loadStatus(); }, 15000);
        setInterval(() => { if (!document.hidden) loadHistory(); }, 120000);
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>
