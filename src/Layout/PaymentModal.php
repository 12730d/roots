<?php

namespace ROOTS\Layout;

/**
 * PaymentModal - Renders the terminal-themed payment gateway modal
 * Handles crypto selection, order summary, and transaction initialization
 */
class PaymentModal
{
    /**
     * Render the payment gateway modal HTML and CSS
     */
    public static function render(): void
    {
        ?>
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true"
    data-bs-backdrop="false" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-0 payment-modal-content">

            <div
                class="modal-header border-bottom border-success border-opacity-50 pb-3 d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                    <h4 class="modal-title fw-bold text-white mb-2" id="paymentModalLabel">
                        <i class="fas fa-shield-alt me-2"></i>
                        SECURE_PAYMENT_GATEWAY
                    </h4>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="small text-white-50 ms-2" id="live-rate-indicator">
                            Loading rates...
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="px-4 pt-4">
                <div class="d-flex justify-content-between text-uppercase small text-white-50"
                    style="font-family: monospace;">
                    <div class="step-item active w-100 text-center" data-step="1">
                        <span class="step-title" data-orig="ORDER_SUMMARY">Order Summary</span>
                    </div>
                    <div class="step-item w-100 text-center" data-step="2">
                        <span class="step-title" data-orig="SELECT_NET">Select Payment Method</span>
                    </div>
                    <div class="step-item w-100 text-center" data-step="3">
                        <span class="step-title" data-orig="EXECUTE">Complete Payment</span>
                    </div>
                </div>
            </div>

            <div class="modal-body p-4">

                <!-- CSRF token for JS-driven form submissions -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(
                            $_SESSION["csrf_token"] ?? "",
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        ); ?>">


                <div class="step-panel active" id="step-panel-1">
                    <div class="package-confirmation-card p-4">
                        <div class="row g-4 align-items-center">
                            <div class="col-lg-8">
                                <h5 class="text-white mb-4">Review Order Details</h5>

                                <div class="row mb-2">
                                    <div class="col-6 text-white-50">Package</div>
                                    <div class="col-6 text-white text-end" id="packageType">Loading...</div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6 text-white-50">Points Allocation</div>
                                    <div class="col-6 text-white text-end fw-bold" id="summaryTotal">0</div>
                                </div>
                                <hr class="border-success opacity-50">
                                <div class="row">
                                    <div class="col-6 text-white">Total Cost</div>
                                    <div class="col-6 text-white text-end fs-4" id="summaryPrice">$0.00</div>
                                </div>
                                <div class="text-end text-white-50 x-small mt-1 current-date-display"></div>

                                <div id="savingsInfo" class="mt-3 d-none">
                                </div>
                            </div>

                            <div class="col-lg-4 text-center border-start border-success border-opacity-25">
                                <i class="fas fa-check-circle fa-4x text-success mb-3 opacity-50"></i>
                                <small class="text-white-50 d-block">Instant Delivery</small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-lg px-5" onclick="goToStep(2)" type="button">
                                Continue to Payment
                            </button>
                        </div>
                    </div>
                </div>

                <div class="step-panel" id="step-panel-2">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0 text-white">Select Payment Method</h5>
                        <button class="btn btn-sm" onclick="fetchCryptoRates()" type="button">
                            Refresh Rates
                        </button>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <button type="button" class="crypto-selection-card h-100 p-4 w-100 text-start"
                                onclick="selectCrypto('bitcoin', this)">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fab fa-bitcoin fa-2x text-warning me-3"></i>
                                    <div>
                                        <h5 class="crypto-name text-white mb-0">BITCOIN</h5>
                                        <small class="text-white-50">BTC_CORE</small>
                                    </div>
                                </div>
                                <div class="text-white-50 small font-monospace">
                                    Network: Bitcoin<br>
                                    Confirmation Time: ~10 minutes
                                </div>
                            </button>
                        </div>

                        <div class="col-md-6">
                            <button type="button" class="crypto-selection-card h-100 p-4 w-100 text-start"
                                onclick="selectCrypto('ethereum', this)">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fab fa-ethereum fa-2x text-success me-3"></i>
                                    <div>
                                        <h5 class="crypto-name text-white mb-0">ETHEREUM</h5>
                                        <small class="text-white-50">ETH_MAINNET</small>
                                    </div>
                                </div>
                                <div class="text-white-50 small font-monospace">
                                    Network: Ethereum<br>
                                    Confirmation Time: ~2 minutes
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <button class="btn" onclick="goToStep(1)" type="button">
                            Back
                        </button>
                        <button class="btn btn-lg px-5" id="continueToPayment" disabled onclick="goToStep(3)"
                            type="button">
                            Initialize Transaction
                        </button>
                    </div>
                </div>

                <div class="step-panel" id="step-panel-3">
                    <div class="row g-4">

                        <!-- ── RIGHT COLUMN: Payment Summary Card ── -->
                        <div class="col-lg-5 order-lg-2">
                            <div class="payment-summary-card p-4 text-center">

                                <!-- Countdown Timer -->
                                <div class="timer-badge" id="timerBadge">
                                    <span id="paymentTimer">--:--</span>
                                </div>

                                <!-- Package & Price Summary -->


                                <hr class="border-success">

                                <!-- Crypto Transfer Amount -->
                                <div class="text-start mb-3">
                                    <div class="summary-label">Required Transfer</div>
                                    <div class="fs-3 summary-value font-monospace" id="cryptoAmount">0.000000</div>
                                    <div class="text-success small" id="cryptoCurrency">BTC</div>
                                </div>

                                <!-- QR Code -->
                                <div class="qr-wrapper">
                                    <div id="bitcoin-qr" class="crypto-qr">
                                        <canvas id="bitcoin-qr-canvas" width="180" height="180"
                                            class="img-fluid d-block"></canvas>
                                    </div>
                                    <div id="ethereum-qr" class="crypto-qr" hidden>
                                        <canvas id="ethereum-qr-canvas" width="180" height="180"
                                            class="img-fluid d-block"></canvas>
                                    </div>
                                </div>

                                <!-- Wallet Address Copy -->
                                <div class="input-group mb-1">
                                    <input type="text" class="form-control form-control-sm text-center font-monospace"
                                        id="walletAddress" readonly value="LOADING ADDRESS..."
                                        aria-label="Wallet address">
                                    <button class="btn btn-sm" type="button" id="copyWalletAddressBtn"
                                        onclick="copyToClipboard('walletAddress')" aria-label="Copy wallet address">
                                        Copy Address
                                    </button>
                                </div>

                            </div>
                        </div>

                        <!-- ── LEFT COLUMN: Instructions & Confirm ── -->
                        <div class="col-lg-7 order-lg-1 d-flex flex-column justify-content-between">

                            <div>
                                <h5 class="mb-4 text-white">Payment Instructions</h5>

                                <!-- Step Instructions -->
                                <ol class="execution-steps mb-3">
                                    <div class="mb-4 text-start">
                                        <div class="summary-label">Target Package</div>
                                        <div class="summary-value fw-bold mb-3" id="selectedPackageName">--</div>

                                        <div class="summary-label">Fiat Value</div>
                                        <div class="fs-4 summary-value" id="packagePriceUSD">$0.00</div>
                                        <div class="text-end text-white-50 x-small mt-1 current-date-display"></div>
                                    </div>
                                    <li>
                                        <span class="step-num">1.</span>
                                        <span>Scan the QR code or copy the wallet address.</span>
                                    </li>
                                    <li>
                                        <span class="step-num">2.</span>
                                        <span>Send the exact amount shown above.</span>
                                    </li>
                                    <li>
                                        <span class="step-num">3.</span>
                                        <span>Confirm transmission using the button below.</span>
                                    </li>
                                </ol>

                                <!-- Warning Notice -->
                                <div class="warning-line" role="alert">
                                    <strong>Important:</strong> Transactions are non-refundable. Please ensure you
                                    select the correct network before sending.
                                </div>

                            </div> <!-- ── RIGHT COLUMN: Payment Summary Card ── -->




                            <div>
                                <!-- Confirm Button -->
                                <div class="verification-form p-4">
                                    <input type="hidden" name="csrf_token"
                                        value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                    <button class="btn btn-lg w-100 py-3" id="confirmPaymentBtn" type="button"
                                        onclick="confirmPayment()">
                                        Confirm Payment Sent
                                    </button>
                                </div>

                                <!-- JS-driven transaction status feedback -->
                                <output class="txn-status-badge" id="txnStatusBadge" aria-live="polite"
                                    for="confirmPaymentBtn">
                                </output>

                                <!-- Back / Abort -->
                                <div class="mt-3 text-center">
                                    <button class="btn-abort" type="button" onclick="goToStep(2)">
                                        &lt; Change Payment Method
                                    </button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer justify-content-center py-2">
                <small class="text-white-50 font-monospace" style="font-size: 0.75rem;">
                    Secure Connection Established | Encryption: AES-256
                </small>
            </div>
        </div>
    </div>
</div>
<?php
    }
}