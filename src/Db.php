<?php
namespace Backup;

use PDO;
use RuntimeException;

/**
 * Db
 * ---
 * • PDO connection pool
 * • Plain-PHP or mysqldump backup engine
 * • Encrypt + off-site push wrapper
 * • Safe restore through mysql CLI
 */
class Db
{
    /* ---------------------------------------------------------
     *  PDO pool (per-alias)
     * --------------------------------------------------------- */
    private static array $pool = [];

    public static function pdo(string $alias): PDO
    {
        if (isset(self::$pool[$alias])) {
            return self::$pool[$alias];
        }
        $cfg = Bootstrap::cfg('databases')[$alias];

        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        if (Bootstrap::cfg('tuning')['unbuffered']) {
            $opt[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        return self::$pool[$alias] = new PDO($dsn, $cfg['user'], $cfg['pass'], $opt);
    }

    /* ---------------------------------------------------------
     *  backupPlain()  – returns local file path
     * --------------------------------------------------------- */
    public static function backupPlain(string $alias): string
    {
        $db = Bootstrap::cfg('databases')[$alias];
        $bk = Bootstrap::cfg('backup');
        $dir = rtrim($bk['dir'], '/') . "/$alias";
        if (!is_dir($dir))
            mkdir($dir, 0755, true);

        $stamp = gmdate('Ymd_His');
        $ext = $bk['compress'] ? '.sql.gz' : '.sql';
        $file = "{$bk['prefix']}{$alias}_{$stamp}.sql$ext";
        $path = "$dir/$file";

        /* -------- fast-path via mysqldump -------- */
        $bin = Performance::mysqldump();
        if ($bk['compress'])
            $gzip = ' | gzip';
        else
            $gzip = '';

        if (Bootstrap::cfg('performance')['prefer_mysqldump'] && $bin && function_exists('shell_exec')) {
            $skip = '';
            $tbls = self::pdo($alias)
                ->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')
                ->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tbls as $t)
                if (Performance::skip($t))
                    $skip .= " --ignore-table={$db['name']}.$t";

            $cmd = escapeshellcmd($bin)
                . " --host={$db['host']} --port={$db['port']}"
                . " --user=" . escapeshellarg($db['user'])
                . " --password=" . escapeshellarg($db['pass'])
                . " --single-transaction --quick --skip-lock-tables"
                . $skip . ' ' . escapeshellarg($db['name'])
                . $gzip . ' > ' . escapeshellarg($path);

            system($cmd, $rc);
            if ($rc !== 0)
                throw new RuntimeException("mysqldump failed ($rc)");

            self::postProcess($alias, $path);
            return $path;
        }

        /* -------- fallback: pure-PHP streamer -------- */
        $tbls = array_values(
            array_filter(
                self::pdo($alias)
                    ->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')
                    ->fetchAll(PDO::FETCH_COLUMN),
                fn($t) => !Performance::skip($t)
            )
        );

        $handle = $bk['compress'] ? gzopen($path, 'wb9') : fopen($path, 'wb');
        $write = $bk['compress']
            ? fn($l = '') => gzwrite($handle, $l . "\n")
            : fn($l = '') => fwrite($handle, $l . "\n");

        $write('-- generated ' . gmdate(DATE_ATOM));
        $write("USE `{$db['name']}`;");
        $write('SET FOREIGN_KEY_CHECKS=0;');
        $write();

        $total = count($tbls);
        $done = 0;
        $start = microtime(true);

        foreach ($tbls as $tbl) {
            $done++;
            if (PHP_SAPI === 'cli') {
                $pct = (int) ($done / $total * 100);
                $eta = $done ? round((microtime(true) - $start) / $done * ($total - $done)) : 0;
                echo "\r$pct%  $tbl  ETA {$eta}s   ";
            }

            [, $ddl] = self::pdo($alias)
                ->query("SHOW CREATE TABLE `$tbl`")
                ->fetch(PDO::FETCH_NUM);

            $write("-- Structure for `$tbl`");
            $write("DROP TABLE IF EXISTS `$tbl`;");
            $write("$ddl;");
            $write();

            $write("-- Data for `$tbl`");
            $st = self::pdo($alias)->prepare("SELECT * FROM `$tbl`");
            $st->execute();

            $cols = [];
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $meta = $st->getColumnMeta($i);
                $cols[] = '`' . $meta['name'] . '`';
            }
            $prefix = "INSERT INTO `$tbl` (" . implode(', ', $cols) . ") VALUES ";
            $batch = [];

            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                $batch[] = '(' . implode(', ', array_map(
                    fn($v) => is_null($v) ? 'NULL' : self::pdo($alias)->quote((string) $v),
                    $row
                )) . ')';

                if (count($batch) >= Bootstrap::cfg('tuning')['chunk_size']) {
                    $write($prefix . implode(",\n", $batch) . ';');
                    $batch = [];
                }
            }
            if ($batch)
                $write($prefix . implode(",\n", $batch) . ';');
            $write();
        }

