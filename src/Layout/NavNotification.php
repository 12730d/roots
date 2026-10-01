<?php
declare(strict_types=1);

namespace ROOTS\Layout;

/**
 * NavNotification - Handles notification system, modal, and associated CSS/JS
 */
class NavNotification
{
    /**
     * Render notification modal
     */
    public static function renderModal(): void
    {
        ?>
        <!-- Terminal Notification Modal -->
        <dialog id="notificationModal" class="bc-modal-dialog" aria-labelledby="notificationModalLabel">
            <div class="bc-modal-content">
                <div class="bc-modal-header">
                    <h5 id="notificationModalLabel" class="bc-modal-title" style="font-family: <?= LayoutConfig::TERMINAL_FONT ?>;
                                   color: var(--bc-accent);
                                   letter-spacing: 2px;">
                        <i class="fa fa-terminal me-2"></i><?= LayoutConfig::NOTIFICATION_MODAL_TITLE ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white close-notif-modal-btn"
                        aria-label="Close notification modal"></button>
                </div>
                <div class="bc-modal-body">
                    <div id="notificationModalBody" style="color: var(--bc-accent);
                                   font-family: <?= LayoutConfig::TERMINAL_FONT ?>;
                                   min-height: 300px;
                                   padding: 8px 12px;
                                   word-break: break-word;
                                   overflow-wrap: anywhere;">
                        <div class="text-center p-3">
                            <div class="simple-spinner" style="margin: 0 auto; width: 24px; height: 24px; border: 2px solid rgba(46, 204, 113, 0.2); border-top-color: var(--bc-accent); border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                            <div class="mt-2" style="letter-spacing: 1px; font-size: 0.75rem;">[ LOADING... ]</div>
                        </div>
                    </div>
                    <div id="loadMoreContainer" class="text-center pb-3" style="display: none;">
                        <button type="button" class="bc-btn-terminal" id="loadMoreNotificationsBtn" aria-label="Load more notifications">
                            <i class="fas fa-plus-circle"></i>LOAD_MORE_LOGS
                        </button>
                    </div>
                </div>
            </div>
        </dialog>
        <?php
    }

    /**
     * Render notification button
     */
    public static function renderButton(): void
    {
        ?>
        <button type="button" class="bc-btn bc-notif-btn" id="showNotificationModalBtn"
            aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="bc-notif-badge" id="notificationPulse" style="display: none;"></span>
        </button>
        <?php
    }

    /**
     * Render notification area container
     */
    public static function renderArea(): void
    {
        ?>
        <div id="notificationArea" role="alert" aria-live="polite" aria-atomic="true"></div>
        <?php
    }

    /**
     * Render notification styles
     */
    public static function renderStyles(): void
    {
        ?>
        <style>
        /* Notification Area Styles - Optimized for performance */
        #notificationArea {
            position: fixed;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999999;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            pointer-events: none;
            width: 100%;
            max-width: 500px;
            list-style: none !important;
            will-change: transform;
        }

        .bc-notification {
            pointer-events: auto;
            min-width: 300px;
            max-width: 420px;
            background: rgba(10, 10, 10, 0.95);
            border: 1px solid rgba(46, 204, 113, 0.3);
            border-left: 3px solid var(--bc-accent);
            border-radius: 4px;
            padding: 10px 14px;
            color: #fff;
            font-family: <?= LayoutConfig::TERMINAL_FONT ?>;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: bc-notif-slide-down 0.25s ease-out;
            transition: opacity 0.2s ease, transform 0.2s ease;
            position: relative;
            list-style: none !important;
            will-change: opacity, transform;
        }

        .bc-notification::marker,
        .bc-notification li::marker {
            content: none !important;
            display: none !important;
        }

        .bc-notification--warning {
            border-left-color: #f1c40f;
        }

        .bc-notification--error {
            border-left-color: #e74c3c;
        }

        .bc-notification--success {
            border-left-color: #2ecc71;
        }

        .bc-notification--info {
            border-left-color: #3498db;
        }

