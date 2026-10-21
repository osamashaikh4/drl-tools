<?php
require_once __DIR__ . '/../bootstrap.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

// JSON API for this page: ?api=list (GET) or ?api=kill (POST), with bcode + ecode.
// An employee's database account is named after their ECODE. Reading V$SESSION and killing
// sessions needs the privileged account, as in Manage User Account.
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

    $bcode = trim((string) ($_REQUEST['bcode'] ?? ''));
    $user  = strtoupper(trim((string) ($_REQUEST['ecode'] ?? '')));
    if (!isset($connections[$bcode])) {
        $reply(['ok' => false, 'error' => 'Unknown branch'], 400);
    }
    if (!preg_match('/^[A-Z0-9_]{1,30}$/', $user)) {
        $reply(['ok' => false, 'error' => 'Invalid employee'], 400);
    }

    $db = connect_branch_db($bcode, $connections, $admin_user, $admin_pass);
    if (!$db) {
        $e = oci_error();
        $reply(['ok' => false, 'error' => "Could not connect to branch {$bcode}: " . ($e['message'] ?? 'unknown error')]);
    }

    $fetch = function (string $sql, array $binds) use ($db) {
        $st = oci_parse($db, $sql);
        foreach ($binds as $k => $v) {
            oci_bind_by_name($st, $k, $binds[$k]);
        }
        if (!@oci_execute($st)) {
            $e = oci_error($st);
            throw new RuntimeException($e['message'] ?? 'Query failed');
        }
        $rows = [];
        while ($r = oci_fetch_array($st, OCI_ASSOC + OCI_RETURN_NULLS)) {
            $rows[] = $r;
        }
        return $rows;
    };

    // Sessions of this user; "blocking" counts the other sessions waiting on each one.
    $sessions = function () use ($fetch, $user) {
        $rows = $fetch(
            "SELECT s.SID, s.SERIAL# AS SERIAL, s.STATUS, s.OSUSER, s.MACHINE, s.PROGRAM, s.MODULE,
                    TO_CHAR(s.LOGON_TIME, 'YYYY-MM-DD HH24:MI:SS') AS LOGON, s.LAST_CALL_ET AS IDLE,
                    s.EVENT, (SELECT COUNT(*) FROM V\$SESSION w WHERE w.BLOCKING_SESSION = s.SID) AS BLOCKING
               FROM V\$SESSION s
              WHERE s.USERNAME = :u AND s.TYPE = 'USER'
              ORDER BY s.LOGON_TIME",
            [':u' => $user]
        );
        return array_map(fn($r) => [
            'sid'      => (int) $r['SID'],
            'serial'   => (int) $r['SERIAL'],
            'status'   => $r['STATUS'],
            'osuser'   => $r['OSUSER'],
            'machine'  => $r['MACHINE'],
            'program'  => $r['PROGRAM'] ?: $r['MODULE'],
            'logon'    => $r['LOGON'],
            'idleSec'  => (int) $r['IDLE'],
            'event'    => $r['EVENT'],
            'blocking' => (int) $r['BLOCKING'],
        ], $rows);
    };

    try {
        if ($_GET['api'] === 'list') {
            $reply(['ok' => true, 'sessions' => $sessions()]);
        }

        if ($_GET['api'] === 'kill') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $reply(['ok' => false, 'error' => 'Wrong request method'], 405);
            }
            // Only sessions that belong to this user right now can be killed.
            $own = [];
            foreach ($sessions() as $s) {
                $own[$s['sid'] . ',' . $s['serial']] = true;
            }
            $results = [];
            foreach ((array) ($_POST['sessions'] ?? []) as $id) {
                $id = (string) $id;
                if (!preg_match('/^\d+,\d+$/', $id) || !isset($own[$id])) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => 'Not a session of this user (it may have ended)'];
                    continue;
                }
                $st = oci_parse($db, "ALTER SYSTEM KILL SESSION '{$id}' IMMEDIATE");
                $ok = @oci_execute($st);
                $e  = $ok ? null : (oci_error($st)['message'] ?? 'Failed');
                $results[] = ['id' => $id, 'ok' => $ok, 'error' => $e];
            }
            $reply(['ok' => true, 'results' => $results, 'sessions' => $sessions()]);
        }
    } catch (Throwable $e) {
        $reply(['ok' => false, 'error' => $e->getMessage()]);
    }
    $reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

