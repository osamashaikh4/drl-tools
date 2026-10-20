<?php
require_once __DIR__ . '/bootstrap.php';

// Only follow same-site relative paths after login.
$next = $_POST['next'] ?? $_GET['next'] ?? '';
if (!is_string($next) || !preg_match('#^/(?!/)#', $next)) {
    $next = 'index.php';
}

if (is_logged_in()) {
    header("Location: " . $next);
    exit();
}

$error = null;
if (isset($_POST['comname']) && isset($_POST['compwd'])) {
    $name = (string) $_POST['comname'];
    $pass = (string) $_POST['compwd'];
    if (isset($USERS[$name]) && hash_equals($USERS[$name]['pass'], $pass)) {
        session_regenerate_id(true);
        $_SESSION['comname'] = $name;
        $_SESSION['compwd']  = $pass;
        header("Location: " . $next);
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}

$pageTitle = 'DRL Tools - Sign in';
include __DIR__ . '/header.php';
?>

<div class="min-h-screen flex items-center justify-center bg-slate-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-white p-10 rounded-3xl shadow-xl border border-slate-200/80">
        <div class="text-center">
            <img class="mx-auto h-24 w-auto mb-4" src="./drlogo.png" alt="DRL Logo">
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">DRL Tools</h2>
            <p class="mt-2 text-xs text-slate-500">Sign in to access the DRL tools</p>
        </div>

        <?php if ($error): ?>
            <div class="p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-xs font-semibold rounded-r-lg">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form class="mt-8 space-y-6" action="login.php" method="POST">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div class="space-y-4">
                <div>
                    <label for="comname" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Username</label>
                    <input id="comname" name="comname" type="text" required placeholder="Enter username" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition">
                </div>

                <div>
                    <label for="compwd" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Password</label>
                    <input id="compwd" name="compwd" type="password" autocomplete="current-password" required placeholder="••••••••" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition">
                </div>
            </div>

            <div>
                <button type="submit" class="w-full flex justify-center py-3.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition">
                    Sign in
                </button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
