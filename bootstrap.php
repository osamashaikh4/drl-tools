<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/constants.php';

$TOOLS = require __DIR__ . '/tools.php';

// Relative path from the running script back to the drl-tools root ('' or '../').
function tools_base(): string
{
    $root   = str_replace('\\', '/', realpath(__DIR__));
    $script = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
    $rest   = trim(substr($script, strlen($root)), '/');

    return $rest === '' ? '' : str_repeat('../', substr_count($rest, '/') + 1);
}

// Folder of the tool the running script belongs to, or null on root pages.
function current_tool(): ?string
{
    $root   = str_replace('\\', '/', realpath(__DIR__));
    $script = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
    $rest   = trim(substr($script, strlen($root)), '/');

    return $rest === '' ? null : explode('/', $rest)[0];
}

function is_logged_in(): bool
{
    global $USER_NAME, $USER_PASS;

    return isset($_SESSION['comname'], $_SESSION['compwd'])
        && $_SESSION['comname'] === $USER_NAME
        && $_SESSION['compwd'] === $USER_PASS;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . tools_base() . 'login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit();
    }
}
