/**
 * CAPTCHA Terminal Theme Enhancements
 * Provides enhanced user experience for CAPTCHA interaction
 */

function shouldAutoFocusCaptcha() {
  if (window.matchMedia("(pointer: coarse)").matches) {
    return false;
  }
  if (window.matchMedia("(max-width: 768px)").matches) {
    return false;
  }
  return true;
}

function focusCaptchaInputIfAppropriate(input) {
  if (input && shouldAutoFocusCaptcha()) {
    input.focus();
  }
}

document.addEventListener("DOMContentLoaded", function () {
  const refreshLinks = document.querySelectorAll(
    '.regen-link, a[onclick*="captcha"]',
  );
  const captchaInput = document.querySelector('input[name="captcha_code"]');

  refreshLinks.forEach((link) => {
    link.addEventListener("click", function (e) {
      e.preventDefault();
      refreshCaptcha();
    });

    link.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        this.click();
      }
    });
  });

  if (captchaInput) {
    captchaInput.addEventListener("input", function () {
      this.classList.remove("error", "success");
      const value = this.value.trim();
      if (value.length >= 4) {
        this.classList.add("success");
      }
    });

    captchaInput.addEventListener("focus", function () {
      this.classList.remove("error");
    });

    if (!captchaInput.value && shouldAutoFocusCaptcha()) {
      setTimeout(() => focusCaptchaInputIfAppropriate(captchaInput), 400);
    }
  }
});

function getCaptchaImageElement() {
  return (
    document.querySelector(".captcha-img") ||
    document.getElementById("captcha-image") ||
    (function () {
      const el = document.getElementById("captcha");
      return el && el.tagName === "IMG" ? el : null;
    })()
  );
}

function getCsrfToken() {
  // Get CSRF token from hidden input in form or from data attribute on button
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  const refreshBtn = document.querySelector(".regen-link");
  const csrfToken = refreshBtn?.dataset.csrfToken || csrfInput?.value;
  return csrfToken || "";
}

function getCaptchaRefreshEndpoint() {
  const path = window.location.pathname.replace(/\/$/, "") || "/";
  const csrfToken = encodeURIComponent(getCsrfToken());

  if (path.includes("contact")) {
    return "/dir/contact?ajax=refresh_captcha&csrf_token=" + csrfToken;
  }
  if (path.includes("login")) {
    return "/login?ajax=refresh_captcha&csrf_token=" + csrfToken;
  }

  return (
    path +
    (path.includes("?") ? "&" : "?") +
    "ajax=refresh_captcha&csrf_token=" +
    csrfToken
  );
}

function setRefreshButtonState(refreshBtn, state, message) {
  if (!refreshBtn) {
    return;
  }

  if (state === "loading") {
    refreshBtn.classList.add("is-loading");
    refreshBtn.disabled = true;
    refreshBtn.setAttribute("aria-busy", "true");
    refreshBtn.dataset.originalText =
      refreshBtn.dataset.originalText || refreshBtn.textContent;
    refreshBtn.textContent = "loading…";
    return;
  }

  refreshBtn.classList.remove("is-loading");
  refreshBtn.disabled = false;
  refreshBtn.removeAttribute("aria-busy");

  if (state === "error") {
    refreshBtn.textContent = message || "refresh failed";
    setTimeout(() => {
      refreshBtn.textContent =
        refreshBtn.dataset.originalText || "refresh code";
    }, 2500);
    return;
  }

  refreshBtn.textContent = refreshBtn.dataset.originalText || "refresh code";
}

function refreshCaptcha() {
  const captchaImg = getCaptchaImageElement();
  const captchaInput = document.querySelector('input[name="captcha_code"]');
  const refreshBtn = document.querySelector(
    '.regen-link, button[onclick*="refreshCaptcha"]',
  );

  if (!captchaImg) {
    console.error("CAPTCHA image element not found");
    setRefreshButtonState(refreshBtn, "error", "unavailable");
    return;
  }

  captchaImg.classList.add("is-refreshing");
  setRefreshButtonState(refreshBtn, "loading");

  const endpoint = getCaptchaRefreshEndpoint();

  fetch(endpoint, {
    method: "GET",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  })
    .then((response) => {
      // Handle rate limiting (429) and CSRF/CORS errors (403)
      if (response.status === 429) {
        throw new Error("Too many refresh requests. Please wait.");
      }
      if (response.status === 403) {
        throw new Error("Security validation failed. Please reload the page.");
      }
      if (!response.ok) {
        throw new Error("HTTP " + response.status);
      }
      const contentType = response.headers.get("Content-Type") || "";
      if (!contentType.includes("application/json")) {
        throw new Error("Expected JSON, got " + contentType);
      }
      return response.json();
    })
    .then((data) => {
      if (data.success && data.image) {
        captchaImg.src = data.image;
        if (captchaInput) {
          captchaInput.value = "";
          captchaInput.classList.remove("error", "success");
          focusCaptchaInputIfAppropriate(captchaInput);
        }
        return;
      }
      throw new Error(data.error || "Invalid CAPTCHA response");
    })
    .catch((err) => {
      console.error("Failed to refresh CAPTCHA via AJAX:", err);
      setRefreshButtonState(
        refreshBtn,
        "error",
        err.message || "refresh failed",
      );
    })
    .finally(() => {
      captchaImg.classList.remove("is-refreshing");
      if (refreshBtn && refreshBtn.classList.contains("is-loading")) {
        setRefreshButtonState(refreshBtn, "idle");
      }
    });
}

function setCaptchaError(hasError) {
  const captchaInput = document.querySelector('input[name="captcha_code"]');
  if (captchaInput) {
    if (hasError) {
      captchaInput.classList.add("error");
      captchaInput.classList.remove("success");
    } else {
      captchaInput.classList.remove("error");
    }
  }
}
