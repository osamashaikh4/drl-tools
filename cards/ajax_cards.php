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

// One option per card: code, cardholder (Self) name, category and how many holders it has.
$rows = card_query_all($srcConn, "
    SELECT i.ICCODE, i.ICSTATUS, c.IC_CNAME,
           (SELECT MAX(h.ICHENAME) FROM IC_HOLDERS h WHERE h.ICCODE = i.ICCODE AND h.RCODE = 'SL') HOLDER,
           (SELECT COUNT(*) FROM IC_HOLDERS h WHERE h.ICCODE = i.ICCODE) HOLDERS
    FROM ICARDS i
    LEFT JOIN ICARDS_CATEGORY c ON c.IC_CCODE = i.IC_CCODE
    ORDER BY i.ICCODE");

$cards = [];
foreach ($rows as $r) {
    $label = $r['ICCODE'] . ' — ' . trim((string)$r['HOLDER']) . ' (' . trim((string)$r['IC_CNAME']) . ', ' . $r['HOLDERS'] . ' holder' . ($r['HOLDERS'] == 1 ? '' : 's') . ')';
    if (strtoupper((string)$r['ICSTATUS']) === 'N') {
        $label .= ' [inactive]';
    }
    $cards[] = ['id' => $r['ICCODE'], 'text' => $label];
}

echo json_encode($cards, JSON_INVALID_UTF8_SUBSTITUTE);
