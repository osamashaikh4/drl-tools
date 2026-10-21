<?php
// MOCK tests for adding a punch (zk_add_punch / zk_punch_input). No network: a fake machine stands
// in for the device. Run: php zk-time/tests/punch_test.php
// For the real machine see punch_hardware_test.php.
require __DIR__ . '/../zk_functions.php';

use ZK\Util;

// Fake machine shared by every "connection", so a reconnect sees what an earlier one wrote.
class FakeDevice
{
    public array $users = [];         // raw getUser() rows
    public array $records = [];       // raw getAttendance() rows
    public string $onWrite = 'store'; // store | refuse | ackOnly | storeOther
    public int $refuseWrites = 0;     // refuse this many writes, then go back to onWrite
    public string $onDelete = 'delete'; // delete | refuse | ackOnly | wholeDay
    public int $hideRecords = 0;      // report this many more records than are sent (short download)
    public array $payloads = [];
    public array $deletes = [];
    public bool $enabled = true;
}

class FakeZK extends ZKLib
{
    public function __construct(private FakeDevice $dev)
    {
    }

    private function answer(int $cmd): void
    {
        $this->_data_recv = pack('vvvv', $cmd, 0, 1, 0);
    }

    public function _command($command, $command_string, $type = Util::COMMAND_TYPE_GENERAL)
    {
        $this->answer(Util::CMD_ACK_OK);
        if ($command === ZK_CMD_GET_FREE_SIZES) {
            $f = array_fill(0, 20, 0);
            $f[8] = count($this->dev->records) + $this->dev->hideRecords;
            return pack('V20', ...$f);
        }
        if ($command === ZK_CMD_SET_DATA) {
            $this->dev->payloads[] = $command_string;
            if ($this->dev->onWrite === 'refuse' || $this->dev->refuseWrites-- > 0) {
                $this->answer(Util::CMD_ACK_ERROR);
                return '';
            }
            if ($this->dev->onWrite !== 'ackOnly' && preg_match('/User_PIN=(\d+)\tVerify_Type=(\d+)\tVerify_Time=([\d-]+)T([\d:]+)\tStatus=(\d+)/', $command_string, $m)) {
                $punch = $this->dev->onWrite === 'storeOther' ? ((int) $m[5] + 1) % 6 : (int) $m[5];
                $this->dev->records[] = ['uid' => 0, 'id' => $m[1], 'state' => (int) $m[2], 'timestamp' => "$m[3] $m[4]", 'type' => $punch];
            }
        }
        if ($command === ZK_CMD_TIME_ATTLOG_DELE) {
            $this->dev->deletes[] = $command_string;
            if ($this->dev->onDelete === 'refuse') {
                $this->answer(Util::CMD_ACK_ERROR);
                return '';
            }
            [, $t0, $t1] = unpack('V2', $command_string);
            if ($this->dev->onDelete === 'wholeDay') {
                $t0 -= $t0 % 86400;
                $t1 = $t0 + 86399;
            }
            if ($this->dev->onDelete !== 'ackOnly') {
                $this->dev->records = array_values(array_filter($this->dev->records, function ($r) use ($t0, $t1) {
                    $t = Util::encodeTime($r['timestamp']);
                    return $t < $t0 || $t > $t1;
                }));
            }
        }
        return '';
    }

    public function getUser() { return $this->dev->users; }
    public function getAttendance() { return $this->dev->records; }
    public function disableDevice() { $this->dev->enabled = false; return ''; }
    public function enableDevice() { $this->dev->enabled = true; return ''; }
    public function disconnect() { return true; }
}

function device(): FakeDevice
{
    $d = new FakeDevice();
    $d->users = [['uid' => 1, 'userid' => '1001', 'name' => 'Test Person', 'role' => 0, 'cardno' => '0', 'password' => '']];
    $d->records = [['uid' => 0, 'id' => '1001', 'state' => 1, 'timestamp' => '2026-10-01 08:59:12', 'type' => 0]];
    return $d;
}

// An opener that answers $answers times and then stops answering.
function opener(FakeDevice $d, int $answers = 99): callable
{
    return function () use ($d, &$answers) {
        return $answers-- > 0 ? new FakeZK($d) : null;
    };
}

$failed = 0;
function check(string $name, bool $ok, $detail = null): void
{
    global $failed;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === null ? '' : ' -> ' . json_encode($detail)) . "\n";
    $failed += $ok ? 0 : 1;
}

$good = ['userid' => '1001', 'time' => '2026-01-15 09:00:00', 'punch' => '0', 'verify' => '1'];
$p = zk_punch_input($good);

