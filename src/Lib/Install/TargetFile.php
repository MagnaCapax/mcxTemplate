<?php
declare(strict_types=1);

namespace Lib\Install;

// TargetFile writes one file inside the new root from the rescue side. The
// archive is untrusted until proven otherwise, so a symlinked parent directory
// or a symlinked destination must never carry the write out of the target root
// (CWE-59): the parent is resolved and checked, an existing entry is removed,
// and the file is created exclusively.
final class TargetFile
{
    public static function write(string $root, string $relative, string $content): void
    {
        $root = rtrim($root, '/');
        if ($relative === '' || $relative[0] !== '/' || strpos($relative, '..') !== false) {
            throw new InstallRefusal('targetWriteRefused', 'target file path must be absolute and clean');
        }
        $rootReal = realpath($root);
        $parentReal = realpath($root . dirname($relative));
        if ($rootReal === false || $parentReal === false
            || ($parentReal !== $rootReal && strncmp($parentReal, $rootReal . '/', strlen($rootReal) + 1) !== 0)) {
            throw new InstallRefusal('targetWriteRefused', 'target directory is missing or resolves outside the target root');
        }
        $file = $parentReal . '/' . basename($relative);
        if (is_link($file) || file_exists($file)) {
            if (is_dir($file) && !is_link($file)) {
                throw new InstallRefusal('targetWriteRefused', 'target file path is a directory');
            }
            @unlink($file);
        }
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new InstallRefusal('targetWriteRefused', 'target file could not be created');
        }
        $written = fwrite($handle, $content);
        $closed = fclose($handle);
        if ($written !== strlen($content) || !$closed) {
            // A short or failed write leaves a truncated file: remove it and refuse.
            @unlink($file);
            throw new InstallRefusal('targetWriteRefused', 'target file could not be written completely');
        }
        @chmod($file, 0644);
    }
}
