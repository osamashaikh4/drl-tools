<?php
require_once __DIR__ . '/../bootstrap.php';

// Hosts and the shared admin account live in config.php (not committed; see config.example.php).
$configFile = __DIR__ . '/config.php';
$config     = is_file($configFile) ? require $configFile : ['user' => '', 'password' => '', 'hosts' => []];
$hosts      = array_values($config['hosts'] ?? []);

// Run one of this folder's PowerShell scripts against a host and return its JSON answer as an array.
// The host and credentials go in through the environment, keeping the password off the command line.
function run_host_script(string $script, array $host, array $config, array $extraEnv = []): array
{
    $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg(__DIR__ . '/' . $script);
    $env = array_merge(getenv(), [
        'HV_HOST' => (string) $host['host'],
        'HV_USER' => (string) ($config['user'] ?? ''),
        'HV_PASS' => (string) ($config['password'] ?? ''),
    ], $extraEnv);

    $started = microtime(true);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Could not start PowerShell'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // The script prints one JSON object; anything around it (a BOM, warnings) is ignored.
    $start  = strpos($out, '{');
    $result = $start === false ? null : json_decode(substr($out, $start), true);
    if (!is_array($result)) {
        $result = ['ok' => false, 'error' => trim($err) ?: (trim($out) ?: 'No answer from PowerShell')];
    }
    $result['seconds'] = round(microtime(true) - $started, 1);

    return $result;
}

// ?check=<host>              replication of every VM on the host (replication.ps1)
// ?details=<host>&vm=<Id>    one VM's summary, replication settings and statistics, recent jobs (details.ps1)
if (isset($_GET['check']) || isset($_GET['details'])) {
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Logged out']);
        exit();
    }

    // Release the session lock so every host from one browser is checked at the same time.
    session_write_close();
    set_time_limit(180);

    $i = (int) ($_GET['check'] ?? $_GET['details']);
    if (!isset($hosts[$i])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown host']);
        exit();
    }

    if (isset($_GET['details'])) {
        $vm = (string) ($_GET['vm'] ?? '');
        if (!preg_match('/^[0-9A-Fa-f-]{36}$/', $vm)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid VM id']);
            exit();
        }
        echo json_encode(run_host_script('details.ps1', $hosts[$i], $config, ['HV_VM' => $vm]));
    } else {
        echo json_encode(run_host_script('replication.ps1', $hosts[$i], $config));
    }
    exit();
}

require_login();
include __DIR__ . '/../header.php';
?>

