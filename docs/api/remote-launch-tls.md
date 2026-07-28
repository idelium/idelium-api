# Remote launcher TLS policy

Idelium API requires every remote launcher endpoint to use HTTPS. Certificate
chain and hostname verification are enabled by default, and remote requests use
finite connection and response timeouts.

## Configuration

| Variable | Purpose | Default |
| --- | --- | --- |
| `IDELIUM_LAUNCHER_CA_BUNDLE` | Readable PEM bundle for a private launcher certificate authority | system trust store |
| `IDELIUM_LAUNCHER_CONNECT_TIMEOUT` | Connection timeout in seconds | `5` |
| `IDELIUM_LAUNCHER_TIMEOUT` | Complete response timeout in seconds | `30` |
| `IDELIUM_LAUNCHER_INSECURE` | Disable certificate verification in `local` or `testing` only | `false` |

Production deployments must leave `IDELIUM_LAUNCHER_INSECURE=false`. When an
organization uses a private certificate authority, mount its public CA bundle
read-only into the API container and set `IDELIUM_LAUNCHER_CA_BUNDLE` to that
container path. Do not mount a private key as the CA bundle.

An unreadable CA bundle, invalid endpoint, failed certificate verification,
connection timeout, or non-successful launcher response fails the launch before
execution. API diagnostics use classified error codes and omit upstream response
bodies, credentials, session identifiers, and authorization headers.

## Compatibility and migration

The request method and payload remain compatible with the legacy launcher
listener. Platform hostnames using `http://` are intentionally rejected because
they cannot provide authenticated transport. Before upgrading, replace each
legacy launcher URL with an `https://` endpoint whose certificate is trusted by
the system trust store or by the configured CA bundle.

The temporary insecure mode is intended only for isolated local diagnosis. The
application refuses to enable it outside Laravel's `local` and `testing`
environments.