        .bc-notification__icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .bc-notification--warning .bc-notification__icon {
            color: #f1c40f;
        }

        .bc-notification--error .bc-notification__icon {
            color: #e74c3c;
        }

        .bc-notification--success .bc-notification__icon {
            color: #2ecc71;
        }

        .bc-notification--info .bc-notification__icon {
            color: #3498db;
        }

        .bc-notification__content {
            flex-grow: 1;
            font-size: 0.85rem;
            line-height: 1.4;
            letter-spacing: 0.5px;
        }

        .bc-notification__close {
            background: transparent;
            border: 0;
            color: rgba(255, 255, 255, 0.4);
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            transition: color 0.2s;
        }

        .bc-notification__close:hover {
            color: #fff;
        }

        @keyframes bc-notif-slide-down {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bc-notification.fade-out {
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notification Modal Styles - Optimized */
        .bc-modal-dialog {
            border: none;
            background: transparent;
            padding: 0;
            max-width: 550px;
            width: 90%;
        }

        .bc-modal-dialog::backdrop {
            background: rgba(0, 0, 0, 0.7);
        }

        /* Smooth Open Animation */
        @keyframes modal-fade-in {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes backdrop-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .bc-modal-dialog[open] {
            animation: modal-fade-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .bc-modal-dialog[open]::backdrop {
            animation: backdrop-fade-in 0.3s ease-out forwards;
        }

        .bc-modal-content {
            background: rgba(5, 10, 5, 0.96);
            border: 1px solid var(--bc-accent);
            border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
            color: var(--bc-text);
        }

        .bc-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--bc-border);
            background: rgba(46, 204, 113, 0.03);
        }

        .bc-modal-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--bc-accent);
        }

        .btn-close {
            background: transparent;
            border: none;
            color: var(--bc-text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--bc-radius-sm);
            transition: all var(--bc-transition-fast);
        }

        .btn-close:hover {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .bc-modal-body {
            padding: 16px;
            max-height: 70vh;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Optimized scrollbar */
        .bc-modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .bc-modal-body::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
        }

        .bc-modal-body::-webkit-scrollbar-thumb {
            background: rgba(46, 204, 113, 0.3);
            border-radius: 3px;
        }

        .bc-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(46, 204, 113, 0.5);
        }

        .bc-btn-terminal {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid var(--bc-accent);
            color: var(--bc-accent);
            padding: 8px 16px;
            border-radius: var(--bc-radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all var(--bc-transition-fast);
        }

        .bc-btn-terminal:hover {
            background: rgba(46, 204, 113, 0.2);
        }

        /* Notification Button Styles - Enhanced */
        .bc-notif-btn {
            background: transparent;
            border: none;
            color: var(--bc-accent);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--bc-transition-base);
            position: relative;
        }

        .bc-notif-btn:hover {
            background: rgba(46, 204, 113, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }

        .bc-notif-btn i {
            font-size: 1.1rem;
        }

        /* Notification Badge & Pulse Animation */
        .bc-notif-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: var(--bc-accent);
            color: #000;
            font-size: 0.6rem;
            font-weight: bold;
            font-family: var(--bc-font-mono, monospace);
            padding: 2px 5px;
            border-radius: 10px;
            border: 2px solid #0a1410;
            box-shadow: 0 0 8px rgba(46, 204, 113, 0.6);
            min-width: 16px;
            text-align: center;
            line-height: 1;
            z-index: 2;
        }

