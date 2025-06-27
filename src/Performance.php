<?php
namespace Backup;

class Performance
{
    public static function skip(string $tbl): bool
    {
        foreach (Bootstrap::cfg('performance')['skip_tables'] as $p) {
            if ($p === '')
                continue;
            if ($p[0] == '/' && preg_match($p, $tbl))
                return true;
            if (strcasecmp($p, $tbl) == 0)
                return true;
        }
        return false;
    }
    public static function mysqldump(): ?string
    {
        $cfg = Bootstrap::cfg('performance')['mysqldump_path'];
        if ($cfg)
            return $cfg;
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'] as $p)
            if (`$p --version 2>/dev/null`)
                return $p;
        return null;
    }
}
