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

// Signed-in account name, or null. The session keeps the name and password used at sign-in,
// so changing an account's password in constants.php signs it out.
function current_user(): ?string
{
    global $USERS;

    $name = $_SESSION['comname'] ?? null;
    $pass = $_SESSION['compwd'] ?? null;

    return is_string($name) && isset($USERS[$name]) && is_string($pass) && hash_equals($USERS[$name]['pass'], $pass)
        ? $name
        : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

// Whether the signed-in account may use a tool (a key of tools.php).
function user_can(string $tool): bool
{
    global $USERS;

    $user = current_user();
    if ($user === null) {
        return false;
    }
    $tools = $USERS[$user]['tools'];
    if (in_array($tool, (array) ($USERS[$user]['except'] ?? []), true)) {
        return false;
    }

    return $tools === '*' || in_array($tool, (array) $tools, true);
}

// Key of the tool the running script belongs to (its folder, or its root-level link), or null.
function current_tool_key(): ?string
{
    global $ALL_TOOLS;

    $folder = current_tool();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    foreach ($ALL_TOOLS as $key => $tool) {
        if ($folder !== null ? ($tool['folder'] ?? null) === $folder : ($tool['link'] ?? null) === $script) {
            return $key;
        }
    }

    return null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . tools_base() . 'login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit();
    }
}

// Every page and request of a tool goes through here, so an account limited to some tools is
// stopped at the door of the others: their pages and their background (JSON) requests alike.
// Anything that is not a known tool is closed to limited accounts, except the shared root pages.
$ALL_TOOLS = $TOOLS;
if (($user = current_user()) !== null && ($USERS[$user]['tools'] !== '*' || !empty($USERS[$user]['except']))) {
    $key    = current_tool_key();
    $shared = current_tool() === null && in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['index.php', 'login.php', 'logout.php'], true);
    if (!$shared && ($key === null || !user_can($key))) {
        http_response_code(403);
        echo 'This account does not have access to this tool.';
        exit();
    }
    // The dashboard and the top menu only list what this account can open.
    $TOOLS = array_filter($TOOLS, 'user_can', ARRAY_FILTER_USE_KEY);
}
unset($user, $key, $shared);
