/* ==========================================================================
   ZolvaPay — Top up wallet (Phase 5)
   Talks to GET /api/fx/currencies, POST /api/wallet/fund. Uses
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
    const form = document.getElementById("fundForm");
    if (!form) return;

    const lockedWrap = document.getElementById("fundLocked");
    const formWrap = document.getElementById("fundFormWrap");
    const successWrap = document.getElementById("fundSuccess");

    const amountInput = document.getElementById("fundAmount");
    const amountError = document.getElementById("fundAmountError");
    const currencySelect = document.getElementById("fundCurrency");
    const formAlert = document.getElementById("fundAlert");
    const submitBtn = document.getElementById("fundSubmit");

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
        const currenciesData = await authedFetch("/api/fx/currencies");
        const currencies = currenciesData.currencies || [];
        currencySelect.innerHTML = "";
        currencies.forEach(function (code, index) {
          const option = document.createElement("option");
          option.value = code;
          option.textContent = code;
          if (index === 0) option.selected = true;
          currencySelect.appendChild(option);
        });
      } catch (e) {
        // form still usable with an empty currency list is not useful —
        // surface it plainly rather than failing silently
        showAlert(formAlert, "Couldn't load supported currencies. Refresh to try again.");
      }

      document.body.classList.add("auth-ready");
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(formAlert);

      const amountValue = amountInput.value.trim();
      const amountNumber = parseFloat(amountValue);
      const currencyValue = currencySelect.value;

      if (!amountValue || isNaN(amountNumber) || amountNumber <= 0) {
        showFieldError(amountInput, amountError, true, "Enter a valid amount.");
        return;
      }
      showFieldError(amountInput, amountError, false);

      if (!currencyValue) {
        showAlert(formAlert, "Choose a currency.");
        return;
      }

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Processing\u2026";

      authedFetch("/api/wallet/fund", {
        method: "POST",
        body: {
          currency_code: currencyValue,
          amount: amountNumber,
          idempotency_key: generateIdempotencyKey(),
        },
      })
        .then(function (data) {
          if (data.checkout_url) {
            // Hand off to the rail's hosted checkout — the wallet is
            // only actually credited once its webhook confirms payment.
            window.location.href = data.checkout_url;
            return;
          }

          document.getElementById("fundSuccessTitle").textContent = "Wallet funded";
          document.getElementById("fundSuccessSub").textContent =
            "You added " + formatAmount(amountNumber) + " " + currencyValue + " to your wallet.";
          formWrap.style.display = "none";
          successWrap.style.display = "block";
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;

          if (err.status === 422) {
            const errors = (err.data && err.data.errors) || {};
            if (errors.amount) {
              showFieldError(amountInput, amountError, true, errors.amount[0]);
            } else {
              showAlert(formAlert, (err.data && err.data.message) || "Something went wrong. Please check your details.");
            }
          } else if (err.status === 403) {
            showAlert(formAlert, (err.data && err.data.message) || "Complete Tier 1 verification before funding your wallet.");
          } else if (err.status === 0) {
            showAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });

    document.getElementById("fundAnotherBtn").addEventListener("click", function () {
      form.reset();
      successWrap.style.display = "none";
      formWrap.style.display = "block";
    });

    init();
  });
})();
