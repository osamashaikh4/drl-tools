<?php
// ZKTeco attendance machines managed by the ZK Devices tool (port 4370, UDP unless 'tcp' => true).
return [
    'main1'    => ['name' => 'Reception', 'ip' => '10.1.52.205'],
    'main2'    => ['name' => 'New Lab Bld', 'ip' => '10.1.52.43'],
    // Same machine over TCP: the attendance log comes in 64 KB blocks, about 12 s instead of 70 s.
    'main2tcp' => ['name' => 'New Lab Bld (TCP)', 'ip' => '10.1.52.43', 'tcp' => true],
];
