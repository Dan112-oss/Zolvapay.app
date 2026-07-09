/* ==========================================================================
   ZolvaPay — Admin KYC queue (Phase 1)
   Client-side admin check is a convenience only; the real gate is the
   'admin' middleware on every /api/admin/kyc/* route — a non-admin token
   gets a 403 from the API regardless of what this page shows.
   ========================================================================== */

(function () {
  "use strict";

  function getToken() {
    return window.ZolvaAuth ? window.ZolvaAuth.getToken() : null;
  }

  async function apiFetch(path, options) {
    options = options || {};
    const headers = Object.assign({ "Accept": "application/json" }, options.headers || {});

    const token = getToken();
    if (token) headers["Authorization"] = "Bearer " + token;

    if (options.body && !(options.body instanceof FormData)) {
      headers["Content-Type"] = "application/json";
    }

    let response;
    try {
      response = await fetch(path, {
        method: options.method || "GET",
        headers: headers,
        body: options.body instanceof FormData ? options.body : (options.body ? JSON.stringify(options.body) : undefined),
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

    if (!response.ok) throw { status: response.status, data: data };
    return data;
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  }

  function formatDocType(type) {
    return { passport: "Passport", national_id: "National ID", drivers_license: "Driver's license" }[type] || type;
  }

  function formatDate(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
  }

  async function viewDocument(recordId, type, btnEl) {
    const originalLabel = btnEl.textContent;
    btnEl.textContent = "Loading\u2026";
    btnEl.disabled = true;
    try {
      const token = getToken();
      const response = await fetch("/api/admin/kyc/" + recordId + "/document/" + type, {
        headers: { "Authorization": "Bearer " + token },
      });
      if (!response.ok) throw new Error("Failed to load document");
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      window.open(url, "_blank");
    } catch (e) {
      alert("Couldn't load that document.");
    } finally {
      btnEl.textContent = originalLabel;
      btnEl.disabled = false;
    }
  }

  function buildRow(record) {
    const row = document.createElement("div");
    row.className = "queue-row";
    row.dataset.id = record.id;

    const user = record.user || {};

    row.innerHTML =
      '<div class="queue-row-top">' +
        '<div>' +
          '<div class="queue-user-name">' + escapeHtml(user.name || "Unknown user") + '</div>' +
          '<div class="queue-user-email">' + escapeHtml(user.email || "") + '</div>' +
          '<span class="queue-doc-type">' + escapeHtml(formatDocType(record.document_type)) + '</span>' +
        '</div>' +
        '<div class="queue-meta">' +
          'Submitted ' + escapeHtml(formatDate(record.submitted_at)) + '<br>' +
          escapeHtml(user.country_code || "") +
        '</div>' +
      '</div>' +
      '<div class="queue-doc-links">' +
        '<button type="button" class="doc-link" data-type="front">View front</button>' +
        (record.has_document_back ? '<button type="button" class="doc-link" data-type="back">View back</button>' : '') +
        '<button type="button" class="doc-link" data-type="selfie">View selfie</button>' +
      '</div>' +
      '<div class="queue-actions">' +
        '<button type="button" class="btn btn-primary btn-approve">Approve</button>' +
        '<button type="button" class="btn btn-reject">Reject</button>' +
      '</div>' +
      '<div class="reject-reason-row">' +
        '<input type="text" placeholder="Reason for rejection" class="reject-reason-input">' +
        '<button type="button" class="btn btn-primary btn-confirm-reject">Confirm</button>' +
      '</div>';

    row.querySelectorAll(".doc-link").forEach(function (btn) {
      btn.addEventListener("click", function () {
        viewDocument(record.id, btn.dataset.type, btn);
      });
    });

    row.querySelector(".btn-approve").addEventListener("click", function () {
      handleApprove(record.id, row);
    });

    const rejectBtn = row.querySelector(".btn-reject");
    const reasonRow = row.querySelector(".reject-reason-row");
    rejectBtn.addEventListener("click", function () {
      reasonRow.classList.toggle("visible");
    });

    row.querySelector(".btn-confirm-reject").addEventListener("click", function () {
      const reason = row.querySelector(".reject-reason-input").value.trim();
      if (!reason) {
        alert("Please enter a reason for rejection.");
        return;
      }
      handleReject(record.id, reason, row);
    });

    return row;
  }

  async function handleApprove(id, row) {
    row.classList.add("processed");
    try {
      await apiFetch("/api/admin/kyc/" + id + "/approve", { method: "POST" });
      row.remove();
      updateEmptyState();
    } catch (e) {
      row.classList.remove("processed");
      alert("Couldn't approve this submission. Please try again.");
    }
  }

  async function handleReject(id, reason, row) {
    row.classList.add("processed");
    try {
      await apiFetch("/api/admin/kyc/" + id + "/reject", {
        method: "POST",
        body: { reason: reason },
      });
      row.remove();
      updateEmptyState();
    } catch (e) {
      row.classList.remove("processed");
      alert("Couldn't reject this submission. Please try again.");
    }
  }

  function updateEmptyState() {
    const list = document.getElementById("queueList");
    const empty = document.getElementById("queueEmpty");
    const count = document.getElementById("queueCount");
    const remaining = list.children.length;
    count.textContent = remaining + " pending submission" + (remaining === 1 ? "" : "s");
    empty.style.display = remaining === 0 ? "" : "none";
  }

  async function loadQueue() {
    const list = document.getElementById("queueList");
    const count = document.getElementById("queueCount");

    let data;
    try {
      data = await apiFetch("/api/admin/kyc?status=pending");
    } catch (e) {
      count.textContent = "Couldn't load the queue.";
      return;
    }

    const records = data.data || [];
    count.textContent = records.length + " pending submission" + (records.length === 1 ? "" : "s");

    if (records.length === 0) {
      document.getElementById("queueEmpty").style.display = "";
      return;
    }

    records.forEach(function (record) {
      list.appendChild(buildRow(record));
    });
  }

  document.addEventListener("DOMContentLoaded", async function () {
    const user = await window.ZolvaAuth.fetchCurrentUser();

    if (!user) {
      window.location.href = "../login.html";
      return;
    }

    if (user.role !== "admin" && user.role !== "super_admin") {
      document.getElementById("adminDenied").style.display = "";
      document.getElementById("queueCount").textContent = "";
      document.body.classList.add("auth-ready");
      return;
    }

    document.body.classList.add("auth-ready");
    loadQueue();
  });
})();
