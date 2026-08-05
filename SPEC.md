# Interchange — service contract v1

**Status:** Draft v0.1.0-spec. Written 2026-08-05 for plan Phase 3.
**Moves to** `~/projects/packages/php/php-interchange/SPEC.md` at plan item 4.1.

The key words MUST, MUST NOT, REQUIRED, SHALL, SHALL NOT, SHOULD, SHOULD NOT, RECOMMENDED, MAY, and OPTIONAL in this document are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

---

## 1. Scope

Interchange is the contract that lets independently deployed services in this fleet interoperate: trace a request across all of them, verify each other's webhooks, agree on error shape, and agree on what a repeated request means.

It exists because reading the five services' source on 2026-08-04/05 established they could not do any of that. Inkwell could not authenticate into Webhook Relay; Webhook Relay and Switchyard signed with mutually unverifiable schemes; no service could answer "which delivery came from this submission?".

**Design rule — additive, never replacement.** A conforming service MUST continue to support its pre-existing native scheme. Contract v1 is an *additional*, negotiated mode. This is what makes zero-downtime migration possible and what preserves each service as a distinct product rather than homogenising five into one.

### 1.1 Conformance

A service is **conformant** when it satisfies every MUST in §3–§7 and the conformance suite reports pass for every requirement key in §8.

A service MAY be declared **deferred** — adoption deliberately abandoned, with a written reason. A deferred service is not a failure; it keeps its native scheme and its consumers keep bridging to it. A service that has adopted and then regresses is **failing**, which is a defect.

---

## 2. Standards this contract builds on

Everything below is an existing published standard. Nothing here is invented notation.

| Concern | Standard | Verified against |
|---|---|---|
| Distributed tracing | W3C Trace Context | `w3.org/TR/trace-context/`, 2026-08-05 |
| Webhook signing | Standard Webhooks | `standard-webhooks/standard-webhooks` spec, 2026-08-05 |
| Error responses | RFC 9457 Problem Details | — |
| Idempotency | IETF `Idempotency-Key` draft semantics | — |

> **Verification note (plan G-0).** Both tracing and signing sections were originally drafted from memory and contained errors. The corrections found are recorded in §10. Do not restate either standard from memory again — quote the spec.

---

## 3. Trace context

### 3.1 `traceparent`

A conforming service MUST accept, propagate, and emit the W3C `traceparent` header.

```
traceparent = version "-" trace-id "-" parent-id "-" trace-flags
```

- `version` — 2 lowercase hex digits. This contract emits `00`. `ff` is invalid.
- `trace-id` — **32 lowercase hex digits** (16 bytes). All-zeroes (`00000000000000000000000000000000`) is invalid and MUST be rejected.
- `parent-id` — **16 lowercase hex digits** (8 bytes). All-zeroes (`0000000000000000`) is invalid and MUST be rejected. Note the spec's term is *parent-id*; it carries the **caller's** span id.
- `trace-flags` — 2 lowercase hex digits. Only the least-significant bit (sampled) is defined. All other bits MUST be set to zero.

Example: `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`

On receiving a valid `traceparent`, a service MUST create a child span whose `parent-id` is the received `parent-id`, and MUST emit its own span id as `parent-id` on any outbound call. On receiving an absent or invalid `traceparent`, a service MUST mint a new trace.

### 3.2 `tracestate`

```
list = list-member 0*31( OWS "," OWS list-member )
```

- A list carries a **maximum of 32 members**.
- Keys are lowercase alpha, digits, `_`, `-`, `*`, `/`. Values are up to 256 printable ASCII characters excluding `,` and `=`.
- Vendors SHOULD propagate **at least 512 characters** of the combined header. *This is a minimum propagation guarantee, not a cap* — an earlier draft of this contract wrongly described it as a 512-character budget.
- When a service modifies its own entry it SHOULD move that entry to the **beginning** of the list. New entries SHOULD likewise be prepended.
- A service **MUST NOT delete keys it did not generate**.
- If truncation is unavoidable, members larger than 128 characters SHOULD be dropped first, and dropping SHOULD proceed from the **end** of the list.
- A malformed `tracestate` MAY be discarded in full; individual invalid members MAY be discarded.

### 3.3 Queue boundaries — normative

