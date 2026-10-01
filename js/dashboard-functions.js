/**
 * Dashboard Functions
 * Contains all dashboard-specific JavaScript functions
 */

// --- Leaderboard Metric Switching Function ---
window.switchLeaderboardMetric = function(metric, btn) {
    // Update button states
    document.querySelectorAll('.metric-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    // Show loading state
    const tableBody = document.getElementById('leaderboardTableBody');
    const podiumContainer = document.getElementById('podiumContainer');
    
    if (tableBody) {
        tableBody.style.opacity = '0.5';
    }
    if (podiumContainer) {
        podiumContainer.style.opacity = '0.5';
    }

    // Fetch leaderboard data with new metric
    fetch(`get_leaderboard.php?metric=${metric}&period=30d`, {
        method: 'GET',
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.has_error === false) {
            // Update leaderboard table
            if (tableBody && data.top_users) {
                tableBody.innerHTML = renderLeaderboardRows(data.top_users, data.current_user?.username);
            }
            
            // Update podium
            if (podiumContainer && data.top_users && data.top_users.length >= 3) {
                podiumContainer.innerHTML = renderPodium(data.top_users);
            }
        } else {
            console.error('Leaderboard API returned error');
        }
    })
    .catch(error => {
        console.error('Failed to fetch leaderboard:', error);
    })
    .finally(() => {
        // Restore opacity
        if (tableBody) {
            tableBody.style.opacity = '1';
        }
        if (podiumContainer) {
            podiumContainer.style.opacity = '1';
        }
    });
};

function renderLeaderboardRows(users, currentUsername) {
    if (!users || users.length === 0) {
        return '<tr><td colspan="5" class="text-center">No data available</td></tr>';
    }

    return users.map((u, idx) => {
        const rank = idx + 1;
        const name = u.display_name || u.username || '---';
        const pts = (u.points || 0).toLocaleString();
        const sub = u.subscription || 'BASIC';
        const isPinned = u.username === currentUsername;
        
        let rankClass = '';
        if (rank === 1) rankClass = 'r1';
        else if (rank === 2) rankClass = 'r2';
        else if (rank === 3) rankClass = 'r3';

        const diff = (u.points || 0) - (u.previous_points || 0);
        let changeIcon = '<span style="color:#666666;font-size:.8rem;">—</span>';
        if (diff > 0) {
            changeIcon = '<i class="fas fa-arrow-up" style="color:#66aa66;font-size:.8rem;"></i>';
        } else if (diff < 0) {
            changeIcon = '<i class="fas fa-arrow-down" style="color:#aa6666;font-size:.8rem;"></i>';
        }

        return `
            <tr class="${isPinned ? 'pinned-row' : ''}">
                <td><span class="rank-num ${rankClass}">${String(rank).padStart(2, '0')}</span></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <img src="${u.avatar_url || 'img/user.jpg'}" alt="${name}" class="lb-avatar" loading="lazy">
                        <span>${name}${isPinned ? '<span class="you-tag">[OPERATIVE]</span>' : ''}</span>
                    </div>
                </td>
                <td class="d-none d-sm-table-cell"><span class="sub-tag tier-${sub.toLowerCase()}">${sub}</span></td>
                <td style="font-weight:600;">${pts}</td>
                <td>${changeIcon}</td>
            </tr>
        `;
    }).join('');
}

function renderPodium(users) {
    const order = [users[1], users[0], users[2]];
    const posLabels = ['02', '01', '03'];
    
    return order.map((u, i) => {
        const isFirst = posLabels[i] === '01';
        const name = u.display_name || u.username || '---';
        const pts = (u.points || 0).toLocaleString();
        
        return `
            <div class="podium-cell ${isFirst ? 'pos-1' : ''}">
                <span class="podium-rank-num">${posLabels[i]}</span>
                <img src="${u.avatar_url || 'img/user.jpg'}" alt="${name}" class="podium-avatar" loading="lazy">
                <div class="podium-name">${name}</div>
                <div class="podium-pts">${pts}</div>
            </div>
        `;
    }).join('');
}

