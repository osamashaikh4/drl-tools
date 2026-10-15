<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

// Release the session lock so the parallel branch requests from one browser run concurrently.
session_write_close();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/card_functions.php';

$bcode = trim($_GET['bcode'] ?? '');
$srcConn = card_connect_branch($bcode, $connections);

if (!$srcConn) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not connect to branch ' . $bcode]);
    exit();
}

const CARD_SEARCH_LIMIT = 50;

// ?count=1 — how many cards the branch has (also proves the branch is reachable).
if (isset($_GET['count'])) {
    $rows = card_query_all($srcConn, "SELECT COUNT(*) N FROM ICARDS");
    echo json_encode(['total' => (int)($rows[0]['N'] ?? 0)]);
    exit();
}

// ?q=… — cards whose code or cardholder (Self) name contains the text, best matches first.
$q = strtoupper(trim((string)($_GET['q'] ?? '')));
if ($q === '') {
    echo json_encode(['results' => []]);
    exit();
}
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$rows = card_query_all($srcConn, "
    SELECT * FROM (
        SELECT i.ICCODE, i.ICSTATUS, c.IC_CNAME, h.ICHENAME HOLDER,
               (SELECT COUNT(*) FROM IC_HOLDERS x WHERE x.ICCODE = i.ICCODE) HOLDERS
        FROM ICARDS i
        LEFT JOIN ICARDS_CATEGORY c ON c.IC_CCODE = i.IC_CCODE
        LEFT JOIN IC_HOLDERS h ON h.ICCODE = i.ICCODE AND h.RCODE = 'SL'
        WHERE UPPER(i.ICCODE) LIKE :q ESCAPE '\\' OR UPPER(h.ICHENAME) LIKE :q ESCAPE '\\'
        ORDER BY CASE WHEN UPPER(i.ICCODE) LIKE :p ESCAPE '\\' THEN 0 ELSE 1 END, i.ICCODE
    ) WHERE ROWNUM <= :n", [':q' => $like, ':p' => $prefix, ':n' => CARD_SEARCH_LIMIT + 1]);

// One option per card: code, cardholder (Self) name, category and how many holders it has.
$cards = [];
foreach ($rows as $r) {
    if (isset($cards[$r['ICCODE']])) {
        continue; // a card with two "Self" holders appears once
    }
    $label = $r['ICCODE'] . ' — ' . trim((string)$r['HOLDER']) . ' (' . trim((string)$r['IC_CNAME']) . ', ' . $r['HOLDERS'] . ' holder' . ($r['HOLDERS'] == 1 ? '' : 's') . ')';
    if (strtoupper((string)$r['ICSTATUS']) === 'N') {
        $label .= ' [inactive]';
    }
    $cards[$r['ICCODE']] = ['id' => $r['ICCODE'], 'text' => $label];
}

$more = count($cards) > CARD_SEARCH_LIMIT;
echo json_encode(['results' => array_slice(array_values($cards), 0, CARD_SEARCH_LIMIT), 'more' => $more], JSON_INVALID_UTF8_SUBSTITUTE);