**Every hop in this fleet crosses a queue.** Inkwell delivers via `DeliverToDestinationJob`, Webhook Relay via `DeliverEventToSubscription`, Switchyard via `IngestLeadJob` → `RouteLeadToClickUpJob`. A contract that propagates only over HTTP produces five disconnected root spans and a trace view that shows nothing useful.

Therefore: a conforming service MUST propagate trace context across asynchronous boundaries. Concretely, for Laravel:

- The active trace context MUST be serialised onto the job payload at dispatch.
- It MUST be restored into the ambient context before the job's `handle()` runs.
- Any HTTP call made from inside a job MUST carry the restored context.

This is requirement `trace.queue` in §8 and is the single highest-risk assumption in the contract. It MUST be proven end-to-end (HTTP → dispatch → worker → outbound HTTP resolving to one connected trace) before any live service adopts.

### 3.4 Trace classification — `mnl_class`

Real production traffic is traced. Real production payloads are never exposed. The classification member is how downstream services know which they are handling.

- The member key is `mnl_class`. Permitted values: `scenario`, `production`.
- Absent, unrecognised, or malformed values MUST be treated as `production` (**fail closed**).
- `mnl_class` is **advisory**. It is client-supplied and forgeable — anyone can send `tracestate: mnl_class=scenario`. It MUST NOT be used as an authorization input, and MUST NOT be the basis for any storage or exposure decision.
- The authoritative classification is the originating system's own record of the trace it minted. A service MAY use the advisory value to *reduce* what it retains; it MUST NOT use it to *authorize* retention.
- **Trace-shaped endpoints** — those addressed by trace id or correlation id, whose purpose is to describe a request's path — MUST return metadata only: type, timestamps, status codes, attempt counts, byte sizes, outcome. They MUST NOT return request or response bodies for any class.

> **Scope, narrowed at G-2.** This applies to trace-shaped endpoints only. It does **not** retroactively strip pre-existing resource endpoints: Webhook Relay's `EventResource` returns the full `payload` and `DeliveryAttemptResource` returns `response_body_snippet` today, both authenticated and workspace-scoped. An earlier draft read as requiring their removal, which would have been a breaking change to a shipped API in service of a rule written for something else. This contract adds no new path to payload bodies; it does not close existing ones.

The key `mnl_class` is a valid `tracestate` simple-key (lowercase alpha plus underscore) and both values are valid values.

---

## 4. Webhook signing

A conforming service MUST support Standard Webhooks as a selectable signing scheme, alongside its native scheme.

### 4.1 Headers

Three headers, all lowercase, all prefixed `webhook-`:

| Header | Content |
|---|---|
| `webhook-id` | Unique identifier for this message. **Constant across retries** of the same message. |
| `webhook-timestamp` | Integer Unix timestamp, seconds since epoch. |
| `webhook-signature` | Space-delimited list of signatures. |

### 4.2 Signed content

The signed base string is the three components joined by full stops:

```
{webhook-id}.{webhook-timestamp}.{raw-request-body}
```

The body MUST be the exact bytes transmitted, before any parsing or re-serialisation.

### 4.3 Signature format

```
webhook-signature: v1,<base64(HMAC-SHA256(key, signed-content))>
```

- The version prefix for symmetric HMAC-SHA256 is `v1`, followed by a comma, followed by the **base64** encoding of the raw signature bytes. Not hex.
- Asymmetric ed25519 signatures use the prefix `v1a`. Support for `v1a` is OPTIONAL in contract v1.

### 4.4 Secrets

- Symmetric secrets are **base64-encoded and prefixed `whsec_`**.
- The HMAC key is the **base64-decoded bytes of the value after stripping the `whsec_` prefix** — not the literal prefixed string. Implementations that HMAC the raw string will produce signatures that no compliant verifier accepts.
- Asymmetric keys use `whsk_` (private) and `whpk_` (public).

### 4.5 Rotation

`webhook-signature` is a **space-delimited list**. During rotation a sender signs with both the new and old secrets and emits both signatures in that single header. A verifier MUST accept the message if **any** listed signature is valid.

