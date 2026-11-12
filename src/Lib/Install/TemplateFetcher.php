<?php
declare(strict_types=1);

namespace Lib\Install;

// TemplateFetcher downloads the template archive named by the plan into the private
// staging path (tmpfs on the rescue system). Before the first byte it refuses when the
// staging filesystem or the free RAM cannot hold sizeBytes plus a margin, so a short
// rescue system fails with a named code instead of an out-of-memory kill in the middle
// of a wipe. The download is capped at sizeBytes. Installer::prepare() then checks size
// and sha256 of the file it just received, before anything is wiped.
final class TemplateFetcher
{
    // Headroom for the rescue system itself, the engine and the tar/PHP processes.
    public const MARGIN_BYTES = 268435456;

    private HttpTransport $http;
    /** @var callable(string):(int|float|false) */
    private $freeSpace;
    /** @var callable():int */
    private $memAvailable;
    private ?TmpfsGrower $grower;

    /**
     * @param callable(string):(int|float|false)|null $freeSpace   disk_free_space stand-in for tests
     * @param callable():int|null                     $memAvailable MemAvailable in bytes, 0 when unknown
     * @param TmpfsGrower|null                        $grower      raises a too-small tmpfs limit when free RAM can hold the template; null keeps the plain refusal
     */
    public function __construct(HttpTransport $http, ?callable $freeSpace = null, ?callable $memAvailable = null, ?TmpfsGrower $grower = null)
    {
        $this->http = $http;
        $this->freeSpace = $freeSpace ?? 'disk_free_space';
        $this->memAvailable = $memAvailable ?? [self::class, 'readMemAvailable'];
        $this->grower = $grower;
    }

    /**
     * Download $url to the NEW file $dest. $dest's directory is the private staging directory.
     */
    public function fetch(string $url, string $dest, int $sizeBytes): void
    {
        $need = $sizeBytes + self::MARGIN_BYTES;
        $free = ($this->freeSpace)(dirname($dest));
        $mem = ($this->memAvailable)();
        // The rescue system caps /run at 10% of RAM. When that cap is the only thing in the way (the free
        // RAM measured here holds the template plus margin), raise the cap once and measure again. An
        // unknown RAM figure (0) never grows it: the RAM check below could not vouch for the new size.
        if ($this->grower !== null && $free !== false && $free < $need && $mem >= $need) {
            $this->grower->growFor(dirname($dest), $need, $free);
            $free = ($this->freeSpace)(dirname($dest));
        }
        if ($free === false || $free < $need) {
            throw new InstallRefusal('templateNoSpace', 'the staging filesystem cannot hold the template plus its margin');
        }
        // 0 means /proc/meminfo could not be read: only the filesystem check applies then.
        if ($mem > 0 && $mem < $need) {
            throw new InstallRefusal('templateNoSpace', 'free memory cannot hold the template plus its margin');
        }
        $answer = $this->http->download($url, $dest, $sizeBytes);
        if ($answer['error'] !== '') {
            throw new InstallRefusal('templateDownloadFailed', 'download failed: ' . $answer['error']);
        }
    }

    // readMemAvailable returns the MemAvailable line of /proc/meminfo in bytes, 0 when unreadable.
    public static function readMemAvailable(string $file = '/proc/meminfo'): int
    {
        $text = @file_get_contents($file);
        if (is_string($text) && preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $text, $m) === 1) {
            return (int) $m[1] * 1024;
        }
        return 0;
    }
}
