<?php
require_once __DIR__ . "/../auth.php";

date_default_timezone_set("Asia/Karachi");

include __DIR__ . "/../conn.php";
include __DIR__ . "/command_functions.php";

$branches = [];
$btmt = "SELECT BCODE, BNAME FROM BRANCHES WHERE BCODE NOT IN 
('32', '33', '35', '37', '38', '70', '71', '44') ORDER BY BCODE ASC";
$r = oci_parse($conn, $btmt);
oci_execute($r);

while ($row = oci_fetch_array($r)) {
    $branches[$row['BCODE']] = $row['BNAME'];
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['command']) && isset($_POST['execute']) && $_POST['execute'] == "true" && isset($_POST['bcode'])) {
        $commands = explode(';', $_POST['command']);
        $final_command = [];

        foreach ($commands as $sql) {
            $sql = trim($sql);
            if ($sql === '') continue;
            if (!preg_match('/^\s*(INSERT|UPDATE)\b/i', $sql)) {
                header("Location: index.php?err=" . urlencode("Only INSERT and UPDATE commands are allowed."));
                exit();
            }
            $final_command[] = $sql;
        }

        if (count($final_command) > 0) {
            $status = [];
            foreach ($_POST['bcode'] as $bcode) {
                if ($bcode != '' && isset($connections[$bcode])) {
                    $sid = "drl";

                    if ($bcode == '13' || $bcode == '24' || $bcode == '27') {
                        $sid = "dbnc";
                    }

                    $bconn = @oci_connect("drl", "drl", $connections[$bcode] . '/' . $sid);
                    if (!$bconn) {
                        $status[] = ['bcode' => $bcode, 'query' => '', 'status' => "Error connecting branch"];
                    } else {
                        foreach ($final_command as $s) {
                            // Skip an INSERT whose exact row is already on this branch.
                            $check = insert_row_exists($bconn, $s);
                            if ($check['exists'] === true) {
                                $status[] = ['bcode' => $bcode, 'query' => $s . ';', 'status' => 'Skipped: row already exists'];
                                continue;
                            }
                            $note = $check['exists'] === null ? ' (duplicate check not done: ' . $check['reason'] . ')' : '';

                            $x = oci_parse($bconn, $s);
                            $result = @oci_execute($x);
                            $status[] = [
                                'bcode' => $bcode,
                                'query' => $s . ';',
                                'status' => ($result ? 'Executed successfully' : 'Execution failed: ' . oci_error($x)['message']) . $note
                            ];
                        }
                    }
                } else {
                    $status[] = ['bcode' => $bcode, 'query' => '', 'status' => "Branch connection not found"];
                }
            }
            $_SESSION['commands_status']  = $status;
            header("Location: status.php");
            exit();
        }
    }
    header("Location: index.php");
    exit();
}

if (isset($_GET['err']) && $_GET['err']) {
    $e = htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8');
    $msg = "
        <script>
        iziToast.error({
            title: 'Error',
            message: '{$e}',
            onClosed: function () {
                const url = new URL(window.location.href);
                url.searchParams.delete('err');
                window.history.pushState({}, '', url.toString());
            }
        });
        </script>
    ";
}
include __DIR__ . "/header.php";
?>
<div class="sm:mx-auto sm:w-full sm:max-w-sm">
    <img class="mx-auto h-28 w-auto" src="../drlogo.png" alt="Your Company">
</div>
<div class="bg-white px-6 pb-6 max-w-[885px] mx-auto">
    <div class="p-6 mx-auto mt-10 max-w-2xl rounded-lg ring-1 ring-gray-200 lg:mx-0 lg:max-w-none">
        <form class="flex items-center flex-col" action="index.php" method="POST">
            <div class="flex flex-col gap-2 mb-6 w-full">
                <label class="font-semibold" for="bcode">Branches</label>
                <select required class="branches_select" multiple="multiple" name="bcode[]">
                    <?php
                    foreach ($branches as $bcode => $bname) {
                        $selected = isset($_GET['bcode']) && $_GET['bcode'] == $bcode ? "selected" : "";
                    ?>
                        <option <?= $selected ?> value="<?= $bcode ?>"><?= $bname ?></option>
                    <?php
                    }
                    ?>
                </select>
            </div>
            <div class="flex flex-col gap-2 w-full">
                <label class="font-semibold" for="command">Command</label>
                <textarea required name="command" id="command" placeholder="Enter command" class="min-h-[120px] block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-1 focus:ring-blue-500 sm:text-sm sm:leading-6"></textarea>
            </div>
            <button name="execute" value="true" class="mt-6 flex w-full items-center justify-center rounded-md border border-transparent bg-blue-500 px-8 py-3 text-base font-medium text-white hover:bg-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:ring-offset-1">Execute</button>
        </form>
    </div>
</div>
<script>
    $(document).ready(function() {
        $('.branches_select').select2({
            placeholder: "Select a branch",
        });
    });
</script>
<?php
if (isset($msg)) {
    echo $msg;
}
?>
<?php include __DIR__ . "/../footer.php"; ?>
