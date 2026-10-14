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

$bcode  = trim($_GET['bcode'] ?? '');
$iccode = trim($_GET['iccode'] ?? '');

if (!isset($connections[$bcode]) || $iccode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit();
}

$bconn = card_connect_branch($bcode, $connections);
if (!$bconn) {
    $e = oci_error();
    echo json_encode(['bcode' => $bcode, 'connected' => false, 'error' => $e['message'] ?? 'Connection failed']);
    exit();
}

// Case-insensitive so "a-r-ng-370" finds "A-R-NG-370"; the stored code is returned.
$cards = card_query_all($bconn, "
    SELECT i.ICCODE, i.IC_CCODE, c.IC_CNAME, i.ICSTATUS, i.ICFORM_NO,
           TO_CHAR(i.ICISSUE_DATE, 'DD-Mon-YYYY') ISSUE, TO_CHAR(i.ICEXPIRY, 'DD-Mon-YYYY') EXPIRY
    FROM ICARDS i
    LEFT JOIN ICARDS_CATEGORY c ON c.IC_CCODE = i.IC_CCODE
    WHERE UPPER(TRIM(i.ICCODE)) = UPPER(:k)", [':k' => $iccode]);

$result = ['bcode' => $bcode, 'connected' => true, 'found' => !empty($cards)];

if ($cards) {
    $card    = $cards[0];
    $holders = card_query_all($bconn, "
        SELECT h.RCODE, r.RNAME, h.ICHECODE, h.ICHENAME, h.ICHSTATUS
        FROM IC_HOLDERS h
        LEFT JOIN RELATIONS r ON r.RCODE = h.RCODE
        WHERE h.ICCODE = :k
        ORDER BY CASE WHEN h.RCODE = 'SL' THEN 0 ELSE 1 END, h.ICHECODE", [':k' => $card['ICCODE']]);

    $self = '';
    foreach ($holders as $h) {
        if ($h['RCODE'] === 'SL') {
            $self = $h['ICHENAME'];
            break;
        }
    }

    $result['card']    = $card + ['HOLDER' => $self];
    $result['holders'] = $holders;
}

oci_close($bconn);
echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
