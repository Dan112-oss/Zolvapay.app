/* ZolvaPay — Loans (Phase 9)
   Talks to GET/POST /api/loans*, GET /api/fx/currencies.
   Note: applying is NOT gated by a KYC "locked" screen the way other
   pages are — LoanScoringService itself returns a clear rejection
   reason (which can be "KYC Tier 1 required") as part of a normal
   scored response, so the form stays open and shows that reason inline
   rather than blocking the page before the user even tries. */
(function () {
  "use strict";

  async function authedFetch(path, options) {
    options = options || {};
    const token = window.ZolvaAuth.getToken();
    const headers = Object.assign({ "Content-Type": "application/json", "Accept": "application/json", "Authorization": "Bearer " + token }, options.headers || {});
    let response;
    try {
      response = await fetch(path, { method: options.method || "GET", headers: headers, body: options.body ? JSON.stringify(options.body) : undefined });
    } catch (e) { throw { status: 0, data: null }; }
    let data = null;
    try { data = await response.json(); } catch (e) { data = null; }
    if (!response.ok) throw { status: response.status, data: data };
    return data;
  }

  function showAlert(el, msg) { el.textContent = msg; el.classList.add("visible"); }
  function hideAlert(el) { el.classList.remove("visible"); el.textContent = ""; }
  function formatAmount(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  document.addEventListener("DOMContentLoaded", function () {
    const applyFormCard = document.getElementById("applyFormCard");
    if (!applyFormCard) return;

    const applyForm = document.getElementById("applyForm");
    const applyAlert = document.getElementById("applyAlert");
    const loanCurrencySelect = document.getElementById("loanCurrency");
    const applySubmitBtn = document.getElementById("applySubmitBtn");
    const loansList = document.getElementById("loansList");
    const emptyState = document.getElementById("emptyState");

    function showApplyForm() { applyFormCard.classList.add("visible"); applyFormCard.scrollIntoView({ behavior: "smooth", block: "nearest" }); }
    function hideApplyForm() { applyFormCard.classList.remove("visible"); applyForm.reset(); hideAlert(applyAlert); }
    document.getElementById("showApplyFormBtn").addEventListener("click", showApplyForm);
    document.getElementById("cancelApplyBtn").addEventListener("click", hideApplyForm);

    function loanTemplate(loan) {
      const isActive = loan.status === "active";
      return (
        '<div class="loan-card" data-loan-id="' + loan.id + '">' +
          '<div class="loan-top"><span class="loan-amount">' + formatAmount(loan.principal_minor / 100) + ' ' + loan.currency_code + '</span>' +
          '<span class="loan-status ' + loan.status + '">' + loan.status + '</span></div>' +
          (loan.status === 'rejected'
            ? '<p class="loan-reason">' + (loan.rejection_reason || 'Not approved.') + '</p>'
            : '<p class="loan-detail">Interest: ' + (loan.interest_rate_bps / 100) + '% \u00b7 Outstanding: ' + formatAmount(loan.outstanding_balance_minor / 100) + ' ' + loan.currency_code + '</p>' +
              (loan.due_date ? '<p class="loan-detail">Due: ' + loan.due_date + '</p>' : '')) +
          (isActive
            ? '<button type="button" class="loan-action-btn" data-action="repay" data-loan-id="' + loan.id + '">Repay</button>' +
              '<div class="loan-inline-form" data-form-for="' + loan.id + '">' +
                '<input type="number" step="0.01" min="0.01" placeholder="Amount" data-amount-input="' + loan.id + '">' +
                '<button type="button" data-confirm-for="' + loan.id + '">Go</button>' +
              '</div>'
            : '') +
        '</div>'
      );
    }

    async function loadLoans() {
      let loans = [];
      try { loans = (await authedFetch("/api/loans")).loans || []; } catch (e) { loans = []; }
      if (loans.length === 0) { loansList.innerHTML = ""; emptyState.style.display = "block"; return; }
      emptyState.style.display = "none";
      loansList.innerHTML = loans.map(loanTemplate).join("");
    }

    loansList.addEventListener("click", function (event) {
      const actionBtn = event.target.closest("[data-action]");
      if (actionBtn) {
        const form = loansList.querySelector('[data-form-for="' + actionBtn.dataset.loanId + '"]');
        if (form) form.classList.toggle("visible");
        return;
      }

      const confirmBtn = event.target.closest("[data-confirm-for]");
      if (confirmBtn) {
        const loanId = confirmBtn.dataset.confirmFor;
        const input = loansList.querySelector('[data-amount-input="' + loanId + '"]');
        const amount = parseFloat(input.value);
        if (!amount || amount <= 0) { input.style.borderColor = "#D9483B"; return; }
        input.style.borderColor = "";

        confirmBtn.disabled = true;
        authedFetch("/api/loans/" + loanId + "/repay", { method: "POST", body: { amount: amount } })
          .then(loadLoans)
          .catch(function (err) { alert((err.data && err.data.message) || "Something went wrong."); })
          .finally(function () { confirmBtn.disabled = false; });
      }
    });

    applyForm.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(applyAlert);

      const amount = parseFloat(document.getElementById("loanAmount").value);
      const currency = loanCurrencySelect.value;
      if (!amount || amount <= 0) { showAlert(applyAlert, "Enter a valid amount."); return; }
      if (!currency) { showAlert(applyAlert, "Choose a currency."); return; }

      const originalLabel = applySubmitBtn.textContent;
      applySubmitBtn.disabled = true;
      applySubmitBtn.textContent = "Applying\u2026";

      authedFetch("/api/loans/apply", { method: "POST", body: { amount: amount, currency_code: currency } })
        .then(function () { hideApplyForm(); return loadLoans(); })
        .catch(function (err) { showAlert(applyAlert, (err.data && err.data.message) || "Couldn't submit the application."); })
        .finally(function () { applySubmitBtn.disabled = false; applySubmitBtn.textContent = originalLabel; });
    });

    async function init() {
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) { window.location.href = "login.html"; return; }

      try {
        const currencies = (await authedFetch("/api/fx/currencies")).currencies || [];
        loanCurrencySelect.innerHTML = "";
        currencies.forEach(function (code, i) {
          const option = document.createElement("option");
          option.value = code; option.textContent = code;
          if (i === 0) option.selected = true;
          loanCurrencySelect.appendChild(option);
        });
      } catch (e) { /* form still usable */ }

      await loadLoans();
      document.body.classList.add("auth-ready");
    }

    init();
  });
})();