        @keyframes pulse-dot {
            0% {
                box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.7);
            }
            70% {
                box-shadow: 0 0 0 6px rgba(46, 204, 113, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(46, 204, 113, 0);
            }
        }
        </style>
        <?php
    }

    /**
     * Render notification JavaScript
     */
    public static function renderJavaScript(string $base_path): void
    {
        $base_path_js = LayoutHelpers::basePathForJs($base_path);
        ?>
        <script <?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonceAttribute(); ?>>
        // Minimal HTML escape for notification rendering
        if (typeof window.escapeHtmlNotif === 'undefined') {
            window.escapeHtmlNotif = function(text) {
                if (typeof text !== 'string') text = String(text);
                return text.replace(/[&<>'"]/g, function(match) {
                    return ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        "'": '&#39;',
                        '"': '&quot;'
                    })[match] || match;
                });
            };
        }
        const escapeHtml = window.escapeHtmlNotif;

        // Store base_path from PHP for JavaScript usage
        if (typeof window.BASE_PATH === 'undefined') {
            window.BASE_PATH = <?= LayoutHelpers::jsString($base_path_js) ?>;
        }
        const BASE_PATH = window.BASE_PATH;
        const NOTIFICATION_ENDPOINT = joinPath(BASE_PATH, 'get_recent_notifications');

        // Helper to join paths safely
        function joinPath(base, path) {
            if (!base) return path;
            return base.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
        }

        // Notification caching system for performance
        const NotificationCache = {
            data: null,
            timestamp: 0,
            cacheDuration: 30000, // 30 seconds cache

            set: function(data) {
                this.data = data;
                this.timestamp = Date.now();
            },

            get: function() {
                const now = Date.now();
                if (this.data && (now - this.timestamp) < this.cacheDuration) {
                    return this.data;
                }
                return null;
            },

            clear: function() {
                this.data = null;
                this.timestamp = 0;
            },

            isValid: function() {
                return this.data && (Date.now() - this.timestamp) < this.cacheDuration;
            }
        };

        // --- Notifications Logic ---
        function elementContainsFocus(element) {
            const active = document.activeElement;
            return !!active && element && element.contains(active);
        }

        // Global variable handles (using window to avoid redeclaration errors)
        window.notificationTriggerElement = window.notificationTriggerElement || null;

        function showNotificationModal() {
            const modalElement = document.getElementById('notificationModal');
            const trigger = document.querySelector('.bc-notif-btn');
            if (!modalElement || !trigger) return;

            if (modalElement.open) {
                closeNotificationModal();
                return;
            }

            const rect = trigger.getBoundingClientRect();

            if (window.innerWidth > 576) {
                modalElement.style.position = 'fixed';
                modalElement.style.top = (rect.bottom + 12) + 'px';
                modalElement.style.left = 'auto';
                modalElement.style.right = (window.innerWidth - rect.right) + 'px';
                modalElement.style.margin = '0';
            } else {
                modalElement.style.position = 'fixed';
                modalElement.style.top = '70px';
                modalElement.style.left = '50%';
                modalElement.style.transform = 'translateX(-50%)';
                modalElement.style.margin = '0';
            }

            notificationTriggerElement = document.activeElement;
            modalElement.showModal();

            currentNotificationLimit = 5;
            loadNotifications(currentNotificationLimit);
        }

        function closeNotificationModal() {
            const modalElement = document.getElementById('notificationModal');
            if (modalElement) {
                modalElement.close();
                if (notificationTriggerElement) {
                    notificationTriggerElement.focus();
                }
            }
        }

        let currentNotificationLimit = 5;
        const MAX_NOTIFICATIONS = 30;
        let isLoadingNotifications = false;

        function loadMoreNotifications() {
            if (currentNotificationLimit < MAX_NOTIFICATIONS && !isLoadingNotifications) {
                currentNotificationLimit += 5;
                loadNotifications(currentNotificationLimit, false);
            }
        }

        function loadNotifications(limit = 5, useCache = true) {
            // Check cache first if enabled
            if (useCache && NotificationCache.isValid()) {
                const cachedData = NotificationCache.get();
                const notifications = cachedData.notifications || (cachedData.data && cachedData.data.notifications) || [];
                const pending = cachedData.pending_count || cachedData.count || (cachedData.data && cachedData.data.count) || 0;
                
                displayNotifications(notifications.slice(0, limit));
                updateNotificationCount(pending);
                return;
            }

            if (isLoadingNotifications) return;
            isLoadingNotifications = true;

            const url = `${NOTIFICATION_ENDPOINT}${NOTIFICATION_ENDPOINT.includes('?') ? '&' : '?'}limit=${limit}&mark_read=1`;
            fetch(url, { credentials: 'include' })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.text();
                })
                .then(text => {
                    const trimmed = text.trim();
                    let data = null;

                    // Try direct JSON parse first
                    try {
                        data = JSON.parse(trimmed);
                    } catch (err) {
                        // Attempt to extract the first JSON object from response
                        const first = trimmed.indexOf('{');
                        const last = trimmed.lastIndexOf('}');
                        if (first !== -1 && last !== -1 && last > first) {
                            try {
                                data = JSON.parse(trimmed.slice(first, last + 1));
                            } catch (err2) {
                                console.error('Failed to parse JSON from extracted content:', err2);
                            }
                        }
                    }

                    if (!data) {
                        console.error('Invalid JSON response from notifications endpoint:', trimmed);
                        displayErrorMessage('Unable to load notifications at this time.');
                        return;
                    }

                    // Cache the response
                    NotificationCache.set(data);

                    // Support multiple payload shapes
                    const notifications = data.notifications || (data.data && data.data.notifications) || [];
                    const pending = data.pending_count || data.count || (data.data && data.data.count) || 0;

                    displayNotifications(notifications);
                    updateNotificationCount(pending);
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    displayErrorMessage('Error loading notifications');
                })
                .finally(() => {
                    isLoadingNotifications = false;
                });
        }

        function displayErrorMessage(message) {
            const container = document.getElementById('notificationModalBody');
            if (container) {
                container.innerHTML = `<div class="alert alert-danger text-center">${escapeHtml(message)}</div>`;
            }
        }

        function displayNotifications(notifications) {
            const container = document.getElementById('notificationModalBody');
            if (!container) return;

            if (!notifications || notifications.length === 0) {
                container.innerHTML = `
                            <div class="text-center p-4">
                                <div style="background: rgba(0,20,0,0.3); border: 1px dashed rgba(0,255,65,0.2); padding: 24px; border-radius: 4px;">
                                <i class="fas fa-inbox fa-2x mb-3" style="color: rgba(0,143,17,0.5);"></i>
                                <h6 style="color: #00ff41; font-family: 'Courier New', monospace; letter-spacing: 1.5px; margin-top: 10px;">> NULL_BUFFER</h6>
                                <p style="color: rgba(0,255,65,0.4); font-family: 'Courier New', monospace; font-size: 0.75rem; margin-top: 8px;">[ NO_NOTIFICATIONS_FOUND ]</p>
                            </div>
                        </div>`;
                document.getElementById('loadMoreContainer').style.display = 'none';
                return;
            }

            let html = '';
            notifications.forEach((notif, index) => {
                const typeClass = notif.type ? `bc-notification--${notif.type.toLowerCase()}` : '';
                const iconClass = notif.icon || 'fa-info-circle';
                const timestamp = notif.created_at ? formatNotificationTime(notif.created_at) : '';
                const category = notif.category || '';
                const priority = notif.priority || '';
                const actionUrl = notif.action_url || '';
                const actionLabel = notif.action_label || 'View Details';
                
                // Priority indicator
                let priorityBadge = '';
                if (priority) {
                    const priorityColors = {
                        'high': '#e74c3c',
                        'medium': '#f39c12',
                        'low': '#3498db'
                    };
                    const priorityColor = priorityColors[priority.toLowerCase()] || '#95a5a6';
                    priorityBadge = `<span style="background: ${priorityColor}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.65rem; text-transform: uppercase; margin-right: 6px;">${escapeHtml(priority)}</span>`;
                }

                // Category badge
                let categoryBadge = '';
                if (category) {
                    categoryBadge = `<span style="background: rgba(46, 204, 113, 0.2); color: var(--bc-accent); padding: 2px 6px; border-radius: 3px; font-size: 0.65rem; margin-right: 6px;">${escapeHtml(category)}</span>`;
                }

                // Action link
                let actionLink = '';
                if (actionUrl) {
                    actionLink = `<a href="${escapeHtml(actionUrl)}" style="color: var(--bc-accent); text-decoration: none; font-size: 0.75rem; display: inline-block; margin-top: 6px;" onclick="closeNotificationModal();">${escapeHtml(actionLabel)} →</a>`;
                }
                
                html += `
                    <div class="bc-notification ${typeClass}" data-notif-id="${notif.id || index}" style="margin-bottom: 8px;">
                        <div class="bc-notification__icon">
                            <i class="fas ${iconClass}"></i>
                        </div>
                        <div class="bc-notification__content" style="flex: 1;">
                            <div style="display: flex; align-items: center; margin-bottom: 4px;">
                                ${priorityBadge}
                                ${categoryBadge}
                            </div>
                            <div style="font-weight: 600; font-size: 0.9rem; margin-bottom: 3px;">${escapeHtml(notif.title || 'Notification')}</div>
                            <div style="font-size: 0.8rem; opacity: 0.9; line-height: 1.3;">${escapeHtml(notif.message || '')}</div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                                ${timestamp ? `<span style="font-size: 0.7rem; opacity: 0.6;">${escapeHtml(timestamp)}</span>` : '<span></span>'}
                                ${actionLink}
                            </div>
                        </div>
                        <button type="button" class="bc-notification__close" aria-label="Close notification" style="margin-left: 8px;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            });

            container.innerHTML = html;

            // Show load more button if there might be more notifications
            const loadMoreContainer = document.getElementById('loadMoreContainer');
            if (loadMoreContainer && notifications.length >= currentNotificationLimit && currentNotificationLimit < MAX_NOTIFICATIONS) {
                loadMoreContainer.style.display = 'block';
            } else {
                loadMoreContainer.style.display = 'none';
            }

            // Add close button listeners
            container.querySelectorAll('.bc-notification__close').forEach(btn => {
                btn.addEventListener('click', function() {
                    const notifEl = this.closest('.bc-notification');
                    if (notifEl) {
                        notifEl.classList.add('fade-out');
                        setTimeout(() => notifEl.remove(), 200);
                    }
                });
            });
        }

        // Format notification time in a user-friendly way
        function formatNotificationTime(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);

            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return `${diffMins}m ago`;
            if (diffHours < 24) return `${diffHours}h ago`;
            if (diffDays < 7) return `${diffDays}d ago`;
            
            return date.toLocaleDateString();
        }

        function updateNotificationCount(count) {
            const badge = document.getElementById('notificationPulse');
            if (!badge) return;

            if (count > 0) {
                badge.style.display = 'inline-block';
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.animation = 'pulse-dot 2s infinite';
            } else {
                badge.style.display = 'none';
            }
        }

        // Function to refresh notifications (can be called externally)
        function refreshNotifications(force = false) {
            if (force) {
                NotificationCache.clear();
            }
            loadNotifications(currentNotificationLimit, !force);
        }

        // Make refresh function available globally
        window.refreshNotifications = refreshNotifications;

        // Dropdown behavior is handled by Bootstrap (data-bs-toggle="dropdown")
        // and by /js/global-nav.js for non-Bootstrap triggers.
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-load notifications count on page load (with cache support)
            if (typeof NOTIFICATION_ENDPOINT !== 'undefined' && !window.ROOTS_NOTIF_FETCHED) {
                window.ROOTS_NOTIF_FETCHED = true;
                
                // Check cache first
                if (NotificationCache.isValid()) {
                    const cachedData = NotificationCache.get();
                    const pending = cachedData.pending_count || cachedData.count || (cachedData.data && cachedData.data.count) || 0;
                    updateNotificationCount(pending);
                    
                    // Dispatch event with cached data
                    window.dispatchEvent(new CustomEvent('roots:notifications:loaded', {
                        detail: cachedData
                    }));
                } else {
                    // Fetch fresh data
                    fetch(NOTIFICATION_ENDPOINT, { credentials: 'include' })
                        .then(response => response.json())
                        .then(data => {
                            // Cache the response
                            NotificationCache.set(data);
                            
                            const pending = data.pending_count || data.count || 0;
                            updateNotificationCount(pending);

                            // Dispatch event so other components can reuse this data
                            window.dispatchEvent(new CustomEvent('roots:notifications:loaded', {
                                detail: data
                            }));
                        })
                        .catch(err => {
                            window.ROOTS_NOTIF_FETCHED = false;
                            console.error('Failed to load notification count:', err);
                        });
                }
            }

            const showBtn = document.getElementById('showNotificationModalBtn');
            const closeBtn = document.querySelector('.close-notif-modal-btn');
            const loadMoreBtn = document.getElementById('loadMoreNotificationsBtn');

            if (showBtn) {
                showBtn.addEventListener('click', showNotificationModal);
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', closeNotificationModal);
            }

            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', loadMoreNotifications);
            }

            // Close modal on backdrop click
            const modal = document.getElementById('notificationModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeNotificationModal();
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Render notification system helper functions for global use
     */
    public static function renderHelperFunctions(): void
    {
        ?>
        <script <?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonceAttribute(); ?>>
        // Notification System
        if (typeof window.showNotification === 'undefined') {
            window.showNotificationQueue = window.showNotificationQueue || [];
            window.showNotification = function(message, type = 'info', duration = 3000) {
                // Minimal HTML escape for notification rendering
                if (typeof window.escapeHtmlNotif === 'undefined') {
                    window.escapeHtmlNotif = function(text) {
                        if (typeof text !== 'string') text = String(text);
                        return text.replace(/[&<>'"]/g, function(match) {
                            return ({
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                "'": '&#39;',
                                '"': '&quot;'
                            })[match] || match;
                        });
                    };
                }
                const container = document.getElementById('notificationArea');
                
                // If container not ready, queue the notification
                if (!container) {
                    window.showNotificationQueue.push({message, type, duration});
                    return;
                }

                const notification = document.createElement('div');

                // Map bootstrap types to our custom types if needed
                const typeMap = {
                    'danger': 'error',
                    'warning': 'warning',
                    'success': 'success',
                    'info': 'info'
                };
                const allowedTypes = ['error', 'warning', 'success', 'info'];
                const mappedType = allowedTypes.includes(typeMap[type] || type) ? (typeMap[type] || type) : 'info';

                notification.className = `bc-notification bc-notification--${mappedType}`;

                let icon = 'info-circle';
                if (mappedType === 'success') icon = 'check-circle';
                if (mappedType === 'warning') icon = 'exclamation-triangle';
                if (mappedType === 'error') icon = 'times-circle';

                notification.innerHTML = `
                    <div class="bc-notification__icon">
                        <i class="fas fa-${icon}"></i>
                    </div>
                    <div class="bc-notification__content">
                        ${window.escapeHtmlNotif(message)}
                    </div>
                    <button type="button" class="bc-notification__close" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                container.appendChild(notification);

                const closeBtn = notification.querySelector('.bc-notification__close');
                const removeNotif = () => {
                    notification.classList.add('fade-out');
                    setTimeout(() => notification.remove(), 300);
                };

                closeBtn.onclick = removeNotif;
                closeBtn.onkeypress = (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        removeNotif();
                    }
                };

                if (duration > 0) {
                    setTimeout(removeNotif, duration);
                }
            };

            // Process any queued notifications once the script is loaded
            document.addEventListener('DOMContentLoaded', () => {
                const checkContainer = setInterval(() => {
                    const container = document.getElementById('notificationArea');
                    if (container && window.showNotificationQueue.length > 0) {
                        while (window.showNotificationQueue.length > 0) {
                            const notif = window.showNotificationQueue.shift();
                            window.showNotification(notif.message, notif.type, notif.duration);
                        }
                        clearInterval(checkContainer);
                    }
                }, 100);
                // Stop checking after 5 seconds
                setTimeout(() => clearInterval(checkContainer), 5000);
            });
        }
        </script>
        <?php
    }
}
