/**
 * Payment Modal Logic v5.0 - Professional Secure Edition
 * Theme: Professional, Calm, Secure
 * Changelog:
 * - Updated to professional color scheme
 * - Enhanced error handling and security
 * - Improved performance with optimized rendering
 * - Better accessibility and user experience
 */

// Configuration
const CONFIG = {
  timerDuration: 15 * 60, // 15 minutes
  refreshRate: 60000, // Refresh prices every 60s
  verificationTime: 5 * 60, // 5 minutes verification wait
  wallets: {
    bitcoin: {
      enabled: true,
      addresses: [
        "bc1qrgysw96nf4t6nuaqlr060g6twtmnkasn97uac5",
        "bc1q7nq40276edx25we0j0qp54v98e0l9v0ft48e4m",
        "bc1q4v9rv2e407sjy4tat03f2nlpq365pc4tl07m94",
        "bc1qradakeyjq36w524ptwf23s7xru5pz0jagmzele",
        "bc1q0hqdfvazxrunf8tsglxmc7hf3wc60uapdh8maw",
        "bc1qafgzpwpuv8h6jj2hwat4q5x22fhm2jgkntfsu9",
        "bc1q2w4nd2u4rgxqpw6dzez9wqarlqlg357cqq2qw4",
        "bc1qjwe37yquyzeugm24cy47klhezlw448mcff7a7t",
        "bc1qfeewjs8323plqv4f9arvcvueeltdc2eja7a52v",
        "bc1qt4q3z9kulz9n7kaq74phfwdppngk7330q33gxw",
      ],
      network: "BTC",
      regex: /^[a-fA-F0-9]{64}$/,
    },
    ethereum: {
      enabled: false, // TEMPORARILY DISABLED (keep for future use)
      address: "",
      network: "ETH",
      regex: /^0x([A-Fa-f0-9]{64})$/,
    },
  },
  fallbackRates: {
    bitcoin: 1 / 89398,
    ethereum: 1 / 2932.36,
  },
  theme: {
    primary: "#19b03a", // Muted Green (site points color)
    bg: "#1a1a2e", // Calm Dark Background
    text: "#e8e8e8", // Soft White
  },
};

// State Management
let state = {
  order: null,
  selectedCrypto: null,
  rates: { bitcoin: 0, ethereum: 0 },
  timer: null,
  timeLeft: CONFIG.timerDuration,
  rateInterval: null,
  verificationInterval: null,
  statusInterval: null,
  paymentSubmitted: false,
};

// Current step tracker
let currentStep = 1;

// --- Wallet Address Management ---

function getRandomBitcoinAddress() {
  const addresses = CONFIG.wallets.bitcoin.addresses;
  // Use cryptographically secure random selection
  const randomValues = new Uint32Array(1);
  crypto.getRandomValues(randomValues);
  const randomIndex = randomValues[0] % addresses.length;
  const selectedAddress = addresses[randomIndex];
  return selectedAddress;
}

// --- Initialization ---

document.addEventListener("DOMContentLoaded", function () {
  initializePaymentModal();
  initializeBuyButtons();

  // Cleanup on page unload
  window.addEventListener("beforeunload", cleanup);
});

function initializePaymentModal() {
  const modal = document.getElementById("paymentModal");
  if (!modal) {
    console.warn("Payment modal not found");
    return;
  }

  modal.addEventListener("show.bs.modal", () => {
    try {
      fetchCryptoRates();
      startRateRefresh();
      goToStep(1);

      // Add boot sequence effect
      addBootSequence(modal);

      if (state.order) updateOrderSummary();
    } catch (e) {
      console.error("Error showing payment modal:", e);
    }
  });

  modal.addEventListener("hidden.bs.modal", () => {
    try {
      cleanup();
    } catch (e) {
      console.error("Error hiding payment modal:", e);
    }
  });

  modal.addEventListener("hide.bs.modal", (e) => {
    try {
      if (state.verificationInterval) {
        e.preventDefault();
        if (
          !confirm(
            "Warning: Payment verification in progress. Are you sure you want to close?",
          )
        ) {
          return false;
        }
      }
    } catch (err) {
      console.error("Error in hide.bs.modal handler:", err);
    }
  });
}

function addBootSequence(modal) {
  const title = modal.querySelector(".modal-title");
  if (title) {
    title.innerHTML =
      '<span style="color: var(--text-gray);">Initializing secure connection...</span>';
    setTimeout(() => {
      title.innerHTML =
        '<span style="color: var(--text-dark);">Secure Payment Gateway</span>';
    }, 600);
  }
}

function cleanup() {
  stopRateRefresh();
  if (state.timer) clearInterval(state.timer);
  if (state.verificationInterval) clearInterval(state.verificationInterval);
  if (state.statusInterval) clearInterval(state.statusInterval);
  state.timer = null;
  state.verificationInterval = null;
  state.statusInterval = null;
}

// --- Pricing & API ---

async function fetchCryptoRates() {
  const indicator = document.getElementById("live-rate-indicator");
  if (indicator) {
    indicator.innerHTML =
      '<span style="color: var(--text-gray);">Updating rates...</span>';
  }

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000);

    const response = await fetch(
      "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd",
      { signal: controller.signal },
    );

    clearTimeout(timeoutId);

    if (!response.ok) throw new Error(`API Error: ${response.status}`);

    const data = await response.json();
    if (data.bitcoin?.usd) state.rates.bitcoin = 1 / data.bitcoin.usd;
    if (data.ethereum?.usd) state.rates.ethereum = 1 / data.ethereum.usd;

    // Rates updated (debug suppressed)

    if (state.selectedCrypto && currentStep === 3 && state.order) {
      updateCryptoAmount();
    }
  } catch (e) {
    console.warn("Rate fetch failed, using fallback:", e);
    state.rates.bitcoin = CONFIG.fallbackRates.bitcoin;
    state.rates.ethereum = CONFIG.fallbackRates.ethereum;
    if (indicator) {
      indicator.innerHTML =
        '<span style="color: #ff9800; font-weight: 600;">Using fallback rates</span>';
    }
  } finally {
    if (indicator && !indicator.innerHTML.includes('fallback')) {
      indicator.innerHTML =
        '<span style="color: var(--primary-blue); font-weight: 600;">Rates updated</span>';
    }
  }
}

function startRateRefresh() {
  if (state.rateInterval) clearInterval(state.rateInterval);
  state.rateInterval = setInterval(fetchCryptoRates, CONFIG.refreshRate);
}

function stopRateRefresh() {
  if (state.rateInterval) {
    clearInterval(state.rateInterval);
    state.rateInterval = null;
  }
}

// --- Navigation ---

