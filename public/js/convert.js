/* ==========================================================================
   ZolvaPay — Convert currency (Phase 4)
   Talks to GET /api/fx/currencies, POST /api/fx/quote, POST /api/fx/convert,
   and GET /api/wallet. Uses window.ZolvaAuth (auth.js) for the token/
   current-user check; this file owns nothing about session state itself.
   ========================================================================== */

(function () {
  "use strict";

  function generateIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    // Fallback for older browsers/webviews without crypto.randomUUID.
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
      {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": "Bearer " + token,
      },
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
    try {
      data = await response.json();
    } catch (parseError) {
      data = null;
    }

    if (!response.ok) {
      throw { status: response.status, data: data };
    }

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
    if (show && message) {
      errorEl.textContent = message;
    }
  }

  function formatAmount(value) {
    return Number(value).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("convertForm");
    if (!form) return; // not on this page

    const lockedWrap = document.getElementById("convertLocked");
    const formWrap = document.getElementById("convertFormWrap");
    const successWrap = document.getElementById("convertSuccess");

    const fromAmountInput = document.getElementById("fromAmount");
    const fromAmountError = document.getElementById("fromAmountError");
    const fromCurrencySelect = document.getElementById("fromCurrency");
    const toCurrencySelect = document.getElementById("toCurrency");
    const balanceNote = document.getElementById("convertBalanceNote");
    const formAlert = document.getElementById("convertAlert");
    const submitBtn = document.getElementById("convertSubmit");
    const swapBtn = document.getElementById("swapCurrenciesBtn");

    const quoteBox = document.getElementById("convertQuoteBox");
    const quoteAmount = document.getElementById("convertQuoteAmount");
    const quoteRate = document.getElementById("convertQuoteRate");
    const quoteMargin = document.getElementById("convertQuoteMargin");

    let walletBalances = [];
    let supportedCurrencies = [];
    let quoteToken = 0; // guards against a slow, stale quote response overwriting a newer one
    let latestQuote = null;

    function populateFromCurrencies() {
      fromCurrencySelect.innerHTML = "";
      if (walletBalances.length === 0) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = "--";
        fromCurrencySelect.appendChild(option);
        return;
      }
      walletBalances.forEach(function (balance, index) {
        const option = document.createElement("option");
        option.value = balance.currency_code;
        option.textContent = balance.currency_code;
        if (index === 0) option.selected = true;
        fromCurrencySelect.appendChild(option);
      });
    }

    function populateToCurrencies() {
      const fromValue = fromCurrencySelect.value;
      toCurrencySelect.innerHTML = "";
      const options = supportedCurrencies.filter(function (code) {
        return code !== fromValue;
      });
      if (options.length === 0) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = "--";
        toCurrencySelect.appendChild(option);
        return;
      }
      options.forEach(function (code, index) {
        const option = document.createElement("option");
        option.value = code;
        option.textContent = code;
        if (index === 0) option.selected = true;
        toCurrencySelect.appendChild(option);
      });
    }

    function updateBalanceNote() {
      const selected = walletBalances.find(function (b) {
        return b.currency_code === fromCurrencySelect.value;
      });
      if (selected) {
        balanceNote.textContent = "Available: " + formatAmount(selected.available_balance) + " " + selected.currency_code;
      } else {
        balanceNote.textContent = "";
      }
    }

    function hideQuote() {
      quoteBox.classList.remove("visible");
      latestQuote = null;
    }

    function fetchQuote() {
      const amountValue = parseFloat(fromAmountInput.value);
      const fromValue = fromCurrencySelect.value;
      const toValue = toCurrencySelect.value;

      if (!amountValue || amountValue <= 0 || !fromValue || !toValue || fromValue === toValue) {
        hideQuote();
        return;
      }

      const thisToken = ++quoteToken;

      authedFetch("/api/fx/quote", {
        method: "POST",
        body: {
          from_currency: fromValue,
          to_currency: toValue,
          amount: amountValue,
        },
      })
        .then(function (quote) {
          if (thisToken !== quoteToken) return; // a newer request has since started

          latestQuote = quote;
          quoteAmount.textContent = formatAmount(quote.amount_to) + " " + quote.to_currency;
          quoteRate.textContent = "1 " + quote.from_currency + " = " + quote.effective_rate.toFixed(6) + " " + quote.to_currency;
          quoteMargin.textContent = (quote.margin_bps / 100).toFixed(2) + "%";
          quoteBox.classList.add("visible");
        })
        .catch(function () {
          if (thisToken !== quoteToken) return;
          hideQuote();
        });
    }

    let quoteDebounceTimer = null;
    function scheduleQuote() {
      window.clearTimeout(quoteDebounceTimer);
      quoteDebounceTimer = window.setTimeout(fetchQuote, 350);
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
        const [walletData, currenciesData] = await Promise.all([
          authedFetch("/api/wallet"),
          authedFetch("/api/fx/currencies"),
        ]);
        walletBalances = walletData.balances || [];
        supportedCurrencies = currenciesData.currencies || [];
      } catch (e) {
        walletBalances = [];
        supportedCurrencies = [];
      }

      populateFromCurrencies();
      populateToCurrencies();
      updateBalanceNote();

      fromCurrencySelect.addEventListener("change", function () {
        updateBalanceNote();
        populateToCurrencies();
        scheduleQuote();
      });
      toCurrencySelect.addEventListener("change", scheduleQuote);
      fromAmountInput.addEventListener("input", scheduleQuote);

      document.body.classList.add("auth-ready");
    }

    swapBtn.addEventListener("click", function () {
      const fromHasOption = Array.from(fromCurrencySelect.options).some(function (o) {
        return o.value === toCurrencySelect.value;
      });
      if (!toCurrencySelect.value || !fromHasOption) return;

      const newFrom = toCurrencySelect.value;
      fromCurrencySelect.value = newFrom;
      updateBalanceNote();
      populateToCurrencies();
      scheduleQuote();
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(formAlert);

      const amountValue = fromAmountInput.value.trim();
      const amountNumber = parseFloat(amountValue);
      const fromValue = fromCurrencySelect.value;
      const toValue = toCurrencySelect.value;

      let hasError = false;

      if (!amountValue || isNaN(amountNumber) || amountNumber <= 0) {
        showFieldError(fromAmountInput, fromAmountError, true, "Enter a valid amount.");
        hasError = true;
      } else {
        showFieldError(fromAmountInput, fromAmountError, false);
      }

      if (!fromValue || !toValue || fromValue === toValue) {
        showAlert(formAlert, "Choose two different currencies.");
        hasError = true;
      }

      if (hasError) return;

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Converting\u2026";

      authedFetch("/api/fx/convert", {
        method: "POST",
        body: {
          from_currency: fromValue,
          to_currency: toValue,
          amount: amountNumber,
          idempotency_key: generateIdempotencyKey(),
        },
      })
        .then(function (data) {
          const q = data.quote || {};
          document.getElementById("convertSuccessSub").textContent =
            "You converted " + formatAmount(amountNumber) + " " + fromValue +
            " into " + formatAmount(q.amount_to || 0) + " " + toValue + ".";
          formWrap.style.display = "none";
          successWrap.style.display = "block";
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;

          if (err.status === 422) {
            const errors = (err.data && err.data.errors) || {};
            let handledAny = false;
            if (errors.amount) {
              showFieldError(fromAmountInput, fromAmountError, true, errors.amount[0]);
              handledAny = true;
            }
            if (!handledAny) {
              showAlert(formAlert, (err.data && err.data.message) || "Something went wrong. Please check your details.");
            }
          } else if (err.status === 403) {
            showAlert(formAlert, (err.data && err.data.message) || "Complete Tier 1 verification before converting currency.");
          } else if (err.status === 409) {
            showAlert(formAlert, (err.data && err.data.message) || "This conversion couldn't be completed.");
          } else if (err.status === 0) {
            showAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });

    document.getElementById("convertAnotherBtn").addEventListener("click", function () {
      form.reset();
      hideQuote();
      successWrap.style.display = "none";
      formWrap.style.display = "block";
      init();
    });

    init();
  });
})();
