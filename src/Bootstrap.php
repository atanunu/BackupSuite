<?php
namespace Backup;

class Bootstrap
{
    public static array $cfg;

    public static function init(string $configPath): void
    {
        self::$cfg = require $configPath;
        session_start();
        if (($m = self::$cfg['tuning']['memory_limit'] ?? '') !== '')
            ini_set('memory_limit', $m);
    }
    public static function cfg(string $k)
    {
        return self::$cfg[$k];
    }
    public static function html($s)
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
