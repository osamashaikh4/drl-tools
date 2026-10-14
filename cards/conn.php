<?php
require_once __DIR__ . '/../conn.php';
// Branch 01 (254) has the fullest category / designation lookup lists, so the New Card form reads from it.
$main_conn = oci_connect("drl", "drl", "10.1.52.254/drl");