function goToStep(step) {
  if (step < 1 || step > 3) return;

  // Prevent going back if payment is in progress
  if (state.paymentSubmitted && step < currentStep) {
    alert("Payment is in progress. Please wait for confirmation.");
    return;
  }

  try {
    currentStep = step;

    // Handle Panel Visibility
    document.querySelectorAll(".step-panel").forEach((p) => {
      p.classList.remove("active");
      p.style.display = "none"; // Explicitly hide
    });

    const target = document.getElementById(`step-panel-${step}`);
    if (target) {
      target.classList.add("active");
      target.style.display = "block";
      // Add a slight flicker effect on step change
      target.style.opacity = "0";
      setTimeout(() => (target.style.opacity = "1"), 100);
    }

    // Update Step Indicators (Terminal Breadcrumbs)
    document.querySelectorAll(".step-item").forEach((i) => {
      i.classList.remove("active", "completed");

      const stepNum = Number.parseInt(i.dataset.step, 10);
      const titleSpan = i.querySelector(".step-title");

      if (stepNum < step) {
        i.classList.add("completed");
        if (titleSpan) titleSpan.innerHTML = `[DONE]`;
      } else if (stepNum === step) {
        i.classList.add("active");
        if (titleSpan)
          titleSpan.innerHTML = `> ${titleSpan.dataset.orig || titleSpan.textContent} <span class="blink">_</span>`;
      } else if (titleSpan?.dataset.orig) {
        // Reset text
        titleSpan.textContent = titleSpan.dataset.orig;
      }
    });

    if (step === 1 && state.order) updateOrderSummary();
    if (step === 3) setTimeout(() => initializeFinalStep(), 100);
  } catch (e) {
    console.error("Error in goToStep:", e);
  }
}

// --- Pending Purchases Check ---

async function checkPendingPurchases() {
  try {
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
    const response = await fetch("api_stats", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrfToken || "",
      },
      body: JSON.stringify({
        action: "check_pending_purchases",
        csrf_token: csrfToken || "",
      }),
    });

    if (response.ok) {
      const result = await response.json();
      return result.pending_count || 0;
    }
  } catch (e) {
    console.warn("Failed to check pending purchases:", e);
  }
  return 0;
}

// --- Logic & Interactions ---

function initializeBuyButtons() {
  const buttons = document.querySelectorAll(".buy-points");
  if (buttons.length === 0) return;

  buttons.forEach((btn) => {
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener("click", function (e) {
      e.preventDefault();
      handleBuyButtonClick(this);
    });
  });
}

async function handleBuyButtonClick(button) {
  try {
    // Check pending purchases limit (max 5)
    const pendingCheck = await checkPendingPurchases();
    if (pendingCheck >= 5) {
      alert(
        "You have reached the maximum limit of 5 pending purchases. Please wait for your current purchases to be approved before making new ones.",
      );
      return;
    }

    const price = Number.parseFloat(button.dataset.price) || 0;
    const amount = Number.parseInt(button.dataset.amount, 10) || 0;
    const originalPrice =
      Number.parseFloat(button.dataset.originalPrice) || price;
    const packageId = button.dataset.package || "";

    if (price <= 0 || amount <= 0) {
      alert(">> ERROR: DATA_PACKET_CORRUPT");
      return;
    }

    const packageName = generatePackageName(amount);

    const bonus = Number.parseInt(button.dataset.bonus, 10) || 0;

    state.order = {
      type: "points",
      points: amount + bonus,
      basePoints: amount,
      bonusPoints: bonus,
      price: price,
      package: packageName,
      packageId: packageId,
      savings: Math.max(0, originalPrice - price),
      savingsPercent:
        originalPrice > price
          ? Math.round(((originalPrice - price) / originalPrice) * 100)
          : 0,
    };

    updateOrderSummary();
  } catch (error) {
    console.error("Error:", error);
  }
}

function generatePackageName(amount) {
  let tier = "STARTER_KIT";
  if (amount >= 50000) tier = "ENTERPRISE_NEXUS";
  else if (amount >= 25000) tier = "PRO_V3";
  else if (amount >= 10000) tier = "STANDARD_CORE";
  else if (amount >= 5000) tier = "BASIC_LINK";

  return `${tier} [${amount.toLocaleString()} PTS]`;
}

function updateOrderSummary() {
  if (!state.order) return;

  const updates = [
    { id: "packageType", value: state.order.package },
    { id: "summaryTotal", value: state.order.points.toLocaleString() },
    { id: "summaryPrice", value: `$${(state.order.price || 0).toFixed(2)}` },
  ];

  updates.forEach(({ id, value }) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  });

  // Detailed points breakdown
  const summaryTotalEl = document.getElementById("summaryTotal");
  if (summaryTotalEl && state.order.bonusPoints > 0) {
    summaryTotalEl.textContent = `${state.order.points.toLocaleString()} (${state.order.basePoints.toLocaleString()} + ${state.order.bonusPoints.toLocaleString()} Bonus)`;
  }

  // Add Date next to price
  const dateEl = document.getElementById("currentDateDisplay");
  if (dateEl) {
    const today = new Date();
    const options = {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    };
    dateEl.textContent = today
      .toLocaleDateString("en-US", options)
      .toUpperCase();
  }

  // Update savings info with terminal styling
  const savingsEl = document.getElementById("savingsInfo");
  if (savingsEl) {
    if (state.order.savings > 0) {
      savingsEl.textContent = `>> DISCOUNT_DETECTED: ${state.order.savingsPercent}% OFF | CREDITS SAVED: $${state.order.savings.toFixed(2)}`;
      savingsEl.classList.remove("d-none");
      savingsEl.style.padding = "5px";
      savingsEl.style.borderStyle = "dashed";
      savingsEl.style.color = "#33ff00";
    } else {
      savingsEl.classList.add("d-none");
    }
  }
}

function selectCrypto(type, element) {
  if (!CONFIG.wallets[type]) return;
  if (CONFIG.wallets[type].enabled === false) {
      alert("This payment method is currently unavailable.");
      return;
  }

  document.querySelectorAll(".crypto-selection-card").forEach((c) => {
    c.classList.remove("selected");
    c.style.borderColor = ""; // إزالة التنسيق المباشر للسماح للـ CSS بالعمل
  });

  element.classList.add("selected");

  state.selectedCrypto = type;

  const continueBtn = document.getElementById("continueToPayment");
  if (continueBtn) continueBtn.disabled = false;
}

