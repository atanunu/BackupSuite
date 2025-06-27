<?php
namespace Backup;

class UI
{
    /**
     * Render the dashboard page.
     *
     * @param string $alias Current DB alias
     * @param string $flash Optional flash message
     */
    public static function page(string $alias, string $flash = ''): void
    {
        $csrf = Security::token();
        $dbs = Bootstrap::cfg('databases');
        $dir = rtrim(Bootstrap::cfg('backup')['dir'], '/') . "/$alias";
        $files = is_dir($dir)
            ? array_values(array_diff(scandir($dir, SCANDIR_SORT_DESCENDING), ['.', '..']))
            : [];

        $role = $_SESSION['role'];                    // set after login
        $perm = Bootstrap::cfg('roles')[$role] ?? [];
        $can = fn($p) => in_array('*', $perm) || in_array($p, $perm);

        /* ---------- HTML + minimal CSS ---------- */
        echo '<!doctype html><title>DB-Backup</title><style>
body{font:15px/1.5 system-ui,sans-serif;max-width:820px;margin:2rem auto}
button,a.button{display:inline-block;padding:.5rem 1rem;margin:.2rem;background:#0275d8;color:#fff;border:none;border-radius:4px;text-decoration:none;cursor:pointer}
button:hover,a.button:hover{background:#025aa5}
table{width:100%;border-collapse:collapse;margin-top:1rem}
th,td{padding:.4rem;border:1px solid #ccc;text-align:left}
.msg.ok{color:green}.msg.fail{color:#c00}.msg.warn{color:#c90}
footer{margin-top:2rem;font-size:.85em;color:#666}
</style>';

        /* flash */
        if ($flash) {
            $cls = str_starts_with($flash, '✅') ? 'ok'
                : (str_starts_with($flash, '⚠️') ? 'warn' : 'fail');
            echo "<p class=\"msg $cls\">$flash</p>";
        }

        /* top form */
        echo "<form method=post>
              <input type=hidden name=csrf value=$csrf>
              <select name=db>";
        foreach ($dbs as $k => $cfg) {
            $sel = $k === $alias ? ' selected' : '';
            echo "<option value='" . Bootstrap::html($k) . "'$sel>"
                . Bootstrap::html($k) . ' (' . Bootstrap::html($cfg['name']) . ')</option>';
        }
        echo '</select>';

        if ($can('backup'))
            echo '<button name=action value=backup>Run Backup</button>';
        if ($can('upload'))
            echo '<button name=action value=upload>Upload Last</button>';
        echo '<button name=action value=view>View</button>';
        if ($can('logs'))
            echo "<a class=button href='?action=logs&csrf=$csrf'>Logs</a>";
        echo "<a class=button href='?action=logout&csrf=$csrf'>Logout</a>";
        echo '</form>';

        /* list backups */
        if ($files) {
            echo '<table><thead><tr><th>File</th><th>Size</th><th>Actions</th></tr></thead><tbody>';
            foreach ($files as $f) {
                $size = number_format(filesize("$dir/$f") / 1024, 1);
                echo '<tr><td>' . Bootstrap::html($f) . "</td><td>{$size} KB</td><td>";

                if ($can('download'))
                    echo "<a class=button href=\"?action=download&db=$alias&file=" . urlencode($f) . "&csrf=$csrf\">Download</a>";

                if ($can('restore'))
                    echo "<a class=button href=\"?action=restore&db=$alias&file=" . urlencode($f) . "&csrf=$csrf\" onclick=\"return confirm('Restore and overwrite the database?')\">Restore</a>";

                if ($can('delete'))
                    echo "<a class=button href=\"?action=delete&db=$alias&file=" . urlencode($f) . "&csrf=$csrf\" onclick=\"return confirm('Delete this backup?')\">Delete</a>";

                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo "<p><em>No backups for “" . Bootstrap::html($alias) . "”.</em></p>";
        }

        echo '<footer>DB-Backup Suite · ' . date('Y') . '</footer>';
    }
}
