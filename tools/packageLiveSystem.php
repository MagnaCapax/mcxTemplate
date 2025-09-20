#!/usr/bin/env php
<?php
declare(strict_types=1);

// packageLiveSystem.php stays in place as a thin compatibility shim.
// The wrapper keeps the historical entry point alive after the rename.
// It simply defers to the modern implementation in templateAssemble.php.

require_once __DIR__ . '/templateAssemble.php';