function initializeFinalStep() {
  if (!state.order || !state.selectedCrypto) return;

  try {
    const config = CONFIG.wallets[state.selectedCrypto];
    const orderPrice = state.order.price || 0;

    updateElement("packagePriceUSD", `$${orderPrice.toFixed(2)}`);
    updateElement("selectedPackageName", state.order.package);

    const walletAddressEl = document.getElementById("walletAddress");
    if (walletAddressEl) {
      const selectedAddress = config.addresses
        ? getRandomBitcoinAddress()
        : config.address;
      walletAddressEl.value = selectedAddress;
      walletAddressEl.style.fontFamily = "monospace";
    }

    const networkEl = document.querySelector(".current-network");
    if (networkEl) networkEl.textContent = `[NET: ${config.network}]`;

    document
      .querySelectorAll(".crypto-qr")
      .forEach((qr) => (qr.style.display = "none"));
    const qrEl = document.getElementById(`${state.selectedCrypto}-qr`);
    if (qrEl) {
      qrEl.style.display = "block";
      qrEl.style.border = "4px solid #fff"; // White border for QR specifically so it scans
    }

    // Use setTimeout to prevent blocking
    setTimeout(() => {
      try {
        updateCryptoAmount();
        updatePaymentQrCode();
        // Timer will start when user clicks "Confirm Payment Sent" (confirmPayment())
      } catch (e) {
        console.error("Error initializing payment step:", e);
      }
    }, 50);
  } catch (e) {
    console.error("Error in initializeFinalStep:", e);
  }
}

