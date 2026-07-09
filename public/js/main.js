/* ==========================================================================
   ZolvaPay — Shared site behavior (Phase 0)
   Anything here should be generic UI polish shared across pages.
   Auth-specific logic (login/signup/session) lives in auth.js, not here.
   ========================================================================== */

(function () {
  "use strict";

  function setFooterYear() {
    const yearEl = document.getElementById("year");
    if (yearEl) {
      yearEl.textContent = new Date().getFullYear();
    }
  }

  function addNavbarScrollShadow() {
    const navbar = document.querySelector(".navbar");
    if (!navbar) return;

    function updateShadow() {
      if (window.scrollY > 8) {
        navbar.style.boxShadow = "0 4px 14px rgba(11, 30, 61, 0.06)";
      } else {
        navbar.style.boxShadow = "none";
      }
    }

    updateShadow();
    window.addEventListener("scroll", updateShadow, { passive: true });
  }

  document.addEventListener("DOMContentLoaded", function () {
    setFooterYear();
    addNavbarScrollShadow();
  });
})();
