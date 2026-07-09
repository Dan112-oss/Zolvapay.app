/* ZolvaPay — Savings (Phase 9)
   Talks to GET/POST /api/savings/goals, POST .../deposit, .../withdraw,
   GET /api/fx/currencies. Uses window.ZolvaAuth. */
(function () {
  "use strict";

  function generateIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0; const v = c === "x" ? r : (r & 0x3) | 0x8; return v.toString(16);
    });
  }

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
    const lockedWrap = document.getElementById("savingsLocked");
    const contentWrap = document.getElementById("savingsContent");
    if (!contentWrap) return;

    const goalsList = document.getElementById("goalsList");
    const emptyState = document.getElementById("emptyState");
    const createFormCard = document.getElementById("createFormCard");
    const createForm = document.getElementById("createForm");
    const createAlert = document.getElementById("createAlert");
    const goalCurrencySelect = document.getElementById("goalCurrency");
    const createSubmitBtn = document.getElementById("createSubmitBtn");

    function showCreateForm() { createFormCard.classList.add("visible"); createFormCard.scrollIntoView({ behavior: "smooth", block: "nearest" }); }
    function hideCreateForm() { createFormCard.classList.remove("visible"); createForm.reset(); hideAlert(createAlert); }
    document.getElementById("showCreateFormBtn").addEventListener("click", showCreateForm);
    document.getElementById("cancelCreateBtn").addEventListener("click", hideCreateForm);
    document.getElementById("emptyCreateBtn").addEventListener("click", showCreateForm);

    function goalTemplate(goal) {
      const pct = goal.target_amount_minor ? Math.min(100, Math.round((goal.current_amount_minor / goal.target_amount_minor) * 100)) : null;
      return (
        '<div class="goal-card" data-goal-id="' + goal.id + '">' +
          '<div class="goal-top"><span class="goal-name">' + goal.name + '</span><span class="goal-status">' + goal.status + '</span></div>' +
          '<div class="goal-amount">' + formatAmount(goal.current_amount_minor / 100) + ' ' + goal.currency_code + '</div>' +
          '<div class="goal-target">' + (goal.target_amount_minor ? 'Target: ' + formatAmount(goal.target_amount_minor / 100) + ' ' + goal.currency_code + ' \u00b7 ' + pct + '%' : 'Open-ended \u00b7 ' + (goal.interest_rate_bps / 100) + '% APY') + '</div>' +
          (goal.target_amount_minor ? '<div class="goal-bar-track"><div class="goal-bar-fill" style="width:' + pct + '%"></div></div>' : '') +
          '<div class="goal-actions">' +
            '<button type="button" class="goal-action-btn" data-action="deposit" data-goal-id="' + goal.id + '">Add money</button>' +
            '<button type="button" class="goal-action-btn" data-action="withdraw" data-goal-id="' + goal.id + '">Withdraw</button>' +
          '</div>' +
          '<div class="goal-inline-form" data-form-for="' + goal.id + '">' +
            '<input type="number" step="0.01" min="0.01" placeholder="Amount" data-amount-input="' + goal.id + '">' +
            '<button type="button" data-confirm-for="' + goal.id + '">Go</button>' +
          '</div>' +
        '</div>'
      );
    }

    let pendingAction = null; // { goalId, action }

    async function loadGoals() {
      let goals = [];
      try { goals = (await authedFetch("/api/savings/goals")).goals || []; } catch (e) { goals = []; }
      if (goals.length === 0) { goalsList.innerHTML = ""; emptyState.style.display = "block"; return; }
      emptyState.style.display = "none";
      goalsList.innerHTML = goals.map(goalTemplate).join("");
    }

    goalsList.addEventListener("click", function (event) {
      const actionBtn = event.target.closest("[data-action]");
      if (actionBtn) {
        const goalId = actionBtn.dataset.goalId;
        const form = goalsList.querySelector('[data-form-for="' + goalId + '"]');
        pendingAction = { goalId: goalId, action: actionBtn.dataset.action };
        if (form) form.classList.toggle("visible");
        return;
      }

      const confirmBtn = event.target.closest("[data-confirm-for]");
      if (confirmBtn && pendingAction) {
        const goalId = confirmBtn.dataset.confirmFor;
        const input = goalsList.querySelector('[data-amount-input="' + goalId + '"]');
        const amount = parseFloat(input.value);
        if (!amount || amount <= 0) { input.style.borderColor = "#D9483B"; return; }
        input.style.borderColor = "";

        confirmBtn.disabled = true;
        authedFetch("/api/savings/goals/" + goalId + "/" + pendingAction.action, {
          method: "POST",
          body: { amount: amount, idempotency_key: generateIdempotencyKey() },
        })
          .then(loadGoals)
          .catch(function (err) { alert((err.data && err.data.message) || "Something went wrong."); })
          .finally(function () { confirmBtn.disabled = false; });
      }
    });

    createForm.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(createAlert);

      const name = document.getElementById("goalName").value.trim();
      const currency = goalCurrencySelect.value;
      const target = document.getElementById("goalTarget").value.trim();

      if (!name) { showAlert(createAlert, "Give your goal a name."); return; }
      if (!currency) { showAlert(createAlert, "Choose a currency."); return; }

      const originalLabel = createSubmitBtn.textContent;
      createSubmitBtn.disabled = true;
      createSubmitBtn.textContent = "Creating\u2026";

      authedFetch("/api/savings/goals", {
        method: "POST",
        body: { name: name, currency_code: currency, target_amount: target ? parseFloat(target) : undefined },
      })
        .then(function () { hideCreateForm(); return loadGoals(); })
        .catch(function (err) { showAlert(createAlert, (err.data && err.data.message) || "Couldn't create the goal."); })
        .finally(function () { createSubmitBtn.disabled = false; createSubmitBtn.textContent = originalLabel; });
    });

    async function init() {
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) { window.location.href = "login.html"; return; }

      if (!user.kyc_tier || user.kyc_tier < 1) {
        lockedWrap.style.display = "block"; contentWrap.style.display = "none";
        document.body.classList.add("auth-ready");
        return;
      }
      contentWrap.style.display = "block";

      try {
        const currencies = (await authedFetch("/api/fx/currencies")).currencies || [];
        goalCurrencySelect.innerHTML = "";
        currencies.forEach(function (code, i) {
          const option = document.createElement("option");
          option.value = code; option.textContent = code;
          if (i === 0) option.selected = true;
          goalCurrencySelect.appendChild(option);
        });
      } catch (e) { /* form still usable */ }

      await loadGoals();
      document.body.classList.add("auth-ready");
    }

    init();
  });
})();
