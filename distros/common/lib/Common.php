<?php
declare(strict_types=1);

namespace Distros\Common;

$repoRoot = dirname(__DIR__, 3);
require_once $repoRoot . '/src/Lib/Provisioning/Common.php';

// Expose the shared provisioning helpers under the historical namespace.
class Common extends \Lib\Provisioning\Common
{
}
