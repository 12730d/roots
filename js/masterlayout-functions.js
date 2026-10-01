/**
 * MasterLayout Functions
 * Contains all shared functions for the MasterLayout
 */

// Global variable for lock notification throttling
let lastLockNotifyTime = 0;

/**
 * Function to show locked message with throttling
 */
function bcShowLocked(customMsg = null) {
    const now = Date.now();
    // Throttling: Prevent showing another notification within 3 seconds (3000ms)
    // as requested by the user to avoid spam and duplication.
    if (now - lastLockNotifyTime < 3000) return;
    lastLockNotifyTime = now;

    // Get lock message from global variable set by PHP
    const defaultMsg = globalThis.LOCK_MSG || 'This feature requires a paid subscription.';
    const msg = customMsg || defaultMsg;

    // Use the site's built-in notification system (top-right toast)
    // instead of browser alerts or large modals.
    if (typeof globalThis.showNotification === "function") {
        globalThis.showNotification(msg, "warning", 5000);
    } else {
        // Fallback for pages where NavNotification might not be fully loaded
        console.warn('Notification system not ready, falling back to basic alert.');
        // We use console.warn instead of alert to respect the user's wish 
        // to avoid "browser messages" as much as possible.
    }
}

/**
 * Robust function to copy onion address from various HUD/Bar structures
 */
function copyOnionAddress(el) {
    if (!el) return;
    // Support all possible class names used across different layouts
    const textEl = el.querySelector('.addr-text, .address-val, .bc-website-bar-url');
    if (!textEl) {
        console.warn('[HUD] Address element not found');
        return;
    }

    const url = textEl.textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        // Support multiple possible class names for the copy indicator
        const copyTag = el.querySelector('.address-copy, .copy-hint');
        if (copyTag) {
            const original = copyTag.textContent;
            copyTag.textContent = '';
            const originalColor = copyTag.style.color;
            copyTag.style.color = '#fff';
            setTimeout(() => {
                copyTag.textContent = original;
                copyTag.style.color = originalColor;
            }, 1500);
        }
        // Show centralized notification if available
        if (typeof globalThis.showNotification === 'function') {
            showNotification('Secure link copied to clipboard', 'success', 1500);
        }
    }).catch(err => {
        console.error('[HUD] Failed to copy address: ', err);
    });
}

/**
 * Function to load more notifications
 */
function loadMoreNotifications() {
    // Implementation depends on your notification system
    // Add your notification loading logic here
}

/**
 * Function to show notification modal
 */
function showNotificationModal() {
    const modalElement = document.getElementById('notificationModal');
    const trigger = document.querySelector('.bc-notif-btn');
    if (!modalElement || !trigger) return;

    if (modalElement.open) {
        closeNotificationModal();
        return;
    }

    const rect = trigger.getBoundingClientRect();

    if (globalThis.innerWidth > 576) {
        modalElement.style.position = 'fixed';
        modalElement.style.top = (rect.bottom + 12) + 'px';
        modalElement.style.left = 'auto';
        modalElement.style.right = (globalThis.innerWidth - rect.right) + 'px';
        modalElement.style.margin = '0';
    } else {
        modalElement.style.position = 'fixed';
        modalElement.style.top = '70px';
        modalElement.style.left = '50%';
        modalElement.style.transform = 'translateX(-50%)';
        modalElement.style.margin = '0';
    }

    globalThis.notificationTriggerElement = document.activeElement;
    modalElement.showModal();

    // Load notifications with slight delay to improve performance
    if (typeof globalThis.loadNotifications === 'function') {
        setTimeout(() => globalThis.loadNotifications(5), 100);
    }
}

/**
 * Function to close notification modal
 */
function closeNotificationModal() {
    const modalElement = document.getElementById('notificationModal');
    if (modalElement) {
        modalElement.close();
        if (globalThis.notificationTriggerElement) {
            globalThis.notificationTriggerElement.focus();
        }
    }
}

