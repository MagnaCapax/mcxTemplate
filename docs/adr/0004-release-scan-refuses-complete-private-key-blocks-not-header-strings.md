# ADR-0004: The release scan refuses complete private key blocks, not header strings

## Metadata
- Author: Väinämöinen
- Date: 2026-11-12
- Status: Accepted

## Context
`tools/templateRelease.php` runs `ContentScan` over the captured rootfs and refuses a release that would bake a private key into a template installed on many machines. The scan refused any file holding the text of a private key BEGIN header. The first real release, from a scrubbed Debian 13 rootfs, was refused on four files shipped by Debian packages:
- the shared-mime-info magic database (`usr/share/mime/magic`, `mime.cache`): a file-type signature, a header string with no body and no END line;
- a fail2ban test (`fail2ban/tests/action_d/test_smtp.py`): a published test key written as several Python string literals;
- that test's bytecode (`__pycache__/test_smtp.cpython-313.pyc`): the same key, its pieces joined into one complete block.

None of them is a secret the capture introduced. A path or extension allowlist (`tests/`, `__pycache__/`, `*.pyc`, `usr/`) would clear them, and would also clear a real key that happens to sit there.

## Options Considered
- Allowlist locations: simple, and lets a real key through because of where it sits. Rejected.
- Exempt by package ownership (dpkg file lists and checksums): the bytecode is generated at install time and is in no checksum list, and the lists can be edited by whoever produced the capture. Rejected.
- A complete-block rule plus an exact, hash-listed exemption for known-public blocks. Chosen.

## Decision
- A file holds a private key only when it contains a complete block: a BEGIN line of a private key type (PEM, OpenSSH, PGP), a body, and the END line of the same type. The body must contain at least one run of four base64 characters and no quote character (`'`, `"`, backtick); nothing else is required of it, so indented, commented, CRLF, JSON-escaped, one-line and binary-embedded keys all count. `src/Lib/Release/PrivateKeyBlock.php` holds the rule.
- A complete block is exempt only when the sha256 of its normalised text is listed in `PrivateKeyBlock::PUBLIC`, each entry commented with the package and upstream file that publish it. Normalisation: the BEGIN line, then each non-empty body line trimmed, then the END line, each followed by one LF; backslash runs followed by `r` or `n` in the body are newlines first. For a plain LF PEM file that is the block as written.
- An exempt finding is not silent: it is reported as `publicFixture`, which does not refuse; `templateRelease.php` prints it as `NOTICE publicFixture <path>`. Any other complete block in the same file makes it `privateKey`.
- No exemption is ever by path, directory or extension. The only location rule stays the SSH host keys `etc/ssh/ssh_host_*_key`, which the configure tasks reset at install time.

## Consequences
- Package test fixtures and file-type signatures no longer block releases; every unlisted complete block still refuses, wherever it sits.
- A key written as separate quoted string pieces in program source is not detected in that form (its bytecode, if present, is). This is the cost of clearing source-level fixtures without an allowlist.
- A new published fixture needs a reviewed list entry; until then it refuses, which is the safe failure.

## References
- ADR-0002 (install core), ADR-0003 (capture tools refuse unsafe inputs).
- `src/Lib/Release/ContentScan.php`, `src/Lib/Release/PrivateKeyBlock.php`, `tests/unit/Release/ContentScanTest.php`.
