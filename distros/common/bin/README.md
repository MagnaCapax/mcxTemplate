# Hostname Configuration Script

The `configure_hostname.php` script configures both the static and pretty hostnames
for a machine. The script is designed to be idempotent and uses system tools such as
`hostnamectl` when available, with a fallback to the legacy `hostname` utility.

## Usage

Run the script with PHP while ensuring the required environment variable is set:

```bash
MCX_HOSTNAME="example-host" MCX_PRETTY_HOSTNAME="Example Host" php configure_hostname.php
```

* `MCX_HOSTNAME` (required) — the static hostname that will be applied.
* `MCX_PRETTY_HOSTNAME` (optional) — the descriptive hostname applied when
  `hostnamectl` supports it.

The script must be executed with root privileges because it modifies system
hostname settings and may update `/etc/hostname`.

## Behavior

1. Validates required environment variables and hostname formatting.
2. Detects the best available hostname tool (`hostnamectl` preferred).
3. Applies the static hostname and, when possible, the pretty hostname.
4. Leaves the system unchanged if the hostname already matches the requested
   value, aside from ensuring the pretty hostname when provided.