const QRCodeLocal = (() => {
  const QRMode = { MODE_8BIT_BYTE: 1 << 2 };
  const QRErrorCorrectLevel = { L: 1, M: 0, Q: 3, H: 2 };
  const QRMaskPattern = {
    PATTERN000: 0,
    PATTERN001: 1,
    PATTERN010: 2,
    PATTERN011: 3,
    PATTERN100: 4,
    PATTERN101: 5,
    PATTERN110: 6,
    PATTERN111: 7,
  };

  const QRMath = {};
  QRMath.EXP_TABLE = new Array(256);
  QRMath.LOG_TABLE = new Array(256);
  for (let i = 0; i < 8; i++) QRMath.EXP_TABLE[i] = 1 << i;
  for (let i = 8; i < 256; i++)
    QRMath.EXP_TABLE[i] =
      QRMath.EXP_TABLE[i - 4] ^
      QRMath.EXP_TABLE[i - 5] ^
      QRMath.EXP_TABLE[i - 6] ^
      QRMath.EXP_TABLE[i - 8];
  for (let i = 0; i < 255; i++) QRMath.LOG_TABLE[QRMath.EXP_TABLE[i]] = i;
  QRMath.glog = (n) => {
    if (n < 1) throw new Error("glog");
    return QRMath.LOG_TABLE[n];
  };
  QRMath.gexp = (n) => {
    while (n < 0) n += 255;
    while (n >= 256) n -= 255;
    return QRMath.EXP_TABLE[n];
  };

  const QRUtil = {};
  QRUtil.PATTERN_POSITION_TABLE = [
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
    [6, 30, 54],
    [6, 32, 58],
    [6, 34, 62],
    [6, 26, 46, 66],
    [6, 26, 48, 70],
    [6, 26, 50, 74],
    [6, 30, 54, 78],
    [6, 30, 56, 82],
    [6, 30, 58, 86],
    [6, 34, 62, 90],
    [6, 28, 50, 72, 94],
    [6, 26, 50, 74, 98],
    [6, 30, 54, 78, 102],
    [6, 28, 54, 80, 106],
    [6, 32, 58, 84, 110],
    [6, 30, 58, 86, 114],
    [6, 34, 62, 90, 118],
    [6, 26, 50, 74, 98, 122],
    [6, 30, 54, 78, 102, 126],
    [6, 26, 52, 78, 104, 130],
    [6, 30, 56, 82, 108, 134],
    [6, 34, 60, 86, 112, 138],
    [6, 30, 58, 86, 114, 142],
    [6, 34, 62, 90, 118, 146],
    [6, 30, 54, 78, 102, 126, 150],
    [6, 24, 50, 76, 102, 128, 154],
    [6, 28, 54, 80, 106, 132, 158],
    [6, 32, 58, 84, 110, 136, 162],
    [6, 26, 54, 82, 110, 138, 166],
    [6, 30, 58, 86, 114, 142, 170],
  ];
  QRUtil.G15 =
    (1 << 10) |
    (1 << 8) |
    (1 << 5) |
    (1 << 4) |
    (1 << 2) |
    (1 << 1) |
    Math.trunc(1);
  QRUtil.G18 =
    (1 << 12) |
    (1 << 11) |
    (1 << 10) |
    (1 << 9) |
    (1 << 8) |
    (1 << 5) |
    (1 << 2) |
    Math.trunc(1);
  QRUtil.G15_MASK = (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);
  QRUtil.getBCHDigit = (data) => {
    let digit = 0;
    while (data !== 0) {
      digit++;
      data >>>= 1;
    }
    return digit;
  };
  QRUtil.getBCHTypeInfo = (data) => {
    let d = data << 10;
    while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15) >= 0)
      d ^=
        QRUtil.G15 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15));
    return ((data << 10) | d) ^ QRUtil.G15_MASK;
  };
  QRUtil.getBCHTypeNumber = (data) => {
    let d = data << 12;
    while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18) >= 0)
      d ^=
        QRUtil.G18 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18));
    return (data << 12) | d;
  };
  QRUtil.getPatternPosition = (typeNumber) =>
    QRUtil.PATTERN_POSITION_TABLE[typeNumber - 1];
  QRUtil.getMask = (maskPattern, i, j) => {
    switch (maskPattern) {
      case QRMaskPattern.PATTERN000:
        return (i + j) % 2 === 0;
      case QRMaskPattern.PATTERN001:
        return i % 2 === 0;
      case QRMaskPattern.PATTERN010:
        return j % 3 === 0;
      case QRMaskPattern.PATTERN011:
        return (i + j) % 3 === 0;
      case QRMaskPattern.PATTERN100:
        return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0;
      case QRMaskPattern.PATTERN101:
        return ((i * j) % 2) + ((i * j) % 3) === 0;
      case QRMaskPattern.PATTERN110:
        return (((i * j) % 2) + ((i * j) % 3)) % 2 === 0;
      case QRMaskPattern.PATTERN111:
        return (((i + j) % 2) + ((i * j) % 3)) % 2 === 0;
      default:
        throw new Error("bad maskPattern:" + maskPattern);
    }
  };
  QRUtil.getErrorCorrectPolynomial = (errorCorrectLength) => {
    let a = new QRPolynomial([1], 0);
    for (let i = 0; i < errorCorrectLength; i++)
      a = a.multiply(new QRPolynomial([1, QRMath.gexp(i)], 0));
    return a;
  };
  QRUtil.getLengthInBits = (mode, type) => {
    if (1 <= type && type < 10) return mode === QRMode.MODE_8BIT_BYTE ? 8 : 0;
    if (type < 27) return mode === QRMode.MODE_8BIT_BYTE ? 16 : 0;
    return mode === QRMode.MODE_8BIT_BYTE ? 16 : 0;
  };

  function QRPolynomial(num, shift) {
    let offset = 0;
    while (offset < num.length && num[offset] === 0) offset++;
    this.num = new Array(num.length - offset + shift);
    for (let i = 0; i < num.length - offset; i++) this.num[i] = num[i + offset];
  }
  QRPolynomial.prototype.get = function (index) {
    return this.num[index];
  };
  QRPolynomial.prototype.getLength = function () {
    return this.num.length;
  };
  QRPolynomial.prototype.multiply = function (e) {
    const num = new Array(this.getLength() + e.getLength() - 1).fill(0);
    for (let i = 0; i < this.getLength(); i++)
      for (let j = 0; j < e.getLength(); j++)
        num[i + j] ^= QRMath.gexp(
          QRMath.glog(this.get(i)) + QRMath.glog(e.get(j)),
        );
    return new QRPolynomial(num, 0);
  };
  QRPolynomial.prototype.mod = function (e) {
    if (this.getLength() - e.getLength() < 0) return this;
    const ratio = QRMath.glog(this.get(0)) - QRMath.glog(e.get(0));
    const num = new Array(this.getLength());
    for (let i = 0; i < this.getLength(); i++) num[i] = this.get(i);
    for (let i = 0; i < e.getLength(); i++)
      num[i] ^= QRMath.gexp(QRMath.glog(e.get(i)) + ratio);
    return new QRPolynomial(num, 0).mod(e);
  };

  function QRBitBuffer() {
    this.buffer = [];
    this.length = 0;
  }
  QRBitBuffer.prototype.get = function (index) {
    const bufIndex = Math.floor(index / 8);
    return ((this.buffer[bufIndex] >>> (7 - (index % 8))) & 1) === 1;
  };
  QRBitBuffer.prototype.put = function (num, length) {
    for (let i = 0; i < length; i++)
      this.putBit(((num >>> (length - i - 1)) & 1) === 1);
  };
  QRBitBuffer.prototype.getLengthInBits = function () {
    return this.length;
  };
  QRBitBuffer.prototype.putBit = function (bit) {
    const bufIndex = Math.floor(this.length / 8);
    if (this.buffer.length <= bufIndex) this.buffer.push(0);
    if (bit) this.buffer[bufIndex] |= 0x80 >>> (this.length % 8);
    this.length++;
  };

  function QR8bitByte(data) {
    this.mode = QRMode.MODE_8BIT_BYTE;
    this.data = data;
  }
  QR8bitByte.prototype.getLength = function () {
    return this.data.length;
  };
  QR8bitByte.prototype.write = function (buffer) {
    for (let i = 0; i < this.data.length; i++)
      buffer.put(this.data.codePointAt(i), 8);
  };

  function QRRSBlock(totalCount, dataCount) {
    this.totalCount = totalCount;
    this.dataCount = dataCount;
  }
  QRRSBlock.RS_BLOCK_TABLE = [
    [1, 26, 19],
    [1, 26, 16],
    [1, 26, 13],
    [1, 26, 9],
    [1, 44, 34],
    [1, 44, 28],
    [1, 44, 22],
    [1, 44, 16],
    [1, 70, 55],
    [1, 70, 44],
    [2, 35, 17],
    [2, 35, 13],
    [1, 100, 80],
    [2, 50, 32],
    [2, 50, 24],
    [4, 25, 9],
    [1, 134, 108],
    [2, 67, 43],
    [2, 33, 15, 2, 34, 16],
    [2, 33, 11, 2, 34, 12],
    [2, 86, 68],
    [4, 43, 27],
    [4, 43, 19],
    [4, 43, 15],
    [2, 98, 78],
    [4, 49, 31],
    [2, 32, 14, 4, 33, 15],
    [4, 39, 13, 1, 40, 14],
    [2, 121, 97],
    [2, 60, 38, 2, 61, 39],
    [4, 40, 18, 2, 41, 19],
    [4, 40, 14, 2, 41, 15],
    [2, 146, 116],
    [3, 58, 36, 2, 59, 37],
    [4, 36, 16, 4, 37, 17],
    [4, 34, 12, 4, 35, 13],
    [2, 86, 68, 2, 87, 69],
    [4, 69, 43, 1, 70, 44],
    [6, 43, 19, 2, 44, 20],
    [6, 43, 15, 2, 44, 16],
  ];
  QRRSBlock.getRsBlockTable = (typeNumber, errorCorrectLevel) => {
    if (typeNumber < 1 || typeNumber > 10) return null;
    const offset = (typeNumber - 1) * 4;
    switch (errorCorrectLevel) {
      case QRErrorCorrectLevel.L:
        return QRRSBlock.RS_BLOCK_TABLE[offset + 0];
      case QRErrorCorrectLevel.M:
        return QRRSBlock.RS_BLOCK_TABLE[offset + 1];
      case QRErrorCorrectLevel.Q:
        return QRRSBlock.RS_BLOCK_TABLE[offset + 2];
      case QRErrorCorrectLevel.H:
        return QRRSBlock.RS_BLOCK_TABLE[offset + 3];
      default:
        return null;
    }
  };
  QRRSBlock.getRSBlocks = (typeNumber, errorCorrectLevel) => {
    const rsBlock = QRRSBlock.getRsBlockTable(typeNumber, errorCorrectLevel);
    if (!rsBlock) throw new Error("bad rs block");
    const length = rsBlock.length / 3;
    const list = [];
    for (let i = 0; i < length; i++) {
      const count = rsBlock[i * 3 + 0];
      const totalCount = rsBlock[i * 3 + 1];
      const dataCount = rsBlock[i * 3 + 2];
      for (let j = 0; j < count; j++)
        list.push(new QRRSBlock(totalCount, dataCount));
    }
    return list;
  };

  function QRCodeModel(typeNumber_, errorCorrectLevel_) {
    this.typeNumber = typeNumber_;
    this.errorCorrectLevel = errorCorrectLevel_;
    this.modules = null;
    this.moduleCount = 0;
    this.dataCache = null;
    this.dataList = [];
  }
  QRCodeModel.prototype.addData = function (data) {
    this.dataList.push(new QR8bitByte(data));
    this.dataCache = null;
  };
  QRCodeModel.prototype.isDark = function (row, col) {
    return this.modules[row][col];
  };
  QRCodeModel.prototype.getModuleCount = function () {
    return this.moduleCount;
  };
  QRCodeModel.prototype.make = function () {
    for (let t = 1; t <= 10; t++) {
      try {
        QRCodeModel.createData(t, this.errorCorrectLevel, this.dataList);
        this.typeNumber = t;
        break;
      } catch (e) {
        // Expected exception when data doesn't fit in this QR code version
        // QRCodeModel.createData throws "code length overflow" when version is too small
        if (e.message !== "code length overflow") {
          throw e; // Re-throw unexpected errors
        }
        console.debug(
          `QR version ${t} too small for data, trying next version`,
        );
      }
    }
    this.typeNumber = this.typeNumber || 1;
    this.makeImpl(false, 0);
  };
  QRCodeModel.prototype.makeImpl = function (test, maskPattern) {
    this.moduleCount = this.typeNumber * 4 + 17;
    this.modules = new Array(this.moduleCount);
    for (let row = 0; row < this.moduleCount; row++) {
      this.modules[row] = new Array(this.moduleCount);
      for (let col = 0; col < this.moduleCount; col++)
        this.modules[row][col] = null;
    }
    this.setupPositionProbePattern(0, 0);
    this.setupPositionProbePattern(this.moduleCount - 7, 0);
    this.setupPositionProbePattern(0, this.moduleCount - 7);
    this.setupAlignmentPattern();
    this.setupTimingPattern();
    this.setupTypeInfo(test, maskPattern);
    if (this.typeNumber >= 7) this.setupTypeNumber(test);
    if (this.dataCache === null)
      this.dataCache = QRCodeModel.createData(
        this.typeNumber,
        this.errorCorrectLevel,
        this.dataList,
      );
    this.mapData(this.dataCache, maskPattern);
  };
  QRCodeModel.prototype.setupAlignmentPattern = function () {
    const pos = QRUtil.getPatternPosition(this.typeNumber);
    for (const row of pos) {
      for (const col of pos) {
        if (this.modules[row][col] === null) {
          this.fillAlignmentPattern(row, col);
        }
      }
    }
  };
  QRCodeModel.prototype.fillAlignmentPattern = function (row, col) {
    for (let r = -2; r <= 2; r++) {
      for (let c = -2; c <= 2; c++) {
        const isBorder = Math.abs(r) === 2 || Math.abs(c) === 2;
        const isCenter = r === 0 && c === 0;
        this.modules[row + r][col + c] = isBorder || isCenter;
      }
    }
  };
  QRCodeModel.prototype.setupTypeNumber = function (test) {
    const bits = QRUtil.getBCHTypeNumber(this.typeNumber);
    for (let i = 0; i < 18; i++) {
      const mod = !test && ((bits >> i) & 1) === 1;
      this.modules[Math.floor(i / 3)][(i % 3) + this.moduleCount - 8 - 3] = mod;
    }
    for (let i = 0; i < 18; i++) {
      const mod = !test && ((bits >> i) & 1) === 1;
      this.modules[(i % 3) + this.moduleCount - 8 - 3][Math.floor(i / 3)] = mod;
    }
  };
  QRCodeModel.prototype.setupPositionProbePattern = function (row, col) {
    for (let r = -1; r <= 7; r++) {
      if (row + r <= -1 || this.moduleCount <= row + r) continue;
      for (let c = -1; c <= 7; c++) {
        if (col + c <= -1 || this.moduleCount <= col + c) continue;
        if (
          (0 <= r && r <= 6 && (c === 0 || c === 6)) ||
          (0 <= c && c <= 6 && (r === 0 || r === 6)) ||
          (2 <= r && r <= 4 && 2 <= c && c <= 4)
        )
          this.modules[row + r][col + c] = true;
        else this.modules[row + r][col + c] = false;
      }
    }
  };
  QRCodeModel.prototype.setupTimingPattern = function () {
    for (let i = 8; i < this.moduleCount - 8; i++) {
      if (this.modules[i][6] === null) this.modules[i][6] = i % 2 === 0;
      if (this.modules[6][i] === null) this.modules[6][i] = i % 2 === 0;
    }
  };
  QRCodeModel.prototype.setupTypeInfo = function (test, maskPattern) {
    const data = (this.errorCorrectLevel << 3) | maskPattern;
    const bits = QRUtil.getBCHTypeInfo(data);
    for (let i = 0; i < 15; i++) {
      const mod = !test && ((bits >> i) & 1) === 1;
      if (i < 6) this.modules[i][8] = mod;
      else if (i < 8) this.modules[i + 1][8] = mod;
      else this.modules[this.moduleCount - 15 + i][8] = mod;
    }
    for (let i = 0; i < 15; i++) {
      const mod = !test && ((bits >> i) & 1) === 1;
      if (i < 8) this.modules[8][this.moduleCount - i - 1] = mod;
      else if (i < 9) this.modules[8][15 - i - 1 + 1] = mod;
      else this.modules[8][15 - i - 1] = mod;
    }
    this.modules[this.moduleCount - 8][8] = !test;
  };
  QRCodeModel.prototype.mapData = function (data, maskPattern) {
    let inc = -1;
    let row = this.moduleCount - 1;
    let state = { byteIndex: 0, bitIndex: 7 };
    for (let col = this.moduleCount - 1; col > 0; col -= 2) {
      if (col === 6) col--;
      while (true) {
        for (let c = 0; c < 2; c++) {
          this.setModule(row, col - c, data, maskPattern, state);
        }
        row += inc;
        if (row < 0 || this.moduleCount <= row) {
          row -= inc;
          inc = -inc;
          break;
        }
      }
    }
  };
  QRCodeModel.prototype.setModule = function (
    row,
    col,
    data,
    maskPattern,
    state,
  ) {
    if (this.modules[row][col] === null) {
      let dark = false;
      if (state.byteIndex < data.length) {
        dark = ((data[state.byteIndex] >>> state.bitIndex) & 1) === 1;
      }
      if (QRUtil.getMask(maskPattern, row, col)) {
        dark = !dark;
      }
      this.modules[row][col] = dark;
      state.bitIndex--;
      if (state.bitIndex === -1) {
        state.byteIndex++;
        state.bitIndex = 7;
      }
    }
  };
  QRCodeModel.PAD0 = 0xec;
  QRCodeModel.PAD1 = 0x11;
  QRCodeModel.createData = function (typeNumber, errorCorrectLevel, dataList) {
    const rsBlocks = QRRSBlock.getRSBlocks(typeNumber, errorCorrectLevel);
    const buffer = new QRBitBuffer();
    for (const data of dataList) {
      buffer.put(data.mode, 4);
      buffer.put(
        data.getLength(),
        QRUtil.getLengthInBits(data.mode, typeNumber),
      );
      data.write(buffer);
    }
    let totalDataCount = 0;
    for (const rsBlock of rsBlocks) totalDataCount += rsBlock.dataCount;
    if (buffer.getLengthInBits() > totalDataCount * 8)
      throw new Error("code length overflow");
    if (buffer.getLengthInBits() + 4 <= totalDataCount * 8) buffer.put(0, 4);
    while (buffer.getLengthInBits() % 8 !== 0) buffer.putBit(false);
    while (buffer.getLengthInBits() < totalDataCount * 8) {
      buffer.put(QRCodeModel.PAD0, 8);
      if (buffer.getLengthInBits() >= totalDataCount * 8) break;
      buffer.put(QRCodeModel.PAD1, 8);
    }
    return QRCodeModel.createBytes(buffer, rsBlocks);
  };
  function processRSBlock(buffer, rsBlock, offset) {
    const dcCount = rsBlock.dataCount;
    const ecCount = rsBlock.totalCount - dcCount;
    const dcdata = new Array(dcCount);
    for (let i = 0; i < dcdata.length; i++)
      dcdata[i] = 0xff & buffer.buffer[i + offset];
    const rsPoly = QRUtil.getErrorCorrectPolynomial(ecCount);
    const rawPoly = new QRPolynomial(dcdata, rsPoly.getLength() - 1);
    const modPoly = rawPoly.mod(rsPoly);
    const ecdata = new Array(rsPoly.getLength() - 1);
    for (let i = 0; i < ecdata.length; i++) {
      const modIndex = i + modPoly.getLength() - ecdata.length;
      ecdata[i] = modIndex >= 0 ? modPoly.get(modIndex) : 0;
    }
    return { dcdata, ecdata, dcCount, ecCount };
  }

  function interleaveData(dcdata, ecdata, maxDcCount, maxEcCount) {
    const totalCodeCount =
      dcdata.reduce((sum, arr) => sum + arr.length, 0) +
      ecdata.reduce((sum, arr) => sum + arr.length, 0);
    const data = new Array(totalCodeCount);
    let index = 0;
    for (let i = 0; i < maxDcCount; i++)
      for (const dcArray of dcdata)
        if (i < dcArray.length) data[index++] = dcArray[i];
    for (let i = 0; i < maxEcCount; i++)
      for (const ecArray of ecdata)
        if (i < ecArray.length) data[index++] = ecArray[i];
    return data;
  }

  QRCodeModel.createBytes = function (buffer, rsBlocks) {
    let offset = 0;
    let maxDcCount = 0;
    let maxEcCount = 0;
    const dcdata = new Array(rsBlocks.length);
    const ecdata = new Array(rsBlocks.length);
    for (let r = 0; r < rsBlocks.length; r++) {
      const result = processRSBlock(buffer, rsBlocks[r], offset);
      dcdata[r] = result.dcdata;
      ecdata[r] = result.ecdata;
      maxDcCount = Math.max(maxDcCount, result.dcCount);
      maxEcCount = Math.max(maxEcCount, result.ecCount);
      offset += result.dcCount;
    }
    return interleaveData(dcdata, ecdata, maxDcCount, maxEcCount);
  };

  const qrcode = (typeNumber, errorCorrectionLevel) => {
    const qr = new QRCodeModel(
      typeNumber,
      QRErrorCorrectLevel[errorCorrectionLevel] ?? QRErrorCorrectLevel.M,
    );
    return {
      addData: (data) => qr.addData(data),
      make: () => qr.make(),
      isDark: (r, c) => qr.isDark(r, c),
      getModuleCount: () => qr.getModuleCount(),
    };
  };

  function renderToCanvas(text, canvas, sizePx) {
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const size = Math.max(64, Number.parseInt(sizePx, 10) || 180);
    canvas.width = size;
    canvas.height = size;

    // Use the built-in QR code implementation with auto-detection
    const qr = qrcode(0, "M");
    qr.addData(text);
    qr.make();

    const count = qr.getModuleCount();
    const scale = Math.max(1, Math.floor(size / count));
    const realSize = count * scale;
    const margin = Math.floor((size - realSize) / 2);

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = "#000000";
    for (let r = 0; r < count; r++) {
      for (let c = 0; c < count; c++) {
        if (qr.isDark(r, c))
          ctx.fillRect(margin + c * scale, margin + r * scale, scale, scale);
      }
    }
  }

  return { renderToCanvas };
})();

