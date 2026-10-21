<?php
// Copy to config.php (not committed) and fill in. Routers watched by Router Activity Tracker.
return [
    // Seconds between samples. Each sample's speeds are counted for this long when totalling data.
    'interval' => 15,
    // Days of history kept.
    'keepDays' => 90,
    'routers'  => [
        // Tenda with remote web management on (Advanced -> Remote Web Management).
        // 'password' is the router's login password; leave '' while it has none.
        'tenda' => ['name' => 'Tenda', 'url' => 'http://10.140.70.145:8080', 'password' => ''],
    ],
    // Your own names for devices, by MAC (lower case, colons).
    'labels' => [
        // 'a4:c3:f0:b7:fa:07' => 'Reception laptop',
    ],
];
