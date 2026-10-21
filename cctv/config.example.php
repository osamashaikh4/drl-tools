<?php
// Copy to config.php (not committed) and fill in. Hikvision cameras / NVRs used by the CCTV tool.
// 'user' / 'password' are the shared login; a device can override them with its own.
return [
    'user'     => 'admin',
    'password' => 'change-me',
    'devices'  => [
        // ['name' => 'NVR Main',     'ip' => '10.1.52.146'],
        // ['name' => 'Gate camera',  'ip' => '10.1.52.1', 'user' => 'admin', 'password' => 'other-pass'],
    ],
];
