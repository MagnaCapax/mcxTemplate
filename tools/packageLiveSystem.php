#!/usr/bin/env php
<?php
declare(strict_types=1);

// packageLiveSystem.php remains for compatibility so existing tooling keeps working.
// The wrapper forwards execution to the maintained template assembler entry point.

// Ensure help banners show the compatibility script name when invoked directly.
$_SERVER['argv'][0] = __FILE__;

// Delegate the actual work to the modern implementation without duplicating logic.
require_once __DIR__ . '/templateAssemble.php';
