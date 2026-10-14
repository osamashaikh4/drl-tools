# DRL Tools

One place for the DRL branch database tools, with one login.

```
drl-tools/
├── index.php          dashboard (cards built from tools.php)
├── login.php / logout.php
├── bootstrap.php      session, constants, tool registry, is_logged_in(), require_login()
├── auth.php           require this at the top of any protected page
├── conn.php           $conn (253) + $connections (branch IPs), shared by every tool
├── constants.php      login credentials
├── tools.php          registry of tools (dashboard + top nav)
├── header.php / footer.php   shared <head>, assets and top nav
├── assets/            tailwind, jquery, select2, iziToast
├── commands/          run INSERT/UPDATE on branches
├── data-deficit/      branch vs 253 deficit check & fill (logs go to data-deficit/logs/)
└── users/             add/copy/transfer/manage users (+ its own conn.php for 254 and system account)
```

## Adding a new tool

1. Create a folder, e.g. `drl-tools/my-tool/`, with an `index.php`.
2. Start each protected page with:
   ```php
   require_once __DIR__ . '/../auth.php';
   require_once __DIR__ . '/../conn.php';   // if it needs the databases
   ```
   then `include __DIR__ . '/../header.php';` before output and
   `include __DIR__ . '/../footer.php';` at the end. JSON endpoints should use
   `bootstrap.php` + `is_logged_in()` and return 401 instead of redirecting.
3. Add an entry to `tools.php`.
4. Use a tool-specific key for anything stored in `$_SESSION` (e.g. `mytool_status`),
   since all tools share one session.
