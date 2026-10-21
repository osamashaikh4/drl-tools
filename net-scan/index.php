<?php
require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('Asia/Karachi');

// Each scan of a subnet is kept here so the next one can show what is new and what has gone
// (not committed; data/.htaccess keeps it off the web).
const NS_DATA = __DIR__ . '/data';
// TCP ports checked on every device found, with what an open port usually means.
const NS_PORTS = [
    22 => 'SSH', 23 => 'Telnet', 80 => 'HTTP', 135 => 'Windows RPC', 443 => 'HTTPS', 445 => 'Windows shares',
    554 => 'RTSP video', 1521 => 'Oracle', 3306 => 'MySQL', 3389 => 'Remote Desktop', 5985 => 'WinRM',
    8000 => 'Hikvision', 8080 => 'HTTP alt', 9100 => 'Printing', 37777 => 'Dahua',
];

// Subnets this server is on, as "a.b.c.0/nn".
function ns_local_subnets(): array
{
    $out = shell_exec('powershell.exe -NoProfile -NonInteractive -Command "Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike \'127.*\' -and $_.IPAddress -notlike \'169.254.*\' } | ForEach-Object { $_.IPAddress + \'/\' + $_.PrefixLength }"');
    $nets = [];
    foreach (preg_split('/\s+/', trim((string) $out)) as $cidr) {
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d+)$/', $cidr, $m) && $m[2] >= 22 && $m[2] <= 30) {
            $nets[] = long2ip(ip2long($m[1]) & (-1 << (32 - (int) $m[2]))) . '/' . $m[2];
        }
    }
    return array_values(array_unique($nets));
}

// "a.b.c.d/nn" normalised to its network address, or null when not a /22 to /30 private subnet.
function ns_cidr(string $cidr): ?string
{
    if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d+)$/', trim($cidr), $m) || ip2long($m[1]) === false || $m[2] < 22 || $m[2] > 30) {
        return null;
    }
    $ip = $m[1];
    $private = str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip);
    return $private ? long2ip(ip2long($ip) & (-1 << (32 - (int) $m[2]))) . '/' . $m[2] : null;
}

// Vendor of a MAC address from the IEEE registry (oui.json, built from Wireshark's manuf list).
function ns_vendor(?string $mac): ?string
{
    static $oui = null;
    if (!$mac) {
        return null;
    }
    $oui ??= json_decode((string) file_get_contents(__DIR__ . '/oui.json'), true);
    $hex = strtoupper(str_replace(['-', ':'], '', $mac));
    foreach ([9, 7] as $len) {
        if (isset($oui['long'][substr($hex, 0, $len)])) {
            return $oui['long'][substr($hex, 0, $len)];
        }
    }
    if (isset($oui['24'][substr($hex, 0, 6)])) {
        return $oui['24'][substr($hex, 0, 6)];
    }
    // Second hex digit 2, 6, A or E: a locally assigned (often randomised) MAC, typical of phones.
    return in_array($hex[1] ?? '', ['2', '6', 'A', 'E'], true) ? 'Random / private MAC' : null;
}

// Best guess at what a device is, from its vendor and open ports.
function ns_kind(array $h, ?string $vendor): string
{
    $p = array_flip($h['ports']);
    $v = strtolower((string) $vendor);
    if (!empty($h['self'])) return 'This server';
    if (str_contains($v, 'zkteco')) return 'Attendance machine';
    if (isset($p[37777]) || isset($p[8000]) || (isset($p[554]) && !isset($p[3389]))) return 'Camera / NVR';
    if (isset($p[9100])) return 'Printer';
    if (str_contains($v, 'microsoft') && (isset($p[3389]) || isset($p[445]))) return isset($p[1521]) ? 'Hyper-V VM · Oracle' : 'Hyper-V VM';
    if (isset($p[1521])) return 'Oracle server';
    if (isset($p[3389]) || isset($p[445]) || isset($p[135])) return 'Windows PC / server';
    if (isset($p[22])) return 'Linux / network device';
    if (isset($p[80]) || isset($p[443])) return 'Web-managed device';
    if (str_starts_with($v, 'random')) return 'Phone / laptop';
    return 'Unknown';
}

// Your labels plus the ZK machines.
function ns_labels(): array
{
    $labels = is_file(__DIR__ . '/names.php') ? (require __DIR__ . '/names.php') : [];
    $zk = is_file(__DIR__ . '/../zk-time/devices.php') ? (require __DIR__ . '/../zk-time/devices.php') : [];
    foreach ($zk as $d) {
        $labels[$d['ip']] ??= 'ZK: ' . $d['name'];
    }
    $norm = [];
    foreach ($labels as $k => $v) {
        $norm[strtoupper(str_replace(':', '-', $k))] = $v;
    }
    return $norm;
}