function buildCryptoPaymentUri(cryptoType, address, amount) {
  if (!cryptoType || !address) return "";

  if (cryptoType === "bitcoin") {
    const uri = new URL(`bitcoin:${address}`);
    if (amount) uri.searchParams.set("amount", amount);
    return uri.toString();
  }

  return address;
}

function updatePaymentQrCode() {
  if (!state.selectedCrypto) return;

  const qrContainer = document.getElementById(`${state.selectedCrypto}-qr`);
  if (!qrContainer) return;

  const canvas =
    document.getElementById(`${state.selectedCrypto}-qr-canvas`) ||
    qrContainer.querySelector("canvas");
  if (!canvas) return;

  const addressEl = document.getElementById("walletAddress");
  const address = (addressEl?.value || "").trim();
  if (!address) return;

  try {
    // Use requestAnimationFrame to prevent blocking the main thread
    requestAnimationFrame(() => {
      try {
        QRCodeLocal.renderToCanvas(address, canvas, 180);
      } catch (e) {
        console.error("QR code rendering failed:", e);
        // Show fallback text if QR generation fails
        const ctx = canvas.getContext("2d");
        if (ctx) {
          ctx.fillStyle = "#ffffff";
          ctx.fillRect(0, 0, canvas.width || 180, canvas.height || 180);
          ctx.fillStyle = "#000000";
          ctx.font = "12px monospace";
          ctx.textAlign = "center";
          ctx.fillText("QR Error", canvas.width / 2, canvas.height / 2);
        }
      }
    });
  } catch (e) {
    console.error("QR code generation failed:", e);
    const ctx = canvas.getContext("2d");
    if (ctx) {
      ctx.clearRect(0, 0, canvas.width || 180, canvas.height || 180);
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, canvas.width || 180, canvas.height || 180);
      ctx.fillStyle = "#000000";
      ctx.font = "12px monospace";
      ctx.textAlign = "center";
      ctx.fillText("QR Error", canvas.width / 2, canvas.height / 2);
    }
  }
}

