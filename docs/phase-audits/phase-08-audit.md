# Phase 08 — Invoice QR Design

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The verification core this phase designs around is implemented and hardened: HMAC-bound hashes, UUID verification with 404/409/200 semantics, verify counters + timeline, traversal-guarded signed PDF URLs, and a test-pinned contract suite. But the phase's titular deliverable — a QR code on the invoice — does not exist: no QR service, no PDF embedding, and the manual's reuse candidate (`CashierQrService`) was removed. Additionally, verification quietly changed access models from public (manual design) to authenticated-only (test-pinned), which closes the auditor-without-account flow the QR design assumed. Residual risks are a missing feature and a stale manual, not defects in what exists.

## 2. Phase Objective

Per `PHASE-08-INVOICE-QR-DESIGN.md` (source: `HEAD:docs/production-manual/PHASE-08-INVOICE-QR-DESIGN.md`, 558 lines): specify the QR architecture for invoice verification — current cashier-QR state, verification endpoint/hash/resource payload, the no-QR-on-invoice gap, recommended QR payload/design/placement, security architecture, verification flow, implementation roadmap (5 steps), and threat model.

## 3. Scope

**In scope:** QR artifact presence/absence, verification endpoint contract + access model, hash algorithm, resource payload, roadmap-step completion status, threat-model currency.

**Out of scope:** invoice generation/lifecycle (Phase 07), cashier payments (Phase 06 — QR removal covered as drift input).

## 4. What Was Supposed to Be Implemented

The manual claims: cashier QR exists via `CashierQrService` (chillerlan/php-qrcode, SVG); invoice verify endpoint is PUBLIC with 60/min throttle returning invoice+order+qr_content; HMAC = SHA-256(snapshot_hash + app.key) with documented limitations; `InvoiceResource.qr_content` object (uuid/number/hash/issued_at/url); gap = no QR on PDF; roadmap: (1) real PDF [HIGH], (2) `InvoiceQrService` [MED], (3) embed in PDF [MED], (4) counters [DONE], (5) public verification page [LOW]; threat model (collision, key compromise, truncation, replay, DDoS, timing, DB tampering) + correction/cancel/no-PDF edge cases.

## 5. What Actually Exists

