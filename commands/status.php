<?php
require_once __DIR__ . "/../auth.php";

date_default_timezone_set("Asia/Karachi");

include __DIR__ . "/../conn.php";
include __DIR__ . "/header.php";

?>
<style>
    tr:not(:last-of-type) {
        border-top: 1px solid #e0e0e0;
        border-bottom: 1px solid #e0e0e0;
    }

    td {
        border-right: 1px solid #e0e0e0;
    }

    th {
        border-right: 1px solid #e0e0e0;
    }

    th,
    td {
        font-size: 14px;
        padding: 8px;
    }
</style>
<div class="p-6 sm:mx-auto sm:w-full sm:max-w-sm">
    <img class="mx-auto h-28 w-auto" src="../drlogo.png" alt="Your Company">
</div>
<div class="bg-white px-6 pb-6 max-w-[88%] mx-auto">
    <div class="flex flex-col gap-2">
        <p class="font-bold">Query Results</p>
        <div class="rounded border border-[#e0e0e0] max-h-[550px] overflow-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="text-center">Branch</th>
                        <th class="text-left">Query</th>
                        <th class="text-left">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (isset($_SESSION['commands_status'])) {
                        foreach ($_SESSION['commands_status'] as $key => $status) {
                    ?>
                            <tr>
                                <?php
                                foreach ($status as $x => $s) {
                                ?>
                                    <td class="<?= $x === 'bcode' ? "text-center" : "text-left" ?>"><?= $s ?></td>
                                <?php
                                }
                                ?>
                            </tr>
                    <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    <button onclick="window.location.href='index.php'" class="w-fit mx-auto mt-6 flex items-center justify-center rounded-md border border-transparent bg-blue-500 px-8 py-3 text-base font-medium text-white hover:bg-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:ring-offset-1">Go Back</button>
</div>
<?php include __DIR__ . "/../footer.php"; ?>
