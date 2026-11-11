<?php
declare(strict_types=1);

namespace Tests\Unit\Release;

// TarFixture writes small tar.gz files in pure PHP (ustar, GNU long names, pax paths), so release
// tests need no tar binary and the bytes are exactly what the test says.
final class TarFixture
{
    /**
     * @param array<int,array{0:string,1:string,2?:string}> $entries [name, content, type?] ("0" file, "2" symlink, "5" dir)
     */
    public static function write(string $path, array $entries): string
    {
        file_put_contents($path, gzencode(self::bytes($entries)));
        return $path;
    }

    /** @param array<int,array{0:string,1:string,2?:string}> $entries */
    public static function bytes(array $entries): string
    {
        $out = '';
        foreach ($entries as $e) {
            $out .= self::entry($e[0], $e[1], $e[2] ?? '0');
        }
        return $out . str_repeat("\0", 1024);
    }

    // entry: names over 100 bytes get a GNU long-name entry first, as GNU tar writes them.
    public static function entry(string $name, string $content, string $type = '0'): string
    {
        $out = '';
        $short = $name;
        if (strlen($name) > 100) {
            $out .= self::header('././@LongLink', strlen($name) + 1, 'L') . self::pad($name . "\0");
            $short = substr($name, 0, 100);
        }
        $size = $type === '0' ? strlen($content) : 0;
        $linkTarget = $type === '2' ? $content : '';
        return $out . self::header($short, $size, $type, $linkTarget) . ($type === '0' ? self::pad($content) : '');
    }

    // paxEntry: a pax extended header carrying the real path, then the file.
    public static function paxEntry(string $name, string $content): string
    {
        $record = ' path=' . $name . "\n";
        $len = strlen($record) + strlen((string) (strlen($record) + 3));
        $record = ($len) . $record;
        return self::header('PaxHeader/x', strlen($record), 'x') . self::pad($record) . self::header('placeholder', strlen($content), '0') . self::pad($content);
    }

    public static function header(string $name, int $size, string $type, string $link = ''): string
    {
        $h = str_pad(substr($name, 0, 100), 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0" . sprintf('%011o', 0) . "\0" . '        ' . $type . str_pad($link, 100, "\0")
            . "ustar\0" . '00' . str_pad('root', 32, "\0") . str_pad('root', 32, "\0") . str_repeat("\0", 16);
        $h = str_pad($h, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($h)));
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }

    // pem builds a PEM-style private key header; written in pieces so the file holds no key-shaped literal.
    public static function pem(string $kind): string
    {
        return '-----' . 'BEGIN ' . $kind . ' PRIVATE KEY' . ($kind === 'PGP' ? ' BLOCK' : '') . '-----' . "\nAAAA\n";
    }

    public static function pad(string $data): string
    {
        return $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }
}