require_login();
include __DIR__ . '/header.php';
$branches = get_branches_list($conn, $connections);
?>

<div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">← Back to Dashboard</a>
        <h1 class="text-2xl font-bold text-slate-900">Kill User Sessions</h1>
        <p class="text-sm text-slate-500">End an employee's database sessions on a branch, e.g. a hung or locked login.</p>
    </div>

    <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Branch <span class="text-red-500">*</span></label>
                <select id="branch" class="branch_select w-full">
                    <option value="">Select branch</option>
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>" <?= $bcode == '01' ? 'selected' : '' ?>><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Employee <span class="text-red-500">*</span></label>
                <select id="employee" disabled class="user_select w-full"><option value="">Select a branch first...</option></select>
                <p id="employeeHint" class="text-xs text-slate-500 mt-1.5 tabular-nums"></p>
            </div>
        </div>
    </div>

    <div id="sessionsCard" class="hidden mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">Sessions <span id="count" class="font-normal text-sm text-slate-500"></span></h2>
            <div class="flex gap-2">
                <button type="button" id="refresh" class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-100">Refresh</button>
                <button type="button" id="killSelected" disabled class="px-3 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-xs font-semibold text-white disabled:opacity-50">Kill selected</button>
                <button type="button" id="killAll" disabled class="px-3 py-2 rounded-lg border border-red-200 bg-white text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50">Kill all</button>
            </div>
        </div>
        <div id="sessions" class="overflow-x-auto text-sm"></div>
    </div>
</div>

