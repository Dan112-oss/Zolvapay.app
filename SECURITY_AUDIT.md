# ZolvaPay — Security Audit & Sign-Off Checklist (Phase 8)

Reviewed: all code delivered across Phases 0–7 (auth/KYC, wallet ledger, P2P transfers, FX, payment rails, cards, bill payments), plus this phase's own additions. This is a code-level review from inside the same tool session that wrote the code — it is **not** a substitute for an independent third-party penetration test or a real compliance sign-off, both of which the blueprint's own Phase 8 explicitly calls for and neither of which I can perform.

## What's solid

- **Ledger core.** `WalletService` uses real pessimistic row locking (`lockForUpdate()`) on `wallet_balances` before every mutation, wrapped in DB transactions. Balances are stored as integer minor units, never floats. `ledger_entries` is append-only and is treated as the source of truth over the `wallet_balances` cache, matching the blueprint's own rule in Section 3.
- **Idempotency.** Every wallet-mutating write requires an idempotency key with a unique DB constraint, checked before the write happens. Retried requests return the original transaction rather than double-applying.
- **Money-in-flight patterns.** Withdrawals and bill payments debit (reserve) the wallet immediately and reverse automatically on rail/biller failure — the user can never spend money that's mid-flight. Funding only credits on confirmed success (webhook or synchronous mock), never speculatively.
- **PCI scope.** Full card PAN/CVV are never written to the database anywhere — `CardService::reveal()` fetches them live from the processor per-request and they exist only in that response.
- **Webhook authentication.** Both Flutterwave adapters (payment rails, billers) verify an inbound webhook's signature with `hash_equals()` (timing-safe) before trusting the payload.
- **SQL injection / mass assignment.** Everything goes through Eloquent/the query builder; no raw string-interpolated SQL anywhere in the reviewed code. `$fillable` is used correctly on every model — no `$guarded = []`.
- **Secrets.** `.env` is gitignored; `.env.example` never carries real values, including the Open Exchange Rates key you shared in chat, which was never written into any committed file.

## What Phase 8 fixes

- **No rate limiting existed anywhere through Phase 7.** Every auth and money-moving endpoint was reachable at unlimited request rates. Fixed: `throttle:5,1` on register/login, `throttle:60,1` baseline on all authenticated routes, `throttle:15,1` (stricter) on every route that moves money, `throttle:10,1` on card detail reveal.
- **No fraud/velocity checks existed, despite being in the blueprint since Phase 2 (Section 2.9).** Fixed: `FraudService` blocks a request outright (429) if a user exceeds a configured count of transfers/withdrawals/conversions/bill payments within a rolling window, and separately flags (non-blocking) any single transaction above a configurable threshold for admin review.
- **`audit_logs` — specified in the blueprint's own Section 3 schema back at Phase 2, never actually built.** Fixed: table + `AuditLogService`, wired into every money-moving controller and the two most sensitive admin actions (KYC approve/reject, admin wallet credit/debit).
- **No compliance export existed.** Fixed: streamed CSV export of transactions by date range, plus a fraud-alert visibility endpoint, both admin-gated.

## Real gaps still open (not in this phase's scope — flagging, not fixing)

These are launch blockers for a real fintech, not code-quality nitpicks. Listed so they don't get mistaken for "handled" just because Phase 8 happened:

1. **No MFA.** The blueprint's own Phase 1 called for TOTP/SMS OTP; only email+password auth exists. High priority before real-money launch.
2. **No password reset flow.** There's no forgot-password endpoint at all yet.
3. **No account lockout policy.** Phase 8's `throttle:5,1` slows brute force but is per-route, not a real "N failed logins locks the account + alerts the user" policy.
4. **No step-up authentication on sensitive actions.** Revealing a card's full PAN/CVV, or an admin approving KYC, both happen with only the existing session token — no re-auth (password/OTP) prompt. Worth adding, especially for card reveal.
5. **Marqeta and Flutterwave integrations are unverified against live sandboxes.** Every adapter file says this in its own docblock — this environment has no network access to actually call either API. Treat every field name/response shape as "needs a real sandbox pass," not "tested."
6. **Encryption at rest, infra-level HTTPS enforcement, DB backup/PITR policy, and data-residency (blueprint Section 5) are all infrastructure decisions**, not application code — nothing in this codebase can confirm or deny them. They need sign-off from whoever owns your hosting environment.
7. **No independent penetration test has been run.** Everything above is a code-reading review, which catches a different (and narrower) set of issues than an actual pentest against a running instance.

## Load/concurrency testing

- `tests/Feature/WalletConcurrencyTest.php` — fast PHPUnit regression check for idempotency and insufficient-balance handling. Explicitly **does not** prove locking holds under real concurrency (single-process limitation — see the file's own docblock).
- `load-test/k6-ledger-load-test.js` — the actual concurrency test: fires genuinely parallel HTTP requests at a running instance. **Run this against staging before launch.** Never against production.

## Sign-off checklist before real-money public launch

- [ ] Run `load-test/k6-ledger-load-test.js` against staging; confirm balance math holds exactly (see script header for the manual verification steps)
- [ ] Independent penetration test completed and findings closed
- [ ] MFA implemented (blueprint Phase 1 deliverable, still outstanding)
- [ ] Password reset flow implemented
- [ ] Account lockout policy implemented
- [ ] Marqeta integration verified against a live sandbox, not just this codebase's assumptions
- [ ] Flutterwave (transfers + bills) integration verified against a live sandbox
- [ ] Infra sign-off: encryption at rest, HTTPS/TLS termination, DB backups, data residency for your first launch country
- [ ] Compliance report format (the CSV this phase built is generic — confirm it matches your actual regulator's required template) reviewed by whoever owns that filing
- [ ] Fraud velocity/large-transaction thresholds in `config/fraud.php` tuned for real expected usage (current values are conservative placeholders)
- [ ] Rate limit values in `routes/api.php` tuned against real traffic patterns (current values are conservative placeholders)
