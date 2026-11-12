<?php
declare(strict_types=1);

namespace Lib\Install;

// TmpfsGrower raises the size limit of the tmpfs that holds the staging directory. The rescue
// system's initramfs mounts /run as tmpfs at 10% of RAM, so a 15 GiB unit gets about 1.5 GiB there
// while 14 GiB of RAM is free: a template that fits in RAM would be refused only because of that
// default limit. A tmpfs size is a cap, not a reservation, so raising it costs nothing until bytes
// are written, and the caller has already checked that free RAM can hold them.
final class TmpfsGrower
{
    private CommandRunner $reader;
    private CommandRunner $writer;
    /** @var callable(string):(int|float|false) */
    private $totalSpace;

    /**
     * @param CommandRunner                                $reader     findmnt only
     * @param CommandRunner                                $writer     the remount; never called for anything but tmpfs
     * @param callable(string):(int|float|false)|null      $totalSpace disk_total_space stand-in for tests
     */
    public function __construct(CommandRunner $reader, CommandRunner $writer, ?callable $totalSpace = null)
    {
        $this->reader = $reader;
        $this->writer = $writer;
        $this->totalSpace = $totalSpace ?? 'disk_total_space';
    }

    /**
     * Make the filesystem under $dir offer at least $need free bytes when it is tmpfs. $free is what
     * it offers now (int|float, as disk_free_space returns it). Returns true when the remount succeeded; false leaves everything as it was
     * (not tmpfs, size unknown, remount refused), and the caller re-measures either way.
     */
    public function growFor(string $dir, int $need, $free): bool
    {
        // FSTYPE first: the target may be any path, the type is one word.
        $found = $this->reader->run(['findmnt', '-n', '-o', 'FSTYPE,TARGET', '--target', $dir]);
        $parts = explode(' ', trim($found['out']), 2);
        if ($found['code'] !== 0 || count($parts) !== 2 || $parts[0] !== 'tmpfs' || $parts[1] === '') {
            return false;
        }
        $total = ($this->totalSpace)($dir);
        if ($total === false) {
            return false;
        }
        // The new limit is what is used now plus what the template needs; the kernel rounds it up to whole pages.
        $size = (int) $total - (int) $free + $need;
        $done = $this->writer->run(['mount', '-o', 'remount,size=' . $size, $parts[1]]);
        return $done['code'] === 0;
    }
}
