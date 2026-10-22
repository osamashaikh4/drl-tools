<?php
// ZKTeco attendance machines managed by the ZK Devices tool (UDP port 4370; 'tcp' => true downloads
// the attendance log over TCP instead).
return [
    'main1'    => ['name' => 'Reception', 'ip' => '10.1.52.205'],
    'main2'    => ['name' => 'New Lab Bld', 'ip' => '10.1.52.43'],
    // Same machine, attendance log over TCP (64 KB blocks, about 15 s instead of 70 s); everything else
    // stays on UDP, as this machine takes about 4 s to answer each small command over TCP.
    'main2tcp' => ['name' => 'New Lab Bld (TCP)', 'ip' => '10.1.52.43', 'tcp' => true],
];
