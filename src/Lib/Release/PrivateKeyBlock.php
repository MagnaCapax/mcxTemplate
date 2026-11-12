<?php
declare(strict_types=1);

namespace Lib\Release;

// PrivateKeyBlock finds complete private key blocks in file content, for ContentScan.
//
// A complete block is a BEGIN line of a private key type (PEM such as RSA, EC, PKCS#8 and encrypted PKCS#8,
// OpenSSH, or a PGP private key block), a body, and the END line of the SAME type after it. The body is
// everything between the two lines; it must hold at least one run of four base64 characters and no quote
// character (' " `). Nothing else is required of it, so a key that is indented, commented out, written
// with CRLF, JSON "\n" escapes or spaces instead of newlines, or embedded in a binary is still a key.
// What is not a key:
//   - a header string on its own (no END line), such as a file-type magic signature;
//   - a block whose body is broken up by string quotes: a key written as several string literals in
//     program source (test fixtures do this). Its compiled form joins the pieces and IS found.
// Where the file sits never matters here; ContentScan alone decides the SSH host key exception.
//
// Known-public blocks: a few distribution packages ship a published private key as a test fixture.
// Such a block is matched by the sha256 of its normalised text (normalise() below) against PUBLIC.
final class PrivateKeyBlock
{
    private const BEGIN = '/-----BEGIN ([A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?)-----/';

    // sha256 of the normalised block => its public source (package and upstream file).
    // Add an entry only for a key its upstream publishes on purpose (a test fixture or an example), with the
    // package and file named, after checking the block in that package. Never add a key a capture produced,
    // and never exempt by path: the same bytes anywhere else are exempt, a different key anywhere is not.
    public const PUBLIC = [
        // fail2ban 1.1.0-8 (Debian 13): the test key in fail2ban/tests/action_d/test_smtp.py, which the .py splits
        // across string literals and py3compile joins into __pycache__/test_smtp.cpython-313.pyc at install time.
        // Hash checked 2026-11-12 on the debian-13 base rootfs capture and on a separately installed copy.
        '9b8842459141a13fd3563421f2ed1602a6457e1fff3af0b15761ea51260260a8' => 'fail2ban tests/action_d/test_smtp.py fixture',
    ];

    /**
     * Every complete block in $data, each normalised.
     *
     * @return string[]
     */
    public static function find(string $data): array
    {
        if (preg_match_all(self::BEGIN, $data, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return [];
        }
        $blocks = [];
        foreach ($hits as $hit) {
            $begin = $hit[0][0];
            $end = '-----END ' . $hit[1][0] . '-----';
            $bodyAt = $hit[0][1] + strlen($begin);
            $endAt = strpos($data, $end, $bodyAt);
            if ($endAt === false) {
                continue; // a header with no END line of its type is not a key
            }
            $body = substr($data, $bodyAt, $endAt - $bodyAt);
            if (strpbrk($body, "'\"`") !== false || preg_match('#[A-Za-z0-9+/]{4}#', $body) !== 1) {
                continue; // split across string literals, or no body at all
            }
            $blocks[] = self::normalise($begin, $body, $end);
        }
        return $blocks;
    }

    /**
     * The text a block is hashed as: the BEGIN line, then each non-empty body line trimmed, then the END line,
     * each followed by one "\n". Before the body is split into lines, every backslash run followed by "r" or "n"
     * (a string escape such as \n or \\n) becomes a newline. Trimming removes space, tab, CR, LF, NUL and VT.
     * For a plain PEM file with LF line endings this is exactly the block as the file holds it.
     */
    public static function normalise(string $begin, string $body, string $end): string
    {
        $body = preg_replace('/\\\\+[rn]/', "\n", $body) ?? $body;
        $lines = array_filter(array_map('trim', explode("\n", $body)), static fn (string $line): bool => $line !== '');
        return $begin . "\n" . implode('', array_map(static fn (string $line): string => $line . "\n", $lines)) . $end . "\n";
    }

    /**
     * Which of the blocks are known public and which are not.
     *
     * @param string[] $blocks normalised blocks from find()
     * @param array<string,string> $public sha256 => source
     * @return array{0:int,1:int} count of known-public blocks, count of all other blocks
     */
    public static function classify(array $blocks, array $public): array
    {
        $known = 0;
        foreach ($blocks as $block) {
            if (isset($public[hash('sha256', $block)])) {
                $known++;
            }
        }
        return [$known, count($blocks) - $known];
    }
}