function updateCryptoAmount() {
  if (!state.order || !state.selectedCrypto) return;

  const rate = state.rates[state.selectedCrypto];
  const finalRate =
    rate > 0 ? rate : CONFIG.fallbackRates[state.selectedCrypto];
  const cryptoAmount = (state.order.price * finalRate).toFixed(8);

  updateElement("cryptoAmount", cryptoAmount);
  updateElement("cryptoCurrency", CONFIG.wallets[state.selectedCrypto].network);

  updatePaymentQrCode();
}

function updateElement(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

// --- Payment Processing ---

function confirmPayment() {
  if (!state.order || !state.selectedCrypto) return;

  const btn = document.getElementById("confirmPaymentBtn");
  if (!btn) return;

  // Generate a 64-character hex string to match server validation
  const array = new Uint32Array(16);
  globalThis.crypto.getRandomValues(array);
  const transactionRef = Array.from(array)
    .map(num => num.toString(16).padStart(8, '0'))
    .join('')
    .substring(0, 64);

  btn.disabled = true;
  btn.innerHTML = "Processing...";

  // Start countdown timer when payment is confirmed
  startTimer();

  // Hide "Change Payment Method" button and show status message
  const abortBtn = document.querySelector(".btn-abort");
  const statusBadge = document.getElementById("txnStatusBadge");
  if (abortBtn) abortBtn.style.display = "none";
  if (statusBadge) {
    statusBadge.style.display = "block";
    statusBadge.textContent =
      "Payment is in progress. Please wait for confirmation.";
  }

  submitPayment(transactionRef);
}

async function submitPayment(transactionRef) {
  const btn = document.getElementById("confirmPaymentBtn");
  const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

  try {
    const response = await fetch("process_payment", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrfToken || "",
      },
      body: JSON.stringify({
        type: state.order.type,
        points: state.order.points,
        bonus: 0,
        price: state.order.price,
        transactionHash: transactionRef,
        cryptoType: state.selectedCrypto,
        csrf_token: csrfToken || "",
      }),
    });

    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const result = await response.json();

    if (result.success) {
      state.paymentSubmitted = true;
      btn.innerHTML = "Payment verification in progress";
      btn.style.borderColor = "var(--primary-blue)";
      btn.disabled = true;

      showPaymentSubmittedMessage();
    } else {
      throw new Error(result.message || "Payment processing failed");
    }
  } catch (e) {
    console.error("Submission error:", e);
    state.paymentSubmitted = false;
    showErrorState(e.message);
  }
}

