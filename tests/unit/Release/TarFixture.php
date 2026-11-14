<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

// TarFixture writes small tar.gz files in pure PHP (ustar, GNU long names, pax paths), so release
// tests need no tar binary and the bytes are exactly what the test says.
final class TarFixture
{
    /**
     * @param array<int,array{0:string,1:string,2?:string,3?:int}> $entries [name, content, type?, mode?] ("0" file, "1" hard link, "2" symlink, "5" dir; mode default 0644)
     */
    public static function write(string $path, array $entries): string
    {
        file_put_contents($path, gzencode(self::bytes($entries)));
        return $path;
    }

    /** @param array<int,array{0:string,1:string,2?:string,3?:int}> $entries */
    public static function bytes(array $entries): string
    {
        $out = '';
        foreach ($entries as $e) {
            $out .= self::entry($e[0], $e[1], $e[2] ?? '0', $e[3] ?? 0644);
        }
        return $out . str_repeat("\0", 1024);
    }

    // entry: names over 100 bytes get a GNU long-name entry first, as GNU tar writes them.
    public static function entry(string $name, string $content, string $type = '0', int $mode = 0644): string
    {
        $out = '';
        $short = $name;
        if (strlen($name) > 100) {
            $out .= self::header('././@LongLink', strlen($name) + 1, 'L') . self::pad($name . "\0");
            $short = substr($name, 0, 100);
        }
        $size = $type === '0' ? strlen($content) : 0;
        $linkTarget = $type === '2' || $type === '1' ? $content : '';
        if (strlen($linkTarget) > 100) {
            // GNU long link name entry (type K) before the link itself.
            $out .= self::header('././@LongLink', strlen($linkTarget) + 1, 'K') . self::pad($linkTarget . "\0");
            $linkTarget = substr($linkTarget, 0, 100);
        }
        return $out . self::header($short, $size, $type, $linkTarget, $mode) . ($type === '0' ? self::pad($content) : '');
    }

    // paxEntry: a pax extended header carrying the real path, then the file.
    public static function paxEntry(string $name, string $content): string
    {
        $record = ' path=' . $name . "\n";
        $len = strlen($record) + strlen((string) (strlen($record) + 3));
        $record = ($len) . $record;
        return self::header('PaxHeader/x', strlen($record), 'x') . self::pad($record) . self::header('placeholder', strlen($content), '0') . self::pad($content);
    }

    public static function header(string $name, int $size, string $type, string $link = '', int $mode = 0644): string
    {
        $h = str_pad(substr($name, 0, 100), 100, "\0") . sprintf('%07o', $mode) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0" . sprintf('%011o', 0) . "\0" . '        ' . $type . str_pad($link, 100, "\0")
            . "ustar\0" . '00' . str_pad('root', 32, "\0") . str_pad('root', 32, "\0") . str_repeat("\0", 16);
        $h = str_pad($h, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($h)));
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }

    // runtime returns the entries of a rootfs that has what the install engine runs: php through the
    // alternatives chain Debian and Ubuntu use, and smartctl.
    /** @return array<int,array{0:string,1:string,2?:string,3?:int}> */
    public static function runtime(): array
    {
        return [['usr/bin/php8.2', "php\n", '0', 0755], ['etc/alternatives/php', '/usr/bin/php8.2', '2'], ['usr/bin/php', '/etc/alternatives/php', '2'],
            ['usr/sbin/smartctl', "smartctl\n", '0', 0755]];
    }

    // keyLine builds the BEGIN or END line of a private key block ($kind '' is PKCS#8, 'PGP' adds ' BLOCK');
    // written in pieces so the file holds no key-shaped literal.
    public static function keyLine(string $edge, string $kind): string
    {
        return '-----' . $edge . ' ' . ($kind === '' ? '' : $kind . ' ') . 'PRIVATE KEY' . ($kind === 'PGP' ? ' BLOCK' : '') . '-----';
    }

    // pem builds a complete private key block with an obviously fake body (never key material).
    public static function pem(string $kind, string $body = "AAAA\n"): string
    {
        return self::keyLine('BEGIN', $kind) . "\n" . $body . self::keyLine('END', $kind) . "\n";
    }

    public static function pad(string $data): string
    {
        return $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }
}