if (isset($_GET['scan'])) {
    header('Content-Type: application/json');
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Logged out']);
        exit();
    }
    session_write_close();
    set_time_limit(180);

    $cidr = ns_cidr((string) $_GET['scan']);
    if ($cidr === null) {
        echo json_encode(['ok' => false, 'error' => 'Enter a private subnet between /22 and /30, e.g. 10.1.52.0/24']);
        exit();
    }

    $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg(__DIR__ . '/scan.ps1');
    $env = array_merge(getenv(), ['NS_CIDRS' => $cidr, 'NS_PORTS' => implode(',', array_keys(NS_PORTS))]);
    $started = microtime(true);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $start = strpos($out, '{');
    $res   = $start === false ? null : json_decode(substr($out, $start), true);
    if (!is_array($res) || empty($res['ok'])) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? (trim($err) ?: 'The scan did not finish')]);
        exit();
    }

    // Compare with the previous scan of this subnet (matched by MAC when known, else by IP).
    if (!is_dir(NS_DATA)) {
        @mkdir(NS_DATA, 0775, true);
    }
    $file = NS_DATA . '/last-' . str_replace(['.', '/'], ['-', '_'], $cidr) . '.json';
    $prev = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $id   = fn($h) => $h['mac'] ?: $h['ip'];
    $seenBefore = [];
    foreach (($prev['hosts'] ?? []) as $h) {
        $seenBefore[$id($h)] = $h;
    }

    $labels = ns_labels();
    $hosts  = [];
    $now    = [];
    foreach ($res['hosts'] as $h) {
        $h['ports']  = array_values(array_map('intval', (array) ($h['ports'] ?? [])));
        sort($h['ports']);
        $h['vendor'] = ns_vendor($h['mac']);
        $h['kind']   = ns_kind($h, $h['vendor']);
        $h['label']  = $labels[$h['ip']] ?? ($h['mac'] ? ($labels[strtoupper($h['mac'])] ?? null) : null);
        $h['new']    = $prev !== null && !isset($seenBefore[$id($h)]);
        // Same device, different address since last time.
        $was = $seenBefore[$id($h)]['ip'] ?? null;
        $h['movedFrom'] = $h['mac'] && $was && $was !== $h['ip'] ? $was : null;
        $hosts[] = $h;
        $now[$id($h)] = true;
    }
    usort($hosts, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

    $gone = [];
    foreach ($seenBefore as $k => $h) {
        if (!isset($now[$k])) {
            $gone[] = ['ip' => $h['ip'], 'mac' => $h['mac'], 'name' => $h['name'] ?? null, 'vendor' => ns_vendor($h['mac']), 'kind' => $h['kind'] ?? null, 'label' => $labels[$h['ip']] ?? null];
        }
    }
    usort($gone, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

    $scannedAt = date('Y-m-d H:i:s');
    @file_put_contents($file, json_encode(['at' => $scannedAt, 'hosts' => $hosts]));

    echo json_encode([
        'ok'       => true,
        'cidr'     => $cidr,
        'scanned'  => $res['scanned'],
        'hosts'    => $hosts,
        'gone'     => $gone,
        'previous' => $prev['at'] ?? null,
        'at'       => $scannedAt,
        'seconds'  => round(microtime(true) - $started, 1),
    ]);
    exit();
}

require_login();
$subnets   = ns_local_subnets();
$pageTitle = 'Network Scan';
include __DIR__ . '/../header.php';
?>

<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Network Scan</h1>
        <p class="text-sm text-slate-500">Every device on the chosen subnets: IP, MAC and maker, host name, open ports and a best guess at what it is. Devices that block ping are still found on this server's own subnets through ARP.</p>
    </div>

    <form id="scanForm" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6 flex flex-wrap items-end gap-4">
        <div>
            <p class="text-xs font-semibold text-slate-600 mb-1.5">This server's subnets</p>
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                <?php foreach ($subnets as $i => $s): ?>
                    <label class="inline-flex items-center gap-2 text-sm font-mono cursor-pointer"><input type="checkbox" class="subnet w-4 h-4 rounded border-slate-300" value="<?= htmlspecialchars($s) ?>" <?= $i === 0 ? 'checked' : '' ?>><?= htmlspecialchars($s) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <label class="text-xs font-semibold text-slate-600">Other subnet
            <input id="customSubnet" placeholder="e.g. 172.16.9.0/24" class="block mt-1 w-48 rounded-lg border border-slate-300 text-sm p-2 font-mono">
        </label>
        <button type="submit" id="scanButton" class="px-6 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white disabled:opacity-60 inline-flex items-center gap-2">Scan</button>
        <p id="scanStatus" class="text-xs text-slate-500 tabular-nums basis-full"></p>
    </form>

    <div id="summary" class="hidden grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-4"></div>

    <div id="toolbar" class="hidden flex flex-wrap items-center justify-between gap-3 mb-3">
        <div class="flex flex-wrap gap-2">
            <input id="search" type="search" placeholder="Search IP, MAC, name, maker, port…" class="w-72 rounded-lg border border-slate-300 text-sm p-2">
            <select id="kindFilter" class="rounded-lg border border-slate-300 text-sm p-2 bg-white"><option value="">All types</option></select>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer"><input type="checkbox" id="onlyNew" class="w-4 h-4 rounded border-slate-300"> New / moved only</label>
        </div>
        <button type="button" id="export" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-100">Export Excel</button>
    </div>

    <div id="results" class="space-y-6"></div>
</div>

<script src="../assets/js/xlsx-js-style.min.js"></script>
<script>
    $(function() {
        const PORTS = <?= json_encode(NS_PORTS) ?>;
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        let scans = []; // one answer per subnet

        const KIND = {
            'This server': 'bg-slate-900 text-white', 'Attendance machine': 'bg-rose-50 text-rose-700', 'Camera / NVR': 'bg-amber-50 text-amber-700',
            'Printer': 'bg-sky-50 text-sky-700', 'Hyper-V VM': 'bg-violet-50 text-violet-700', 'Hyper-V VM · Oracle': 'bg-violet-50 text-violet-700',
            'Oracle server': 'bg-red-50 text-red-700', 'Windows PC / server': 'bg-blue-50 text-blue-700', 'Linux / network device': 'bg-emerald-50 text-emerald-700',
            'Web-managed device': 'bg-teal-50 text-teal-700', 'Phone / laptop': 'bg-slate-100 text-slate-600',
        };
        const kindBadge = (k) => `<span class="inline-block px-2 py-0.5 rounded-md text-xs font-semibold whitespace-nowrap ${KIND[k] || 'bg-slate-50 text-slate-500'}">${esc(k)}</span>`;
        const portBadges = (ports) => ports.map((p) => `<span title="${esc(PORTS[p] || '')}" class="inline-block px-1.5 py-0.5 mr-1 mb-0.5 rounded bg-slate-100 text-slate-700 text-[11px] font-mono">${p}</span>`).join('');

        function filtered(hosts) {
            const q = $('#search').val().trim().toLowerCase(), kind = $('#kindFilter').val(), onlyNew = $('#onlyNew').is(':checked');
            return hosts.filter((h) => (!kind || h.kind === kind) && (!onlyNew || h.new || h.movedFrom) &&
                (!q || [h.ip, h.mac, h.name, h.vendor, h.kind, h.label, h.ports.join(' ')].join(' ').toLowerCase().includes(q)));
        }

        function render() {
            const all = scans.flatMap((s) => s.hosts);
            const count = (f) => all.filter(f).length;
            const tile = (label, n, cls) => `<div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">${label}</div><div class="text-2xl font-bold tabular-nums ${cls || 'text-slate-800'}">${n}</div></div>`;
            $('#summary').removeClass('hidden').html(
                tile('Devices', all.length) + tile('New', count((h) => h.new), 'text-emerald-600') + tile('Moved IP', count((h) => h.movedFrom), 'text-amber-600') +
                tile('Gone', scans.reduce((n, s) => n + s.gone.length, 0), 'text-red-600') + tile('Windows', count((h) => /Windows|Hyper-V/.test(h.kind))) + tile('Cameras / printers', count((h) => /Camera|Printer/.test(h.kind))));

            const kinds = [...new Set(all.map((h) => h.kind))].sort();
            const cur = $('#kindFilter').val();
            $('#kindFilter').html('<option value="">All types</option>' + kinds.map((k) => `<option ${k === cur ? 'selected' : ''}>${esc(k)}</option>`).join(''));
            $('#toolbar').removeClass('hidden');

            $('#results').html(scans.map((s) => {
                const rows = filtered(s.hosts);
                return `<section class="bg-white rounded-2xl border border-slate-200 shadow-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3 border-b border-slate-100">
                        <h2 class="text-base font-bold text-slate-900 font-mono">${esc(s.cidr)}</h2>
                        <p class="text-xs text-slate-500 tabular-nums">${s.hosts.length} of ${s.scanned} addresses in use · ${s.seconds}s · ${s.previous ? 'compared with scan of ' + esc(s.previous) : 'first scan, nothing to compare yet'}</p>
                    </div>
                    <div class="overflow-x-auto"><table class="w-full text-sm">
                        <thead class="text-xs text-slate-500 uppercase bg-slate-50"><tr>
                            <th class="px-4 py-2 text-left">IP</th><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">MAC / maker</th><th class="px-3 py-2 text-left">Open ports</th><th class="px-4 py-2 text-right">Ping</th>
                        </tr></thead>
                        <tbody>${rows.map((h) => `<tr class="border-t border-slate-100 ${h.new ? 'bg-emerald-50/60' : h.movedFrom ? 'bg-amber-50/60' : ''}">
                            <td class="px-4 py-2 font-mono whitespace-nowrap">${esc(h.ip)}${h.new ? ' <span class="ml-1 px-1.5 py-0.5 rounded bg-emerald-600 text-white text-[10px] font-sans font-bold">NEW</span>' : ''}${h.movedFrom ? `<div class="text-[11px] text-amber-700 font-sans">was ${esc(h.movedFrom)}</div>` : ''}</td>
                            <td class="px-3 py-2">${kindBadge(h.kind)}</td>
                            <td class="px-3 py-2">${h.label ? '<div class="font-semibold text-slate-900">' + esc(h.label) + '</div>' : ''}<div class="text-slate-600 text-xs">${esc(h.name || (h.label ? '' : '—'))}</div></td>
                            <td class="px-3 py-2"><div class="font-mono text-xs text-slate-700">${esc(h.mac || '—')}</div><div class="text-xs text-slate-500">${esc(h.vendor || '')}</div></td>
                            <td class="px-3 py-2">${portBadges(h.ports) || '<span class="text-slate-400 text-xs">none</span>'}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-xs ${h.ping ? 'text-slate-600' : 'text-slate-400'}">${h.ping ? h.ms + ' ms' : 'no reply'}</td>
                        </tr>`).join('') || '<tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">Nothing matches</td></tr>'}</tbody>
                    </table></div>
                    ${s.gone.length ? `<div class="px-5 py-3 border-t border-slate-100 text-xs"><span class="font-semibold text-red-700">Not found this time (seen in the previous scan):</span> ${s.gone.map((g) =>
                        `<span class="inline-block mr-3 font-mono">${esc(g.ip)}</span><span class="text-slate-500 mr-4">${esc([g.label, g.name, g.kind, g.vendor].filter(Boolean).join(' · '))}</span>`).join('')}</div>` : ''}
                </section>`;
            }).join(''));
        }
        $('#search, #kindFilter, #onlyNew').on('input change', render);

        $('#scanForm').on('submit', function(e) {
            e.preventDefault();
            const list = $('.subnet:checked').map((_, el) => el.value).get();
            const custom = $('#customSubnet').val().trim();
            if (custom) list.push(custom);
            if (!list.length) return iziToast.warning({ title: 'Subnet', message: 'Tick a subnet or enter one', position: 'topRight' });

            $('#scanButton').prop('disabled', true).html(SPINNER + ' Scanning…');
            const started = Date.now();
            const tick = setInterval(() => $('#scanStatus').html(SPINNER + ' Scanning ' + esc(list.join(', ')) + ' … ' + Math.round((Date.now() - started) / 1000) + 's'), 1000);
            const answers = [];
            $.when(...list.map((cidr) => $.ajax({ url: 'index.php', data: { scan: cidr }, dataType: 'json', timeout: 200000 })
                .done((r) => r.ok ? answers.push(r) : iziToast.error({ title: cidr, message: r.error, position: 'topRight' }))
                .fail(() => iziToast.error({ title: cidr, message: 'The scan failed', position: 'topRight' }))
            )).always(() => {
                clearInterval(tick);
                $('#scanButton').prop('disabled', false).text('Scan');
                if (!answers.length) { $('#scanStatus').text(''); return; }
                scans = answers.sort((a, b) => list.indexOf(a.cidr) - list.indexOf(b.cidr));
                $('#scanStatus').text('Done in ' + Math.round((Date.now() - started) / 1000) + 's.');
                render();
            });
        });

        $('#export').on('click', function() {
            const head = ['Subnet', 'IP', 'Type', 'Label', 'Host name', 'MAC', 'Maker', 'Open ports', 'Ping ms', 'New', 'Was IP'];
            const rows = scans.flatMap((s) => filtered(s.hosts).map((h) => [s.cidr, h.ip, h.kind, h.label || '', h.name || '', h.mac || '', h.vendor || '', h.ports.join(', '), h.ping ? h.ms : '', h.new ? 'yes' : '', h.movedFrom || '']));
            const ws = XLSX.utils.aoa_to_sheet([head, ...rows]);
            head.forEach((_, c) => { ws[XLSX.utils.encode_cell({ r: 0, c })].s = { font: { bold: true } }; });
            ws['!cols'] = [16, 15, 22, 20, 28, 19, 30, 30, 8, 5, 15].map((wch) => ({ wch }));
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Devices');
            XLSX.writeFile(wb, 'Network-scan-' + new Date().toISOString().slice(0, 10) + '.xlsx');
        });
    });
</script>
</body>

</html>
