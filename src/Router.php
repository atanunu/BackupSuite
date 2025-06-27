<?php
namespace Backup;

use Backup\Auth;          // new login helper

class Router
{
    public static function dispatch(): void
    {
        /* ------------------------------------------------------------------
         *  CLI mode  (cron / manual backups)  — unchanged
         * ------------------------------------------------------------------ */
        if (PHP_SAPI === 'cli') {
            $cmd = $GLOBALS['argv'][1] ?? 'help';
            $alias = $GLOBALS['argv'][2] ?? Bootstrap::cfg('default');

            switch ($cmd) {
                case 'cron':
                    Scheduler::run();
                    exit;
                case 'backup':
                    echo Db::runBackup($alias) . PHP_EOL;
                    exit;
                case 'restore':
                    $file = $GLOBALS['argv'][3] ?? die("restore <db> <file>\n");
                    Db::runRestore($alias, $file);
                    echo "Restore OK\n";
                    exit;
                default:
                    echo "Usage: backup | restore | cron\n";
                    exit;
            }
        }

        /* ------------------------------------------------------------------
         *  Web mode — LOGIN GATE (new)
         * ------------------------------------------------------------------ */
        session_start();
        if (!isset($_SESSION['stage']) || $_SESSION['stage'] !== 'OK') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                Security::check($_POST['csrf'] ?? null);
                Auth::handlePost();     // never returns on success
            }
            Auth::form();               // show username / password / TOTP
        }

        /* ------------------------------------------------------------------
         *  All requests past this point are authenticated
         * ------------------------------------------------------------------ */
        $alias = $_POST['db'] ?? $_GET['db'] ?? Bootstrap::cfg('default');
        $action = $_POST['action'] ?? $_GET['action'] ?? 'view';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::check($_POST['csrf'] ?? null);
        }
        if (isset($_GET['csrf']))
            Security::check($_GET['csrf']);

        /* helper: redirect with flash msg */
        $flash = fn($msg) => header('Location: ?db=' . $alias . '&msg=' . urlencode($msg));

        try {
            switch ($action) {
                /* -------------------------------------------------- */
                case 'backup':
                    $f = Db::runBackup($alias);
                    Notifier::alert('Backup OK', basename($f), true);
                    $flash('✅ Backup ' . basename($f));
                    break;

                case 'upload':
                    $dir = rtrim(Bootstrap::cfg('backup')['dir'], '/') . "/$alias";
                    $last = array_values(array_filter(
                        scandir($dir, SCANDIR_SORT_DESCENDING),
                        fn($f) => preg_match('/\\.enc$|\\.sql(\\.gz)?$/', $f)
                    ))[0] ?? null;
                    if ($last) {
                        Uploader::push("$dir/$last", $alias);
                        $flash('✅ Upload OK');
                    } else
                        $flash('⚠️ No backup found');
                    break;

                case 'restore':
                    $file = basename($_GET['file'] ?? '');
                    Db::runRestore(
                        $alias,
                        rtrim(Bootstrap::cfg('backup')['dir'], '/') . "/$alias/$file"
                    );
                    Notifier::alert('Restore OK', $file, true);
                    $flash('✅ Restore complete');
                    break;

                case 'download':
                    $file = basename($_GET['file'] ?? '');
                    $path = rtrim(Bootstrap::cfg('backup')['dir'], '/') . "/$alias/$file";
                    if (!is_file($path))
                        die('404');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . preg_replace('/\\.enc$/', '', $file) . '"');
                    $enc = str_ends_with($file, '.enc');
                    $gz = str_ends_with($file, '.gz') || str_ends_with($file, '.gz.enc');
                    if ($enc) {
                        $buf = Security::decrypt(file_get_contents($path));
                        echo $gz ? gzdecode($buf) : $buf;
                    } else
                        readfile($path);
                    exit;

                case 'delete':
                    $file = basename($_GET['file'] ?? '');
                    $path = rtrim(Bootstrap::cfg('backup')['dir'], '/') . "/$alias/$file";
                    if (is_file($path))
                        unlink($path);
                    @unlink($path . '.sha256');
                    $flash('✅ Deleted');
                    break;

                case 'logs':
                    header('Content-Type: text/plain');
                    $tail = fn($f) => @implode("\n", array_slice(file($f, FILE_IGNORE_NEW_LINES), -500));
                    echo "=== scheduler.log ===\n" . $tail(__DIR__ . '/../scheduler.log') . "\n\n";
                    echo "=== notifier.log  ===\n" . $tail(__DIR__ . '/../notifier.log') . "\n";
                    exit;

                case 'logout':
                    session_destroy();
                    header('Location: ./');
                    exit;

                case 'view':
                default:
                    UI::page($alias, Bootstrap::html($_GET['msg'] ?? ''));
            }
        } catch (\Throwable $e) {
            UI::page($alias, '❌ ' . $e->getMessage());
        }
    }
}