<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">VM Replication</h1>
            <p class="text-sm text-slate-500">Hyper-V replication health of every VM on each host: the same data as <span class="font-mono">Get-VMReplication</span>, read over WMI. Open a VM's Details for its statistics and recent jobs.</p>
        </div>
        <button id="runAll" <?= $hosts ? '' : 'disabled' ?> class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-700 text-sm font-semibold text-white shadow disabled:opacity-60 disabled:cursor-not-allowed">Check All</button>
    </div>

    <?php if (!$hosts): ?>
        <div class="p-5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900 space-y-2">
            <p class="font-semibold">No Hyper-V hosts configured yet.</p>
            <p>Copy <span class="font-mono">vm-replication/config.example.php</span> to <span class="font-mono">vm-replication/config.php</span>, then fill in the admin account and the host list.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Hosts reached</div>
                <div class="text-2xl font-bold tabular-nums text-slate-800"><span id="sumHosts">0</span><span class="text-sm text-slate-400 font-semibold"> / <?= count($hosts) ?></span></div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">VMs</div>
                <div class="text-2xl font-bold tabular-nums text-slate-800" id="sumVms">0</div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Normal</div>
                <div class="text-2xl font-bold tabular-nums text-emerald-600" id="sumNormal">0</div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Warning</div>
                <div class="text-2xl font-bold tabular-nums text-amber-600" id="sumWarning">0</div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Critical</div>
                <div class="text-2xl font-bold tabular-nums text-red-600" id="sumCritical">0</div>
            </div>
        </div>

        <div class="space-y-5">
            <?php foreach ($hosts as $i => $h): ?>
                <section class="host bg-white rounded-2xl border border-slate-200 shadow-sm" data-i="<?= $i ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
                        <div>
                            <h2 class="text-base font-bold text-slate-900"><?= htmlspecialchars($h['name'] ?? $h['host']) ?></h2>
                            <p class="text-xs font-mono text-slate-500"><?= htmlspecialchars($h['host']) ?><span class="host-name"></span></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="host-status text-xs text-slate-500 tabular-nums"></span>
                            <button type="button" class="recheck text-xs font-semibold text-slate-600 hover:text-slate-900 underline underline-offset-2">Recheck</button>
                        </div>
                    </div>
                    <div class="host-body px-5 py-4 text-sm text-slate-400">Not checked yet.</div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    $(function() {
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const results = {}; // host index -> last answer

        const HEALTH = {
            Normal: 'bg-emerald-50 text-emerald-700 border-emerald-200',
            Warning: 'bg-amber-50 text-amber-700 border-amber-200',
            Critical: 'bg-red-50 text-red-700 border-red-200',
        };
        const badge = (health) => `<span class="inline-block px-2 py-0.5 rounded-md border text-xs font-semibold ${HEALTH[health] || 'bg-slate-50 text-slate-600 border-slate-200'}">${esc(health || 'Unknown')}</span>`;
        // How long ago the last replication was, e.g. " · 1 h 2 min ago" (times are the host's local time).
        function ago(when) {
            if (!when) return '';
            const min = Math.round((Date.now() - new Date(when.replace(' ', 'T'))) / 60000);
            if (!(min >= 0)) return '';
            const text = min < 60 ? min + ' min' : Math.floor(min / 60) + ' h ' + (min % 60) + ' min';
            return ' <span class="text-slate-400">· ' + text + ' ago</span>';
        }
        const every = (sec) => !sec ? '—' : sec < 60 ? sec + ' s' : Math.round(sec / 60) + ' min';

        function vmTable(vms) {
            if (!vms.length) return '<p class="text-slate-500">No replicated VMs on this host.</p>';
            // Worst health first so problems are at the top.
            const rank = { Critical: 0, Warning: 1, Normal: 2 };
            const rows = vms.slice().sort((a, b) => (rank[a.Health] ?? 3) - (rank[b.Health] ?? 3) || String(a.VMName).localeCompare(b.VMName));
            return `<div class="overflow-x-auto -mx-5"><table class="w-full text-sm">
                <thead class="text-xs text-slate-500 uppercase"><tr class="border-b border-slate-100">
                    <th class="px-5 py-2 text-left">VM</th><th class="px-3 py-2 text-left">Health</th><th class="px-3 py-2 text-left">State</th>
                    <th class="px-3 py-2 text-left">Mode</th><th class="px-3 py-2 text-left">Primary → Replica</th>
                    <th class="px-3 py-2 text-right">Every</th><th class="px-3 py-2 text-right">Last replication</th><th class="px-5 py-2"></th>
                </tr></thead>
                <tbody>${rows.map((v) => `<tr class="vm-row border-t border-slate-100 row-in" data-vm="${esc(v.Id)}">
                    <td class="px-5 py-2 font-semibold text-slate-900">${esc(v.VMName)}</td>
                    <td class="px-3 py-2">${badge(v.Health)}</td>
                    <td class="px-3 py-2 text-slate-700">${esc(v.State)}</td>
                    <td class="px-3 py-2 text-slate-700">${esc(v.Mode)}</td>
                    <td class="px-3 py-2 font-mono text-xs text-slate-600">${esc(v.PrimaryServer)} → ${esc(v.ReplicaServer)}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-600">${every(v.FrequencySec)}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-600 whitespace-nowrap">${esc(v.LastReplicationTime || '—')}${ago(v.LastReplicationTime)}</td>
                    <td class="px-5 py-2 text-right"><button type="button" class="vm-details text-xs font-semibold text-violet-700 hover:text-violet-500 whitespace-nowrap" aria-expanded="false">Details ▾</button></td>
                </tr>`).join('')}</tbody></table></div>`;
        }

        // --- Details of one VM, loaded when its row is opened -----------------------------------
        const fmtBytes = (b) => {
            if (b == null) return '—';
            const u = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0;
            while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
            return (i ? b.toFixed(1) : b) + ' ' + u[i];
        };
        const fmtUptime = (sec) => {
            if (!sec) return '—';
            const d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
            return (d ? d + ' d ' : '') + h + ' h ' + m + ' min';
        };
        const dl = (pairs) => '<dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">' + pairs
            .filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([k, v]) => `<dt class="text-slate-500">${esc(k)}</dt><dd class="text-slate-800 tabular-nums break-words">${v}</dd>`).join('') + '</dl>';
        const panel = (title, body) => `<div class="min-w-0"><h4 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">${title}</h4><div class="text-xs">${body}</div></div>`;
        const count = (n, bad) => `<span class="${n && bad ? 'text-red-600 font-semibold' : ''}">${n}</span>`;

        function detailsHtml(d) {
            const vm = d.vm || {}, st = d.settings, s = d.stats;
            const running = vm.State === 'Running';
            const vmPanel = panel('Virtual machine', dl([
                ['State', esc(vm.State) + (vm.Status ? ' <span class="text-slate-400">· ' + esc(vm.Status) + '</span>' : '')],
                ['Uptime', running ? fmtUptime(vm.UptimeSec) : null],
                ['CPUs', esc(vm.Processors) + (running && vm.ProcessorLoad != null ? ' <span class="text-slate-400">· ' + esc(vm.ProcessorLoad) + '% load</span>' : '')],
                ['Memory', running && vm.MemoryMB ? fmtBytes(vm.MemoryMB * 1048576) + (vm.MemoryAvailable != null ? ' <span class="text-slate-400">· ' + esc(vm.MemoryAvailable) + '% free</span>' : '') : null],
                ['Heartbeat', running ? esc(vm.Heartbeat || '—') : null],
                ['Guest OS', vm.GuestOS ? esc(vm.GuestOS) : null],
            ]) + (running ? '' : '<p class="mt-2 text-slate-400">A replica copy is normally off.</p>'));

            const setPanel = panel('Replication settings', st ? dl([
                ['Primary', '<span class="font-mono">' + esc(st.PrimaryServer || '—') + '</span>'],
                ['Replica', '<span class="font-mono">' + esc(st.ReplicaServer || '—') + '</span>' + (st.Port ? ' <span class="text-slate-400">: ' + esc(st.Port) + '</span>' : '')],
                ['Authentication', esc(st.Authentication)],
                ['Frequency', every(st.FrequencySec)],
                ['Recovery points', st.RecoveryPoints ? esc(st.RecoveryPoints) + ' extra' : 'Latest only'],
                ['VSS snapshots', st.VssSnapshotHours ? 'every ' + esc(st.VssSnapshotHours) + ' h' : 'Off'],
                ['Compression', st.Compression ? 'On' : 'Off'],
            ]) : '<p class="text-slate-400">No settings found.</p>');

            const statPanel = panel('Replication statistics', s ? dl([
                ['Period', esc(s.From || '—') + ' → ' + esc(s.To || '—')],
                ['Health', badge(s.Health)],
                ['Successful', count(s.Successful)],
                ['Missed', count(s.Missed, true)],
                ['Failed', count(s.Failed, true)],
                ['Network failures', count(s.NetworkFailures, true)],
                ['VSS failures', count(s.VssFailures, true)],
                ['Latency', s.AvgLatencySec ? esc(s.AvgLatencySec) + ' s' : '—'],
                ['Replicated', fmtBytes(s.TotalBytes) + (s.Successful ? ' <span class="text-slate-400">· avg ' + fmtBytes(s.TotalBytes / s.Successful) + '</span>' : '')],
                ['Largest cycle', fmtBytes(s.MaxBytes)],
                ['Pending', s.PendingBytes ? '<span class="font-semibold text-amber-700">' + fmtBytes(s.PendingBytes) + '</span>' : '0'],
                ['Last test failover', s.LastTestFailover ? esc(s.LastTestFailover) : null],
            ]) : '<p class="text-slate-400">No statistics.</p>');

            const jobs = d.jobs || [];
            const JOB = { Completed: 'text-emerald-700', Running: 'text-blue-700', Exception: 'text-red-700', Killed: 'text-red-700', Terminated: 'text-red-700' };
            const jobPanel = panel('Recent Hyper-V jobs <span class="normal-case font-normal">(the host keeps only recent ones)</span>', jobs.length
                ? `<div class="max-h-64 overflow-y-auto border border-slate-200 rounded-lg bg-white"><table class="w-full"><tbody>${jobs.map((j) => `<tr class="border-t border-slate-100 first:border-t-0">
                    <td class="px-2 py-1 text-slate-500 tabular-nums whitespace-nowrap">${esc(j.Started || '')}</td>
                    <td class="px-2 py-1 text-slate-800">${esc(j.Name)}${j.Error ? '<div class="text-red-700">' + esc(j.Error) + '</div>' : ''}</td>
                    <td class="px-2 py-1 whitespace-nowrap ${JOB[j.State] || 'text-slate-600'}">${esc(j.State)}${j.State === 'Running' && j.Percent != null ? ' ' + esc(j.Percent) + '%' : ''}</td>
                    <td class="px-2 py-1 text-right text-slate-500 tabular-nums whitespace-nowrap">${j.ElapsedSec != null ? esc(j.ElapsedSec) + ' s' : ''}</td>
                </tr>`).join('')}</tbody></table></div>`
                : '<p class="text-slate-400">No recent jobs on this VM.</p>');

            return `<div class="grid grid-cols-1 md:grid-cols-3 gap-6">${vmPanel}${setPanel}${statPanel}</div><div class="mt-5">${jobPanel}</div>`;
        }

        // Opening a row loads its details (fresh each time); closing hides them.
        $(document).on('click', '.vm-details', function() {
            const $btn = $(this), $row = $btn.closest('.vm-row'), $next = $row.next('.vm-detail-row');
            if ($next.length) {
                $next.remove();
                $btn.attr('aria-expanded', 'false').text('Details ▾');
                return;
            }
            $btn.attr('aria-expanded', 'true').text('Hide ▴');
            const $cell = $('<td colspan="8" class="px-5 py-4 bg-slate-50 border-t border-slate-100"></td>').html(SPINNER + ' <span class="text-xs text-slate-500">Loading details…</span>');
            $('<tr class="vm-detail-row"></tr>').append($cell).insertAfter($row);
            $.ajax({ url: 'index.php', data: { details: $row.closest('.host').data('i'), vm: $row.data('vm') }, dataType: 'json', timeout: 200000 })
                .done((d) => $cell.html(d.ok ? detailsHtml(d) : '<p class="text-xs text-red-700">' + esc(d.error) + '</p>'))
                .fail((xhr) => $cell.html('<p class="text-xs text-red-700">' + esc(xhr.statusText === 'timeout' ? 'Loading details timed out' : 'Could not load details') + '</p>'));
        });

        function updateSummary() {
            let hosts = 0, vms = 0;
            const count = { Normal: 0, Warning: 0, Critical: 0 };
            Object.values(results).forEach((r) => {
                if (!r.ok) return;
                hosts++;
                (r.vms || []).forEach((v) => { vms++; if (v.Health in count) count[v.Health]++; });
            });
            $('#sumHosts').text(hosts);
            $('#sumVms').text(vms);
            $('#sumNormal').text(count.Normal);
            $('#sumWarning').text(count.Warning);
            $('#sumCritical').text(count.Critical);
        }

        function check($host) {
            const i = $host.data('i');
            const $status = $host.find('.host-status'), $body = $host.find('.host-body');
            $status.html(SPINNER + ' Checking…');
            $host.find('.recheck').prop('disabled', true);
            return $.ajax({ url: 'index.php', data: { check: i }, dataType: 'json', timeout: 200000 })
                .done(function(r) {
                    results[i] = r;
                    if (r.ok) {
                        $host.find('.host-name').text(r.hostName ? ' · ' + r.hostName : '');
                        $status.text('Checked ' + r.checked + ' · ' + r.seconds + 's');
                        $body.removeClass('text-slate-400').html(vmTable(r.vms || []));
                    } else {
                        $status.html('<span class="text-red-600 font-semibold">Failed</span> · ' + esc(r.seconds) + 's');
                        $body.removeClass('text-slate-400').html('<p class="text-red-700 whitespace-pre-wrap break-words">' + esc(r.error) + '</p>');
                    }
                })
                .fail(function(xhr) {
                    results[i] = { ok: false };
                    $status.html('<span class="text-red-600 font-semibold">Failed</span>');
                    $body.removeClass('text-slate-400').html('<p class="text-red-700">' + esc(xhr.statusText === 'timeout' ? 'The check timed out' : 'The check failed') + '</p>');
                })
                .always(function() {
                    $host.find('.recheck').prop('disabled', false);
                    updateSummary();
                });
        }

        // All hosts at once; each finishes on its own.
        function checkAll() {
            const $run = $('#runAll').prop('disabled', true).html(SPINNER + ' Checking…');
            $.when(...$('.host').map((_, el) => check($(el))).get()).always(() => $run.prop('disabled', false).text('Check All'));
        }

        $('#runAll').on('click', checkAll);
        $(document).on('click', '.recheck', function() { check($(this).closest('.host')); });
        if ($('.host').length) checkAll();
    });
</script>
</body>

</html>
