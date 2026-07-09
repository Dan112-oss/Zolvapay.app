/* ==========================================================================
   ZolvaPay — Cards (Phase 6)
   Talks to GET/POST /api/cards, POST /api/cards/{id}/freeze|unfreeze,
   PATCH /api/cards/{id}/limits, POST /api/cards/{id}/reveal, and
   GET /api/fx/currencies. Uses window.ZolvaAuth (auth.js).
   ========================================================================== */

(function () {
  "use strict";

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
  function formatAmount(value) {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function monthYear(month, year) {
    return String(month).padStart(2, "0") + "/" + String(year).slice(-2);
  }

  document.addEventListener("DOMContentLoaded", function () {
    const lockedWrap = document.getElementById("cardsLocked");
    const contentWrap = document.getElementById("cardsContent");
    if (!contentWrap) return;

    const cardsList = document.getElementById("cardsList");
    const emptyState = document.getElementById("emptyState");

    const issueFormCard = document.getElementById("issueFormCard");
    const issueForm = document.getElementById("issueForm");
    const issueAlert = document.getElementById("issueAlert");
    const issueCurrencySelect = document.getElementById("issueCurrency");
    const issueSubmitBtn = document.getElementById("issueSubmitBtn");

    function showIssueForm() {
      issueFormCard.classList.add("visible");
      issueFormCard.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
    function hideIssueForm() {
      issueFormCard.classList.remove("visible");
      issueForm.reset();
      hideAlert(issueAlert);
    }

    document.getElementById("showIssueFormBtn").addEventListener("click", showIssueForm);
    document.getElementById("cancelIssueBtn").addEventListener("click", hideIssueForm);
    document.getElementById("emptyIssueBtn").addEventListener("click", showIssueForm);

    function cardTemplate(card) {
      const frozen = card.status === "frozen";
      const limitText = card.spend_limit_minor
        ? formatAmount(card.spend_limit_minor / 100) + " " + card.currency_code + " daily limit"
        : "No spend limit set";

      return (
        '<div class="card-tile' + (frozen ? " frozen" : "") + '" data-card-id="' + card.id + '">' +
          '<div class="card-tile-top">' +
            '<span class="card-tile-currency">' + card.currency_code + ' \u00b7 VIRTUAL</span>' +
            '<span class="card-tile-status">' + card.status + '</span>' +
          '</div>' +
          '<div class="card-tile-pan">' + card.masked_pan + '</div>' +
          '<div class="card-tile-bottom">' +
            '<span class="card-tile-name">' + card.cardholder_name + '</span>' +
            '<span class="card-tile-expiry">' + monthYear(card.expiry_month, card.expiry_year) + '</span>' +
          '</div>' +
        '</div>' +
        '<div class="reveal-box" data-reveal-for="' + card.id + '"></div>' +
        '<div class="limit-row" data-limit-for="' + card.id + '">' +
          '<input type="number" step="0.01" min="0.01" placeholder="Daily limit (' + card.currency_code + ')" data-limit-input="' + card.id + '">' +
          '<button type="button" data-save-limit="' + card.id + '">Save</button>' +
        '</div>' +
        '<p style="font-size:12px;color:var(--muted);margin:-14px 0 12px;">' + limitText + '</p>' +
        '<div class="card-actions">' +
          '<button type="button" class="card-action-btn" data-action="reveal" data-card-id="' + card.id + '">Show details</button>' +
          '<button type="button" class="card-action-btn" data-action="limit" data-card-id="' + card.id + '">Set limit</button>' +
          '<button type="button" class="card-action-btn' + (frozen ? "" : " danger") + '" data-action="' + (frozen ? "unfreeze" : "freeze") + '" data-card-id="' + card.id + '">' +
            (frozen ? "Unfreeze" : "Freeze") +
          '</button>' +
        '</div>'
      );
    }

    async function loadCards() {
      let cards = [];
      try {
        const data = await authedFetch("/api/cards");
        cards = data.cards || [];
      } catch (e) {
        cardsList.innerHTML = "";
        emptyState.style.display = "none";
        return;
      }

      if (cards.length === 0) {
        cardsList.innerHTML = "";
        emptyState.style.display = "block";
        return;
      }

      emptyState.style.display = "none";
      cardsList.innerHTML = cards.map(cardTemplate).join("");
    }

    cardsList.addEventListener("click", function (event) {
      const btn = event.target.closest("[data-action]");
      if (!btn) return;

      const action = btn.dataset.action;
      const cardId = btn.dataset.cardId;

      if (action === "freeze" || action === "unfreeze") {
        btn.disabled = true;
        authedFetch("/api/cards/" + cardId + "/" + action, { method: "POST" })
          .then(loadCards)
          .catch(function () { btn.disabled = false; });
        return;
      }

      if (action === "limit") {
        const row = cardsList.querySelector('[data-limit-for="' + cardId + '"]');
        if (row) row.classList.toggle("visible");
        return;
      }

      if (action === "reveal") {
        const box = cardsList.querySelector('[data-reveal-for="' + cardId + '"]');
        if (!box) return;

        if (box.classList.contains("visible")) {
          box.classList.remove("visible");
          box.innerHTML = "";
          return;
        }

        btn.disabled = true;
        authedFetch("/api/cards/" + cardId + "/reveal", { method: "POST" })
          .then(function (data) {
            const d = data.details || {};
            box.innerHTML =
              '<div class="reveal-row"><span>Card number</span><strong>' + d.pan + '</strong></div>' +
              '<div class="reveal-row"><span>Expiry</span><strong>' + monthYear(d.expiry_month, d.expiry_year) + '</strong></div>' +
              '<div class="reveal-row"><span>CVV</span><strong>' + d.cvv + '</strong></div>' +
              '<p class="reveal-warning">Only visible to you. Closes automatically in 20s.</p>';
            box.classList.add("visible");

            window.setTimeout(function () {
              box.classList.remove("visible");
              box.innerHTML = "";
            }, 20000);
          })
          .catch(function () {
            box.innerHTML = '<p class="reveal-warning">Couldn\'t load card details. Try again.</p>';
            box.classList.add("visible");
          })
          .finally(function () { btn.disabled = false; });
      }
    });

    cardsList.addEventListener("click", function (event) {
      const saveBtn = event.target.closest("[data-save-limit]");
      if (!saveBtn) return;

      const cardId = saveBtn.dataset.saveLimit;
      const input = cardsList.querySelector('[data-limit-input="' + cardId + '"]');
      const value = input.value.trim();
      const amount = value === "" ? null : parseFloat(value);

      if (value !== "" && (isNaN(amount) || amount <= 0)) {
        input.style.borderColor = "#D9483B";
        return;
      }
      input.style.borderColor = "";

      saveBtn.disabled = true;
      authedFetch("/api/cards/" + cardId + "/limits", {
        method: "PATCH",
        body: { spend_limit: amount },
      })
        .then(loadCards)
        .finally(function () { saveBtn.disabled = false; });
    });

    issueForm.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert(issueAlert);

      const currencyValue = issueCurrencySelect.value;
      if (!currencyValue) {
        showAlert(issueAlert, "Choose a currency.");
        return;
      }

      const cardholderName = document.getElementById("cardholderName").value.trim();
      const originalLabel = issueSubmitBtn.textContent;
      issueSubmitBtn.disabled = true;
      issueSubmitBtn.textContent = "Issuing\u2026";

      authedFetch("/api/cards", {
        method: "POST",
        body: {
          currency_code: currencyValue,
          cardholder_name: cardholderName || undefined,
        },
      })
        .then(function () {
          hideIssueForm();
          return loadCards();
        })
        .catch(function (err) {
          showAlert(issueAlert, (err.data && err.data.message) || "Couldn't issue a card. Please try again.");
        })
        .finally(function () {
          issueSubmitBtn.disabled = false;
          issueSubmitBtn.textContent = originalLabel;
        });
    });

    async function init() {
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) {
        window.location.href = "login.html";
        return;
      }

      if (!user.kyc_tier || user.kyc_tier < 1) {
        lockedWrap.style.display = "block";
        contentWrap.style.display = "none";
        document.body.classList.add("auth-ready");
        return;
      }

      contentWrap.style.display = "block";

      try {
        const currenciesData = await authedFetch("/api/fx/currencies");
        const currencies = currenciesData.currencies || [];
        issueCurrencySelect.innerHTML = "";
        currencies.forEach(function (code, index) {
          const option = document.createElement("option");
          option.value = code;
          option.textContent = code;
          if (index === 0) option.selected = true;
          issueCurrencySelect.appendChild(option);
        });
      } catch (e) {
        // issue form stays usable even if this fails to load; the
        // "--" placeholder option makes the missing state obvious
      }

      await loadCards();
      document.body.classList.add("auth-ready");
    }

    init();
  });
})();