> This differs from the native schemes in this fleet, which emit a second header (e.g. Inkwell's `X-Inkwell-Signature-Old`). Adopting Standard Webhooks means rotation moves from two headers to one multi-value header.

### 4.5.1 Adoption is per-destination, never bulk

A service offering `standard-webhooks` MUST treat scheme selection as
per-destination configuration and MUST NOT provide a bulk migration.

Rationale, learned in the first real adoption: Standard Webhooks secrets are
base64 with a `whsec_` prefix and the HMAC key is the decoded bytes (§4.4).
An existing secret in any other format cannot be reinterpreted, so switching a
live destination requires **issuing a new secret and coordinating with whoever
consumes it**. There is a human on the other end of every destination. A
service's native scheme therefore remains the permanent default for
already-existing destinations.

### 4.6 Verification

A verifier MUST:

- Reject when no listed signature validates.
- Compare in **constant time**.
- Reject when `webhook-timestamp` falls outside an allowable tolerance of now, in either direction.

The specification recommends a tolerance without fixing one. **This contract sets the default tolerance at 5 minutes**, configurable per consumer. That number is our choice, not the spec's.

**A verifier MUST allow its clock to be supplied by the caller.** Discovered in
the first adoption: an implementation that reads the wall clock directly cannot
verify a message it signed at a frozen timestamp, which makes the tolerance
check untestable for every consumer. Reading the process clock is the correct
*default*, not the only option.

### 4.7 `webhook-id` is delivery-side, and is not `Idempotency-Key`

`webhook-id` is stable across retries of the same message, so a **receiver** SHOULD treat a repeated `webhook-id` as a redelivery rather than a new message.

**It is distinct from the `Idempotency-Key` of §6 and MUST NOT be treated as a substitute.** They run in opposite directions:

| | Direction | Protects against |
|---|---|---|
| `Idempotency-Key` (§6) | inbound, caller → us | a caller double-submitting the same request |
| `webhook-id` (§4.7) | outbound, us → consumer | us double-delivering the same message |

A service MAY see both on the same logical flow — accepting an event under an `Idempotency-Key` and then delivering it under a `webhook-id`. Neither value implies anything about the other, and a service MUST NOT derive one from the other or use one to satisfy the other's requirement.

*Added at G-2: the two were conflated in the first draft, which would have produced a service that silently deduplicated the wrong thing.*

---

## 5. Error responses

A conforming service MUST return RFC 9457 `application/problem+json` for every documented error path, carrying at minimum `type`, `title`, and `status`, plus `detail` where a human-readable explanation exists.

> Measured 2026-08-05: all five services already satisfy this, having inherited a common `ProblemResponse` from a shared scaffold. Adoption here is **convergence onto one implementation**, not addition of a missing capability.

---

## 6. Idempotency

A conforming service accepting non-idempotent requests MUST support an `Idempotency-Key` request header.

- A repeat of the same key with the **same** request body MUST return the original cached response rather than re-performing the effect.
- A repeat of the same key with a **different** request body MUST be rejected with `409 Conflict`.
- Keys are scoped per workspace/tenant.
- The retention window is **24 hours**.

> **This is a behaviour change, deliberately chosen.** Measured 2026-08-05: Webhook Relay stores a request fingerprint and returns 409 on same-key/different-body. Switchyard has no fingerprint column and performs no conflict detection. Both already use 24-hour windows. This contract adopts Webhook Relay's semantics, which means **Switchyard gains a 409 path it does not have today**. That MUST appear in Switchyard's migration note and API documentation. It is not a refactor.

---

### 6.1 Scheme-usage evidence

A service supporting more than one signing scheme MUST record scheme usage
**durably in its own datastore**, not in logs.

Retiring a legacy scheme requires evidence of a quiet period — typically 30
consecutive days with no traffic on it. Log retention on the hosts this fleet
runs on is 30 days with a size cap, which is exactly the window the decision
needs and therefore far too thin to be the evidence for it. A counter that
begins the moment a second scheme becomes selectable cannot be truncated out
from under the decision.

## 7. Event envelope

Services exchanging domain events SHOULD carry the following information. **Field names are RECOMMENDED, not normative** — a service MAY keep its existing names and MUST document the mapping.

> **Downgraded at G-2.** The first draft made this envelope normative. Webhook Relay's `POST /v1/events` takes `{type, payload}` — validated, documented in its OpenAPI, and compiled into four generated SDKs. Mandating `data` would have renamed a field on a shipped public API to satisfy a cosmetic preference. What this contract actually requires is that correlation and causation are *carried*; what they are called is the service's business.

```json
{
  "id":             "evt_...",
  "type":           "lead.captured",
  "time":           "2026-08-05T12:04:03.114Z",
  "correlation_id": "...",
  "causation_id":   "...",
  "data":           { }
}
```

- `correlation_id` groups everything belonging to one logical workflow.
- `causation_id` names the **immediate** predecessor: a dead-letter replay's event is caused by the original event; a retry attempt is caused by the failed attempt. This is what lets a consumer render a retry/replay chain rather than a flat list.
- If the failure-theatre work that renders those chains is cut, `causation_id` MUST be removed from the contract rather than left as unused surface.

---

## 8. Conformance requirement keys

The conformance suite asserts these keys. They are also the column headings on the console's conformance dashboard.

| Key | Requirement |
|---|---|
| `trace.http` | Accepts inbound `traceparent`, creates a child span, echoes on response |
| `trace.http.echo` | The emitted `traceparent` carries the received `trace-id` |
| `trace.queue` | Trace context survives dispatch → worker → outbound HTTP as one trace (§3.3) |
| `trace.tracestate` | Preserves foreign members; prepends own; never deletes others' keys |
| `trace.class.failclosed` | Absent/unknown/forged `mnl_class` reads as `production` |
| `trace.class.noauthz` | Classification never affects authorization, storage, or exposure |
| `sig.standard-webhooks.sign` | Emits the three headers with a correct `v1,<base64>` signature |
| `sig.standard-webhooks.verify` | Accepts valid, rejects tampered/expired/wrong-key |
| `sig.rotation` | Accepts any valid signature in the space-delimited list |
| `sig.secret.decode` | Base64-decodes after stripping `whsec_` before HMAC |
| `error.problem_json` | Documented error paths return RFC 9457 |
| `idem.replay` | Same key + same body returns the cached response |
| `idem.conflict` | Same key + different body returns 409 |

**The suite ships assertions; each service ships an adapter.** A generic package cannot know `IngestLeadJob` from `DeliverEventToSubscription`. Each service MUST implement a `ConformanceHarness` declaring how to trigger its inbound path, its outbound signed path, and its queued path.

---

## 9. Versioning

- `0.x` — breaking changes permitted in minor releases. The contract is unstable until it has survived real adoption.
- **v1.0.0 is gated** on the conformant set having adopted, and at least one service having completed a real migration under it.
- After v1.0.0: additive changes are minor; any removal or semantic change forces v2.
- Native (pre-contract) schemes remain supported for at least one major version after a deprecation is announced.

### 9.1 Consumption is pinned

Interchange is a single point of failure across every service that installs it. A bad patch release breaks signature verification everywhere at once.

- Every consumer MUST pin an **exact** version. No `^`, no `~`.
- Upgrades roll out **one service at a time**, each through its own regression and conformance gates.
- Automated dependency bumps MUST be disabled for this package.
- A release MUST NOT be tagged and adopted in the same change.

### 9.2 Changing this spec

A change to this document fans out to eight places: this spec, two conformance packages, five service adapters, and the cross-service integration suite. **The sweep is part of the change, not a follow-up**, and requires a conformance-package minor bump.

---

## 10. Corrections found at G-0

Recorded because the plan asserted these from memory and was wrong. This section is the argument for why the verification gate existed.

| # | Asserted from memory | Verified reality |
|---|---|---|
| 1 | `tracestate` has a "512-character budget" | 512 is a **minimum propagation guarantee** (SHOULD propagate *at least* 512), not a cap. Truncation guidance is drop >128-char members first, from the end |
| 2 | Rotation emits a second header, as the native schemes do | Standard Webhooks uses **one header with a space-delimited list** of signatures |
| 3 | Secret is used as-is | Secrets are **base64-encoded with a `whsec_` prefix**; the HMAC key is the decoded bytes after stripping it. Getting this wrong produces signatures no compliant verifier accepts |
| 4 | 5-minute replay tolerance is specified | The spec recommends a tolerance but **fixes no number**. 5 minutes is this contract's choice and is labelled as such (§4.6) |

Additionally confirmed correct, and now sourced rather than assumed: header names `webhook-id` / `webhook-timestamp` / `webhook-signature`; signed content `{id}.{timestamp}.{payload}`; signature encoding base64 not hex; `traceparent` field lengths and all-zeroes invalidity; the 32-member `tracestate` limit.

Newly learned and folded in: `webhook-id` is **constant across retries** and therefore doubles as the receiver's idempotency key (§4.7), which ties §4 and §6 together more tightly than the plan anticipated.