// --- Timer Functions ---

function startTimer() {
  if (state.timer) clearInterval(state.timer);

  state.timeLeft = CONFIG.timerDuration;
  const display = document.getElementById("paymentTimer");

  if (!display) return;

  state.timer = setInterval(() => {
    state.timeLeft--;
    const m = Math.floor(state.timeLeft / 60);
    const s = state.timeLeft % 60;

    display.innerHTML = `${m}:${s < 10 ? "0" : ""}${s}`;

    if (state.timeLeft <= 0) {
      clearInterval(state.timer);
      handleTimerExpiry();
    }
  }, 1000);
}

function handleTimerExpiry() {
  if (state.paymentSubmitted) {
    showAdminApprovalWait();
  } else {
    alert("Session expired. Please refresh the page to try again.");
  }
}

// --- Utilities ---

function escapeHtmlPayment(unsafe) {
  if (typeof unsafe !== "string") return unsafe;
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function copyToClipboard(id) {
  const el = document.getElementById(id);
  if (!el) return;

  navigator.clipboard.writeText(el.value).then(() => {
    const btn = el.nextElementSibling;
    if (btn) {
      const originalHtml = btn.innerHTML;
      btn.innerHTML = "Copied!";
      setTimeout(() => {
        btn.innerHTML = originalHtml;
      }, 2000);
    }
  });
}

function showVerificationWait() {
  const modalBody = document.querySelector(".modal-body");
  const modalHeader = document.querySelector(".modal-header");

  if (!modalBody) return;
  if (modalHeader) modalHeader.style.display = "none";

  const packagePrice = state.order
    ? `$${state.order.price.toFixed(2)}`
    : "$0.00";
  let timeRemaining = CONFIG.verificationTime;

  modalBody.innerHTML = createVerificationHTML(
    state.order.package,
    packagePrice,
  );

  // Status Log Logic
  let logLines = [
    "Initializing secure connection...",
    "Verifying transaction...",
    "Processing payment...",
    "Finalizing confirmation...",
  ];
  let currentLog = 0;

  state.statusInterval = setInterval(() => {
    if (currentLog < logLines.length) {
      const logContainer = document.getElementById("terminalLogs");
      if (logContainer) {
        const line = document.createElement("div");
        line.style.opacity = "0.8";
        line.style.color = "var(--text-gray)";
        line.textContent = logLines[currentLog];
        logContainer.appendChild(line);
        currentLog++;
      }
    }
  }, 2000);

  state.verificationInterval = setInterval(() => {
    timeRemaining--;
    updateVerificationProgress(timeRemaining, CONFIG.verificationTime);

    if (timeRemaining <= 0) {
      clearInterval(state.verificationInterval);
      clearInterval(state.statusInterval);
      redirectToReviewPage();
    }
  }, 1000);

  // showConfetti(); // Disabled for performance
}

function createVerificationHTML(packageName, packagePrice) {
  const container = document.createElement("div");
  container.className = "p-4";
  container.style.textAlign = "center";

  const iconDiv = document.createElement("div");
  iconDiv.className = "mb-4";
  const icon = document.createElement("i");
  icon.className = "fas fa-check-circle fa-3x";
  icon.style.color = "var(--success-green)";
  iconDiv.appendChild(icon);
  container.appendChild(iconDiv);

  const heading = document.createElement("h3");
  heading.style.borderBottom = "2px solid var(--primary-blue)";
  heading.style.display = "inline-block";
  heading.style.paddingBottom = "12px";
  heading.style.color = "var(--text-dark)";
  heading.textContent = "Payment Submitted";
  container.appendChild(heading);

  const alertDiv = document.createElement("div");
  alertDiv.className = "terminal-alert text-start my-4";

  const packageLabel = document.createElement("div");
  packageLabel.innerHTML = `<strong>Package:</strong> ${escapeHtmlPayment(packageName)}`;
  alertDiv.appendChild(packageLabel);

  const amountLabel = document.createElement("div");
  amountLabel.innerHTML = `<strong>Amount:</strong> ${escapeHtmlPayment(packagePrice)}`;
  alertDiv.appendChild(amountLabel);

  const statusLabel = document.createElement("div");
  statusLabel.innerHTML = `<strong>Status:</strong> <span style="color: var(--primary-blue);">Pending Verification</span>`;
  alertDiv.appendChild(statusLabel);

  container.appendChild(alertDiv);

  const progressDiv = document.createElement("div");
  progressDiv.className = "my-4";

  const progressHeader = document.createElement("div");
  progressHeader.className = "d-flex justify-content-between mb-2";

  const progressLabel = document.createElement("span");
  progressLabel.style.color = "var(--text-gray)";
  progressLabel.textContent = "Progress";
  progressHeader.appendChild(progressLabel);

  const percentageSpan = document.createElement("span");
  percentageSpan.id = "verificationPercentage";
  percentageSpan.style.color = "var(--primary-blue)";
  percentageSpan.style.fontWeight = "600";
  percentageSpan.textContent = "0%";
  progressHeader.appendChild(percentageSpan);

  progressDiv.appendChild(progressHeader);

  const progressBar = document.createElement("div");
  progressBar.className = "progress";
  progressBar.style.height = "24px";

  const progressBarInner = document.createElement("div");
  progressBarInner.id = "verificationProgressBar";
  progressBarInner.className = "progress-bar progress-bar-striped progress-bar-animated";
  progressBarInner.role = "progressbar";
  progressBarInner.style.width = "0%";
  progressBar.appendChild(progressBarInner);

  progressDiv.appendChild(progressBar);

  const timeDiv = document.createElement("div");
  timeDiv.className = "mt-2 text-end";

  const timeLabel = document.createElement("span");
  timeLabel.style.color = "var(--text-gray)";
  timeLabel.textContent = "Estimated Time: ";
  timeDiv.appendChild(timeLabel);

  const timeSpan = document.createElement("span");
  timeSpan.id = "verificationTimeLeft";
  timeSpan.style.color = "var(--primary-blue)";
  timeSpan.style.fontWeight = "600";
  timeSpan.textContent = "--:--";
  timeDiv.appendChild(timeSpan);

  progressDiv.appendChild(timeDiv);
  container.appendChild(progressDiv);

  const logsDiv = document.createElement("div");
  logsDiv.id = "terminalLogs";
  logsDiv.className = "text-start p-3";
  logsDiv.style.background = "rgba(26, 26, 46, 0.95)";
  logsDiv.style.border = "1px solid var(--primary-blue)";
  logsDiv.style.minHeight = "120px";
  logsDiv.style.fontSize = "0.85rem";
  logsDiv.style.fontFamily = "var(--main-font)";
  logsDiv.style.borderRadius = "8px";

  const logLine = document.createElement("div");
  logLine.style.color = "var(--success-green)";
  logLine.textContent = "Connection established.";
  logsDiv.appendChild(logLine);

  container.appendChild(logsDiv);

  return container.outerHTML;
}

function updateVerificationProgress(timeRemaining, totalTime) {
  const percentage = ((totalTime - timeRemaining) / totalTime) * 100;
  const mins = Math.floor(timeRemaining / 60);
  const secs = timeRemaining % 60;

  const timeDisplay = document.getElementById("verificationTimeLeft");
  if (timeDisplay)
    timeDisplay.textContent = `${mins}:${secs < 10 ? "0" : ""}${secs}`;

  const progressBar = document.getElementById("verificationProgressBar");
  const percentageDisplay = document.getElementById("verificationPercentage");

  if (progressBar) progressBar.style.width = percentage + "%";
  if (percentageDisplay)
    percentageDisplay.textContent = percentage.toFixed(1) + "%";
}

function showPaymentSubmittedMessage() {
  const alertDiv = document.createElement("div");
  alertDiv.className = "terminal-alert";
  alertDiv.textContent = "Please wait: Waiting for payment confirmation.";

  const verificationForm = document.querySelector(".verification-form");
  if (verificationForm?.parentNode) {
    verificationForm.parentNode.insertBefore(
      alertDiv,
      verificationForm.nextSibling,
    );
  }
}

function showAdminApprovalWait() {
  const modalBody = document.querySelector(".modal-body");
  const modalHeader = document.querySelector(".modal-header");

  if (!modalBody) return;
  if (modalHeader) modalHeader.style.display = "none";

  modalBody.innerHTML = "";

  const container = document.createElement("div");
  container.className = "p-4 text-center";

  const icon = document.createElement("i");
  icon.className = "fas fa-lock fa-3x mb-3";
  icon.style.color = "var(--primary-blue)";
  container.appendChild(icon);

  const heading = document.createElement("h3");
  heading.className = "mb-4";
  heading.style.color = "var(--text-dark)";
  heading.textContent = "Awaiting Admin Approval";
  container.appendChild(heading);

  const paragraph = document.createElement("p");
  paragraph.className = "mb-4";
  paragraph.style.color = "var(--text-gray)";
  paragraph.innerHTML = "Your transaction has been queued in the database.<br>Manual authorization is required.";
  container.appendChild(paragraph);

  const alertDiv = document.createElement("div");
  alertDiv.className = "terminal-alert text-start";

  const packageLabel = document.createElement("div");
  packageLabel.innerHTML = `<strong>Package:</strong> ${state.order ? escapeHtmlPayment(state.order.package) : "Unknown"}`;
  alertDiv.appendChild(packageLabel);

  const refLabel = document.createElement("div");
  refLabel.innerHTML = "<strong>Reference:</strong> Pending";
  alertDiv.appendChild(refLabel);

  container.appendChild(alertDiv);

  const button = document.createElement("button");
  button.className = "btn w-100 mt-4";
  button.textContent = "Check Status Logs";
  button.onclick = redirectToReviewPage;
  container.appendChild(button);

  modalBody.appendChild(container);
}

function showErrorState(msg) {
  const btn = document.getElementById("confirmPaymentBtn");
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = "Retry Payment";
    btn.style.borderColor = "var(--pm-danger)";
    btn.style.color = "var(--pm-danger)";
  }
  alert(`Error: ${msg}`);
}

