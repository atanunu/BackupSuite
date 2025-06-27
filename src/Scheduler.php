<?php
namespace Backup;

use DateTime;

/**
 * Scheduler
 * ---------
 * • Evaluates cron-style expressions
 * • Launches due backups once per minute
 * • Writes results to scheduler.log
 * • Sends daily digest e-mail at a fixed time
 *
 * CLI entry:  php public/index.php cron
 */
class Scheduler
{
    /* --------------------------------------------------------
     *  cronMatch()
     * --------------------------------------------------------
     *  Supports:  *  */N  a-b  a,b,c
     * -------------------------------------------------------- */
    private static function cronMatch(string $expr, DateTime $t): bool
    {
        $parts = explode(' ', preg_replace('/\s+/', ' ', trim($expr)));
        if (count($parts) !== 5) return false;

        [$mn, $hr, $dom, $mon, $dow] = $parts;
        $checks = [
            [$mn,  $t->format('i')],
            [$hr,  $t->format('G')],
            [$dom, $t->format('j')],
            [$mon, $t->format('n')],
            [$dow, $t->format('w')],
        ];

        foreach ($checks as [$field, $now]) {
            if ($field === '*') continue;          // wildcard always matches
            $hit = false;

            foreach (explode(',', $field) as $token) {
                /* step */           //   */5   10-30/2
                if (str_contains($token, '/')) {
                    [$range, $step] = explode('/', $token);
                    [$a, $b] = $range === '*' ? [0, 59] : explode('-', $range);
                    if ($now >= $a && $now <= $b && (($now - $a) % (int)$step) === 0) {
                        $hit = true; break;
                    }
                }
                /* range */          //  10-18
                elseif (str_contains($token, '-')) {
                    [$a, $b] = explode('-', $token);
                    if ($now >= $a && $now <= $b) { $hit = true; break; }
                }
                /* literal */        //  0  15
                else {
                    if ($now == (int)$token) { $hit = true; break; }
                }
            }
            if (!$hit) return false;
        }
        return true;
    }

    /* --------------------------------------------------------
     *  run()  – invoked from CLI every minute
     * -------------------------------------------------------- */
    public static function run(): void
    {
        $cfg = Bootstrap::cfg('schedule');
        if (!($cfg['enabled'] ?? false)) return;

        $now   = new DateTime('now');
        $logF  = __DIR__ . '/../scheduler.log';

        /* ---- 1. run due jobs ---- */
        foreach ($cfg['jobs'] as $job) {
            if (self::cronMatch($job['cron'], $now)) {
                try {
                    $f   = Db::runBackup($job['db']);
                    $msg = $now->format('c') . ' OK ' . $job['db'] . ' ' . basename($f);
                } catch (\Throwable $e) {
                    $msg = $now->format('c') . ' FAIL ' . $job['db'] . ' ' . $e->getMessage();
                }
                file_put_contents($logF, $msg . "\n", FILE_APPEND);
            }
        }

        /* ---- 2. daily digest ---- */
        if (
            ($cfg['digest']['enabled'] ?? false) &&
            $now->format('H:i') === $cfg['digest']['time']
        ) {
            $today = substr($now->format(DATE_ATOM), 0, 10);
            $lines = array_filter(
                explode("\n", @file_get_contents($logF)),
                fn($l) => str_starts_with($l, $today)
            );

            if ($lines) {
                Notifier::alert(
                    'Backup Digest ' . $today,
                    implode("\n", $lines),
                    true           // success flag so we use success_drivers
                );
            }
        }
    }
}