// --- Event Listeners for Dashboard Buttons ---
document.addEventListener('DOMContentLoaded', function() {
    // Analytics period buttons
    const periodButtons = document.querySelectorAll('.period-btn');
    periodButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const period = this.getAttribute('data-period');
            if (typeof window.switchAnalyticsPeriod === 'function') {
                window.switchAnalyticsPeriod(period, this);
            }
        });
    });

    // Leaderboard metric tabs
    const metricTabs = document.querySelectorAll('.metric-tab');
    metricTabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const metric = this.getAttribute('data-metric');

            // Update button states
            metricTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Show loading state
            const tableBody = document.getElementById('leaderboardTableBody');
            const podiumContainer = document.getElementById('podiumContainer');
            
            if (tableBody) {
                tableBody.style.opacity = '0.5';
            }
            if (podiumContainer) {
                podiumContainer.style.opacity = '0.5';
            }

            // Fetch leaderboard data with new metric
            fetch(`get_leaderboard.php?metric=${metric}&period=30d`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.has_error === false) {
                    // Update leaderboard table
                    if (tableBody && data.top_users) {
                        tableBody.innerHTML = renderLeaderboardRows(data.top_users, data.current_user?.username);
                    }
                    
                    // Update podium
                    if (podiumContainer && data.top_users && data.top_users.length >= 3) {
                        podiumContainer.innerHTML = renderPodium(data.top_users);
                    }
                } else {
                    console.error('Leaderboard API returned error');
                }
            })
            .catch(error => {
                console.error('Failed to fetch leaderboard:', error);
            })
            .finally(() => {
                // Restore opacity
                if (tableBody) {
                    tableBody.style.opacity = '1';
                }
                if (podiumContainer) {
                    podiumContainer.style.opacity = '1';
                }
            });
        });
    });
});

// --- Notifications Ticker Logic ---
document.addEventListener('DOMContentLoaded', function() {
    let allNotifications = [];
    let currentIndex = 0;
    let tickerInterval = null;
    let isPinned = false;

    const elCount = document.getElementById('pendingCount');
    const elContent = document.getElementById('notificationContent');

    function updateTickerContent(n) {
        if (!elContent) return;

        // Fade out
        elContent.classList.add('hide');

        setTimeout(() => {
            const icon = n.status === 'completed' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            const color = n.status === 'completed' ? 'var(--term-green)' : 'var(--term-accent)';

            let html = `
                    <div class="alert-terminal p-1 px-2 mb-0 rounded-0 ticker-fade" style="border-left-color: ${color}; border-top: none; border-right: none; border-bottom: none;">
                        <div class="d-flex align-items-center">
                            <i class="fas ${icon} me-2" style="color: ${color}; font-size: 0.65rem; opacity: 0.8;"></i>
                            <div class="ms-1 w-100" style="overflow: hidden;">
                                <div class="d-flex justify-content-between">
                                    <small style="color: ${color}; font-weight: bold; font-size: 0.6rem;">> ${n.status.toUpperCase()}${isPinned ? ' [FIXED]' : ''}</small>
                                    <small class="text-muted" style="font-size: 0.55rem;">${n.date}</small>
                                </div>
                                <div class="mt-0" style="overflow: hidden;">
                                    <p class="mb-0 notification-message" style="color: #fff; white-space: nowrap; text-overflow: ellipsis; font-size: 0.65rem;">${n.message}</p>
                                </div>
                            </div>
                        </div>
                    </div>`;

            elContent.innerHTML = html;
            // Fade in
            elContent.classList.remove('hide');
        }, 500);
    }

    function startRotation() {
        if (tickerInterval) clearInterval(tickerInterval);
        if (allNotifications.length <= 1) return;

        tickerInterval = setInterval(() => {
            if (isPinned) return;
            currentIndex = (currentIndex + 1) % allNotifications.length;
            updateTickerContent(allNotifications[currentIndex]);
        }, 3000);
    }

    function fetchNotifications() {
        // [OPTIMIZED] Check if MasterLayout or another component already fetched
        if (globalThis.ROOTS_NOTIF_FETCHED) return;
        globalThis.ROOTS_NOTIF_FETCHED = true;

        fetch('get_recent_notifications', { credentials: 'include' })
            .then(res => res.json())
            .then(data => {
                processNotificationData(data);
            })
            .catch(err => {
                globalThis.ROOTS_NOTIF_FETCHED = false;
                console.error('Ticker fetch error:', err);
            });
    }

    function updateNotificationCount(pendingCount) {
        if (!elCount) return;

        elCount.textContent = pendingCount > 0 ? `${pendingCount} ALERT(S)` : 'SECURE';
        elCount.style.background = pendingCount > 0 ? 'var(--term-accent)' : 'var(--term-green)';
        elCount.style.boxShadow = pendingCount > 0 ? '0 0 10px var(--term-accent)' : 'none';
    }

    function pinLatestNotification(latest) {
        isPinned = true;
        currentIndex = 0;
        updateTickerContent(latest);
        if (tickerInterval) clearInterval(tickerInterval);
    }

    function resumeRotation() {
        isPinned = false;
        if (currentIndex >= allNotifications.length) currentIndex = 0;
        updateTickerContent(allNotifications[currentIndex]);
        startRotation();
    }

    function processNotificationData(data) {
        allNotifications = data.notifications || [];
        const pendingCount = data.pending_count || 0;

        updateNotificationCount(pendingCount);

        if (allNotifications.length > 0) {
            const latest = allNotifications[0];
            const latestDate = new Date(latest.raw_date);
            const now = new Date();
            const diffMins = (now - latestDate) / (1000 * 60);

            if (diffMins < 10) {
                pinLatestNotification(latest);
            } else {
                resumeRotation();
            }
        }

        // Display empty state if no notifications
        if (elContent && allNotifications.length === 0) {
            elContent.innerHTML = `
                    <div class="text-center py-2" style="color: var(--term-green-dim);">
                        <p class="mb-0 small">> ALL SYSTEMS NOMINAL</p>
                    </div>`;
        }
    }

    // Listen for MasterLayout notifications
    globalThis.addEventListener('roots:notifications:loaded', function(e) {
        processNotificationData(e.detail);
    });

    // Initial fetch
    fetchNotifications();
    // Refresh notifications every 5 minutes
    setInterval(fetchNotifications, 300000);
});

