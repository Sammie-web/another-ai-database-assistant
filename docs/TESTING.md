# Testing and release plan

## Implemented automated coverage

`tests/AssistantTest.php` exercises tenant-filtered rows and counts, output-column redaction, preview without mutation, idempotent confirmation, create/delete confirmation, stale-row rejection, cross-tenant proposal hiding, proposal expiry, authorization revocation, protected-field rejection, identifier injection, mocked Responses tool flow, and authenticated chat.

The default suite uses SQLite for feedback. CI repeats it against MySQL 8.4 to exercise the actual dialect. SQLite alone cannot establish row-lock correctness. Every test uses fake HTTP responses; no real OpenAI charges or credentials are required.

Gemini regression coverage additionally checks provider routing, authenticated requests, thought-signature and call-ID replay, empty argument objects, scoped evidence, preview-only writes, incomplete responses and safe HTTP errors. Two live tests are skipped by default; `php scripts/test-live.php` explicitly enables them for the provider configured in `.env` and incurs API usage. Keep test databases isolated.

## Required host integration tests before release

1. Authentication: guest redirect/401, disabled assistant user denied, inactive membership denied. Confirm CSRF middleware rejects a missing/invalid token outside Laravel's test bypass; cross-origin requests must fail.
2. Authorization: read-only roles cannot propose or confirm. Cross-user same-tenant and cross-tenant proposal IDs return 404. Tenant switching and permission revocation clear old conversation context. Unknown resources and protected fields fail closed.
3. Domain invariants: enforce model-specific policies, lifecycle transitions, required fields, uniqueness, foreign keys, timestamps and soft deletes through host application services. Prove unintended cascades cannot occur.
4. Concurrency: use two independent MySQL connections/processes to confirm one proposal concurrently. Exactly one business mutation and confirmation audit must result. Concurrent direct edits must cause stale preview conflicts. Verify deadlock rollback and safe retry behavior.
5. Atomicity: force an audit insert failure and assert that both the business mutation and confirmation marker roll back. Use InnoDB for all participating tables.
6. Input boundaries: SQL-looking strings remain bound values, invalid or excessive limits fail, duplicate tool fields fail, malformed tool JSON fails safely, unknown tool names do not execute, and huge result payloads return a bounded error. Test null, unicode, invalid numeric values, integer limits and overly long fields.
7. Model orchestration: malformed/incomplete responses, refusals, unknown functions, tool budget exhaustion, 401/429/500 responses, transport timeouts and provider outages produce recoverable UI errors without leaking secrets. Assert all reasoning/output items are replayed and every function result uses the correct call ID.
8. Privacy: inspect outbound provider payloads to ensure secret columns and foreign-tenant data never appear. Verify server logs contain neither API keys nor sensitive prompt/row content. Test session expiry, retention cleanup and encrypted proposal backups.
9. UI: desktop/mobile widths, keyboard-only use, screen-reader labels, busy/reset states, retry behavior, HTML/XSS strings rendered as text, evidence display, expired/stale previews, double clicks and network loss after successful confirmation. Verify deployment CSP; extract inline styles/scripts or use nonces for a restrictive host CSP.
10. Performance: representative tenant sizes, `(tenant_id,id)` index plans, MySQL timeout enforcement, 50-row limit, count latency, Redis session locking, rate limiting across workers, tenant spend budget and provider throughput. Set measurable SLOs from application traffic.

## Evaluation set

Use synthetic records and a fixed expected answer/allowed-action matrix. Include at least 50 natural-language cases covering discovery, empty results, pagination, counts, ambiguous edits, delete intent, unsupported analytics and malicious instructions stored in row values. Assert tool selection, scope and write safety independently of prose quality. Require zero unauthorized reads or writes; manually inspect ambiguous prompts and claimed execution. Repeat for every model/prompt/tool-schema change.

## Rollout

Run all host integration checks, enable read-only Gates for an internal cohort, then enable narrowly selected domain write operations after reviewing audits. Monitor errors, conflicts, latency, spend and user corrections. Disable `assistant.access` to stop all usage immediately; revoke `assistant.write` to retain read-only service. Roll back code independently of audit retention. Do not automatically reverse business mutations: use a separately reviewed domain correction.