<script>
    $(function() {
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s == null ? '' : String(s)).html();
        const toast = (ok, message) => iziToast[ok ? 'success' : 'error']({ title: ok ? 'Done' : 'Failed', message, position: 'topRight' });
        const idle = (s) => s < 60 ? s + ' s' : s < 3600 ? Math.floor(s / 60) + ' min' : Math.floor(s / 3600) + ' h ' + Math.floor(s % 3600 / 60) + ' min';
        let current = []; // sessions on screen

        $('.branch_select').select2({ placeholder: 'Select branch' });
        $('.user_select').select2({ placeholder: 'Select a branch first...', allowClear: true });
        const $employee = $('#employee'), $hint = $('#employeeHint');
        let token = 0;

        function loadEmployees(bcode) {
            const t = ++token;
            $employee.prop('disabled', true).empty().append(new Option(bcode ? 'Loading employees...' : 'Select a branch first...', '', true, true)).trigger('change');
            $('#sessionsCard').addClass('hidden');
            if (!bcode) return $hint.text('');
            $hint.html(SPINNER + ' Loading employees from branch ' + esc(bcode) + '…');
            $.getJSON('ajax_employees.php', { bcode }).done((data) => {
                if (t !== token) return;
                const frag = document.createDocumentFragment();
                frag.appendChild(new Option('Search or select employee...', '', true, true));
                data.forEach((e) => frag.appendChild(new Option(e.ename + ' (' + e.ecode + ')', e.ecode, false, false)));
                $employee.empty()[0].appendChild(frag);
                $employee.prop('disabled', false).trigger('change');
                $hint.text(data.length.toLocaleString('en-US') + ' employees on branch ' + bcode + '.');
            }).fail(() => { if (t === token) $hint.html('<span class="text-red-600">Could not load employees from branch ' + esc(bcode) + '.</span>'); });
        }
        $('#branch').on('change', function() { loadEmployees($(this).val()); });
        $employee.on('change', () => $employee.val() ? loadSessions() : $('#sessionsCard').addClass('hidden'));

        function params() { return { bcode: $('#branch').val(), ecode: $employee.val() }; }

        function render(list) {
            current = list;
            $('#count').text('(' + list.length + ')');
            $('#killAll').prop('disabled', !list.length);
            $('#killSelected').prop('disabled', true);
            $('#sessions').html(`<table class="w-full">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr>
                    <th class="px-4 py-2 w-8"><input type="checkbox" id="checkAll" class="w-4 h-4 rounded border-slate-300"></th>
                    <th class="px-3 py-2 text-left">SID, serial</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">PC / Windows user</th>
                    <th class="px-3 py-2 text-left">Program</th><th class="px-3 py-2 text-left">Logged on</th><th class="px-4 py-2 text-right">Idle</th>
                </tr></thead>
                <tbody>${list.map((s) => `<tr class="border-t border-slate-100 ${s.blocking ? 'bg-red-50' : ''}">
                    <td class="px-4 py-2"><input type="checkbox" class="pick w-4 h-4 rounded border-slate-300" value="${s.sid},${s.serial}"></td>
                    <td class="px-3 py-2 font-mono text-xs">${s.sid}, ${s.serial}</td>
                    <td class="px-3 py-2"><span class="text-xs font-semibold ${s.status === 'ACTIVE' ? 'text-emerald-700' : 'text-slate-500'}">${esc(s.status)}</span>
                        ${s.blocking ? `<div class="text-xs font-semibold text-red-700">Blocking ${s.blocking} other session${s.blocking > 1 ? 's' : ''}</div>` : ''}</td>
                    <td class="px-3 py-2 text-xs"><div class="text-slate-800">${esc(s.machine)}</div><div class="text-slate-500">${esc(s.osuser)}</div></td>
                    <td class="px-3 py-2 text-xs text-slate-700">${esc(s.program)}</td>
                    <td class="px-3 py-2 text-xs tabular-nums text-slate-600 whitespace-nowrap">${esc(s.logon)}</td>
                    <td class="px-4 py-2 text-right text-xs tabular-nums text-slate-600">${idle(s.idleSec)}</td>
                </tr>`).join('') || '<tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No sessions for this employee on this branch.</td></tr>'}</tbody>
            </table>`);
        }

        function loadSessions() {
            $('#sessionsCard').removeClass('hidden');
            $('#sessions').html('<p class="px-5 py-6 text-slate-500">' + SPINNER + ' Reading sessions…</p>');
            $.getJSON('kill_session.php?api=list', params())
                .done((r) => r.ok ? render(r.sessions) : $('#sessions').html('<p class="px-5 py-6 text-red-700">' + esc(r.error) + '</p>'))
                .fail(() => $('#sessions').html('<p class="px-5 py-6 text-red-700">Request failed.</p>'));
        }
        $('#refresh').on('click', loadSessions);

        $(document).on('change', '#checkAll', function() { $('.pick').prop('checked', this.checked); $('#killSelected').prop('disabled', !this.checked); });
        $(document).on('change', '.pick', () => $('#killSelected').prop('disabled', !$('.pick:checked').length));

        function kill(ids) {
            if (!ids.length) return;
            const who = $employee.find(':selected').text();
            if (!confirm(`Kill ${ids.length} session${ids.length > 1 ? 's' : ''} of ${who} on branch ${$('#branch').val()}? Unsaved work in those sessions is lost.`)) return;
            $('#killSelected, #killAll').prop('disabled', true);
            $.post('kill_session.php?api=kill', Object.assign(params(), { sessions: ids }), null, 'json').done((r) => {
                if (!r.ok) return toast(false, r.error);
                const done = r.results.filter((x) => x.ok).length, failed = r.results.filter((x) => !x.ok);
                toast(!failed.length, done + ' killed' + (failed.length ? ', ' + failed.length + ' failed: ' + failed.map((f) => f.error).join('; ') : ''));
                render(r.sessions);
            }).fail(() => { toast(false, 'Request failed'); loadSessions(); });
        }
        $('#killSelected').on('click', () => kill($('.pick:checked').map((_, el) => el.value).get()));
        $('#killAll').on('click', () => kill(current.map((s) => s.sid + ',' + s.serial)));

        loadEmployees($('#branch').val());
    });
</script>
</body>

</html>
