/* ==========================================================================
   ZolvaPay — Auth (Phase 1)
   Talks to the real Laravel + Sanctum API (/api/auth/*) instead of the
   Phase 0 localStorage stub. Tokens are Bearer tokens issued by Sanctum;
   there is no more local "users" store or fake session object.
   ========================================================================== */

(function () {
  "use strict";

  const API_BASE = "/api/auth";
  const TOKEN_KEY = "zolvapay_token";

  /* ---------- Token storage helpers ---------- */

  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || sessionStorage.getItem(TOKEN_KEY) || null;
  }

  function storeToken(token, remember) {
    // Clear both first so switching "remember me" on/off never leaves a
    // stale copy behind in the other storage.
    localStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(TOKEN_KEY);

    if (remember) {
      localStorage.setItem(TOKEN_KEY, token);
    } else {
      sessionStorage.setItem(TOKEN_KEY, token);
    }
  }

  function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(TOKEN_KEY);
  }

  /* ---------- API helper ---------- */

  /**
   * Wraps fetch() with JSON headers, the bearer token (if present), and
   * consistent error handling. Throws an object shaped like:
   *   { status: <http status or 0 for network failure>, data: <parsed body or null> }
   */
  async function apiFetch(path, options) {
    options = options || {};
    const headers = Object.assign(
      {
        "Content-Type": "application/json",
        "Accept": "application/json",
      },
      options.headers || {}
    );

    const token = getToken();
    if (token) {
      headers["Authorization"] = "Bearer " + token;
    }

    let response;
    try {
      response = await fetch(API_BASE + path, {
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

  /* ---------- Public auth actions ---------- */

  async function login(email, password, remember) {
    const data = await apiFetch("/login", {
      method: "POST",
      body: { email: email, password: password },
    });
    storeToken(data.token, remember);
    return data.user;
  }

  async function register(payload, remember) {
    const data = await apiFetch("/register", {
      method: "POST",
      body: payload,
    });
    storeToken(data.token, remember);
    return data.user;
  }

  async function logout() {
    try {
      if (getToken()) {
        await apiFetch("/logout", { method: "POST" });
      }
    } catch (e) {
      // Best effort — even if the network call fails, clear the local
      // token so the user is logged out on this device regardless.
    }
    clearToken();
  }

  async function fetchCurrentUser() {
    if (!getToken()) return null;
    try {
      const data = await apiFetch("/me");
      return data.user;
    } catch (e) {
      // Token missing/expired/revoked server-side — treat as logged out.
      clearToken();
      return null;
    }
  }

  /* ---------- Validation helpers ---------- */

  function isValidEmail(value) {
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailPattern.test(value.trim());
  }

  function showFieldError(inputEl, errorEl, show, message) {
    if (!inputEl || !errorEl) return;
    inputEl.classList.toggle("input-error", show);
    errorEl.classList.toggle("visible", show);
    if (show && message) {
      errorEl.textContent = message;
    }
  }

  function showFormAlert(alertEl, message) {
    if (!alertEl) return;
    alertEl.textContent = message;
    alertEl.classList.add("visible");
  }

  function hideFormAlert(alertEl) {
    if (!alertEl) return;
    alertEl.classList.remove("visible");
    alertEl.textContent = "";
  }

  /**
   * Applies Laravel-style validation errors ({ field: [messages] }) to
   * whatever matching field-error elements exist on the current page.
   * fieldMap maps API field names -> { input, error } DOM element ids.
   */
  function applyServerErrors(errors, fieldMap, formAlert) {
    let handledAny = false;
    Object.keys(fieldMap).forEach(function (apiField) {
      if (errors && errors[apiField]) {
        const ids = fieldMap[apiField];
        const inputEl = document.getElementById(ids.input);
        const errorEl = document.getElementById(ids.error);
        showFieldError(inputEl, errorEl, true, errors[apiField][0]);
        handledAny = true;
      }
    });
    if (!handledAny) {
      showFormAlert(formAlert, "Something went wrong. Please check your details and try again.");
    }
  }

  /* ---------- Login form wiring ---------- */

  function initLoginForm() {
    const form = document.getElementById("loginForm");
    if (!form) return;

    const emailInput = document.getElementById("email");
    const passwordInput = document.getElementById("password");
    const emailError = document.getElementById("emailError");
    const passwordError = document.getElementById("passwordError");
    const rememberMe = document.getElementById("rememberMe");
    const formAlert = document.getElementById("formAlert");
    const submitBtn = document.getElementById("loginSubmit");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideFormAlert(formAlert);

      const emailValue = emailInput.value.trim();
      const passwordValue = passwordInput.value;

      let hasError = false;

      if (!emailValue || !isValidEmail(emailValue)) {
        showFieldError(emailInput, emailError, true, "Enter a valid email address.");
        hasError = true;
      } else {
        showFieldError(emailInput, emailError, false);
      }

      if (!passwordValue) {
        showFieldError(passwordInput, passwordError, true, "Password is required.");
        hasError = true;
      } else {
        showFieldError(passwordInput, passwordError, false);
      }

      if (hasError) return;

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Logging in\u2026";

      login(emailValue, passwordValue, !!(rememberMe && rememberMe.checked))
        .then(function () {
          window.location.href = "dashboard.html";
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;

          if (err.status === 401) {
            showFormAlert(formAlert, (err.data && err.data.message) || "These credentials do not match our records.");
          } else if (err.status === 403) {
            showFormAlert(formAlert, (err.data && err.data.message) || "This account is not active.");
          } else if (err.status === 422) {
            applyServerErrors(err.data && err.data.errors, {
              email: { input: "email", error: "emailError" },
              password: { input: "password", error: "passwordError" },
            }, formAlert);
          } else if (err.status === 0) {
            showFormAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showFormAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });
  }

  /* ---------- Signup form wiring ---------- */

  function initSignupForm() {
    const form = document.getElementById("signupForm");
    if (!form) return;

    const fullNameInput = document.getElementById("fullName");
    const emailInput = document.getElementById("signupEmail");
    const countryInput = document.getElementById("country");
    const passwordInput = document.getElementById("signupPassword");
    const confirmInput = document.getElementById("confirmPassword");
    const termsInput = document.getElementById("termsCheckbox");

    const fullNameError = document.getElementById("fullNameError");
    const emailError = document.getElementById("signupEmailError");
    const countryError = document.getElementById("countryError");
    const passwordError = document.getElementById("signupPasswordError");
    const confirmError = document.getElementById("confirmPasswordError");

    const formAlert = document.getElementById("formAlert");
    const submitBtn = document.getElementById("signupSubmit");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideFormAlert(formAlert);

      const nameValue = fullNameInput.value.trim();
      const emailValue = emailInput.value.trim();
      const countryValue = countryInput.value;
      const passwordValue = passwordInput.value;
      const confirmValue = confirmInput.value;

      let hasError = false;

      if (!nameValue) {
        showFieldError(fullNameInput, fullNameError, true, "Enter your full name.");
        hasError = true;
      } else {
        showFieldError(fullNameInput, fullNameError, false);
      }

      if (!emailValue || !isValidEmail(emailValue)) {
        showFieldError(emailInput, emailError, true, "Enter a valid email address.");
        hasError = true;
      } else {
        showFieldError(emailInput, emailError, false);
      }

      if (!countryValue) {
        showFieldError(countryInput, countryError, true, "Select your country.");
        hasError = true;
      } else {
        showFieldError(countryInput, countryError, false);
      }

      if (!passwordValue || passwordValue.length < 8) {
        showFieldError(passwordInput, passwordError, true, "Minimum 8 characters.");
        hasError = true;
      } else {
        showFieldError(passwordInput, passwordError, false);
      }

      if (!confirmValue || confirmValue !== passwordValue) {
        showFieldError(confirmInput, confirmError, true, "Passwords don't match.");
        hasError = true;
      } else {
        showFieldError(confirmInput, confirmError, false);
      }

      if (!termsInput.checked) {
        showFormAlert(formAlert, "Please accept the Terms of Service and Privacy Policy to continue.");
        hasError = true;
      }

      if (hasError) return;

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Creating account\u2026";

      register(
        {
          name: nameValue,
          email: emailValue,
          country_code: countryValue,
          password: passwordValue,
          password_confirmation: confirmValue,
        },
        true
      )
        .then(function () {
          window.location.href = "dashboard.html";
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;

          if (err.status === 422) {
            applyServerErrors(err.data && err.data.errors, {
              name: { input: "fullName", error: "fullNameError" },
              email: { input: "signupEmail", error: "signupEmailError" },
              country_code: { input: "country", error: "countryError" },
              password: { input: "signupPassword", error: "signupPasswordError" },
            }, formAlert);
          } else if (err.status === 0) {
            showFormAlert(formAlert, "Can't reach the server. Check your connection and try again.");
          } else {
            showFormAlert(formAlert, "Something went wrong. Please try again.");
          }
        });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initLoginForm();
    initSignupForm();
  });

  // Public API used by protected pages (e.g. dashboard.html) to check auth
  // state, fetch the current user, and log out.
  window.ZolvaAuth = {
    getToken: getToken,
    logout: logout,
    fetchCurrentUser: fetchCurrentUser,
  };
})();