/**
 * Initialize event listeners when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function() {
    // Event listener for onion address copy
    const onionAddressElements = document.querySelectorAll('.top-bar-address, .hud-bracket');
    onionAddressElements.forEach(el => {
        el.addEventListener('click', function() {
            copyOnionAddress(this);
        });
    });

    // Event listeners for locked links using event delegation
    document.addEventListener('click', function(e) {
        const lockedEl = e.target.closest('.bc-locked-link, .bc-locked-item, .bc-locked-btn');
        if (lockedEl) {
            e.preventDefault();
            e.stopPropagation();
            // Get custom message from data-lock-message attribute if available
            const customMsg = lockedEl.dataset.lockMessage;
            const parsedMsg = customMsg ? JSON.parse(customMsg) : null;
            bcShowLocked(parsedMsg);
        }
    });

    document.addEventListener('keypress', function(e) {
        const lockedEl = e.target.closest('.bc-locked-link, .bc-locked-item, .bc-locked-btn');
        if (lockedEl && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            e.stopPropagation();
            // Get custom message from data-lock-message attribute if available
            const customMsg = lockedEl.dataset.lockMessage;
            const parsedMsg = customMsg ? JSON.parse(customMsg) : null;
            bcShowLocked(parsedMsg);
        }
    });

    // Event listener for load more notifications button
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadMoreNotifications);
        loadMoreBtn.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                loadMoreNotifications();
            }
        });
    }

    // Event listener for notification modal button
    const notifModalBtn = document.querySelector('.bc-notif-btn');
    if (notifModalBtn) {
        notifModalBtn.addEventListener('click', showNotificationModal);
        notifModalBtn.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                showNotificationModal();
            }
        });
    }

    // Event listener for close notification modal button
    const closeNotifBtn = document.querySelector('.close-notif-modal-btn');
    if (closeNotifBtn) {
        closeNotifBtn.addEventListener('click', closeNotificationModal);
        closeNotifBtn.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                closeNotificationModal();
            }
        });
    }

    // Close modal when clicking outside
    const notificationModal = document.getElementById('notificationModal');
    if (notificationModal) {
        notificationModal.addEventListener('click', function(e) {
            const rect = notificationModal.getBoundingClientRect();
            const isInDialog = (rect.top <= e.clientY && e.clientY <= rect.top + rect.height &&
                               rect.left <= e.clientX && e.clientX <= rect.left + rect.width);
            if (!isInDialog) {
                closeNotificationModal();
            }
        });

        // Close modal on Escape key
        notificationModal.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeNotificationModal();
            }
        });
    }

    // Navigation scroll buttons functionality
    initNavScrollButtons();
});

/**
 * Initialize navigation scroll buttons
 */
function initNavScrollButtons() {
    const scrollWrapper = document.querySelector('.bc-nav__scroll-wrapper');
    const scrollContainer = document.querySelector('.bc-nav__scroll-container');
    const scrollList = document.querySelector('.bc-nav__list');
    const leftBtn = document.querySelector('.bc-nav__scroll-btn--left');
    const rightBtn = document.querySelector('.bc-nav__scroll-btn--right');

    if (!scrollWrapper || !scrollContainer || !scrollList || !leftBtn || !rightBtn) {
        return;
    }

    // Check if scrolling is needed
    function checkScrollNeeded() {
        const isOverflowing = scrollList.scrollWidth > scrollContainer.clientWidth;
        const currentTransform = scrollList.style.transform || 'translateX(0px)';
        const currentX = parseInt(currentTransform.replace('translateX(', '').replace('px)', '')) || 0;
        const maxScroll = scrollContainer.clientWidth - scrollList.scrollWidth;

        // Show/hide buttons based on scroll position
        if (isOverflowing) {
            leftBtn.classList.toggle('is-visible', currentX < 0);
            rightBtn.classList.toggle('is-visible', currentX > maxScroll);
        } else {
            leftBtn.classList.remove('is-visible');
            rightBtn.classList.remove('is-visible');
        }
    }

    // Scroll left
    leftBtn.addEventListener('click', function() {
        const currentTransform = scrollList.style.transform || 'translateX(0px)';
        const currentX = parseInt(currentTransform.replace('translateX(', '').replace('px)', '')) || 0;
        const newX = Math.min(0, currentX + 200);
        scrollList.style.transform = `translateX(${newX}px)`;
        checkScrollNeeded();
    });

    // Scroll right
    rightBtn.addEventListener('click', function() {
        const currentTransform = scrollList.style.transform || 'translateX(0px)';
        const currentX = parseInt(currentTransform.replace('translateX(', '').replace('px)', '')) || 0;
        const maxScroll = scrollContainer.clientWidth - scrollList.scrollWidth;
        const newX = Math.max(maxScroll, currentX - 200);
        scrollList.style.transform = `translateX(${newX}px)`;
        checkScrollNeeded();
    });

    // Check scroll on load and resize
    window.addEventListener('load', checkScrollNeeded);
    window.addEventListener('resize', checkScrollNeeded);
    scrollContainer.addEventListener('scroll', checkScrollNeeded);

    // Initial check
    setTimeout(checkScrollNeeded, 100);
}
