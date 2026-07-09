/* ==========================================================================
   ZolvaPay — KYC (Phase 1)
   Loads the user's current KYC status and either shows the Tier 1 form
   (not submitted / rejected) or a status card (pending / approved).
   ========================================================================== */

(function() {
  "use strict";
  
  const API_BASE = "/api/kyc";
  
  function getToken() {
    return window.ZolvaAuth ? window.ZolvaAuth.getToken() : null;
  }
  
  /**
   * Same shape/behavior as auth.js's apiFetch, duplicated here (rather than
   * shared) so this file has no dependency on auth.js internals — only on
   * its public getToken()/fetchCurrentUser() surface.
   */
  async function apiFetch(path, options) {
    options = options || {};
    const headers = Object.assign({ "Accept": "application/json" }, options.headers || {});
    
    const token = getToken();
    if (token) {
      headers["Authorization"] = "Bearer " + token;
    }
    
    // Only set Content-Type for JSON bodies — FormData needs the browser
    // to set its own multipart boundary.
    if (options.body && !(options.body instanceof FormData)) {
      headers["Content-Type"] = "application/json";
    }
    
    let response;
    try {
      response = await fetch(API_BASE + path, {
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
    
    if (!response.ok) {
      throw { status: response.status, data: data };
    }
    
    return data;
  }
  
  function show(el) { if (el) el.style.display = ""; }
  
  function hide(el) { if (el) el.style.display = "none"; }
  
  function showFieldError(inputEl, errorEl, message) {
    if (!inputEl || !errorEl) return;
    inputEl.classList.add("input-error");
    errorEl.textContent = message;
    errorEl.classList.add("visible");
  }
  
  function clearFieldError(inputEl, errorEl) {
    if (!inputEl || !errorEl) return;
    inputEl.classList.remove("input-error");
    errorEl.classList.remove("visible");
  }
  
  function showAlert(alertEl, message) {
    if (!alertEl) return;
    alertEl.textContent = message;
    alertEl.classList.add("visible");
  }
  
  function hideAlert(alertEl) {
    if (!alertEl) return;
    alertEl.classList.remove("visible");
    alertEl.textContent = "";
  }
  
  document.addEventListener("DOMContentLoaded", function() {
    const loading = document.getElementById("kycLoading");
    const formWrap = document.getElementById("kycFormWrap");
    const pendingState = document.getElementById("kycPendingState");
    const approvedState = document.getElementById("kycApprovedState");
    const rejectedNotice = document.getElementById("kycRejectedNotice");
    
    const form = document.getElementById("kycForm");
    const alertEl = document.getElementById("kycAlert");
    const submitBtn = document.getElementById("kycSubmit");
    
    const documentType = document.getElementById("documentType");
    const documentNumber = document.getElementById("documentNumber");
    const documentFront = document.getElementById("documentFront");
    const documentBack = document.getElementById("documentBack");
    const selfie = document.getElementById("selfie");
    
    (async function init() {
      // Authoritative auth check.
      const user = await window.ZolvaAuth.fetchCurrentUser();
      if (!user) {
        window.location.href = "login.html";
        return;
      }
      
      document.body.classList.add("auth-ready");
      
      let statusData;
      try {
        statusData = await apiFetch("/status");
      } catch (e) {
        hide(loading);
        show(formWrap);
        return;
      }
      
      hide(loading);
      
      const latest = statusData.latest;
      
      if (statusData.kyc_tier >= 1 || (latest && latest.verification_status === "approved")) {
        show(approvedState);
        return;
      }
      
      if (latest && latest.verification_status === "pending") {
        show(pendingState);
        return;
      }
      
      if (latest && latest.verification_status === "rejected") {
        rejectedNotice.textContent = "Your last submission was rejected: " +
          (latest.rejection_reason || "no reason given") +
          ". You can fix the issue and resubmit below.";
        show(rejectedNotice);
      }
      
      show(formWrap);
    })();
    
    if (!form) return;
    
    form.addEventListener("submit", function(event) {
      event.preventDefault();
      hideAlert(alertEl);
      
      let hasError = false;
      
      if (!documentType.value) {
        showFieldError(documentType, document.getElementById("documentTypeError"), "Select a document type.");
        hasError = true;
      } else {
        clearFieldError(documentType, document.getElementById("documentTypeError"));
      }
      
      if (!documentNumber.value.trim()) {
        showFieldError(documentNumber, document.getElementById("documentNumberError"), "Enter your document number.");
        hasError = true;
      } else {
        clearFieldError(documentNumber, document.getElementById("documentNumberError"));
      }
      
      if (!documentFront.files.length) {
        showFieldError(documentFront, document.getElementById("documentFrontError"), "Upload the front of your document.");
        hasError = true;
      } else {
        clearFieldError(documentFront, document.getElementById("documentFrontError"));
      }
      
      if (!selfie.files.length) {
        showFieldError(selfie, document.getElementById("selfieError"), "Upload a selfie.");
        hasError = true;
      } else {
        clearFieldError(selfie, document.getElementById("selfieError"));
      }
      
      if (hasError) return;
      
      const formData = new FormData();
      formData.append("document_type", documentType.value);
      formData.append("document_number", documentNumber.value.trim());
      formData.append("document_front", documentFront.files[0]);
      if (documentBack.files.length) {
        formData.append("document_back", documentBack.files[0]);
      }
      formData.append("selfie", selfie.files[0]);
      
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Submitting\u2026";
      
      apiFetch("/submit", { method: "POST", body: formData })
        .then(function() {
          hide(formWrap);
          show(pendingState);
        })
        .catch(function(err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
          
          if (err.status === 422) {
            showAlert(alertEl, "Please check your details and try again.");
          } else if (err.status === 409) {
            showAlert(alertEl, (err.data && err.data.message) || "You already have a submission on file.");
          } else if (err.status === 0) {
            showAlert(alertEl, "Can't reach the server. Check your connection and try again.");
          } else {
            showAlert(alertEl, "Something went wrong. Please try again.");
          }
        });
    });
  });
})();