echo "Validation\n";
check('valid input accepted', $p === ['userid' => '1001', 'time' => '2026-01-15 09:00:00', 'punch' => 0, 'verify' => 1], $p);
check('datetime-local without seconds gets :00', (zk_punch_input(['time' => '2026-01-15T09:00'] + $good)['time'] ?? null) === '2026-01-15 09:00:00');
foreach ([
    'letters in user ID'    => ['userid' => '10a1'],
    'user ID over 9 digits' => ['userid' => '1234567890'],
    'impossible date'       => ['time' => '2026-02-30 09:00:00'],
    'garbage time'          => ['time' => 'yesterday'],
    'future time'           => ['time' => date('Y-m-d H:i:s', time() + 3600)],
    'before 2000'           => ['time' => '1999-12-31 23:59:59'],
    'unknown punch type'    => ['punch' => '9'],
    'non-numeric punch'     => ['punch' => '0x'],
    'unknown verify mode'   => ['verify' => '15'],
    'missing verify mode'   => ['verify' => ''],
] as $name => $bad) {
    check("refused: $name", is_string(zk_punch_input($bad + $good)));
}

echo "Device answers\n";
$d = device();
$r = zk_add_punch(opener($d, 0), $p, false, 0);
check('no answer: connect error', !$r['ok'] && $r['stage'] === 'connect', $r);

$d = device();
$r = zk_add_punch(opener($d), ['userid' => '4242'] + $p, false, 0);
check('unknown user refused, nothing written', !$r['ok'] && $r['stage'] === 'validate' && !$d->payloads, $r);

$d = device();
$d->hideRecords = 5;
$r = zk_add_punch(opener($d), $p, false, 0);
check('short log download: stops before writing', !$r['ok'] && $r['stage'] === 'read' && !$d->payloads, $r);

$d = device();
$r = zk_add_punch(opener($d), ['time' => '2026-10-01 08:59:12', 'punch' => 1] + $p, false, 0);
check('same person, same second (other type) refused as duplicate', !$r['ok'] && $r['stage'] === 'duplicate' && !$d->payloads, $r);

$d = device();
$r = zk_add_punch(opener($d), $p, true, 0);
check('dry run passes and writes nothing', $r['ok'] && $r['dryRun'] && !$d->payloads && count($d->records) === 1, $r);

$d = device();
$d->onWrite = 'refuse';
$r = zk_add_punch(opener($d), $p, false, 0);
check('device refuses: write error, device re-enabled', !$r['ok'] && $r['stage'] === 'write' && $d->enabled, $r);

$d = device();
$d->onWrite = 'ackOnly';
$r = zk_add_punch(opener($d), $p, false, 0);
check('acknowledged but not stored: not reported as success', !$r['ok'] && $r['stage'] === 'verify' && $r['written'] === false, $r);

$d = device();
$d->onWrite = 'storeOther';
$r = zk_add_punch(opener($d), $p, false, 0);
check('stored with other values: reported, not success', !$r['ok'] && $r['stage'] === 'verify' && $r['written'] === true, $r);

$d = device();
$r = zk_add_punch(opener($d, 1), $p, false, 0);
check('no answer for the check read: written but unverified', !$r['ok'] && $r['stage'] === 'verify' && $r['written'] === true, $r);

$d = device();
$r = zk_add_punch(opener($d), $p, false, 0);
check('success only after the fresh read finds the record', $r['ok'] && $r['verified'] && $r['records'] === 2, $r);
check('payload sent once, in the format confirmed on main1', $d->payloads === ["TableName=ATT_LOG\r\nUser_PIN=1001\tVerify_Type=1\tVerify_Time=2026-01-15T09:00:00\tStatus=0\tWork_Code_ID=0\0"], $d->payloads);
check('timestamp unchanged on the device', end($d->records)['timestamp'] === '2026-01-15 09:00:00');
check('device re-enabled after writing', $d->enabled);
$r = zk_add_punch(opener($d), $p, false, 0);
check('sending it again is refused as duplicate', !$r['ok'] && $r['stage'] === 'duplicate' && count($d->payloads) === 1, $r);

// ---- Delete --------------------------------------------------------------------------------
// Device log as [time, userid, punch, verify] rows, the way zk_records() returns them.
$log = fn(FakeDevice $d) => array_map(fn($r) => [$r['timestamp'], $r['id'], $r['type'], $r['state']], $d->records);
// A busy second: the target, someone else in the same second, and a punch later that day.
function busy(): FakeDevice
{
    $d = device();
    $d->users[] = ['uid' => 2, 'userid' => '1002', 'name' => 'Other Person', 'role' => 0, 'cardno' => '0', 'password' => ''];
    $d->records[] = ['uid' => 0, 'id' => '1001', 'state' => 1, 'timestamp' => '2026-10-02 09:00:00', 'type' => 0];
    $d->records[] = ['uid' => 0, 'id' => '1002', 'state' => 1, 'timestamp' => '2026-10-02 09:00:00', 'type' => 0];
    $d->records[] = ['uid' => 0, 'id' => '1002', 'state' => 1, 'timestamp' => '2026-10-02 17:30:05', 'type' => 1];
    return $d;
}
$target = ['2026-10-02 09:00:00', '1001', 0, 1];

echo "Delete\n";
$d = busy();
$r = zk_delete_punch(opener($d), ['2026-10-02 09:00:00', '1001', 1, 1], false, 0);
check('punch not on the device: refused, nothing deleted', !$r['ok'] && $r['stage'] === 'validate' && !$d->deletes, $r);

