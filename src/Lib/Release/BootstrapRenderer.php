<?php
declare(strict_types=1);

namespace Lib\Release;

use Lib\Install\InstallRefusal;
use Lib\Install\PlanValidator;

// BootstrapRenderer fills the three constants of bootstrap/mcxInstall.sh. The values end up
// inside single quotes of a root-run shell script, so each is validated against a strict
// pattern first (no quote, space, newline or $ can pass), and the template must contain each
// placeholder exactly once.
final class BootstrapRenderer
{
    /**
     * @param string $template contents of bootstrap/mcxInstall.sh
     */
    public static function render(string $template, string $engineUrl, string $engineSha256, string $apiUrl): string
    {
        $values = [
            '@ENGINE_URL@' => self::url($engineUrl, 'engine url'),
            '@ENGINE_SHA256@' => self::sha($engineSha256),
            '@API_URL@' => self::url($apiUrl, 'api url'),
        ];
        foreach ($values as $placeholder => $value) {
            if (substr_count($template, $placeholder) !== 1) {
                throw new InstallRefusal('bootstrapTemplateInvalid', $placeholder . ' must appear exactly once');
            }
            $template = str_replace($placeholder, $value, $template);
        }
        if (strpos($template, '@') !== false && preg_match('/@[A-Z_]+@/', $template) === 1) {
            throw new InstallRefusal('bootstrapTemplateInvalid', 'an unknown placeholder is left in the template');
        }
        return $template;
    }

    // url: the plain https URL pattern of the plan, plus a shell-safety check on top.
    private static function url(string $url, string $what): string
    {
        PlanValidator::httpsUrl($url, $what);
        if (preg_match('/^[A-Za-z0-9:\/._~%+-]+$/D', $url) !== 1) {
            throw new InstallRefusal('bootstrapValueInvalid', $what . ' holds a character that is not shell-safe');
        }
        return $url;
    }

    private static function sha(string $sha): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
            throw new InstallRefusal('bootstrapValueInvalid', 'engine sha256 is not 64 lowercase hex characters');
        }
        return $sha;
    }
}
