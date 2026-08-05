# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-05

Initial release. Deliberately minimal — trace context and Standard Webhooks only, which is what the first adopting service consumes. Redaction and retry helpers are deferred until a second consumer needs them rather than being built speculatively.

### Added

- `TraceContext` — W3C Trace Context parsing, generation and serialisation. Field formats taken from the published specification: 32-hex trace-id, 16-hex parent-id, all-zeroes rejected, version `ff` rejected, undefined flag bits never echoed back.
- `TraceState` — `tracestate` list handling with the spec's mutation rules: own entry prepended, foreign keys never deleted, 32-member cap, oversized members dropped first and from the end.
- `TraceScope` — ambient context holder readable from HTTP middleware, queue workers and HTTP clients alike.
- `QueueTracePropagator` — propagation across the queue boundary via `Queue::createPayloadUsing` plus worker events. Automatic for every dispatch; no per-job trait.
- `TraceMiddleware` — accepts inbound context, creates a child span, binds it to the log context, echoes it on the response.
- `PropagatingHttpClient` — outbound HTTP carrying a fresh child span.
- `StandardWebhooksScheme` — signing and verification, including multi-signature rotation in a single header.
- `SignatureScheme` — interface allowing a service to offer the contract scheme alongside its native one.
- `SPEC.md` — the normative contract document.

### Notes

`0.x` allows breaking changes in minor releases; the contract is unstable until it has survived real adoption. v1.0.0 is gated on the conformant set having adopted and at least one service completing a real migration.
