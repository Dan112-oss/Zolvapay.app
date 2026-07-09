/* ==========================================================================
   ZolvaPay — Activity / transaction history (Phase 3)
   Talks to GET /api/transactions, a Laravel paginator response
   (flat: data, current_page, last_page, total, ...).
   ========================================================================== */

(function () {
  "use strict";

  async function authedFetch(path) {
    const token = window.ZolvaAuth.getToken();
    let response;
    try {
      response = await fetch(path, {
        headers: {
          "Accept": "application/json",
          "Authorization": "Bearer " + token,
        },
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

  function titleFor(tx) {
    if (tx.direction === "sent") return "Sent to " + tx.counterparty_name;
    if (tx.direction === "received") return "Received from " + tx.counterparty_name;
    if (tx.direction === "credit") return "Wallet credited";
    return "Wallet debited";
  }

  function isIncoming(tx) {
    return tx.direction === "received" || tx.direction === "credit";
  }

  function formatDate(isoString) {
    const date = new Date(isoString);
    return date.toLocaleDateString(undefined, { month: "short", day: "numeric" }) +
      " \u00b7 " +
      date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  }

  function renderRow(tx) {
    const incoming = isIncoming(tx);
    const row = document.createElement("div");
    row.className = "tx-row";

    const amountFormatted = tx.amount.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

    const subParts = [formatDate(tx.created_at)];
    if (tx.note) subParts.push(tx.note);

    row.innerHTML =
      '<div class="tx-row-icon ' + (incoming ? "in" : "out") + '">' +
        '<i class="fa-solid ' + (incoming ? "fa-arrow-down" : "fa-arrow-up") + '"></i>' +
      "</div>" +
      '<div class="tx-row-body">' +
        '<div class="tx-row-title"></div>' +
        '<div class="tx-row-sub"></div>' +
      "</div>" +
      '<div class="tx-row-amount ' + (incoming ? "in" : "out") + '">' +
        (incoming ? "+" : "-") + amountFormatted + " " + tx.currency_code +
      "</div>";

    row.querySelector(".tx-row-title").textContent = titleFor(tx);
    row.querySelector(".tx-row-sub").textContent = subParts.join(" \u00b7 ");

    return row;
  }

  document.addEventListener("DOMContentLoaded", function () {
    const txList = document.getElementById("txList");
    if (!txList) return; // not on this page

    const loadingState = document.getElementById("activityLoading");
    const emptyState = document.getElementById("activityEmpty");
    const errorState = document.getElementById("activityError");
    const loadMoreWrap = document.getElementById("loadMoreWrap");
    const loadMoreBtn = document.getElementById("loadMoreBtn");

    let currentPage = 1;
    let lastPage = 1;

    function setState(state) {
      loadingState.style.display = state === "loading" ? "block" : "none";
      emptyState.style.display = state === "empty" ? "block" : "none";
      errorState.style.display = state === "error" ? "block" : "none";
    }

    async function loadPage(page) {
      const data = await authedFetch("/api/transactions?page=" + page);
      currentPage = data.current_page || 1;
      lastPage = data.last_page || 1;

      const rows = data.data || [];
      rows.forEach(function (tx) {
        txList.appendChild(renderRow(tx));
      });

      loadMoreWrap.style.display = currentPage < lastPage ? "block" : "none";
    }

    async function init() {
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) {
        window.location.href = "login.html";
        return;
      }

      setState("loading");
      try {
        await loadPage(1);
        setState(txList.children.length === 0 ? "empty" : "none");
      } catch (e) {
        setState("error");
      }

      document.body.classList.add("auth-ready");
    }

    loadMoreBtn.addEventListener("click", function () {
      loadMoreBtn.disabled = true;
      loadMoreBtn.textContent = "Loading\u2026";
      loadPage(currentPage + 1)
        .catch(function () {
          // Leave existing rows in place; just stop offering to load more.
          loadMoreWrap.style.display = "none";
        })
        .finally(function () {
          loadMoreBtn.disabled = false;
          loadMoreBtn.textContent = "Load more";
        });
    });

    init();
  });
})();