- **Roadmap Step 1 DONE**: real mPDF rendering (Phase 07).
- **Steps 2–3 NOT DONE**: no QR service of any kind exists (repository-wide `*Qr*` search returns only `FaqResource`); no QR content in the PDF job; `CashierQrService` (the proposed reuse base) was deleted.
- **Step 4 DONE**: `verify_count`/`last_verified_at`/`verified_at` + `recordVerified` timeline (`InvoiceController.php:195-201`).
- **Step 5 PARTIAL**: no public HTML verification page; signed view/download URLs exist (capability URLs, `throttle:30,1`).
- **Access model changed**: `GET invoices/verify/{uuid}` now lives inside the `auth:sanctum` group (`routes/api.php:142-197`) with `throttle:5,1` — unauthenticated requests return 401 **by test-pinned design** (`InvoiceVerifyEndpointTest::test_unauthenticated_request_returns_401`, `:81-85`). The manual's public-verification design is superseded.
- **Payload changed**: no `qr_content` object in `InvoiceResource`; instead the full `verification_hash` (64 hex), `verification_url`, and `view_url` are exposed (`InvoiceResource.php:29,46-51`).
- **Hash unchanged**: still `SHA-256(snapshot_hash . app_key)` concatenation (manual's not-true-HMAC note still accurate); `hash_equals` compare; key-rotation caveat stands.
- **Verify semantics preserved**: 404 unknown / 409 tampered (invoice withheld) / 200 authentic with invoice + order + verification URL.

## 6. Architecture

```
(no QR artifact anywhere in the system)

Verification (authenticated):
  GET /api/v1/general/invoices/verify/{uuid} [sanctum + throttle:5,1]
    → verifyInvoice: uuid lookup → recompute HMAC → hash_equals
      → null 404 / tampered 409 (withheld) / authentic 200 (+counter+timeline)
Customer documents:
  myInvoices (owner-scoped) → InvoiceResource (hash + verification_url + view_url, no QR object)
  signed view/download URLs (signature capability, traversal-guarded streaming)
PDF: rendered without any QR block (placement design §"Recommended QR Design" never implemented)
```

## 7. Complete Execution Flow

1. **Verify**: authenticated `GET verify/{uuid}` → lookup → HMAC recompute → 404/409/200; success increments `verify_count`, stamps verified times, records timeline (verified `InvoiceController.php:175-216`).
2. **Present**: resource exposes `verification_url` + full hash + signed `view_url`; frontend could render its own QR from these values (the manual's "frontend generates its own QR" fallback is structurally possible but no frontend contract was found in-repo).
3. **Document**: PDF contains no scannable verification artifact (verified by reading the PDF job — no QR code path).

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Verification requires authentication (intentional, test-pinned) | Route nesting + 401 test | No |
| R2 | Tampered invoices are withheld, never displayed | 409 with authentic:false only | No |
| R3 | First verification timestamps; every verification counts + timelines | Controller `:195-201` | No |
| R4 | PDF access is capability-based (signed URLs), ownership enforced at issuance | Signed group + issuance scoping | No |
| R5 | QR payload design (truncated hash + URL) exists only on paper | Manual §§Recommended/Placement | N/A — unimplemented (F-01) |

## 9. Source of Truth / Authorities

- **Authenticity**: `verifyInvoice` HMAC recompute (sole decider).
- **Presentation**: `InvoiceResource` (hash + URLs; no QR object).
- **Access**: route middleware (sanctum for verify; signed for PDF URLs).
- **Audit**: `verify_count` + timeline rows.
- **Absent**: any QR authority (no service, no template block, no library usage for invoices).

## 10. Database Impact

No QR-specific columns exist (no `qr_path`/`qr_payload` anywhere — the manual's roadmap never reached schema). Verification telemetry (`verify_count`, `last_verified_at`, `verified_at`) is written on the invoices table per successful verification. No migration needed for the current state; QR embedding would need none either (render-time artifact) unless scan analytics are required.

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| GET | `/invoices/verify/{uuid}` | sanctum + throttle:5,1 | `InvoiceController::verify` | 401 guest (changed); 404/409/200 |
| GET | `/invoices/my-invoices` | sanctum | `myInvoices` | owner-scoped |
| GET | `/v1/general/invoices/view/{uuid}`, `/download/{uuid}` | signed + throttle:30,1 | signed handlers | capability URLs, traversal-guarded |

## 12. Authentication & Authorization

Verify requires any authenticated user (no ownership check — UUID unguessability is the barrier; any authenticated user can verify and increment counters on any invoice). PDF URLs are signature-gated without session. Admin invoice ops unchanged (Phase 07). The removal of public verification reduces DDoS/enumeration exposure at the cost of the auditor flow.

## 13. Validation

UUID route binding (`whereUuid` on signed routes); HMAC recompute + constant-time compare; tamper → 409 with no data; traversal guard on stored paths; throttle 5/min on verify (anti-enumeration), 30/min on signed URLs.

## 14. Transactions

Verification counter/timeline writes are single-row updates outside any business transaction (observability-only; failure does not affect authenticity verdict — note: `increment` + `update` + timeline are three writes without a shared transaction; a crash between them leaves counter/timeline skewed — cosmetic only).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Concurrent verifications | atomic `increment`; timestamp first-wins (`verified_at ?? now()`) | STRONGLY REASONED |
| Counter/timeline skew | possible (non-transactional trio) | PROVEN (code) — cosmetic |
| True parallel proof | verify/counter tests exist | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

No QR pipeline exists. Verification is fully synchronous (correct — sub-millisecond HMAC + indexed UUID lookup). Timeline recording is sync within the request.

## 17. Error Handling

Unknown UUID → 404 envelope; tampered → 409 with no invoice data; missing PDF file → 404; guest → 401; throttled → 429. No stack leakage; no state change on any failure path.

## 18. Security

- HMAC binding + `hash_equals` (timing-safe); app.key as the single secret (compromise = forgery capability — manual Threat 2 stands, CRITICAL-by-design, mitigated by env hygiene).
- Auth-required verify + 5/min throttle answers the manual's Threat 5 (DDoS) more aggressively than specified.
- UUIDs unguessable (ordered UUIDs); verification reveals invoice data to any authenticated user — acceptable for an authenticity feature, but it IS an authenticated data disclosure beyond the owner's scope (see F-02).
- Full verification hash exposed in resource responses: not forgeable without the key, but removes the manual's truncation-in-QR rationale from the API surface (anyone can display the full hash; server still authoritative).
- Concat-hash (not `hash_hmac`) retained with the manual's own negligible-risk rationale for fixed-length inputs.
- No key rotation/versioning (manual limitation stands).

## 19. Performance

Verification is one indexed lookup + one hash + two small writes — trivially fast; 5/min throttle is the binding constraint, not compute. No caching (correct — status changes must reflect immediately, e.g., corrected/cancelled invoices).

## 20. Tests & Verification

`InvoiceVerifyEndpointTest` (401/200/409/404/counter — test-pinned access model), `InvoicePdfViewDownloadTest`, `InvoiceDownloadPermissionTest`, `MyInvoicesEndpointTest` (Phase 07 suite). **None executed** (environment). No QR tests exist (nothing to test).

## 21. Edge Cases

Covered: unknown UUID (404); tampered (409 + withhold); corrected/cancelled invoices verify authentically with their status visible (correction/cancel edge cases from the manual hold structurally — status rides the resource); no-PDF-yet (verify works independent of rendering); repeated verification (counter + first-stamp). Not covered: QR-specific edges (moot — no QR).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: TEST GAP (missing feature)
- Status: PROVEN
#### Finding
The phase's titular deliverable — a QR code on the invoice — was never implemented: no QR service, no PDF QR block, no QR library usage for invoices, and the manual's reuse candidate (`CashierQrService`) has been deleted. Roadmap Steps 2–3 are open; Step 5 (public page) is superseded by signed URLs + authenticated verify.
#### Evidence
Repository-wide `*Qr*` search (only `FaqResource`); `GenerateInvoicePdfJob.php` (no QR path); `CashierQrService` absent; manual §§THE GAP/Roadmap.
#### Why it matters
Printed/downloaded invoices carry no scannable authenticity artifact — the anti-counterfeit story ends at the API.
#### Current behavior
API-only verification (hash + URLs, no QR image).
#### Recommended future action
Implement Steps 2–3 per the manual's payload design (or formally defer with a decision record); do not resurrect `CashierQrService` — build `InvoiceQrService` as the manual suggests.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: MEDIUM
- Type: BUSINESS LOGIC
- Status: PROVEN (behavior) / UNPROVEN (intent beyond the test)
#### Finding
Verification moved from public (manual design) to authenticated-only (test-pinned 401). Consequences: (a) auditors/cashiers without accounts cannot verify — the exact flow the QR design serves; (b) any authenticated user can verify any invoice by UUID and inflate its `verify_count` (bounded by 5/min throttle).
#### Evidence
`routes/api.php:193-197` (sanctum nesting); `InvoiceVerifyEndpointTest.php:81-85` (401 pinned); `InvoiceController.php:195` (unconditional increment).
#### Why it matters
Access-model change with product consequences (B2B auditors, in-store verification) that the manual contradicts.
#### Current behavior
Authenticated-only verification.
#### Recommended future action
Confirm the intended verification audience: if auditors matter, add a signed capability verification URL (no session, like PDF URLs); if customers-only is intended, update the manual and consider ownership-scoping verify to reduce counter gaming.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: SECURITY
- Status: PROVEN
#### Finding
The full 64-char `verification_hash` is exposed in `InvoiceResource` responses (and therefore to any authenticated caller), abandoning the manual's truncation rationale. It cannot be forged without `app.key`, and verification remains server-side, so exploitability is nil — but the hash now functions as a display bearer with no rotation story.
#### Evidence
`InvoiceResource.php:29` (full hash serialized).
#### Why it matters
Hygiene: secret-derived values should be need-to-know; combined with F-02, any authenticated user can harvest hashes (which prove nothing offline, but enable convincing-looking forgeries of the DISPLAY layer).
#### Current behavior
Exposed; server authoritative.
#### Recommended future action
Emit only the verification URL (+ truncated display hash if needed) from customer resources; keep full hash server-side/admin-only.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual's Steps 1 and 4 are done, its threat model is current (except access-model assumptions), and its hash-limitation notes (concat vs HMAC, no rotation) remain accurate. The rest (§§Current State, QR Content, Gap, Roadmap 2–3–5, verification-flow auth assumptions) is stale.
#### Evidence
Throughout this phase's verification.
#### Why it matters
The manual is the only QR design record; its stale "current state" (cashier QR exists, verify is public) misleads.
#### Current behavior
Design doc partially realized.
#### Recommended future action
Update the manual to the as-built access model and open roadmap items (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **QR scanned by party without account**: 401 (F-02) — verification fails closed.
- **App-key rotation**: all stored HMACs invalidate (manual limitation; no versioning).
- **PDF without QR printed and re-scanned**: moot (no QR); verification via typed URL requires auth.
- **Counter/timeline skew on crash**: cosmetic divergence only.
- **UUID enumeration**: throttled (5/min) + authenticated + unguessable IDs — infeasible.

## 24. Documentation Drift

Manual accurate for: hash algorithm + limitations, threat model (minus access assumptions), verify semantics (minus auth), counter/timeline behavior, key-file purposes. Stale: cashier-QR current state (deleted), `qr_content` object (replaced by hash/URLs), public verify (now authenticated), throttle (60→5/min), PDF placeholder (now real), roadmap steps status.

## 25. Dependencies

- **Depends on**: Phase 07 (invoice rows, hashes, resources, timeline), auth system, PDF storage.
- **Consumed by**: Phase 12 (customer verification UX), Phase 15 (timeline verification stage).
- **Shared tables**: `invoices` (hash/uuid/telemetry columns).
- **Shared services**: `InvoiceService::verifyInvoice/computeVerificationHash`, `InvoiceTimelineService`.

## 26. Out of Scope

QR artwork/ECC tuning (unimplemented), public verification page (deferred/superseded), HSM-backed signing (manual future consideration), e-invoicing authority submission.

## 27. Residual Risks

1. No QR artifact (F-01) — phase goal unmet.
2. Authenticated-only verification breaks auditor flows (F-02).
3. Full-hash exposure hygiene (F-03).
4. No key rotation story (carried limitation).
5. Manual contradicts as-built access model (F-04).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-08-INVOICE-QR-DESIGN.md` (558 lines, read fully, temp extract). Code: `GenerateInvoicePdfJob.php` (no QR path); `InvoiceResource.php:27-52` (hash/URLs, no qr_content); `InvoiceController.php:175-216` (verify), `:107-155` (signed flows); `routes/api.php:142-197` (auth nesting), `:196` (verify throttle), `:203-207` (signed group); `InvoiceService.php` (`computeVerificationHash`, `verifyInvoice` — Phase 07 evidence); `*Qr*` repository search (negative result is evidence); `InvoiceVerifyEndpointTest.php:81-146` (pinned contract). Tests (listed, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

What exists (verification pipeline, hardened access, tested contract) is correct and well-reasoned; what the phase promised (QR on the invoice) does not exist, and the access model changed under the manual. The verdict reflects a working security core with an unmet artifact goal — not a defect in money, stock, or data handling.
