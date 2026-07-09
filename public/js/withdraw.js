/* ==========================================================================
   ZolvaPay — Withdraw (Phase 5)
   Talks to GET /api/wallet, POST /api/wallet/withdraw. Uses
   window.ZolvaAuth (auth.js) for the token/current-user check.
   ========================================================================== */

(function () {
  "use strict";

  function generateIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0;
      const v = c === "x" ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  async function authedFetch(path, options) {
    options = options || {};
    const token = window.ZolvaAuth.getToken();
    const headers = Object.assign(
      { "Content-Type": "application/json", "Accept": "application/json", "Authorization": "Bearer " + token },
      options.headers || {}
    );

    let response;
    try {
      response = await fetch(path, {
        method: options.method || "GET",
        headers: headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
      });
    } catch (networkError) {
      throw { status: 0, data: null };
    }

    let data = null;
    try { data = await response.json(); } catch (parseError) { data = null; }

    if (!response.ok) throw { status: response.status, data: data };
    return data;
  }

  function showAlert(alertEl, message) {
    alertEl.textContent = message;
    alertEl.classList.add("visible");
  }
  function hideAlert(alertEl) {
    alertEl.classList.remove("visible");
    alertEl.textContent = "";
  }
  function showFieldError(inputEl, errorEl, show, message) {
    if (!inputEl || !errorEl) return;
    inputEl.classList.toggle("input-error", show);
    errorEl.classList.toggle("visible", show);
    if (show && message) errorEl.textContent = message;
  }
  function formatAmount(value) {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("withdrawForm");
    if (!form) return;

    const lockedWrap = document.getElementById("withdrawLocked");
    const formWrap = document.getElementById("withdrawFormWrap");
    const successWrap = document.getElementById("withdrawSuccess");

    const amountInput = document.getElementById("withdrawAmount");
    const amountError = document.getElementById("withdrawAmountError");
    const currencySelect = document.getElementById("withdrawCurrency");
    const balanceNote = document.getElementById("withdrawBalanceNote");
    const formAlert = document.getElementById("withdrawAlert");
    const submitBtn = document.getElementById("withdrawSubmit");

    const accountNameInput = document.getElementById("accountName");
    const accountNameError = document.getElementById("accountNameError");
    const accountNumberInput = document.getElementById("accountNumber");
    const accountNumberError = document.getElementById("accountNumberError");
    const bankCodeInput = document.getElementById("bankCode");
    const bankCodeError = document.getElementById("bankCodeError");

    let walletBalances = [];

    function updateBalanceNote() {
      const selected = walletBalances.find(function (b) { return b.currency_code === currencySelect.value; });
      balanceNote.textContent = selected
        ? "Available: " + formatAmount(selected.available_balance) + " " + selected.currency_code
        : "";
    }

    async function init() {
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) {
        window.location.href = "login.html";
        return;
      }

      if (!user.kyc_tier || user.kyc_tier < 1) {
        lockedWrap.style.display = "block";
        formWrap.style.display = "none";
        document.body.classList.add("auth-ready");
        return;
      }

      try {
        const walletData = await authedFetch("/api/wallet");
        walletBalances = walletData.balances || [];
      } catch (e) {
        walletBalances = [];
      }

      // Only currencies the wallet actually holds are offered — you can't
      // withdraw a balance you don't have.
      currencySelect.innerHTML = "";
      if (walletBalances.length === 0) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = "No balance available";
        currencySelect.appendChild(option);
      } else {
        walletBalances.forEach(function (balance, index) {
          const option = document.createElement("option");
          option.value = balance.currency_code;
          option.textContent = balance.currency_code;
          if (index === 0) option.selected = true;
          currencySelect.appendChild(option);
        });
      }
      updateBalanceNote();
      currencySelect.addEventListener("change", updateBalanceNote);

      document.body.classList.add("auth-ready");
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(formAlert);

      const amountValue = amountInput.value.trim();
      const amountNumber = parseFloat(amountValue);
      const currencyValue = currencySelect.value;
      const accountName = accountNameInput.value.trim();
      const accountNumber = accountNumberInput.value.trim();
      const bankCode = bankCodeInput.value.trim();

      let hasError = false;

      if (!amountValue || isNaN(amountNumber) || amountNumber <= 0) {
        showFieldError(amountInput, amountError, true, "Enter a valid amount.");
        hasError = true;
      } else {
        showFieldError(amountInput, amountError, false);
      }
      if (!currencyValue) {
        showAlert(formAlert, "Choose a currency.");
        hasError = true;
      }
      showFieldError(accountNameInput, accountNameError, !accountName);
      showFieldError(accountNumberInput, accountNumberError, !accountNumber);
      showFieldError(bankCodeInput, bankCodeError, !bankCode);
      if (!accountName || !accountNumber || !bankCode) hasError = true;

      if (hasError) return;

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Submitting\u2026";

      authedFetch("/api/wallet/withdraw", {
        method: "POST",
        body: {
          currency_code: currencyValue,
          amount: amountNumber,
          account_name: accountName,
          account_number: accountNumber,
          bank_code: bankCode,
          idempotency_key: generateIdempotencyKey(),
        },
      })
        .then(function (data) {
          const railTxn = data.payment_rail_transaction || {};
          const completed = railTxn.status === "successful";

          document.getElementById("withdrawSuccessTitle").textContent =
            completed ? "Withdrawal completed" : "Withdrawal submitted";
          document.getElementById("withdrawSuccessSub").textContent = completed
            ? formatAmount(amountNumber) + " " + currencyValue + " has been sent to your bank account."
            : formatAmount(amountNumber) + " " + currencyValue + " has been deducted from your wallet and is being sent to your bank account.";
          formWrap.style.display = "none";
          successWrap.style.display = "block";
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;

          if (err.status === 422) {
            const errors = (err.data && err.data.errors) || {};
            let handledAny = false;
            if (errors.amount) { showFieldError(amountInput, amountError, true, errors.amount[0]); handledAny = true; }
            if (errors.account_number) { showFieldError(accountNumberInput, accountNumberError, true, errors.account_number[0]); handledAny = true; }
            if (errors.bank_code) { showFieldError(bankCodeInput, bankCodeError, true, errors.bank_code[0]); handledAny = true; }
            if (errors.account_name) { showFieldError(accountNameInput, accountNameError, true, errors.account_name[0]); handledAny = true; }
            if (!handledAny) {
              showAlert(formAlert, (err.data && err.data.message) || "Something went wrong. Please check your details.");
            }
          } else if (err.status === 403) {
            showAlert(formAlert, (err.data && err.data.message) || "Complete Tier 1 verification before withdrawing.");
          } else if (err.status === 409) {
            showAlert(formAlert, (err.data && err.data.message) || "Insufficient balance for this withdrawal.");
          } else if (err.status === 0) {
            showAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });

    document.getElementById("withdrawAnotherBtn").addEventListener("click", function () {
      form.reset();
      successWrap.style.display = "none";
      formWrap.style.display = "block";
      init();
    });

    init();
  });
})();
