<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/isapi.php';

// ---------------------------------------------------------------------------------------------
// Snapshot: ?snap=<device>&ch=<channel> streams one JPEG (the camera login stays on the server).
// ---------------------------------------------------------------------------------------------
if (isset($_GET['snap'])) {
    if (!is_logged_in()) {
        http_response_code(401);
        exit();
    }
    session_write_close();
    $d  = cctv_device((int) $_GET['snap']);
    $ch = max(1, min(256, (int) ($_GET['ch'] ?? 1)));
    if (!$d) {
        http_response_code(404);
        exit();
    }
    // Sub-stream (x02) is smaller and quicker; fall back to the main stream (x01).
    // &main=1 asks for the full-resolution main stream first (the viewer uses it when zoomed in).
    foreach (!empty($_GET['main']) ? ['01', '02'] : ['02', '01'] as $stream) {
        $r = isapi($d, 'GET', "/ISAPI/Streaming/channels/{$ch}{$stream}/picture", null, 10);
        if (!$r['error'] && str_starts_with($r['body'], "\xFF\xD8")) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            echo $r['body'];
            exit();
        }
    }
    http_response_code(502);
    header('Content-Type: text/plain');
    echo $r['error'] ?? 'No picture';
    exit();
}

// ---------------------------------------------------------------------------------------------
// JSON API: ?api=info|synctime|reboot&i=<device index>.
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
    session_write_close();
    set_time_limit(120);

    $i = (int) ($_REQUEST['i'] ?? -1);
    $d = cctv_device($i);
    if (!$d) {
        $reply(['ok' => false, 'error' => 'Unknown device'], 400);
    }
    $api = (string) $_GET['api'];
    if (in_array($api, ['synctime', 'reboot', 'set'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $reply(['ok' => false, 'error' => 'Wrong request method'], 405);
    }

    switch ($api) {
        case 'info':
            $err  = null;
            $info = isapi_xml($d, '/ISAPI/System/deviceInfo', $err);
            if (!$info) {
                $reply(['ok' => false, 'error' => $err]);
            }
            $time   = isapi_xml($d, '/ISAPI/System/time');
            $status = isapi_xml($d, '/ISAPI/System/status');
            $local  = xv($time, 'localTime');
            $devTs  = $local ? strtotime($local) : null;
            $out = [
                'ok'       => true,
                'name'     => xv($info, 'deviceName'),
                'model'    => xv($info, 'model'),
                'type'     => xv($info, 'deviceType'),
                'serial'   => xv($info, 'serialNumber'),
                'firmware' => trim(xv($info, 'firmwareVersion') . ' ' . xv($info, 'firmwareReleasedDate')),
                'mac'      => xv($info, 'macAddress'),
                'recorder' => cctv_is_recorder($info),
                'time'     => cctv_time($local),
                'timeMode' => xv($time, 'timeMode'),
                'timeZone' => xv($time, 'timeZone'),
                'serverTime' => date('Y-m-d H:i:s'),
                'driftSec' => $devTs ? $devTs - time() : null,
                'uptimeSec' => ($u = xv($status, 'deviceUpTime')) !== null ? (int) $u : null,
                'cpu'      => $status && isset($status->CPUList->CPU->cpuUtilization) ? (int) $status->CPUList->CPU->cpuUtilization : null,
                'memory'   => $status && isset($status->MemoryList->Memory) ? [(float) $status->MemoryList->Memory->memoryUsage, (float) $status->MemoryList->Memory->memoryAvailable] : null,
                'channels' => [],
                'disks'    => [],
            ];

            if (!$out['recorder']) {
                $out['channels'][] = ['id' => 1, 'name' => $out['name'], 'online' => true, 'ip' => $d['ip'], 'recording' => null];
                $reply($out);
            }

            // Recorder: its camera channels, whether each is online and recording, and its disks.
            $names = [];
            if ($chs = isapi_xml($d, '/ISAPI/ContentMgmt/InputProxy/channels')) {
                foreach ($chs->InputProxyChannel as $c) {
                    $names[(int) $c->id] = [
                        'name' => trim((string) $c->name),
                        'ip'   => trim((string) ($c->sourceInputPortDescriptor->ipAddress ?? '')),
                    ];
                }
            }
            $online = [];
            if ($st = isapi_xml($d, '/ISAPI/ContentMgmt/InputProxy/channels/status')) {
                foreach ($st->InputProxyChannelStatus as $c) {
                    $online[(int) $c->id] = [
                        'online' => strtolower(trim((string) $c->online)) === 'true',
                        'detail' => trim((string) ($c->chanDetectResult ?? '')),
                    ];
                }
            }
            // Working status (where the firmware has it) says which channels are recording.
            $recording = [];
            if ($ws = isapi_xml($d, '/ISAPI/System/workingstatus')) {
                foreach ($ws->ChanStatus ?? [] as $c) {
                    if (isset($c->chanNo)) {
                        $rec = strtolower(trim((string) ($c->record ?? '')));
                        $recording[(int) $c->chanNo] = $rec === '' ? null : in_array($rec, ['true', 'yes', '1'], true);
                    }
                }
            }
            foreach (array_unique(array_merge(array_keys($names), array_keys($online))) as $id) {
                $out['channels'][] = [
                    'id'        => $id,
                    'name'      => $names[$id]['name'] ?? ('Channel ' . $id),
                    'ip'        => $names[$id]['ip'] ?? null,
                    'online'    => $online[$id]['online'] ?? null,
                    'detail'    => $online[$id]['detail'] ?? null,
                    'recording' => $recording[$id] ?? null,
                ];
            }
            usort($out['channels'], fn($a, $b) => $a['id'] <=> $b['id']);

            if ($st = isapi_xml($d, '/ISAPI/ContentMgmt/Storage')) {
                foreach ($st->hddList->hdd ?? [] as $h) {
                    $out['disks'][] = [
                        'id'       => (int) $h->id,
                        'name'     => trim((string) ($h->hddName ?? '')),
                        'status'   => trim((string) ($h->status ?? '')),
                        'capacity' => (float) ($h->capacity ?? 0) * 1048576, // MB -> bytes
                        'free'     => (float) ($h->freeSpace ?? 0) * 1048576,
                        'property' => trim((string) ($h->property ?? '')),
                    ];
                }
            }
            $reply($out);

        // Set the clock to this server's time in Pakistan time (UTC+5). Several devices were still on
        // the factory time zone (UTC+8), which puts the wrong time on the video overlay.
        case 'synctime':
            $local = date('Y-m-d\TH:i:s') . '+05:00';
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Time><timeMode>manual</timeMode><localTime>' . $local . '</localTime><timeZone>CST-5:00:00</timeZone></Time>';
            $r = isapi($d, 'PUT', '/ISAPI/System/time', $xml);
            $reply(['ok' => !$r['error'], 'set' => date('Y-m-d H:i:s') . ' (UTC+5)', 'error' => $r['error']]);

        // Picture settings of a camera reached directly, plus its stream settings (read only).
        case 'controls':
            $settings = cctv_settings($d);
            if (!array_filter($settings, fn($v) => $v !== null)) {
                $reply(['ok' => false, 'error' => 'No camera settings on this device']);
            }
            $reply(['ok' => true, 'settings' => $settings, 'streams' => cctv_streams($d)]);

        // Change one picture setting.
        case 'set':
            $err = cctv_set($d, (string) ($_POST['key'] ?? ''), trim((string) ($_POST['value'] ?? '')));
            $reply(['ok' => $err === null, 'error' => $err]);

        // Recorded clips of an NVR channel on one day.
        case 'recordings':
            $ch   = max(1, min(256, (int) ($_GET['ch'] ?? 1)));
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
            try {
                $reply(['ok' => true, 'date' => $date, 'clips' => cctv_recordings($d, $ch, $date)]);
            } catch (Throwable $e) {
                $reply(['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'reboot':
            if (($_POST['confirm'] ?? '') !== $d['ip']) {
                $reply(['ok' => false, 'error' => 'Confirmation did not match']);
            }
            $r = isapi($d, 'PUT', '/ISAPI/System/reboot');
            $reply(['ok' => !$r['error'], 'error' => $r['error']]);
    }
    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

require_login();
$pageTitle = 'CCTV';
include __DIR__ . '/../header.php';
$devices = array_map(fn($d) => ['name' => $d['name'] ?? $d['ip'], 'ip' => $d['ip']], $CCTV['devices'] ?? []);
?>

<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">CCTV</h1>
            <p class="text-sm text-slate-500">Hikvision cameras and NVRs: live view with zoom, picture settings, recordings, channel and disk health, clock and reboot. Click a picture or channel to open it.</p>
        </div>
        <div class="flex gap-1 rounded-xl bg-slate-100 p-1">
            <button type="button" class="view-tab px-4 py-2 rounded-lg text-sm font-semibold" data-v="status">Status</button>
            <button type="button" class="view-tab px-4 py-2 rounded-lg text-sm font-semibold" data-v="wall">Snapshot wall</button>
        </div>
    </div>

    <?php if (!$devices): ?>
        <div class="p-5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">
            No devices configured. Copy <span class="font-mono">cctv/config.example.php</span> to <span class="font-mono">cctv/config.php</span> and fill in the login and the device list.
        </div>
    <?php else: ?>
        <div id="summary" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6"></div>

        <!-- Status: one card per device -->
        <div id="statusView" class="grid grid-cols-1 lg:grid-cols-2 gap-5"></div>

        <!-- Snapshot wall -->
        <div id="wallView" class="hidden">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <select id="wallDevice" class="rounded-lg border border-slate-300 text-sm p-2 bg-white">
                    <option value="">All devices</option>
                    <?php foreach ($devices as $i => $d): ?><option value="<?= $i ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                </select>
                <select id="wallEvery" class="rounded-lg border border-slate-300 text-sm p-2 bg-white">
                    <option value="0">No auto-refresh</option><option value="10" selected>Refresh every 10 s</option><option value="30">Every 30 s</option><option value="60">Every minute</option>
                </select>
                <select id="wallSize" class="rounded-lg border border-slate-300 text-sm p-2 bg-white">
                    <option value="grid-cols-2 md:grid-cols-3 lg:grid-cols-4">Medium</option><option value="grid-cols-1 md:grid-cols-2">Large</option><option value="grid-cols-3 md:grid-cols-4 lg:grid-cols-6">Small</option>
                </select>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer"><input type="checkbox" id="wallOnline" checked class="w-4 h-4 rounded border-slate-300"> Online channels only</label>
            </div>
            <div id="wall" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3"></div>
        </div>
    <?php endif; ?>
</div>

<!-- Viewer (cctv/viewer.js): live picture with digital zoom, picture settings, recordings -->
<div id="viewer" class="hidden fixed inset-0 z-50 bg-slate-950/80 p-2 sm:p-6">
    <div class="h-full max-w-7xl mx-auto bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between gap-3 px-4 py-2 border-b border-slate-200">
            <h2 id="vTitle" class="text-sm font-bold text-slate-900 truncate"></h2>
            <button type="button" id="vClose" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100">Close ✕</button>
        </div>
        <div class="flex-1 min-h-0 flex flex-col lg:flex-row">
            <div class="flex-1 min-h-0 flex flex-col bg-slate-950">
                <div id="vStage" class="relative flex-1 min-h-[240px] overflow-hidden select-none">
                    <img id="vImg" alt="" draggable="false" class="absolute inset-0 w-full h-full object-contain origin-top-left">
                    <p id="vMsg" class="absolute inset-0 flex items-center justify-center text-slate-400 text-sm pointer-events-none"></p>
                </div>
                <div class="flex flex-wrap items-center gap-2 px-3 py-2 bg-slate-900 text-slate-200 text-xs">
                    <button type="button" class="vz px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 font-bold" data-z="-1" title="Zoom out">−</button>
                    <span id="vZoom" class="tabular-nums w-10 text-center">1×</span>
                    <button type="button" class="vz px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 font-bold" data-z="1" title="Zoom in">+</button>
                    <button type="button" id="vFit" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700">Fit</button>
                    <span class="text-slate-400 hidden sm:inline">Scroll to zoom · drag to move · double-click to fit</span>
                    <select id="vRate" class="ml-auto rounded bg-slate-800 border-0 text-xs py-1">
                        <option value="1000">Refresh 1 s</option><option value="2000" selected>Refresh 2 s</option><option value="5000">Refresh 5 s</option><option value="0">Paused</option>
                    </select>
                    <a id="vSave" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700" href="#" download>Save picture</a>
                    <button type="button" id="vFull" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700">Fullscreen</button>
                </div>
            </div>
            <aside class="lg:w-96 shrink-0 border-t lg:border-t-0 lg:border-l border-slate-200 flex flex-col min-h-0 max-h-[45vh] lg:max-h-none">
                <div class="flex gap-1 p-2 border-b border-slate-200">
                    <button type="button" class="vtab px-3 py-1.5 rounded-lg text-xs font-semibold" data-t="settings">Picture settings</button>
                    <button type="button" class="vtab px-3 py-1.5 rounded-lg text-xs font-semibold" data-t="recordings">Recordings</button>
                </div>
                <div id="vPanel" class="flex-1 overflow-y-auto p-4 text-sm"></div>
            </aside>
        </div>
    </div>
</div>

<script>
    $(function() {
        const DEVICES = <?= json_encode($devices) ?>;
        if (!DEVICES.length) return;
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const info = {}; // device index -> last info answer
        window.CCTV = { DEVICES, info, esc, SPINNER, toast: (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' }) };
        const toast = (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' });

        const fmtBytes = (b) => { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; };
        const fmtUptime = (s) => s == null ? '—' : Math.floor(s / 86400) + ' d ' + Math.floor(s % 86400 / 3600) + ' h';
        function drift(sec) {
            if (sec == null) return '—';
            const a = Math.abs(sec), txt = a < 60 ? a + ' s' : a < 3600 ? Math.round(a / 60) + ' min' : (a / 3600).toFixed(1) + ' h';
            return `<span class="font-semibold ${a <= 30 ? 'text-emerald-700' : a <= 300 ? 'text-amber-700' : 'text-red-700'}">${a <= 5 ? 'In sync' : txt + (sec > 0 ? ' ahead' : ' behind')}</span>`;
        }
        const dot = (state) => `<span class="inline-block w-2 h-2 rounded-full ${state === true ? 'bg-emerald-500' : state === false ? 'bg-red-500' : 'bg-slate-300'}"></span>`;

        // ---- Status cards ------------------------------------------------------------------------
        $('#statusView').html(DEVICES.map((d, i) => `<section class="dev bg-white rounded-2xl border border-slate-200 shadow-sm" data-i="${i}">
            <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-b border-slate-100">
                <div><h2 class="text-base font-bold text-slate-900">${esc(d.name)}</h2><p class="text-xs font-mono text-slate-500">${esc(d.ip)}</p></div>
                <div class="flex items-center gap-3 text-xs">
                    <button type="button" class="open-dev font-semibold text-blue-700 hover:text-blue-500 underline underline-offset-2">Open</button>
                    <button type="button" class="sync font-semibold text-slate-700 hover:text-slate-900 underline underline-offset-2">Sync time</button>
                    <button type="button" class="reboot font-semibold text-red-700 hover:text-red-500 underline underline-offset-2">Reboot</button>
                    <button type="button" class="reload font-semibold text-slate-500 hover:text-slate-900 underline underline-offset-2">Refresh</button>
                </div>
            </div>
            <div class="body px-5 py-4 text-sm text-slate-500">${SPINNER} Connecting…</div>
        </section>`).join(''));

        function renderDevice(i) {
            const r = info[i], $b = $(`.dev[data-i="${i}"] .body`);
            if (!r.ok) return $b.html('<p class="text-red-700">' + esc(r.error) + '</p>');
            const row = (k, v) => v ? `<dt class="text-slate-500">${k}</dt><dd class="text-slate-900 break-words">${v}</dd>` : '';
            let html = `<dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                ${row('Model', esc(r.model) + (r.type ? ' <span class="text-slate-400">· ' + esc(r.type) + '</span>' : ''))}${row('Serial', '<span class="font-mono text-xs">' + esc(r.serial) + '</span>')}
                ${row('Firmware', esc(r.firmware))}${row('Uptime', fmtUptime(r.uptimeSec))}
                ${row('CPU / memory', r.cpu != null ? r.cpu + '%' + (r.memory ? ' · ' + Math.round(r.memory[0]) + ' MB used' : '') : '')}
                ${row('Clock', esc(r.time || '—') + ' · ' + drift(r.driftSec) + (r.timeMode ? ' <span class="text-slate-400">(' + esc(r.timeMode) + ')</span>' : ''))}
            </dl>`;
            if (r.recorder) {
                const on = r.channels.filter((c) => c.online === true).length, off = r.channels.filter((c) => c.online === false).length;
                html += `<h3 class="mt-4 mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Channels <span class="normal-case font-normal">${on} online${off ? ', <span class="text-red-700 font-semibold">' + off + ' offline</span>' : ''}</span></h3>
                    <div class="max-h-56 overflow-y-auto border border-slate-200 rounded-lg"><table class="w-full text-xs"><tbody>${r.channels.map((c) => `<tr class="border-t border-slate-100 first:border-t-0">
                        <td class="px-2 py-1 tabular-nums text-slate-500">D${c.id}</td><td class="px-2 py-1">${dot(c.online)} <button type="button" class="open-ch ml-1 text-slate-800 hover:text-blue-700 hover:underline" data-ch="${c.id}" data-label="${esc(DEVICES[i].name + ' · D' + c.id + ' ' + c.name)}">${esc(c.name)}</button></td>
                        <td class="px-2 py-1 font-mono text-slate-500">${esc(c.ip || '')}</td>
                        <td class="px-2 py-1 ${c.online === false ? 'text-red-700' : 'text-slate-500'}">${c.online === false ? esc(c.detail || 'offline') : ''}</td>
                        <td class="px-2 py-1 text-right">${c.recording === true ? '<span class="text-emerald-700">● Recording</span>' : c.recording === false ? '<span class="text-amber-700">Not recording</span>' : ''}</td>
                    </tr>`).join('') || '<tr><td class="px-2 py-2 text-slate-400">No channels reported</td></tr>'}</tbody></table></div>
                    <h3 class="mt-4 mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Disks</h3>
                    ${r.disks.map((h) => {
                        const used = h.capacity ? Math.round((h.capacity - h.free) / h.capacity * 100) : 0, ok = /^ok$|normal/i.test(h.status);
                        return `<div class="mb-2"><div class="flex justify-between text-xs mb-1"><span class="${ok ? 'text-slate-700' : 'text-red-700 font-semibold'}">HDD ${h.id} ${esc(h.name)} · ${esc(h.status || '?')}${h.property ? ' · ' + esc(h.property) : ''}</span>
                            <span class="tabular-nums text-slate-500">${fmtBytes(h.capacity - h.free)} / ${fmtBytes(h.capacity)}</span></div>
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full ${ok ? 'bg-emerald-500' : 'bg-red-500'}" style="width:${used}%"></div></div></div>`;
                    }).join('') || '<p class="text-xs text-red-700">No disks reported</p>'}`;
            }
            $b.removeClass('text-slate-500').html(html);
        }

        function loadInfo(i) {
            $(`.dev[data-i="${i}"] .body`).html(SPINNER + ' Connecting…');
            return $.ajax({ url: 'index.php', data: { api: 'info', i }, dataType: 'json', timeout: 120000 })
                .done((r) => { info[i] = r; })
                .fail(() => { info[i] = { ok: false, error: 'Request failed' }; })
                .always(() => { renderDevice(i); renderSummary(); if (!$('#wallView').hasClass('hidden')) buildWall(); });
        }

        function renderSummary() {
            const all = Object.values(info);
            const ok = all.filter((r) => r.ok), chans = ok.flatMap((r) => r.channels);
            const disks = ok.flatMap((r) => r.disks), badDisks = disks.filter((h) => !/^ok$|normal/i.test(h.status)).length;
            const tile = (label, v, cls) => `<div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">${label}</div><div class="text-2xl font-bold tabular-nums ${cls || 'text-slate-800'}">${v}</div></div>`;
            $('#summary').html(
                tile('Devices reached', ok.length + '<span class="text-sm text-slate-400"> / ' + DEVICES.length + '</span>') +
                tile('Cameras online', chans.filter((c) => c.online === true).length, 'text-emerald-600') +
                tile('Cameras offline', chans.filter((c) => c.online === false).length, 'text-red-600') +
                tile('Disks', disks.length) + tile('Disk problems', badDisks, badDisks ? 'text-red-600' : 'text-slate-800') +
                tile('Clocks off > 5 min', ok.filter((r) => r.driftSec != null && Math.abs(r.driftSec) > 300).length, 'text-amber-600'));
        }

        $(document).on('click', '.reload', function() { loadInfo($(this).closest('.dev').data('i')); });
        $(document).on('click', '.sync', function() {
            const i = $(this).closest('.dev').data('i');
            if (!confirm(`Set ${DEVICES[i].name}'s clock to this server's time, in Pakistan time (UTC+5)? It switches the device to manual time.`)) return;
            $.post('index.php?api=synctime', { i }, null, 'json').done((r) => { toast(r.ok, r.ok ? 'Time set to ' + r.set : r.error); loadInfo(i); }).fail(() => toast(false, 'Request failed'));
        });
        $(document).on('click', '.reboot', function() {
            const i = $(this).closest('.dev').data('i'), d = DEVICES[i];
            const typed = prompt(`Reboot ${d.name}? Recording stops for a few minutes.\n\nType its IP (${d.ip}) to confirm:`);
            if (typed === null) return;
            if (typed !== d.ip) return toast(false, 'Confirmation did not match; nothing was done');
            $.post('index.php?api=reboot', { i, confirm: typed }, null, 'json').done((r) => toast(r.ok, r.ok ? d.name + ' is rebooting' : r.error)).fail(() => toast(false, 'Request failed'));
        });

        // ---- Snapshot wall -----------------------------------------------------------------------
        let wallTimer = null;
        function buildWall() {
            const only = $('#wallDevice').val(), onlineOnly = $('#wallOnline').is(':checked');
            const tiles = [];
            DEVICES.forEach((d, i) => {
                if (only !== '' && +only !== i) return;
                const r = info[i];
                if (!r || !r.ok) return;
                r.channels.forEach((c) => { if (!onlineOnly || c.online !== false) tiles.push({ i, ch: c.id, label: r.recorder ? d.name + ' · D' + c.id + ' ' + c.name : d.name, off: c.online === false }); });
            });
            $('#wall').attr('class', 'grid gap-3 ' + $('#wallSize').val()).html(tiles.map((t) => `<figure class="tile bg-slate-900 rounded-xl overflow-hidden cursor-zoom-in" data-i="${t.i}" data-ch="${t.ch}" data-label="${esc(t.label)}">
                <div class="aspect-video flex items-center justify-center text-slate-500 text-xs">${t.off ? 'Offline' : SPINNER}</div>
                <figcaption class="px-2 py-1 text-[11px] text-slate-300 truncate">${esc(t.label)}</figcaption></figure>`).join('') || '<p class="text-sm text-slate-500">No channels to show yet.</p>');
            refreshWall();
            clearInterval(wallTimer);
            const every = +$('#wallEvery').val();
            if (every) wallTimer = setInterval(refreshWall, every * 1000);
        }
        // Each image is replaced only once the new one has loaded, so tiles never flash blank.
        function refreshWall() {
            if ($('#wallView').hasClass('hidden')) return;
            $('#wall .tile').each(function(n) {
                const $t = $(this), img = new Image();
                img.className = 'w-full aspect-video object-cover';
                img.onload = () => $t.children().first().replaceWith(img);
                img.onerror = () => $t.children().first().replaceWith('<div class="aspect-video flex items-center justify-center text-red-400 text-xs">No picture</div>');
                // Spread the requests a little so a recorder is not asked for every channel at once.
                setTimeout(() => { img.src = `index.php?snap=${$t.data('i')}&ch=${$t.data('ch')}&t=${Date.now()}`; }, n * 150);
            });
        }
        $('#wallDevice, #wallEvery, #wallSize, #wallOnline').on('change', buildWall);

        function showView(v) {
            $('.view-tab').removeClass('bg-white shadow text-slate-900').addClass('text-slate-500').filter(`[data-v="${v}"]`).addClass('bg-white shadow text-slate-900').removeClass('text-slate-500');
            $('#statusView').toggleClass('hidden', v !== 'status');
            $('#wallView').toggleClass('hidden', v !== 'wall');
            if (v === 'wall') buildWall(); else clearInterval(wallTimer);
        }
        $('.view-tab').on('click', function() { showView($(this).data('v')); });
        showView('status');
        DEVICES.forEach((_, i) => loadInfo(i));
    });
</script>
<script src="viewer.js"></script>
</body>

</html>
