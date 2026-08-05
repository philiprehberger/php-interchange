# Interchange

Service contract for interoperable HTTP services: **W3C Trace Context that survives queue boundaries**, **Standard Webhooks** signing, and pluggable signature schemes so a service can adopt the contract without breaking its existing consumers.

## Why

Five independently deployed services, all built by the same author, turned out to be unable to talk to each other: one couldn't send an Authorization header, two signed with mutually unverifiable HMAC schemes, and none could answer "which delivery came from this submission?". That is the ordinary condition of a service fleet, not an unusual failure.

Interchange is the contract that fixes it, and `SPEC.md` is the normative document.

## Install

```bash
composer require philiprehberger/interchange
```

**Pin an exact version.** This package sits in the signature-verification path of every service that installs it, so a bad patch release breaks the whole fleet at once. No `^`, no `~`, and upgrade one service at a time.

## Trace context across queue boundaries

The reason this package exists rather than a thin wrapper over an existing tracing library: **every hop in a real pipeline crosses a queue.** A contract that only propagates over HTTP produces one disconnected root span per service and a trace view that shows nothing.

```php
// Registered automatically by the service provider.
QueueTracePropagator::register();
```

`Queue::createPayloadUsing` stamps the active context onto **every** dispatch — no per-job trait, no annotation a future contributor can forget. The worker restores it before `handle()` and clears it after, so a long-lived worker never leaks one job's trace into the next.

```php
// In an HTTP request, then inside the queued job it dispatches:
PropagatingHttpClient::make()->post('https://downstream/v1/events', $body);
// → carries traceparent with the same trace-id and a fresh span-id
```

## Standard Webhooks

```php
$scheme = new StandardWebhooksScheme;

$headers = $scheme->sign($messageId, $rawBody, $secret);
// webhook-id, webhook-timestamp, webhook-signature: v1,<base64>

$scheme->verify($headers, $rawBody, $secret);
```

Four details that are easy to get wrong, and that this implementation gets right:

- Signed content is **three** components — `{id}.{timestamp}.{payload}`. Stripe-style schemes use two.
- The signature is **base64** of the raw HMAC bytes, not hex.
- The HMAC key is the **base64-decoded bytes after stripping `whsec_`**, not the literal prefixed string. Signing with the raw string produces signatures no compliant verifier accepts.
- Rotation is a **space-delimited list in the one header**, not a second header:

```php
$headers = $scheme->signWithRotation($id, $body, [$newSecret, $oldSecret]);
// consumers holding either secret accept the message
```

## Additive, never replacement

A service adopting this contract keeps its native scheme working. `SignatureScheme` is an interface precisely so `standard-webhooks` can be offered *alongside* an existing format, negotiated per destination, rather than as a flag day.

That matters more than it sounds: Standard Webhooks secrets are base64 with a `whsec_` prefix, so existing arbitrary-string secrets cannot simply be reinterpreted. Every destination needs a new secret and a handshake with whoever consumes it — which is only survivable as a per-destination opt-in.

## Documentation

- `SPEC.md` — the normative contract, with a record of what verification against the published specs corrected.

## Testing

```bash
composer test
```
