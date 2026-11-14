# ADR-0012: Every install failure reports a short, redacted reason to nodeCore

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-14
- Status: Proposed

## Context
The network install reports a failure to nodeCore as the event `failed` with `{phase, code}` and, since the first live install, an `error` text for `stepFailed` only. The first live install wiped the disk and then failed with only `{"phase":"partition","code":"stepFailed"}`; the cause stayed on the console of a node in the install environment, which has no sshd, so it could not be read from anywhere. That case was fixed for `stepFailed`. Every other refusal still sent the code alone: a `verifyFailed` after the wipe did not say which check failed, a `deviceChanged` did not say which disk, and an unexpected exception was only a class name on the console. The old code comment kept the message back because "other refusals can name disk serials, which nodeCore keeps out of stored events".

## Options Considered
- Send nothing more than the code: leaves every failure after the wipe blind on a node without console access.
- Send every message as it is: refusal messages are written as fixed text, but nothing guarantees a future one will not name a serial, and a step's stderr can carry any text.
- Send the message with the values the old comment protects replaced, bounded in length: chosen.

## Decision
- The `failed` event carries `error` for every failure: the refusal's own message (`<code>: <detail>`: the check that failed, the step and its stderr, the role of a device that changed), cleaned to printable ASCII and cut to `InstallReporter::ERROR_MAX` (400) bytes.
- Redaction is kept, not dropped. Before the cut, every serial and WWN of every disk the hello listed, and the job token, is replaced with `[redacted]` in any letter case (`InstallReporter::withhold()`). Values shorter than four characters are not replaced. A cut therefore never leaves half a serial.
- An unexpected exception (anything that is not an `InstallRefusal`) reports `internalError` with only its class name and the file and line where it was thrown. Its message can carry anything, and stays on the console.
- `DeviceGuard` names the plan role (`root`, `boot0`, `boot1`) of a changed disk, never a serial.

## Consequences
- An operator reading the job's events can tell why an install failed after the wipe without console access.
- A refusal message that someone later writes with a value in it is still safe for serials, WWNs and the token; any other value in a message is sent as written, so messages stay field names and counts ([InstallRefusal](../../src/Lib/Install/InstallRefusal.php)).
- The cut at 400 bytes can hide the end of a long stderr; the console still has all of it.

## References
- [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md), [ADR-0011](0011-strict-installs-fail-on-a-failed-hostname-or-network-render-and-verification-reads-both-back.md)
- `src/Lib/Install/InstallReporter.php`, `src/Lib/Install/NetworkInstall.php`, `src/Lib/Install/DeviceGuard.php`
- `tests/unit/Install/NetworkInstallTest.php`
