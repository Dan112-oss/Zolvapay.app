/**
 * ZolvaPay — ledger concurrency load test (k6)
 * Blueprint Phase 8 deliverable: "Load testing the ledger specifically
 * (concurrent transfer stress test)."
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM WalletConcurrencyTest.php:
 * PHPUnit runs single-threaded — it can't prove WalletService's
 * lockForUpdate() row locking holds up under genuinely simultaneous
 * requests. k6 fires real parallel HTTP requests at a running instance
 * of the app (staging, ideally — never production), which is the only
 * way to actually exercise that.
 *
 * WHAT THIS TESTS: many virtual users hitting POST /api/transfers at
 * the same moment, all debiting the SAME sender wallet. If locking is
 * correct: every request either succeeds (and the sender's balance goes
 * appropriately negative-safe, i.e. never below zero) or fails cleanly
 * with a 409 (insufficient balance) — never a corrupted/partial state,
 * never two requests both reading the same pre-debit balance and both
 * succeeding when only one should have.
 *
 * SETUP before running:
 *   1. Seed TWO real users on your target environment, get an API token
 *      for the SENDER (POST /api/auth/login), and make sure the sender's
 *      NGN balance is exactly SENDER_BALANCE_MINOR below (adjust to match).
 *   2. Install k6: https://k6.io/docs/get-started/installation/
 *   3. Run:
 *        k6 run -e BASE_URL=https://staging.zolvapay.example \
 *               -e TOKEN=<sender_api_token> \
 *               -e RECIPIENT_EMAIL=recipient@example.com \
 *               load-test/k6-ledger-load-test.js
 *
 * AFTER running, verify manually (this script only checks HTTP-level
 * outcomes, not ledger truth):
 *   - Sum of successful transfer amounts + remaining sender balance
 *     must equal the sender's starting balance exactly.
 *   - GET /api/admin/wallets/{sender_wallet}/ledger entry count must
 *     equal (2 x successful transfer count) — one debit, one credit
 *     per successful transfer, no orphans.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN;
const RECIPIENT_EMAIL = __ENV.RECIPIENT_EMAIL;
const TRANSFER_AMOUNT = __ENV.TRANSFER_AMOUNT || '10.00'; // major units, NGN

export const options = {
  scenarios: {
    concurrent_transfers: {
      executor: 'shared-iterations',
      vus: 20,           // 20 virtual users
      iterations: 100,   // 100 total transfer attempts, distributed across them
      maxDuration: '30s',
    },
  },
};

export default function () {
  if (!TOKEN || !RECIPIENT_EMAIL) {
    throw new Error('Set -e TOKEN=<sender_token> and -e RECIPIENT_EMAIL=<recipient_email> — see file header.');
  }

  const payload = JSON.stringify({
    recipient_email: RECIPIENT_EMAIL,
    amount: TRANSFER_AMOUNT,
    currency_code: 'NGN',
    // Deliberately NO idempotency_key — each iteration should be a
    // genuinely new transfer, not a retry, since the goal is testing
    // concurrent DISTINCT debits against the same balance.
  });

  const response = http.post(`${BASE_URL}/api/transfers`, payload, {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${TOKEN}`,
    },
  });

  check(response, {
    'status is 201 (succeeded) or 409 (cleanly rejected — insufficient balance)': (r) =>
      r.status === 201 || r.status === 409,
    'status is NEVER 500 (would indicate a locking/race bug)': (r) => r.status !== 500,
  });

  sleep(0.1);
}
