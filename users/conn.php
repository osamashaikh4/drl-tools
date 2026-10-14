<?php
require_once __DIR__ . '/../conn.php';
$main_conn = oci_connect("drl", "drl", "10.1.52.254/drl");

// Privileged (system) account used only for CREATE USER + role grants.
$admin_user = "system";
$admin_pass = 'Test123$';