// --- Transaction History Stream Logic (Queue Based) ---

// Helper functions moved to outer scope
function getRandomInterval() {
    return 2000 + Math.random() * 1000; // 2-3 seconds
}

// Function to mask/encrypt username
function maskUsername(username) {
    if (!username || username.length <= 3) return username;
    const firstTwo = username.substring(0, 2);
    const stars = '*'.repeat(Math.min(4, username.length - 2));
    const lastTwo = username.substring(Math.max(2, username.length - 2));
    return firstTwo + stars + lastTwo;
}

function renderTransactionHTML(tx) {
    if (!tx) return '';
    const isPositive = ['sale', 'transfer_in', 'bonus', 'refund'].includes(tx.transaction_type);

    // Show arrow instead of number - green for positive, red for negative
    const arrowIcon = isPositive ? 'fa-arrow-up' : 'fa-arrow-down';
    const arrowColor = isPositive ? '#66aa66' : '#aa6666';

    const typeIcon = {
        'sale': 'fa-shopping-cart',
        'purchase': 'fa-credit-card',
        'transfer_in': 'fa-arrow-down',
        'transfer_out': 'fa-arrow-up',
        'bonus': 'fa-gift',
        'refund': 'fa-undo',
        'credit': 'fa-plus-circle',
        'debit': 'fa-minus-circle'
    } [tx.transaction_type] || 'fa-exchange-alt';

    // Clean description
    let desc = tx.description || 'Transaction';
    desc = desc.replace(/0x[0-9a-f]{10,}/gi, '');
    desc = desc.replace(/bc1[a-z0-9]{25,}/gi, '');
    desc = desc.replace(/[13][1-9a-hj-km-z]{25,34}/gi, '');
    desc = desc.replace(/ID:\s*#?\d+/gi, '');
    desc = desc.replace(/#\d+/gi, '');
    desc = desc.replace(/\s+/g, ' ').trim();
    if (!desc) desc = tx.display_name + ' Transaction';

    const maskedUsername = maskUsername(tx.display_name);

    return `
        <div class="tx-item">
            <div class="tx-icon">
                <i class="fas ${typeIcon}"></i>
            </div>
            <div class="tx-user">
                <span>${maskedUsername}</span>
            </div>
            <div class="tx-amount">
                <i class="fas ${arrowIcon}" style="color: ${arrowColor}; font-size: 0.8rem;"></i>
            </div>
            <div class="tx-desc">
                <span>${desc}</span>
            </div>
            <div class="tx-time">
                <span>${tx.time_ago || 'Just now'}</span>
            </div>
        </div>`;
}

document.addEventListener('DOMContentLoaded', function() {
    let allTransactions = [];
    let isFirstLoad = true;
    let currentIndex = 0;
    const container = document.getElementById('transactionStreamContainer');
    const elStatus = document.getElementById('txStreamStatus');

    function displayTransaction(tx) {
        if (!container) return;

        // Create new transaction HTML
        const txHTML = renderTransactionHTML(tx);

        // Fade out existing content
        const currentContent = container.querySelector('.tx-item');
        if (currentContent) {
            currentContent.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            currentContent.style.opacity = '0';
            currentContent.style.transform = 'translateY(5px)';

            // Wait for fade out, then replace and fade in
            setTimeout(() => {
                container.innerHTML = `
                    <div class="tx-item" style="opacity: 0; transform: translateY(-5px); transition: opacity 0.3s ease, transform 0.3s ease;">
                        ${txHTML}
                    </div>`;

                // Fade in new content
                requestAnimationFrame(() => {
                    const newTx = container.querySelector('.tx-item');
                    if (newTx) {
                        newTx.style.opacity = '1';
                        newTx.style.transform = 'translateY(0)';
                    }
                });
            }, 300);
        } else {
            // First transaction - just display it with animation
            container.innerHTML = `
                <div class="tx-item" style="opacity: 0; transform: translateY(-5px); transition: opacity 0.3s ease, transform 0.3s ease;">
                    ${txHTML}
                </div>`;

            requestAnimationFrame(() => {
                const newTx = container.querySelector('.tx-item');
                if (newTx) {
                    newTx.style.opacity = '1';
                    newTx.style.transform = 'translateY(0)';
                }
            });
        }
    }

    function fetchTransactions() {
        if (!container) return;

        fetch('get_transaction_history')
            .then(res => res.json())
            .then(data => {
                // Remove placeholder
                const placeholder = document.getElementById('tx-placeholder');
                placeholder?.remove();

                if (data.success && data.transactions && data.transactions.length > 0) {
                    allTransactions = data.transactions;

                    if (isFirstLoad) {
                        // Display only 1 transaction immediately
                        displayTransaction(allTransactions[0]);
                        isFirstLoad = false;
                        currentIndex = 1;

                        // Start streaming after initial display
                        setTimeout(startTransactionStream, 2000);
                    }
                } else {
                    container.innerHTML = '<div class="tx-empty">No recent transactions</div>';
                    if (elStatus) {
                        elStatus.innerHTML = '<span class="status-idle">IDLE</span>';
                    }
                }
            })
            .catch(err => {
                console.error('Transaction stream error:', err);

                // Remove placeholder
                const placeholder = document.getElementById('tx-placeholder');
                placeholder?.remove();

                container.innerHTML = '<div class="tx-error">Connection failed</div>';
                if (elStatus) {
                    elStatus.innerHTML = '<span class="status-error">OFFLINE</span>';
                }
            });
    }

    function startTransactionStream() {
        if (!container || allTransactions.length === 0) return;

        function streamNext() {
            if (!container || allTransactions.length === 0) return;

            displayTransaction(allTransactions[currentIndex]);
            currentIndex++;

            // Loop back to start if we reach the end
            if (currentIndex >= allTransactions.length) {
                currentIndex = 0;
            }

            // Schedule next transaction
            setTimeout(streamNext, getRandomInterval());
        }

        // Start streaming
        streamNext();
    }

    // Initial fetch
    if (container) {
        if (elStatus) {
            elStatus.innerHTML = '<span class="status-live">LIVE</span>';
        }
        fetchTransactions();
    }
});