        if (PHP_SAPI === 'cli')
            echo "\r100% complete          \n";

        $write('SET FOREIGN_KEY_CHECKS=1;');
        $bk['compress'] ? gzclose($handle) : fclose($handle);

        self::postProcess($alias, $path);
        return $path;
    }

    /* ---------------------------------------------------------
     *  runBackup()  – wrapper: encrypt + off-site push
     * --------------------------------------------------------- */
    public static function runBackup(string $alias): string
    {
        $local = self::backupPlain($alias);

        if (Bootstrap::cfg('security')['encryption']['enabled']) {
            $enc = $local . '.enc';
            file_put_contents($enc, Security::encrypt(file_get_contents($local)));
            Uploader::push($enc, $alias);
            return $enc;
        }

        Uploader::push($local, $alias);
        return $local;
    }

    /* ---------------------------------------------------------
     *  runRestore()  (Pack 5)
     * --------------------------------------------------------- */
    public static function runRestore(string $alias, string $src): void
    {
        if (!Bootstrap::cfg('restore')['enabled']) {
            throw new RuntimeException('Restore disabled in config');
        }
        if (!is_file($src))
            throw new RuntimeException('Backup file missing');

        $enc = str_ends_with($src, '.enc');
        $gz = str_ends_with($src, '.gz') || str_ends_with($src, '.gz.enc');
        $tmp = tempnam(sys_get_temp_dir(), 'restore_');
        $bytes = $enc
            ? Security::decrypt(file_get_contents($src))
            : file_get_contents($src);

        file_put_contents($tmp, $gz ? gzdecode($bytes) : $bytes);

        $mysql = Bootstrap::cfg('restore')['mysql_path'] ?: Performance::mysqldump();
        if (!$mysql)
            throw new RuntimeException('mysql client not found');

        $db = Bootstrap::cfg('databases')[$alias];
        $cmd = escapeshellcmd($mysql)
            . " --host={$db['host']} --port={$db['port']}"
            . " --user=" . escapeshellarg($db['user'])
            . " --password=" . escapeshellarg($db['pass'])
            . ' ' . escapeshellarg($db['name'])
            . ' < ' . escapeshellarg($tmp);

        system($cmd, $rc);
        unlink($tmp);

        if ($rc !== 0)
            throw new RuntimeException("mysql exited $rc");
    }

    /* ---------------------------------------------------------
     *  Helpers
     * --------------------------------------------------------- */
    private static function postProcess(string $alias, string $file): void
    {
        /* sha256 sidecar */
        file_put_contents($file . '.sha256', hash_file('sha256', $file) . "\n");

        /* rotation */
        $keep = Bootstrap::cfg('rotation')['keep'];
        $dir = dirname($file);
        $all = array_values(
            array_filter(
                scandir($dir, SCANDIR_SORT_DESCENDING),
                fn($f) => preg_match('/\\.enc$|\\.sql(\\.gz)?$/', $f)
            )
        );
        foreach (array_slice($all, $keep) as $old) {
            @unlink("$dir/$old");
            @unlink("$dir/$old.sha256");
        }
    }
}
