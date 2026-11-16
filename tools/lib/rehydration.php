<?php
declare(strict_types=1);

// rehydration.php centralises logic for embedding the mcxTemplate engine into
// a captured system. Scripts should reuse these helpers to keep behaviour
// consistent across tooling.

require_once __DIR__ . '/packageCommon.php';

/**
 * Copy a local path into a destination using rsync to preserve metadata.
 *
 * @param string $source Absolute or relative path to copy.
 * @param string $destination Destination directory path. Created if missing.
 * @return void
 */
function rehydrate_copy_local_path(string $source, string $destination): void
{
    ensureBinary('rsync');
    $cleanSource = rtrim($source, DIRECTORY_SEPARATOR);
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        fail('Unable to create destination for rehydration copy: ' . $destination);
    }
    // Use trailing '/.' to copy contents of the source directory into the
    // destination path rather than nesting the directory itself.
    $command = sprintf('rsync -aAXH %s %s', escapeshellarg($cleanSource . '/.'), escapeshellarg($destination));
    passthru($command, $status);
    if ($status !== 0) {
        fail('Rehydration copy failed with status ' . (string) $status . '.');
    }
}

/**
 * Embed the provisioning engine into a staging directory.
 *
 * Inputs
 * - $enginePath: Path to the engine to embed (repo root or curated subset).
 * - $stagingDir: Staging directory containing a rootfs/ subdirectory.
 * - $embedIntoRootfs: When true, copies the engine into rootfs/opt/mcxTemplate.
 *                     When false, writes the engine under template/ at the
 *                     staging root to be copied on target later.
 * - $embedForce: When true and $embedIntoRootfs is set, overwrite an existing
 *                rootfs/opt/mcxTemplate directory.
 *
 * Effects
 * - Copies engine files into the selected location.
 * - Creates parent directories as needed; skips overwrite unless forced.
 *
 * @return void
 */
function rehydrate_staging(string $enginePath, string $stagingDir, bool $embedIntoRootfs = true, bool $embedForce = false): void
{
    if ($embedIntoRootfs) {
        $rootfs = rtrim($stagingDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rootfs';
        $destination = $rootfs . DIRECTORY_SEPARATOR . 'opt' . DIRECTORY_SEPARATOR . 'mcxTemplate';

        if (is_dir($destination) && !$embedForce) {
            // Skip to avoid clobbering an existing engine copy from the source.
            fwrite(STDERR, "Rehydration: rootfs already contains /opt/mcxTemplate; skipping (use --embed-force to overwrite).\n");
            return;
        }

        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
            fail('Unable to create rootfs/opt directory for embedding.');
        }

        if ($embedForce && is_dir($destination)) {
            // Remove any existing content so the copy is clean.
            rehydrate_remove_directory($destination);
        }

        rehydrate_copy_local_path($enginePath, $destination);
        return;
    }

    // Non-embedded mode: stage under template/ for manual copy later.
    $destination = rtrim($stagingDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'template';
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        fail('Unable to create template staging directory.');
    }
    rehydrate_copy_local_path($enginePath, $destination);
}

/**
 * Rehydrate a rootfs tarball into a combined archive.
 *
 * Inputs
 * - $rootfsArchive: Path to a tar.gz archive containing a root filesystem (no rootfs/ prefix).
 * - $enginePath:     Path to the engine to embed.
 * - $outputPath:     Path for the rehydrated tar.gz or 'php://stdout' to stream.
 * - $embedIntoRootfs, $embedForce: See rehydrate_staging.
 *
 * Output
 * - Writes a tar.gz containing top-level rootfs/ (and optionally template/).
 *
 * @return void
 */
function rehydrate_archive(string $rootfsArchive, string $enginePath, string $outputPath, bool $embedIntoRootfs = true, bool $embedForce = false): void
{
    ensureBinary('tar');
    ensureBinary('pigz');

    $stagingDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mcxTemplate-rehydrate-' . uniqid();
    if (!mkdir($stagingDir, 0755, true)) {
        fail('Unable to create rehydration staging directory.');
    }

    try {
        $rootfsDir = $stagingDir . DIRECTORY_SEPARATOR . 'rootfs';
        if (!mkdir($rootfsDir, 0755, true)) {
            fail('Unable to create rootfs staging directory.');
        }

        // Extract the rootfs archive into rootfs/ while preserving metadata.
        $extractCmd = sprintf('tar -xzf %s -C %s', escapeshellarg($rootfsArchive), escapeshellarg($rootfsDir));
        passthru($extractCmd, $status);
        if ($status !== 0) {
            fail('Extraction of rootfs archive failed with status ' . (string) $status . '.');
        }

        rehydrate_staging($enginePath, $stagingDir, $embedIntoRootfs, $embedForce);

        // Package the staging directory contents. When output is STDOUT, stream directly.
        if ($outputPath === '-' || strtolower($outputPath) === 'stdout' || $outputPath === 'php://stdout') {
            $command = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf - -C %s .', escapeshellarg($stagingDir));
            passthru($command, $pkgStatus);
            if ($pkgStatus !== 0) {
                fail('Rehydrated archive stream failed with status ' . (string) $pkgStatus . '.');
            }
        } else {
            $command = sprintf('tar --numeric-owner --xattrs --acls -I pigz -cf %s -C %s .', escapeshellarg($outputPath), escapeshellarg($stagingDir));
            passthru($command, $pkgStatus);
            if ($pkgStatus !== 0) {
                fail('Rehydrated archive write failed with status ' . (string) $pkgStatus . '.');
            }
        }
    } finally {
        // Best-effort cleanup of staging directory.
        rehydrate_remove_directory($stagingDir);
    }
}

/**
 * Recursively remove a directory tree (best-effort).
 *
 * @param string $path Directory to remove.
 * @return void
 */
function rehydrate_remove_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($path);
}
