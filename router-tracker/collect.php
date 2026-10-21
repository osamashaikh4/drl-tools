<?php
// Background collector for Router Activity Tracker. Run once a minute (Task Scheduler):
//   C:\xampp\php\php.exe C:\xampp\htdocs\drl-tools\router-tracker\collect.php
// Each run samples every router every 'interval' seconds for about a minute, then exits.
// Command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require __DIR__ . '/tracker.php';

$config = rt_config();

// One collector at a time: a run that overlaps the previous one just exits.
$lock = fopen(RT_DATA . '/collect.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$until = time() + 55;
do {
    $started = time();
    foreach ($config['routers'] as $key => $router) {
        $r = rt_sample((string) $key, $router, $config['interval'] - 2);
        if (isset($r['error'])) {
            fwrite(STDERR, date('H:i:s') . " {$key}: {$r['error']}\n");
        }
    }
    $wait = $config['interval'] - (time() - $started);
    if (time() + $wait > $until) {
        break;
    }
    sleep(max(1, $wait));
} while (time() < $until);

// Old history is trimmed once an hour.
if ((int) date('i') === 0) {
    rt_prune($config['keepDays']);
}
