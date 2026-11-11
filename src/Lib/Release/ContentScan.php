<?php
declare(strict_types=1);

namespace Lib\Release;

// ContentScan reads a captured root filesystem archive (tar.gz) ONCE, as a stream, and reports
// what must never be baked into a template that is installed on many machines:
//   authorizedKeys  any file named authorized_keys / authorized_keys2 (whatever it holds),
//   privateKey      a private key (PEM/OpenSSH/PGP block) anywhere except the SSH host keys
//                   etc/ssh/ssh_host_*_key, which the configure tasks reset at install time,
//   rootPassword    etc/shadow whose root entry is not locked (no password hash prefix "!" or "*"),
//   shadowMissing   no etc/shadow in the archive at all (the root entry cannot be checked),
//   archiveMalformed the stream is not a tar this scanner can read to its end.
// It never extracts anything and returns paths and codes only, never file content.
final class ContentScan
{
    // Keys are small; a bigger file is not read for content (it is skipped over).
    private const MAX_CONTENT = 262144;
    private const BLOCK = 512;
    private const KEY_MARK = '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY/';
    private const HOST_KEY = '#^etc/ssh/ssh_host_[a-z0-9_]+_key$#D';

    /**
     * @return array<int,array{code:string,path:string}> empty when the archive is clean
     */
    public static function scan(string $archive): array
    {
        $gz = @gzopen($archive, 'rb');
        if ($gz === false) {
            return [['code' => 'archiveMalformed', 'path' => '']];
        }
        $findings = [];
        $shadowSeen = false;
        try {
            $longName = '';
            $paxPath = '';
            $paxSize = null;
            while (true) {
                $header = self::readExact($gz, self::BLOCK);
                if ($header === null || trim($header, "\0") === '') {
                    break; // end of archive (zero block) or clean EOF
                }
                $entry = self::parseHeader($header);
                $type = $entry['type'];
                if ($type === 'S') {
                    throw new \RuntimeException('sparse entries are not supported');
                }

                if ($type === 'L' || $type === 'x' || $type === 'g' || $type === 'K') {
                    $data = self::readData($gz, $entry['size'], true);
                    if ($type === 'L') {
                        $longName = rtrim($data, "\0");
                    } elseif ($type === 'x') {
                        [$paxPath, $paxSize] = self::paxRecords($data);
                    }
                    continue; // metadata entries describe the NEXT entry; keep $longName/$paxPath for it
                }

                // The pax size and path (when present) win over the header fields, then the GNU long name.
                $size = $paxSize ?? $entry['size'];
                $name = $paxPath !== '' ? $paxPath : ($longName !== '' ? $longName : $entry['name']);
                $path = self::normalise($name);
                $longName = '';
                $paxPath = '';
                $paxSize = null;
                $isFile = $type === '0' || $type === "\0" || $type === '7';
                $base = basename($path);
                if ($base === 'authorized_keys' || $base === 'authorized_keys2') {
                    $findings[] = ['code' => 'authorizedKeys', 'path' => $path];
                }
                if ($isFile && $size > 0 && $size <= self::MAX_CONTENT) {
                    $data = self::readData($gz, $size, true);
                    if (preg_match(self::KEY_MARK, $data) === 1 && preg_match(self::HOST_KEY, $path) !== 1) {
                        $findings[] = ['code' => 'privateKey', 'path' => $path];
                    }
                    if ($path === 'etc/shadow') {
                        $shadowSeen = true;
                        if (!self::rootLocked($data)) {
                            $findings[] = ['code' => 'rootPassword', 'path' => $path];
                        }
                    }
                } else {
                    if ($isFile && $path === 'etc/shadow') {
                        $shadowSeen = true;
                        $findings[] = ['code' => 'rootPassword', 'path' => $path]; // unreadable size: cannot prove it is locked
                    }
                    self::readData($gz, $isFile ? $size : 0, false);
                }
            }
        } catch (\RuntimeException $e) {
            $findings[] = ['code' => 'archiveMalformed', 'path' => ''];
        } finally {
            gzclose($gz);
        }
        if (!$shadowSeen && !in_array('archiveMalformed', array_column($findings, 'code'), true)) {
            $findings[] = ['code' => 'shadowMissing', 'path' => 'etc/shadow'];
        }
        return $findings;
    }

