/* ==========================================================================
   ZolvaPay — Pay bills (Phase 7)
   Talks to GET /api/bills/categories, GET /api/bills/billers,
   POST /api/bills/pay, GET /api/fx/currencies. Uses window.ZolvaAuth.
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

  // Only cosmetic — which label/placeholder makes sense for the
  // customer-id field per category. Falls back to a generic label for
  // any category not listed here, so new categories in config/billers.php
  // still work without a frontend change.
  const CUSTOMER_ID_COPY = {
    airtime: { label: "Phone number", placeholder: "e.g. 08012345678" },
    electricity: { label: "Meter number", placeholder: "e.g. 04012345678" },
    tv: { label: "Smartcard / IUC number", placeholder: "e.g. 1234567890" },
  };

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("billsForm");
    if (!form) return;

    const lockedWrap = document.getElementById("billsLocked");
    const formWrap = document.getElementById("billsFormWrap");
    const successWrap = document.getElementById("billsSuccess");

    const categoryTabs = document.getElementById("categoryTabs");
    const billerSelect = document.getElementById("billerSelect");
    const customerIdInput = document.getElementById("customerId");
    const customerIdLabel = document.getElementById("customerIdLabel");
    const customerIdError = document.getElementById("customerIdError");
    const amountInput = document.getElementById("billAmount");
    const amountError = document.getElementById("billAmountError");
    const currencySelect = document.getElementById("billCurrency");
    const formAlert = document.getElementById("billsAlert");
    const submitBtn = document.getElementById("billsSubmit");

    let currentCategory = null;
    let currentBillers = [];

    function applyCustomerIdCopy(category) {
      const copy = CUSTOMER_ID_COPY[category] || { label: "Customer ID", placeholder: "Enter your customer ID" };
      customerIdLabel.textContent = copy.label;
      customerIdInput.placeholder = copy.placeholder;
    }

    async function loadBillers(category) {
      billerSelect.innerHTML = '<option value="">Loading\u2026</option>';
      try {
        const data = await authedFetch("/api/bills/billers?category=" + encodeURIComponent(category));
        currentBillers = data.billers || [];
      } catch (e) {
        currentBillers = [];
      }

      billerSelect.innerHTML = "";
      if (currentBillers.length === 0) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = "No billers available";
        billerSelect.appendChild(option);
        return;
      }
      currentBillers.forEach(function (biller, index) {
        const option = document.createElement("option");
        option.value = biller.code;
        option.textContent = biller.name;
        if (index === 0) option.selected = true;
        billerSelect.appendChild(option);
      });
    }

    function selectCategory(category) {
      currentCategory = category;
      Array.from(categoryTabs.children).forEach(function (tab) {
        tab.classList.toggle("active", tab.dataset.category === category);
      });
      applyCustomerIdCopy(category);
      loadBillers(category);
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
        const [categoriesData, currenciesData] = await Promise.all([
          authedFetch("/api/bills/categories"),
          authedFetch("/api/fx/currencies"),
        ]);

        const categories = categoriesData.categories || [];
        categoryTabs.innerHTML = "";
        categories.forEach(function (category) {
          const tab = document.createElement("div");
          tab.className = "category-tab";
          tab.textContent = category;
          tab.dataset.category = category;
          tab.addEventListener("click", function () { selectCategory(category); });
          categoryTabs.appendChild(tab);
        });

        const currencies = currenciesData.currencies || [];
        currencySelect.innerHTML = "";
        currencies.forEach(function (code, index) {
          const option = document.createElement("option");
          option.value = code;
          option.textContent = code;
          if (index === 0) option.selected = true;
          currencySelect.appendChild(option);
        });

        if (categories.length > 0) {
          selectCategory(categories[0]);
        }
      } catch (e) {
        showAlert(formAlert, "Couldn't load billers. Refresh to try again.");
      }

      document.body.classList.add("auth-ready");
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(formAlert);

      const billerCode = billerSelect.value;
      const billerOption = currentBillers.find(function (b) { return b.code === billerCode; });
      const customerId = customerIdInput.value.trim();
      const amountValue = amountInput.value.trim();
      const amountNumber = parseFloat(amountValue);
      const currencyValue = currencySelect.value;

      let hasError = false;

      if (!billerCode || !billerOption) {
        showAlert(formAlert, "Choose a biller.");
        hasError = true;
      }
      if (!customerId) {
        showFieldError(customerIdInput, customerIdError, true, "This field is required.");
        hasError = true;
      } else {
        showFieldError(customerIdInput, customerIdError, false);
      }
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

      if (hasError) return;

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Paying\u2026";

      authedFetch("/api/bills/pay", {
        method: "POST",
        body: {
          biller_code: billerOption.code,
          biller_name: billerOption.name,
          category: currentCategory,
          customer_id: customerId,
          currency_code: currencyValue,
          amount: amountNumber,
          idempotency_key: generateIdempotencyKey(),
        },
      })
        .then(function (data) {
          const billPayment = data.bill_payment || {};
          const completed = billPayment.status === "successful";

          document.getElementById("billsSuccessTitle").textContent = completed ? "Payment completed" : "Payment submitted";
          document.getElementById("billsSuccessSub").textContent =
            formatAmount(amountNumber) + " " + currencyValue + " " +
            (completed ? "was sent to " : "is being sent to ") + billerOption.name + ".";
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
            if (errors.customer_id) { showFieldError(customerIdInput, customerIdError, true, errors.customer_id[0]); handledAny = true; }
            if (!handledAny) {
              showAlert(formAlert, (err.data && err.data.message) || "Something went wrong. Please check your details.");
            }
          } else if (err.status === 403) {
            showAlert(formAlert, (err.data && err.data.message) || "Complete Tier 1 verification before paying bills.");
          } else if (err.status === 409) {
            showAlert(formAlert, (err.data && err.data.message) || "Insufficient balance for this payment.");
          } else if (err.status === 0) {
            showAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });

    document.getElementById("billsAnotherBtn").addEventListener("click", function () {
      form.reset();
      successWrap.style.display = "none";
      formWrap.style.display = "block";
      init();
    });

    init();
  });
})();
