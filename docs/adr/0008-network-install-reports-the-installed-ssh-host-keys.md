# ADR-0008: The network install reports the installed SSH host keys

## Metadata
- Author: agent draft for review by Aleksi Ursin / MCX
- Date: 2026-11-14
- Status: Proposed

## Context
After a network install ([ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md)) the node reboots into the new system, and the operator side logs in over SSH with the root password the install generated, to check the result. That first login had nothing to compare the host key against: configure task 13 generates fresh host keys inside the chroot, so no key is known in advance. Trusting whatever key answers on port 22 means a machine that answers for the node's address during that window receives the new root password (CWE-322).

Pinning the first key seen does not work either. Until the node reboots, the rescue system answers on port 22 with its own key, so the first key seen is usually the wrong one.

The installer already holds a channel the operator side trusts: the result it sends to nodeCore is authenticated with the job token, and nodeCore keeps the result's facts in the job's events.

## Options Considered
- Pin the first key seen (trust on first use): pins the rescue system's key, which breaks the check and protects nothing.
- Pre-generate the host keys on the operator side and ship them in the plan: private keys would travel over the network and sit in nodeCore. Rejected.
- Read the public keys the install just generated and send them with the result: chosen. Only public keys travel, over the channel that already carries the root password.

## Decision
- A read-only step `hostKeys` runs after `verifyBoot`, while the target is still mounted. It reads `etc/ssh/ssh_host_ed25519_key.pub` and `etc/ssh/ssh_host_ecdsa_key.pub` (`src/Lib/Install/HostKeys.php`). Private key files are never opened.
- A key is reported as `type base64`, with the comment dropped. Only `ssh-ed25519` and `ecdsa-sha2-nistp256/384/521` lines are accepted.
- A symlinked `etc`, `etc/ssh` or key file is refused. The tree came from an archive, and a link could point at another system's key.
- The network mode sends the list as `facts.hostKeys` in `/install/result`.
- Fail-soft: a missing or refused key gives an empty list, and the install still completes. Whoever logs in decides; install-node refuses an unauthenticated login when the list is empty.

## Consequences
- The first login can use strict host key checking against keys the installer reported over the token channel.
- An installed system whose template has no SSH server, or an older engine without this step, reports no keys. The verifying side must then refuse rather than fall back to an unchecked login.
- The keys are public and are stored in nodeCore's job events.

## References
- [ADR-0002](0002-disk-preparation-and-install-live-in-mcxtemplate.md) network install
- `distros/debian/common/tasks/13-reset-ssh-host-keys.php`
- CWE-322: Key Exchange without Entity Authentication