$d = busy();
$r = zk_delete_punch(opener($d), $target, true, 0);
check('dry run says one other punch would be written back, deletes nothing', $r['ok'] && $r['dryRun'] && $r['rewrite'] === 1 && !$d->deletes, $r);

$d = busy();
$before = $log($d);
$r = zk_delete_punch(opener($d), $target, false, 0);
check('delete: target gone, the other punch in that second written back', $r['ok'] && $r['verified'] && $r['rewritten'] === 1 && zk_records_minus($before, $log($d)) === [$target] && count($log($d)) === 3, [$r, $log($d)]);
check('delete asks the device for that one second only', $d->deletes === [pack('VV', Util::encodeTime('2026-10-02 09:00:00'), Util::encodeTime('2026-10-02 09:00:00'))]);
check('device re-enabled after deleting', $d->enabled);

$d = busy();
$d->onDelete = 'wholeDay';
$before = $log($d);
$r = zk_delete_punch(opener($d), $target, false, 0);
check('device deletes more than asked: everything else written back', $r['ok'] && $r['rewritten'] === 2 && zk_records_minus($before, $log($d)) === [$target], [$r, $log($d)]);

$d = busy();
$d->onDelete = 'refuse';
$r = zk_delete_punch(opener($d), $target, false, 0);
check('device refuses the delete: reported, log unchanged', !$r['ok'] && $r['stage'] === 'delete' && count($d->records) === 4, $r);

$d = busy();
$d->onDelete = 'ackOnly';
$r = zk_delete_punch(opener($d), $target, false, 0);
check('acknowledged but still there: not reported as success', !$r['ok'] && $r['stage'] === 'verify' && $r['changed'] === false, $r);

$d = busy();
$d->onWrite = 'refuse';
$r = zk_delete_punch(opener($d), $target, false, 0);
check('other punch cannot be written back: reported with the lost record', !$r['ok'] && $r['stage'] === 'restore' && $r['lost'] === [['2026-10-02 09:00:00', '1002', 0, 1]], $r);

$d = busy();
$r = zk_delete_punch(opener($d, 1), $target, false, 0);
check('no answer for the check read: reported as unsure', !$r['ok'] && $r['stage'] === 'verify' && $r['changed'] === true, $r);

// ---- Edit ----------------------------------------------------------------------------------
echo "Edit\n";
$new = fn(array $o) => $o + ['userid' => '1001', 'time' => '2026-10-02 09:00:00', 'punch' => 0, 'verify' => 1];

$d = busy();
$r = zk_edit_punch(opener($d), $target, $new([]), false, 0);
check('nothing changed: refused', !$r['ok'] && $r['stage'] === 'validate' && !$d->deletes, $r);

$d = busy();
$r = zk_edit_punch(opener($d), $target, $new(['userid' => '1002']), false, 0);
check('moving a punch to another person: refused', !$r['ok'] && $r['stage'] === 'validate' && !$d->deletes, $r);

$d = busy();
$r = zk_edit_punch(opener($d), $target, $new(['time' => '2026-10-01 08:59:12']), false, 0);
check('new time clashes with their other punch: refused before deleting', !$r['ok'] && $r['stage'] === 'duplicate' && !$d->deletes, $r);

$d = busy();
$r = zk_edit_punch(opener($d), $target, $new(['punch' => 1]), true, 0);
check('dry run writes and deletes nothing', $r['ok'] && $r['dryRun'] && !$d->deletes && !$d->payloads, $r);

$d = busy();
$before = $log($d);
$r = zk_edit_punch(opener($d), $target, $new(['punch' => 1]), false, 0);
$want = array_merge(zk_records_minus($before, [$target]), [['2026-10-02 09:00:00', '1001', 1, 1]]);
check('change punch type in the same second', $r['ok'] && $r['verified'] && !zk_records_minus($want, $log($d)) && count($log($d)) === 4, [$r, $log($d)]);

$d = busy();
$before = $log($d);
$r = zk_edit_punch(opener($d), $target, $new(['time' => '2026-10-02 08:55:00']), false, 0);
$want = array_merge(zk_records_minus($before, [$target]), [['2026-10-02 08:55:00', '1001', 0, 1]]);
check('change the time', $r['ok'] && !zk_records_minus($want, $log($d)) && count($log($d)) === 4, [$r, $log($d)]);

$d = device(); // nobody else in the target's second, so the first write is the new punch
$d->records[] = ['uid' => 0, 'id' => '1001', 'state' => 1, 'timestamp' => '2026-10-02 09:00:00', 'type' => 0];
$before = $log($d);
$d->refuseWrites = 1;
$r = zk_edit_punch(opener($d), $target, $new(['time' => '2026-10-02 08:55:00']), false, 0);
check('new punch refused: old punch put back as it was', !$r['ok'] && $r['stage'] === 'add' && $r['restored'] === true && !zk_records_minus($before, $log($d)) && count($log($d)) === count($before), [$r, $log($d)]);

echo $failed ? "\n$failed FAILED\n" : "\nAll mock tests passed\n";
exit($failed ? 1 : 0);
