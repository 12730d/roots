/**
 * The Fund Registry — contact / registration portal
 * CAPTCHA refresh, plan sync, validation, optional matrix (reduced-motion safe)
 */
(function () {
    'use strict';

    const planPrices = { free: '0', basic: '3000', pro: '7000', premium: '12000' };

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function refreshCaptcha() {
        const captchaImg = document.getElementById('captcha');
        const captchaInput = document.querySelector('input[name="captcha_code"]');
        const refreshBtn = document.getElementById('captcha-refresh');
        let willReload = false;

        if (captchaImg) {
            captchaImg.style.opacity = '0.5';
            captchaImg.classList.add('is-refreshing');
        }
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.style.opacity = '0.5';
        }

        fetch('./signup?ajax=refresh_captcha')
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (data.success && data.image) {
                    captchaImg.src = data.image;
                    if (captchaInput) {
                        captchaInput.value = '';
                        captchaInput.focus();
                    }
                } else {
                    throw new Error(data.error || 'Invalid CAPTCHA data');
                }
            })
            .catch(function (err) {
                console.error('Failed to refresh CAPTCHA via AJAX:', err);
                willReload = true;
                const currentUrl = window.location.href.split('?')[0];
                window.location.href = currentUrl + '?refresh=' + Date.now();
            })
            .finally(function () {
                if (willReload) {
                    return;
                }
                if (captchaImg) {
                    captchaImg.style.opacity = '1';
                    captchaImg.classList.remove('is-refreshing');
                }
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.style.opacity = '1';
                }
            });
    }

    function copyToClipboard(e, text) {
        const button = e && e.currentTarget ? e.currentTarget : null;
        const setSuccessState = function () {
            if (!button) {
                return;
            }
            const originalLabel = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () {
                button.textContent = originalLabel;
            }, 2000);
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(setSuccessState).catch(function (err) {
                console.error('Failed to copy text: ', err);
            });
            return;
        }

        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.setAttribute('readonly', '');
        textArea.style.position = 'absolute';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            setSuccessState();
        } catch (err) {
            console.error('Failed to copy text: ', err);
        }
        document.body.removeChild(textArea);
    }

    function validateForm() {
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const passwordMatchError = document.getElementById('password-match-error');

        if (!password || !confirmPassword || !passwordMatchError) {
            return true;
        }

        passwordMatchError.hidden = true;

        if (password.value !== confirmPassword.value) {
            passwordMatchError.hidden = false;
            confirmPassword.focus();
            return false;
        }

        return true;
    }

    function initPlanSelection() {
        const hiddenPlan = document.getElementById('hidden-plan');
        const hiddenPrice = document.getElementById('plan-price');
        const planRadios = document.querySelectorAll('input[name="plan_selection_radio"]');

        planRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!hiddenPlan || !hiddenPrice) {
                    return;
                }
                hiddenPlan.value = radio.value;
                hiddenPrice.value = planPrices[radio.value] || '0';
                document.querySelectorAll('.plan-card').forEach(function (card) {
                    card.classList.toggle('plan-card--selected', card.getAttribute('data-plan') === radio.value);
                });
            });
        });

        const checked = document.querySelector('input[name="plan_selection_radio"]:checked');
        if (checked) {
            checked.dispatchEvent(new Event('change'));
        }

        document.querySelectorAll('.plan-card[data-plan]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                const plan = card.getAttribute('data-plan');
                if (!plan || plan === 'free') {
                    return;
                }
                if (e.target.closest('a, button, input, label')) {
                    return;
                }
                alert(
                    'Please register a free account first. You can upgrade to this plan from your dashboard.',
                );
            });
        });
    }

    function initOptionalMatrix() {
        const canvas = document.getElementById('matrix-bg');
        if (!canvas || prefersReducedMotion()) {
            return;
        }

        canvas.removeAttribute('hidden');

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        let width = 0;
        let height = 0;
        let columns = 0;
        let drops = [];
        const chars = '01';
        const fontSize = 14;

        function resize() {
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = width;
            canvas.height = height;
            columns = Math.floor(width / fontSize);
            drops = new Array(columns).fill(1);
        }

        function draw() {
            ctx.fillStyle = 'rgba(6, 10, 8, 0.1)';
            ctx.fillRect(0, 0, width, height);
            ctx.fillStyle = 'rgba(0, 255, 65, 0.28)';
            ctx.font = fontSize + 'px JetBrains Mono, monospace';

            for (let i = 0; i < drops.length; i++) {
                const char = chars[Math.floor(Math.random() * chars.length)];
                const x = i * fontSize;
                const y = drops[i] * fontSize;
                ctx.fillText(char, x, y);

                if (y > height && Math.random() > 0.985) {
                    drops[i] = 0;
                }
                drops[i]++;
            }
        }

        let raf = 0;
        let last = 0;
        const interval = 90;

        function loop(ts) {
            if (ts - last >= interval) {
                draw();
                last = ts;
            }
            raf = requestAnimationFrame(loop);
        }

        resize();
        window.addEventListener('resize', resize, { passive: true });
        raf = requestAnimationFrame(loop);

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                cancelAnimationFrame(raf);
            } else {
                raf = requestAnimationFrame(loop);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('refresh')) {
            window.history.replaceState({}, document.title, window.location.pathname);
            const captchaInput = document.querySelector('input[name="captcha_code"]');
            if (captchaInput) {
                captchaInput.value = '';
                captchaInput.focus();
            }
        }

        const refreshBtn = document.getElementById('captcha-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', refreshCaptcha);
        }

        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                const text = btn.getAttribute('data-copy');
                if (text) {
                    copyToClipboard(e, text);
                }
            });
        });

        const form = document.getElementById('registration-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!validateForm()) {
                    e.preventDefault();
                }
            });
        }

        const confirmPassword = document.getElementById('confirm_password');
        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                const password = document.getElementById('password');
                const passwordMatchError = document.getElementById('password-match-error');
                if (!password || !passwordMatchError) {
                    return;
                }
                if (this.value && password.value !== this.value) {
                    passwordMatchError.hidden = false;
                } else {
                    passwordMatchError.hidden = true;
                }
            });
        }

        initPlanSelection();
        initOptionalMatrix();
    });

    window.refreshCaptcha = refreshCaptcha;
})();
