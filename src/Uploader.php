<?php
namespace Backup;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * Off-site uploader (Pack 4)
 * --------------------------
 * • S3 / Wasabi / MinIO  – AWS SDK (SigV4)
 * • rclone remote        – exec()
 * • FTP / SFTP           – native PHP FTP or SSH2
 *
 * Any driver that fails simply logs the error; the main backup
 * still completes and local file stays on disk.
 */
class Uploader
{
    /**
     * Push a finished backup file to every enabled target.
     *
     * @param string $file   Absolute path to `.sql`, `.gz`, or `.enc`
     * @param string $alias  DB alias (used as sub-folder key)
     */
    public static function push(string $file, string $alias): void
    {
        $store = Bootstrap::cfg('storage');

        /* =====================================================
         *  1) S3 / Wasabi / MinIO  (AWS SDK)
         * ===================================================== */
        if ($store['s3']['enabled'] ?? false) {
            $s = $store['s3'];

            try {
                $cli = new S3Client([
                    'version' => '2006-03-01',
                    'region' => $s['region'],
                    'endpoint' => $s['endpoint'] ?: null,
                    'use_path_style_endpoint' => (bool) $s['endpoint'],
                    'credentials' => [
                        'key' => $s['access_key'],
                        'secret' => $s['secret_key'],
                    ],
                ]);

                $cli->putObject([
                    'Bucket' => $s['bucket'],
                    'Key' => rtrim($s['prefix'], '/') . "/$alias/" . basename($file),
                    'Body' => fopen($file, 'r'),
                    'ACL' => 'private',
                ]);

                Notifier::log('s3', 200, 'ok');
            } catch (\Throwable $e) {
                Notifier::log('s3', 500, $e->getMessage());
            }
        }

        /* =====================================================
         *  2) rclone remote
         * ===================================================== */
        if (($store['rclone']['enabled'] ?? false) && function_exists('exec')) {
            $r = $store['rclone'];
            $bin = $r['binary'] ?: 'rclone';
            $dest = rtrim($r['remote'], '/') . "/$alias/";
            $cmd = escapeshellcmd($bin) . ' copy ' .
                escapeshellarg($file) . ' ' . escapeshellarg($dest);

            $out = [];
            $code = 0;
            exec("$cmd 2>&1", $out, $code);

            Notifier::log('rclone', $code === 0 ? 200 : 500, implode("\n", $out));
        }

        /* =====================================================
         *  3) FTP / SFTP
         * ===================================================== */
        if ($store['ftp']['enabled'] ?? false) {
            $f = $store['ftp'];
            $remote = rtrim($f['path'], '/') . "/$alias/" . basename($file);

            /* ----- plain FTP ----- */
            if ($f['scheme'] === 'ftp') {
                $conn = @ftp_connect($f['host'], $f['port'] ?? 21, 15);

                if ($conn && @ftp_login($conn, $f['user'], $f['pass'])) {
                    ftp_pasv($conn, true);
                    @ftp_mkdir($conn, rtrim($f['path'], '/') . "/$alias");
                    $ok = ftp_put($conn, $remote, $file, FTP_BINARY);
                    Notifier::log('ftp', $ok ? 200 : 500, $ok ? 'ok' : 'put failed');
                    ftp_close($conn);
                } else {
                    Notifier::log('ftp', 500, 'login failed');
                }
            }

            /* ----- SFTP via ext/ssh2 ----- */ elseif ($f['scheme'] === 'sftp' && function_exists('ssh2_connect')) {
                $ssh = @ssh2_connect($f['host'], $f['port'] ?? 22);

                if ($ssh && @ssh2_auth_password($ssh, $f['user'], $f['pass'])) {
                    $sftp = ssh2_sftp($ssh);
                    @ssh2_sftp_mkdir($sftp, rtrim($f['path'], '/') . "/$alias", 0755, true);

                    $dst = fopen("ssh2.sftp://$sftp$remote", 'w');
                    $src = fopen($file, 'r');
                    $ok = $dst && $src && (stream_copy_to_stream($src, $dst) !== false);

                    if ($src)
                        fclose($src);
                    if ($dst)
                        fclose($dst);

                    Notifier::log('sftp', $ok ? 200 : 500, $ok ? 'ok' : 'copy failed');
                } else {
                    Notifier::log('sftp', 500, 'auth failed');
                }
            }

            /* ----- unsupported scheme ----- */ else {
                Notifier::log('ftp', 500, 'unsupported scheme ' . $f['scheme']);
            }
        }
    }
}