function showConfetti() {
  // Professional confetti effect
  const colors = ["#19b03a", "#5cb85c", "#f0ad4e"];
  for (let i = 0; i < 20; i++) {
    setTimeout(() => {
      const confetti = document.createElement("div");
      confetti.className = "confetti";
      const array = new Uint32Array(1);
      globalThis.crypto.getRandomValues(array);
      confetti.style.left = (array[0] / 0xffffffff) * 100 + "%";
      confetti.style.backgroundColor = colors[i % 3];
      document.body.appendChild(confetti);
      setTimeout(() => confetti.remove(), 4000);
    }, i * 100);
  }
}

function redirectToReviewPage() {
  let basePath = globalThis.location.origin;
  const pathParts = globalThis.location.pathname.split("/").filter(Boolean);

  if (pathParts.length > 0) {
    basePath += "/";
  }
  // The conditional above does same thing, simplify later if logic permits, keeping as requested for structure
  globalThis.location.href = basePath + "my_payments.php";
}

// Global Exports
globalThis.goToStep = goToStep;
globalThis.selectCrypto = selectCrypto;
globalThis.confirmPayment = confirmPayment;
globalThis.copyToClipboard = copyToClipboard;
globalThis.fetchCryptoRates = fetchCryptoRates;
globalThis.redirectToReviewPage = redirectToReviewPage;
