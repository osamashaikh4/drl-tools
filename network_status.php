<?php
require_once __DIR__ . '/bootstrap.php';

// Speed bands for the average round trip (ms) of one branch. Any lost packet drops a branch one band.
const NET_FAST_MS   = 40;
const NET_NORMAL_MS = 120;
const NET_PINGS     = 6;
const NET_SIZES     = [32, 256, 512, 1024, 1472];

if (isset($_GET['check'])) {
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode([]);
        exit();
    }

    // Release the session lock so the parallel branch requests from one browser run concurrently.
    session_write_close();
    ini_set('max_execution_time', '120');

    require_once __DIR__ . '/conn.php';
    require_once __DIR__ . '/cards/card_functions.php';

    $bcode = trim($_GET['bcode'] ?? '');
    $size  = (int) ($_GET['size'] ?? 32);
    if (!isset($connections[$bcode]) || !filter_var($connections[$bcode], FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid branch']);
        exit();
    }
    if (!in_array($size, NET_SIZES, true)) {
        $size = 32;
    }
    $ip = $connections[$bcode];

    // --- Ping ---------------------------------------------------------------------------
    $started = microtime(true);
    exec('ping -4 -n ' . NET_PINGS . ' -w 2000 -l ' . $size . ' ' . $ip, $output);
    $pingSeconds = round(microtime(true) - $started, 1);

    // One entry per packet: reply time in ms, or null when it timed out / was unreachable.
    $times = [];
    $bytes = [];
    $ttls  = [];
    foreach ($output as $line) {
        if (preg_match('/bytes=(\d+)\s+time[=<](\d+)ms\s+TTL=(\d+)/i', $line, $m)) {
            $bytes[] = (int) $m[1];
            $times[] = (int) $m[2];
            $ttls[]  = (int) $m[3];
        } elseif (preg_match('/timed out|unreachable|General failure|transmit failed/i', $line)) {
            $times[] = null;
        }
    }
    $times    = array_slice(array_pad($times, NET_PINGS, null), 0, NET_PINGS);
    $replies  = array_values(array_filter($times, fn($t) => $t !== null));
    $received = count($replies);
    $loss     = round((NET_PINGS - $received) / NET_PINGS * 100);

    $ping = [
        'sent'     => NET_PINGS,
        'received' => $received,
        'loss'     => $loss,
        'times'    => $times,
        'size'     => $size,
        'seconds'  => $pingSeconds,
        'min'      => $received ? min($replies) : null,
        'max'      => $received ? max($replies) : null,
        'avg'      => $received ? round(array_sum($replies) / $received, 1) : null,
        'jitter'   => null,
        'bytes'    => $bytes ? round(array_sum($bytes) / count($bytes)) : null,
        'ttl'      => $ttls ? (int) round(array_sum($ttls) / count($ttls)) : null,
        'hops'     => null,
    ];
    // Jitter: mean change between consecutive replies.
    if ($received > 1) {
        $diffs = [];
        for ($i = 1; $i < $received; $i++) {
            $diffs[] = abs($replies[$i] - $replies[$i - 1]);
        }
        $ping['jitter'] = round(array_sum($diffs) / count($diffs), 1);
    }
    // Hops: distance from the nearest common starting TTL (64 / 128 / 255).
    if ($ping['ttl'] !== null) {
        foreach ([64, 128, 255] as $start) {
            if ($ping['ttl'] <= $start) {
                $ping['hops'] = $start - $ping['ttl'];
                break;
            }
        }
    }

    if ($received === 0) {
        $speed = 'DOWN';
    } else {
        $band  = $ping['avg'] < NET_FAST_MS ? 0 : ($ping['avg'] < NET_NORMAL_MS ? 1 : 2);
        $band  = min(2, $band + ($loss > 0 ? 1 : 0));
        $speed = ['FAST', 'NORMAL', 'SLOW'][$band];
    }

    // --- Oracle listener (TCP 1521) --------------------------------------------------------
    $started  = microtime(true);
    $sock     = @fsockopen($ip, 1521, $errno, $errstr, 3);
    $listener = ['up' => (bool) $sock, 'ms' => $sock ? round((microtime(true) - $started) * 1000) : null, 'error' => $sock ? null : $errstr];
    if ($sock) {
        fclose($sock);
    }

    // --- Database login + clock compared with 253 ---------------------------------------------
    $db = ['up' => false, 'ms' => null, 'error' => null, 'time' => null, 'skew' => null];
    if ($listener['up']) {
        $started = microtime(true);
        $bconn   = card_connect_branch($bcode, $connections, 5);
        if ($bconn) {
            $db['up'] = true;
            $db['ms'] = round((microtime(true) - $started) * 1000);

            $sql   = "SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD HH24:MI:SS') T FROM DUAL";
            $local = card_query_all($bconn, $sql);
            $main  = $conn ? card_query_all($conn, $sql) : [];
            if ($local) {
                $db['time'] = $local[0]['T'];
                if ($main) {
                    $db['skew'] = strtotime($local[0]['T']) - strtotime($main[0]['T']);
                }
            }
            oci_close($bconn);
        } else {
            $e = oci_error();
            $db['error'] = $e['message'] ?? 'Connection failed';
        }
    } else {
        $db['error'] = 'Listener not reachable';
    }

    echo json_encode([
        'bcode'    => $bcode,
        'ip'       => $ip,
        'status'   => $received > 0 ? 'UP' : 'DOWN',
        'speed'    => $speed,
        'ping'     => $ping,
        'listener' => $listener,
        'db'       => $db,
        'checked'  => date('H:i:s'),
    ]);
    exit();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/cards/card_functions.php';

$branches  = card_branches_list($conn, $connections);
$pageTitle = 'Network Status';
include __DIR__ . '/header.php';
?>

<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Network Status</h1>
            <p class="text-sm text-slate-500">Ping, Oracle listener and database login for every branch server.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-xs font-semibold text-slate-600">
                Packet size
                <select id="packetSize" class="block mt-1 rounded-lg border border-slate-300 text-sm p-2 bg-white">
                    <?php foreach (NET_SIZES as $s): ?>
                        <option value="<?= $s ?>"><?= $s ?> bytes</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button id="runAll" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white shadow disabled:opacity-60 disabled:cursor-not-allowed">Check All</button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Online</div>
            <div class="text-2xl font-bold tabular-nums text-emerald-600"><span id="sumUp">0</span><span class="text-sm text-slate-400 font-semibold"> / <?= count($branches) ?></span></div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Down</div>
            <div class="text-2xl font-bold tabular-nums text-red-600" id="sumDown">0</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Slow</div>
            <div class="text-2xl font-bold tabular-nums text-amber-600" id="sumSlow">0</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">DB not connecting</div>
            <div class="text-2xl font-bold tabular-nums text-red-600" id="sumDb">0</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Avg response</div>
            <div class="text-2xl font-bold tabular-nums text-slate-800" id="sumAvg">&mdash;</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Last run</div>
            <div class="text-2xl font-bold tabular-nums text-slate-800" id="lastRun">&mdash;</div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div class="flex flex-wrap items-center gap-3">
        <input id="search" type="search" placeholder="Search branch, code or IP…" autocomplete="off" class="w-64 rounded-lg border border-slate-300 bg-white text-sm px-3 py-1.5">
        <div id="filters" class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm">
            <button data-filter="all" class="px-3 py-1 rounded-md bg-slate-900 text-white font-semibold">All</button>
            <button data-filter="problem" class="px-3 py-1 rounded-md text-slate-600 hover:bg-slate-100">Problems</button>
            <button data-filter="DOWN" class="px-3 py-1 rounded-md text-slate-600 hover:bg-slate-100">Down</button>
            <button data-filter="SLOW" class="px-3 py-1 rounded-md text-slate-600 hover:bg-slate-100">Slow</button>
        </div>
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-500">
            <span><span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span> Fast &lt; <?= NET_FAST_MS ?> ms</span>
            <span><span class="inline-block w-2 h-2 rounded-full bg-sky-500"></span> Normal &lt; <?= NET_NORMAL_MS ?> ms</span>
            <span><span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span> Slow (or packet loss)</span>
            <span id="progress" class="tabular-nums font-semibold text-slate-600"></span>
        </div>
    </div>
    <div id="progressTrack" class="h-1 mb-3 rounded-full bg-slate-100 overflow-hidden opacity-0 transition-opacity">
        <div id="progressBar" class="progress-bar h-full bg-slate-900" style="transform: scaleX(0)"></div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">Branch</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Avg</th>
                    <th class="px-4 py-3 text-left">Replies</th>
                    <th class="px-4 py-3 text-left" title="Min / max round trip and jitter">Min / Max · Jitter</th>
                    <th class="px-4 py-3 text-left">Loss</th>
                    <th class="px-4 py-3 text-left" title="Average bytes per reply">Packet</th>
                    <th class="px-4 py-3 text-left" title="Reply TTL and estimated hops">TTL</th>
                    <th class="px-4 py-3 text-left" title="TCP connect to port 1521">Listener</th>
                    <th class="px-4 py-3 text-left" title="Oracle login time and clock offset from 253">Database</th>
                    <th class="px-4 py-3 text-left">Checked</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody id="resultsBody" class="divide-y divide-slate-100">
                <?php foreach ($branches as $bcode => $bname): ?>
                    <tr class="hover:bg-slate-50" data-bcode="<?= htmlspecialchars($bcode) ?>" data-speed="">
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            <div class="font-semibold text-slate-800"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></div>
                            <div class="font-mono text-xs text-slate-500"><?= htmlspecialchars($connections[$bcode]) ?></div>
                        </td>
                        <td class="px-4 py-2.5 status-cell text-slate-400">&mdash;</td>
                        <td class="px-4 py-2.5 avg-cell tabular-nums whitespace-nowrap"></td>
                        <td class="px-4 py-2.5 bars-cell"></td>
                        <td class="px-4 py-2.5 range-cell tabular-nums whitespace-nowrap text-slate-600"></td>
                        <td class="px-4 py-2.5 loss-cell tabular-nums whitespace-nowrap"></td>
                        <td class="px-4 py-2.5 packet-cell tabular-nums whitespace-nowrap text-slate-600"></td>
                        <td class="px-4 py-2.5 ttl-cell tabular-nums whitespace-nowrap text-slate-600"></td>
                        <td class="px-4 py-2.5 listener-cell tabular-nums whitespace-nowrap"></td>
                        <td class="px-4 py-2.5 db-cell tabular-nums whitespace-nowrap"></td>
                        <td class="px-4 py-2.5 checked-cell tabular-nums whitespace-nowrap text-slate-500"></td>
                        <td class="px-4 py-2.5 text-right">
                            <button class="reload-btn p-1.5 rounded-lg text-slate-500 hover:text-slate-900 hover:bg-slate-100 disabled:opacity-40" title="Check again">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        const CONCURRENCY = 6;
        const tbody = document.getElementById('resultsBody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const runBtn = document.getElementById('runAll');
        const sizeSel = document.getElementById('packetSize');
        const progressEl = document.getElementById('progress');
        const progressTrack = document.getElementById('progressTrack');
        const progressBar = document.getElementById('progressBar');
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const SPEED = {
            FAST: { label: 'Fast', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', bar: 'bg-emerald-500' },
            NORMAL: { label: 'Normal', cls: 'bg-sky-50 text-sky-700 border-sky-200', bar: 'bg-sky-500' },
            SLOW: { label: 'Slow', cls: 'bg-amber-50 text-amber-700 border-amber-200', bar: 'bg-amber-500' },
            DOWN: { label: 'Down', cls: 'bg-red-50 text-red-700 border-red-200', bar: 'bg-red-500' },
            ERROR: { label: 'Error', cls: 'bg-red-50 text-red-700 border-red-200', bar: 'bg-red-500' },
        };
        const results = {}; // bcode -> latest result
        const previous = {}; // bcode -> previous avg, for the trend arrow
        let filter = 'all';

        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const ms = (v) => v === null || v === undefined ? '&mdash;' : v + ' ms';

        try {
            sizeSel.value = localStorage.getItem('net.size') || '32';
        } catch (e) {}

        function setCells(row, values) {
            Object.entries(values).forEach(([cls, html]) => { row.querySelector('.' + cls).innerHTML = html; });
        }

        function pill(speed) {
            const s = SPEED[speed];
            return `<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border text-xs font-semibold ${s.cls}"><span class="w-1.5 h-1.5 rounded-full ${s.bar}"></span>${s.label}</span>`;
        }

        // One small bar per packet, height scaled to the slowest reply; lost packets are short red bars.
        function bars(times, speed) {
            const max = Math.max(1, ...times.filter((t) => t !== null));
            return '<div class="flex items-end gap-0.5 h-6">' + times.map((t) => t === null
                ? '<span class="w-1.5 h-1.5 rounded-sm bg-red-500" title="Timed out"></span>'
                : `<span class="w-1.5 rounded-sm ${SPEED[speed].bar}" style="height:${Math.max(15, t / max * 100)}%" title="${t} ms"></span>`
            ).join('') + '</div>';
        }

        function render(row, d) {
            const p = d.ping;
            const prev = previous[d.bcode];
            let trend = '';
            if (prev != null && p.avg != null && Math.abs(p.avg - prev) >= 5) {
                trend = p.avg > prev
                    ? ` <span class="text-amber-600 text-xs" title="Was ${prev} ms">▲</span>`
                    : ` <span class="text-emerald-600 text-xs" title="Was ${prev} ms">▼</span>`;
            }
            const skew = d.db.skew;
            const skewHtml = skew === null ? '' : ` <span class="text-xs ${Math.abs(skew) > 60 ? 'text-red-600 font-semibold' : 'text-slate-400'}" title="Clock vs 253 (${esc(d.db.time)})">${skew >= 0 ? '+' : ''}${skew}s</span>`;

            setCells(row, {
                'status-cell': pill(d.speed),
                'avg-cell': p.avg === null ? '<span class="text-slate-400">&mdash;</span>' : `<span class="font-semibold text-slate-800">${p.avg} ms</span>${trend}`,
                'bars-cell': bars(p.times, d.speed),
                'range-cell': p.received ? `${p.min} / ${p.max} ms${p.jitter !== null ? ' · ±' + p.jitter : ''}` : '&mdash;',
                'loss-cell': `<span class="${p.loss ? 'text-red-600 font-semibold' : 'text-slate-600'}">${p.loss}%</span> <span class="text-xs text-slate-400">${p.received}/${p.sent}</span>`,
                'packet-cell': p.bytes !== null ? `${p.bytes} B` : `<span class="text-slate-400">${p.size} B</span>`,
                'ttl-cell': p.ttl !== null ? `${p.ttl} <span class="text-xs text-slate-400">${p.hops} hop${p.hops === 1 ? '' : 's'}</span>` : '&mdash;',
                'listener-cell': d.listener.up
                    ? `<span class="text-emerald-600 font-semibold">Open</span> <span class="text-xs text-slate-400">${d.listener.ms} ms</span>`
                    : `<span class="text-red-600 font-semibold" title="${esc(d.listener.error)}">Closed</span>`,
                'db-cell': d.db.up
                    ? `<span class="text-emerald-600 font-semibold">OK</span> <span class="text-xs text-slate-400">${d.db.ms} ms</span>${skewHtml}`
                    : `<span class="text-red-600 font-semibold" title="${esc(d.db.error)}">Not connecting</span>`,
                'checked-cell': `${esc(d.checked)} <span class="text-xs text-slate-400">${p.seconds}s</span>`,
            });
        }

        function updateSummary() {
            const list = Object.values(results);
            const up = list.filter((d) => d.status === 'UP');
            const avgs = up.map((d) => d.ping.avg).filter((v) => v !== null);
            document.getElementById('sumUp').textContent = up.length;
            document.getElementById('sumDown').textContent = list.filter((d) => d.speed === 'DOWN' || d.speed === 'ERROR').length;
            document.getElementById('sumSlow').textContent = list.filter((d) => d.speed === 'SLOW').length;
            document.getElementById('sumDb').textContent = list.filter((d) => d.db && !d.db.up).length;
            document.getElementById('sumAvg').textContent = avgs.length ? (avgs.reduce((a, b) => a + b, 0) / avgs.length).toFixed(1) + ' ms' : '—';
        }

        // Problem rows go to the top: down, then slow, then everything else in branch order.
        function sortRows() {
            const rank = { ERROR: 0, DOWN: 0, SLOW: 1 };
            rows.slice()
                .sort((a, b) => (rank[a.dataset.speed] ?? 2) - (rank[b.dataset.speed] ?? 2) || rows.indexOf(a) - rows.indexOf(b))
                .forEach((r) => tbody.appendChild(r));
            applyFilter();
        }

        // Status filter and search box together: a row shows when it passes both.
        const searchBox = document.getElementById('search');
        function applyFilter() {
            const q = searchBox.value.trim().toLowerCase();
            rows.forEach((r) => {
                const s = r.dataset.speed;
                const dbDown = r.dataset.db === '0';
                const show = filter === 'all'
                    || (filter === 'problem' && (s === 'DOWN' || s === 'ERROR' || s === 'SLOW' || dbDown))
                    || filter === s || (filter === 'DOWN' && s === 'ERROR');
                // The first cell holds "code - name" and the IP.
                const match = !q || r.cells[0].textContent.toLowerCase().includes(q);
                r.classList.toggle('hidden', !show || !match);
            });
        }
        searchBox.addEventListener('input', applyFilter);

        async function checkBranch(row) {
            const bcode = row.dataset.bcode;
            const btn = row.querySelector('.reload-btn');
            btn.disabled = true;
            setCells(row, { 'status-cell': '<span class="text-amber-500 whitespace-nowrap">' + SPINNER + ' Checking…</span>' });

            try {
                const resp = await fetch('?' + new URLSearchParams({ check: 1, bcode: bcode, size: sizeSel.value }));
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const d = await resp.json();
                if (results[bcode]) previous[bcode] = results[bcode].ping.avg;
                results[bcode] = d;
                row.dataset.speed = d.speed;
                row.dataset.db = d.db.up ? '1' : '0';
                render(row, d);
            } catch (err) {
                results[bcode] = { bcode, status: 'DOWN', speed: 'ERROR', ping: { avg: null }, db: null };
                row.dataset.speed = 'ERROR';
                setCells(row, { 'status-cell': `<span title="${esc(err.message)}">${pill('ERROR')}</span>` });
            } finally {
                btn.disabled = false;
                updateSummary();
                row.classList.remove('row-in');
                void row.offsetWidth;
                row.classList.add('row-in');
            }
        }

        let hideBarTimer;
        let running = false;

        async function runAll() {
            if (running) return;
            running = true;
            runBtn.disabled = true;

            const queue = rows.slice();
            let done = 0;
            progressEl.textContent = `0 / ${queue.length}`;
            clearTimeout(hideBarTimer);
            progressTrack.classList.remove('opacity-0');
            progressBar.style.transform = 'scaleX(0)';

            async function worker() {
                while (queue.length) {
                    await checkBranch(queue.shift());
                    progressEl.textContent = `${++done} / ${rows.length}`;
                    progressBar.style.transform = `scaleX(${done / rows.length})`;
                }
            }

            await Promise.all(Array.from({ length: CONCURRENCY }, worker));
            sortRows();
            document.getElementById('lastRun').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            running = false;
            runBtn.disabled = false;
            hideBarTimer = setTimeout(() => progressTrack.classList.add('opacity-0'), 400);
        }

        runBtn.addEventListener('click', runAll);
        sizeSel.addEventListener('change', () => {
            try { localStorage.setItem('net.size', sizeSel.value); } catch (e) {}
        });
        rows.forEach((row) => row.querySelector('.reload-btn').addEventListener('click', async () => {
            await checkBranch(row);
            applyFilter();
        }));
        document.getElementById('filters').addEventListener('click', (e) => {
            const b = e.target.closest('button[data-filter]');
            if (!b) return;
            filter = b.dataset.filter;
            document.querySelectorAll('#filters button').forEach((x) => {
                const on = x === b;
                x.classList.toggle('bg-slate-900', on);
                x.classList.toggle('text-white', on);
                x.classList.toggle('font-semibold', on);
                x.classList.toggle('text-slate-600', !on);
            });
            applyFilter();
        });

        runAll();
    })();
</script>
<?php include __DIR__ . '/footer.php'; ?>