    // rootLocked: the root line's password field must start with "!" or "*" (locked); empty or a hash is not locked.
    public static function rootLocked(string $shadow): bool
    {
        foreach (explode("\n", $shadow) as $line) {
            $fields = explode(':', $line);
            if (($fields[0] ?? '') === 'root') {
                return isset($fields[1]) && $fields[1] !== '' && ($fields[1][0] === '!' || $fields[1][0] === '*');
            }
        }
        return false; // no root line: refuse, a template without root is not a template
    }

    /**
     * @return array{name:string,size:int,type:string}
     */
    private static function parseHeader(string $h): array
    {
        // Checksum: the sum of all header bytes with the checksum field counted as spaces.
        $stored = (int) octdec(trim(substr($h, 148, 8), "\0 "));
        $sum = array_sum(array_map('ord', str_split(substr($h, 0, 148) . '        ' . substr($h, 156))));
        if (trim(substr($h, 148, 8), "\0 ") === '' || $sum !== $stored) {
            throw new \RuntimeException('bad header checksum');
        }
        $name = rtrim(substr($h, 0, 100), "\0");
        // POSIX ustar carries a path prefix; GNU tar ("ustar  ") reuses those bytes for other fields.
        if (substr($h, 257, 6) === "ustar\0") {
            $prefix = rtrim(substr($h, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }
        $sizeField = substr($h, 124, 12);
        if ((ord($sizeField[0]) & 0x80) !== 0) {
            // GNU base-256 size for files over 8 GiB.
            $size = 0;
            for ($i = 1; $i < 12; $i++) {
                $size = ($size << 8) | ord($sizeField[$i]);
            }
        } else {
            $size = (int) octdec(trim($sizeField, "\0 "));
        }
        return ['name' => $name, 'size' => $size, 'type' => $h[156]];
    }

    // normalise drops "./" and leading slashes so rules match "etc/shadow" however the archive spells it.
    private static function normalise(string $name): string
    {
        $name = preg_replace('#^(\./+|/+)+#', '', $name) ?? $name;
        return rtrim($name, '/');
    }

    /**
     * Read a data area of $size bytes plus its padding to the block size.
     * With $keep the bytes are returned; without it they are read and dropped.
     */
    private static function readData($gz, int $size, bool $keep): string
    {
        $kept = '';
        $left = $size;
        while ($left > 0) {
            $chunk = self::readExact($gz, min($left, 65536));
            if ($chunk === null) {
                throw new \RuntimeException('truncated archive');
            }
            $left -= strlen($chunk);
            if ($keep) {
                $kept .= $chunk;
            }
        }
        $pad = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
        if ($pad > 0 && self::readExact($gz, $pad) === null) {
            throw new \RuntimeException('truncated archive');
        }
        return $kept;
    }

    // readExact returns exactly $len bytes, or null at a clean end of stream before the first byte.
    private static function readExact($gz, int $len): ?string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $part = gzread($gz, $len - strlen($buf));
            if ($part === false || $part === '') {
                if ($buf === '') {
                    return null;
                }
                throw new \RuntimeException('truncated archive');
            }
            $buf .= $part;
        }
        return $buf;
    }

    /**
     * Parse a pax extended header ("<len> key=value\n" records) for the keys that change the next entry.
     *
     * @return array{0:string,1:?int} path override, size override
     */
    private static function paxRecords(string $data): array
    {
        $path = '';
        $size = null;
        $offset = 0;
        while ($offset < strlen($data)) {
            $space = strpos($data, ' ', $offset);
            $len = $space === false ? 0 : (int) substr($data, $offset, $space - $offset);
            if ($len <= 0 || $offset + $len > strlen($data)) {
                throw new \RuntimeException('bad pax record');
            }
            $record = substr($data, $space + 1, $offset + $len - $space - 2);
            $eq = strpos($record, '=');
            if ($eq !== false) {
                $key = substr($record, 0, $eq);
                $value = substr($record, $eq + 1);
                if ($key === 'path') {
                    $path = $value;
                } elseif ($key === 'size' && ctype_digit($value)) {
                    $size = (int) $value;
                }
            }
            $offset += $len;
        }
        return [$path, $size];
    }
}
