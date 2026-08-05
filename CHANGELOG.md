# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-08-05

Grown under real adoption pressure rather than speculatively: every component here is one the first adopting service or the next one actually needs.

### Added

- `IdempotencyStore` + `EloquentIdempotencyStore` — **a deliberate behaviour change, not a refactor.** Measured across the fleet: Webhook Relay stores a request fingerprint and returns 409 on same-key/different-body; Switchyard has no fingerprint column and no conflict detection. Windows already agreed at 24h, so only conflict semantics differ. This adopts Webhook Relay's, which means Switchyard gains a 409 path it does not have today.
- `StripeStyleScheme` — the `t=,v1=` hex construction three services already speak, with named constructors for `inkwell-v0`, `switchyard-v0` and `webhook-relay-v0`. Signs two components, not three: the substantive difference from Standard Webhooks.
- `NullScheme` — makes "unsigned" an explicit, auditable choice. `verify()` always returns false, so "no signature required" cannot become "any signature accepted".
- `ProblemDetails` (RFC 9457) — convergence, not new capability: all five services already return problem+json.
- `EventEnvelope` — field names are overridable so a service can keep the names its shipped SDKs compile against. `causing()` builds a causation chain naming the immediate predecessor.
- `ConformanceHarness` interface (0.1.2).

### Changed

- `StandardWebhooksScheme` accepts an injectable clock (0.1.1). Found in the first adoption: a consumer signing at a frozen timestamp could not verify its own output, which made the tolerance check untestable for everyone.

### Spec

`SPEC.md` folded in four findings from the first adoption: per-destination scheme adoption is mandatory (secrets cannot be reinterpreted), verifiers must accept an injected clock, dual-accept applies only where a signed inbound surface exists, and scheme-usage evidence must be durable rather than inferred from logs.

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
