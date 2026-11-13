# Changelog

## Unreleased

- Initial release: global access gate middleware with named codes, shareable links, unlock form, header access, signed cookies, path exceptions, IP allowlist, CORS preflight passthrough, `noindex` headers, throttling, events, and `environment-gate:link` / `environment-gate:generate` commands.
- Optional database tokens (`ENVIRONMENT_GATE_DATABASE=true`), stored as hashes and managed with `environment-gate:token:create`, `:list` and `:revoke`.
