/**
 * Drone Dashboard JavaScript
 * Handles all drone dashboard interactions and API calls
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize dashboard
    initializeDashboard();

    // Setup event listeners
    setupEventListeners();
});

/**
 * Initialize dashboard components
 */
function initializeDashboard() {
    // Load initial data
    loadDashboardData();

    // Setup auto-refresh for statistics
    setupAutoRefresh();

    // Initialize tooltips
    initializeTooltips();
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
    // Register drone form
    document.getElementById('registerDroneForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        registerDrone();
    });

    // Register face form
    document.getElementById('registerFaceForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        registerFace();
    });
}

/**
 * Load dashboard data
 */
async function loadDashboardData() {
    try {
        // Update statistics
        await updateStatistics();

        // Load recent activities
        await loadRecentActivities();

        // Update drone statuses
        await updateDroneStatuses();

    } catch (error) {
        console.error('Error loading dashboard data:', error);
        showNotification('Error loading dashboard data', 'danger');
    }
}

/**
 * Register a new drone
 */
async function registerDrone() {
    try {
        const form = document.getElementById('registerDroneForm');
        const formData = new FormData(form);

        const droneData = {
            user_id: getCurrentUserId(),
            drone_id: formData.get('drone_id'),
            drone_name: formData.get('drone_name'),
            drone_type: formData.get('drone_type'),
            camera_specs: {
                resolution: formData.get('camera_resolution')
            }
        };

        const response = await fetch('/api/v1/drone/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(droneData)
        });

        const result = await response.json();

        if (result.success) {
            showNotification('Drone registered successfully!', 'success');

            // Display API credentials
            showApiCredentials(result.api_key, result.api_secret);

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('registerDroneModal')).hide();

            // Reload dashboard
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('Error registering drone: ' + result.error, 'danger');
        }

    } catch (error) {
        console.error('Error registering drone:', error);
        showNotification('Error registering drone', 'danger');
    }
}

/**
 * Register a new face
 */
async function registerFace() {
    try {
        const form = document.getElementById('registerFaceForm');
        const formData = new FormData(form);

        // Convert image to base64
        const imageFile = formData.get('face_image');
        const imageBase64 = await fileToBase64(imageFile);

        const faceData = {
            face_name: formData.get('face_name'),
            face_image: imageBase64,
            metadata: {
                category: formData.get('category'),
                registration_method: 'dashboard'
            }
        };

        const response = await fetch('/api/v1/faces/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': getApiKey(),
                'X-API-Secret': getApiSecret()
            },
            body: JSON.stringify(faceData)
        });

        const result = await response.json();

        if (result.success) {
            showNotification('Face registered successfully!', 'success');

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('registerFaceModal')).hide();

            // Reload dashboard
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('Error registering face: ' + result.error, 'danger');
        }

    } catch (error) {
        console.error('Error registering face:', error);
        showNotification('Error registering face', 'danger');
    }
}

/**
 * Delete a face
 */
async function deleteFace(faceId) {
    if (!confirm('Are you sure you want to delete this face?')) {
        return;
    }

    try {
        const response = await fetch(`/api/v1/faces/${faceId}`, {
            method: 'DELETE',
            headers: {
                'X-API-Key': getApiKey(),
                'X-API-Secret': getApiSecret()
            }
        });

        const result = await response.json();

        if (result.success) {
            showNotification('Face deleted successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('Error deleting face: ' + result.error, 'danger');
        }

    } catch (error) {
        console.error('Error deleting face:', error);
        showNotification('Error deleting face', 'danger');
    }
}

/**
 * Rotate API keys for a drone
 */
async function rotateApiKeys(droneId) {
    if (!confirm('Are you sure you want to rotate API keys? Old keys will be invalidated.')) {
        return;
    }

    try {
        const userId = getCurrentUserId();

        const response = await fetch('/api/rotate-keys', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                drone_id: droneId,
                user_id: userId
            })
        });

        const result = await response.json();

        if (result.success) {
            showNotification('API keys rotated successfully!', 'success');
            showApiCredentials(result.new_api_key, result.new_api_secret);
        } else {
            showNotification('Error rotating keys: ' + result.error, 'danger');
        }

    } catch (error) {
        console.error('Error rotating keys:', error);
        showNotification('Error rotating keys', 'danger');
    }
}

/**
 * View drone details
 */
function viewDroneDetails(droneId) {
    // Load drone details via AJAX
    fetch(`/api/drone-details/${droneId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showDroneDetailsModal(data.drone);
            } else {
                showNotification('Error loading drone details', 'danger');
            }
        })
        .catch(error => {
            console.error('Error loading drone details:', error);
            showNotification('Error loading drone details', 'danger');
        });
}

/**
 * Load more scans
 */
function loadMoreScans() {
    const currentCount = document.querySelectorAll('#recentScans tbody tr').length;

    fetch(`/api/scans?offset=${currentCount}&limit=10`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.scans.length > 0) {
                appendScanRows(data.scans);
            } else {
                showNotification('No more scans to load', 'info');
            }
        })
        .catch(error => {
            console.error('Error loading more scans:', error);
            showNotification('Error loading scans', 'danger');
        });
}

/**
 * Update statistics
 */
async function updateStatistics() {
    try {
        const response = await fetch('/api/statistics');
        const data = await response.json();

        if (data.success) {
            updateStatCards(data.stats);
        }
    } catch (error) {
        console.error('Error updating statistics:', error);
    }
}

/**
 * Load recent activities
 */
async function loadRecentActivities() {
    try {
        const response = await fetch('/api/activities');
        const data = await response.json();

        if (data.success) {
            updateActivityFeed(data.activities);
        }
    } catch (error) {
        console.error('Error loading activities:', error);
    }
}

/**
 * Update drone statuses
 */
async function updateDroneStatuses() {
    try {
        const response = await fetch('/api/drone-statuses');
        const data = await response.json();

        if (data.success) {
            updateDroneStatusIndicators(data.drones);
        }
    } catch (error) {
        console.error('Error updating drone statuses:', error);
    }
}

/**
 * Setup auto-refresh
 */
function setupAutoRefresh() {
    // Refresh statistics every 30 seconds
    setInterval(() => {
        updateStatistics();
        updateDroneStatuses();
    }, 30000);

    // Refresh activities every 60 seconds
    setInterval(() => {
        loadRecentActivities();
    }, 60000);
}

/**
 * Initialize tooltips
 */
function initializeTooltips() {
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 10000; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(notification);

    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Show API credentials modal
 */
function showApiCredentials(apiKey, apiSecret) {
    const credentialsHtml = `
        <div class="modal fade" id="apiCredentialsModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: #0a0f0a; border: 1px solid #00ff41; color: #e0e0e0;">
                    <div class="modal-header" style="border-bottom: 1px solid #00ff41;">
                        <h5 class="modal-title" style="color: #00ff41;">API Credentials</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" style="color: #00ff41;">API Key</label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="${apiKey}" readonly
                                       style="background: #050505; border: 1px solid rgba(0,255,65,0.3); color: #e0e0e0;">
                                <button class="btn btn-outline-success" onclick="copyToClipboard('${apiKey}')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #00ff41;">API Secret</label>
                            <div class="input-group">
                                <input type="password" class="form-control" value="${apiSecret}" readonly
                                       style="background: #050505; border: 1px solid rgba(0,255,65,0.3); color: #e0e0e0;">
                                <button class="btn btn-outline-success" onclick="this.parentElement.querySelector('input').type = this.parentElement.querySelector('input').type === 'password' ? 'text' : 'password'">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-success" onclick="copyToClipboard('${apiSecret}')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-warning" style="background: rgba(255,149,0,0.1); border: 1px solid #ff9500; color: #ff9500;">
                            <strong>Important:</strong> Save these credentials securely. You won't be able to see the secret key again.
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid rgba(0,255,65,0.3);">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existingModal = document.getElementById('apiCredentialsModal');
    if (existingModal) {
        existingModal.remove();
    }

    // Add new modal
    document.body.insertAdjacentHTML('beforeend', credentialsHtml);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('apiCredentialsModal'));
    modal.show();
}

/**
 * Copy text to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('Copied to clipboard!', 'success');
    }).catch(err => {
        console.error('Failed to copy:', err);
        showNotification('Failed to copy to clipboard', 'danger');
    });
}

/**
 * Convert file to base64
 */
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}

/**
 * Get current user ID from session
 */
function getCurrentUserId() {
    // This would typically come from the server-side session
    return <?php echo $_SESSION['user_id'] ?? 0; ?>;
}

/**
 * Get API key from storage
 */
function getApiKey() {
    return localStorage.getItem('drone_api_key') || '';
}

/**
 * Get API secret from storage
 */
function getApiSecret() {
    return localStorage.getItem('drone_api_secret') || '';
}

/**
 * Save API credentials
 */
function saveApiCredentials(apiKey, apiSecret) {
    localStorage.setItem('drone_api_key', apiKey);
    localStorage.setItem('drone_api_secret', apiSecret);
}

/**
 * Update stat cards
 */
function updateStatCards(stats) {
    // Update individual stat cards with animation
    const statCards = [
        { id: 'totalDrones', value: stats.total_drones },
        { id: 'totalScans', value: stats.total_scans_today },
        { id: 'successfulMatches', value: stats.successful_matches },
        { id: 'registeredFaces', value: stats.registered_faces }
    ];

    statCards.forEach(card => {
        const element = document.getElementById(card.id);
        if (element) {
            animateValue(element, parseInt(element.textContent), card.value, 1000);
        }
    });
}

/**
 * Update activity feed
 */
function updateActivityFeed(activities) {
    const feedContainer = document.getElementById('activityFeed');
    if (!feedContainer) return;

    feedContainer.innerHTML = activities.map(activity => `
        <div class="activity-item">
            <div class="activity-time">${formatDateTime(activity.timestamp)}</div>
            <div class="activity-text">${activity.description}</div>
        </div>
    `).join('');
}

/**
 * Update drone status indicators
 */
function updateDroneStatusIndicators(drones) {
    drones.forEach(drone => {
        const indicator = document.getElementById(`drone-status-${drone.drone_id}`);
        if (indicator) {
            indicator.className = `status-${drone.status}`;
            indicator.textContent = drone.status.toUpperCase();
        }
    });
}

/**
 * Append scan rows to table
 */
function appendScanRows(scans) {
    const tbody = document.querySelector('#recentScans tbody');
    if (!tbody) return;

    scans.forEach(scan => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><code>${scan.scan_id.substring(0, 12)}...</code></td>
            <td>${scan.drone_name}</td>
            <td>${formatDateTime(scan.timestamp)}</td>
            <td>${scan.faces_detected}</td>
            <td>${scan.match_found ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>'}</td>
            <td>
                <div class="progress" style="width: 100px; height: 8px;">
                    <div class="progress-bar bg-success" style="width: ${scan.confidence * 100}%"></div>
                </div>
                <small>${Math.round(scan.confidence * 100)}%</small>
            </td>
            <td>${scan.processing_time}ms</td>
        `;
        tbody.appendChild(row);
    });
}

/**
 * Show drone details modal
 */
function showDroneDetailsModal(drone) {
    // Create and show drone details modal
    const detailsHtml = `
        <div class="modal fade" id="droneDetailsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content" style="background: #0a0f0a; border: 1px solid #00ff41; color: #e0e0e0;">
                    <div class="modal-header" style="border-bottom: 1px solid #00ff41;">
                        <h5 class="modal-title" style="color: #00ff41;">Drone Details: ${drone.drone_name}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-success mb-3">Basic Information</h6>
                                <p><strong>ID:</strong> ${drone.drone_id}</p>
                                <p><strong>Type:</strong> ${drone.drone_type}</p>
                                <p><strong>Status:</strong> ${drone.status}</p>
                                <p><strong>Subscription:</strong> ${drone.subscription_tier}</p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-success mb-3">Rate Limits</h6>
                                <p><strong>Per Minute:</strong> ${drone.rate_limit_per_minute}</p>
                                <p><strong>Per Hour:</strong> ${drone.rate_limit_per_hour}</p>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h6 class="text-success mb-3">Activity Statistics</h6>
                                <p><strong>Total Scans:</strong> ${drone.total_scans}</p>
                                <p><strong>Successful Matches:</strong> ${drone.successful_matches}</p>
                                <p><strong>Last Active:</strong> ${formatDateTime(drone.last_active)}</p>
                                <p><strong>Registered:</strong> ${formatDateTime(drone.created_at)}</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid rgba(0,255,65,0.3);">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existingModal = document.getElementById('droneDetailsModal');
    if (existingModal) {
        existingModal.remove();
    }

    // Add new modal
    document.body.insertAdjacentHTML('beforeend', detailsHtml);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('droneDetailsModal'));
    modal.show();
}

/**
 * Animate value change
 */
function animateValue(element, start, end, duration) {
    const range = end - start;
    const increment = range > 0 ? 1 : -1;
    const stepTime = Math.abs(Math.floor(duration / range));
    let current = start;

    const timer = setInterval(() => {
        current += increment;
        element.textContent = current;
        if (current === end) {
            clearInterval(timer);
        }
    }, stepTime);
}

/**
 * Format date/time
 */
function formatDateTime(dateTime) {
    const date = new Date(dateTime);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Handle drone real-time status updates
 */
function handleDroneStatusUpdate(droneId, status) {
    const statusElement = document.getElementById(`drone-status-${droneId}`);
    if (statusElement) {
        statusElement.className = `status-${status}`;
        statusElement.textContent = status.toUpperCase();

        // Add visual notification
        statusElement.style.animation = 'pulse 0.5s ease-in-out';
        setTimeout(() => {
            statusElement.style.animation = '';
        }, 500);
    }
}

/**
 * WebSocket connection for real-time updates
 */
function setupWebSocketConnection() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsUrl = `${protocol}//${window.location.host}/ws/drone-updates`;

    try {
        const ws = new WebSocket(wsUrl);

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);

            switch (data.type) {
                case 'scan_complete':
                    showNotification(`Scan ${data.scan_id} completed`, 'info');
                    updateStatistics();
                    break;
                case 'match_found':
                    showNotification(`Match found: ${data.face_name}`, 'success');
                    break;
                case 'drone_status':
                    handleDroneStatusUpdate(data.drone_id, data.status);
                    break;
                default:
                    // Unknown message type - ignore
                    break;
            }
        };

        ws.onopen = () => {
            // WebSocket connected
        };

        ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        ws.onclose = () => {
            // WebSocket disconnected
            // Attempt to reconnect after 5 seconds
            setTimeout(setupWebSocketConnection, 5000);
        };

    } catch (error) {
        console.error('WebSocket setup failed:', error);
    }
}

// Initialize WebSocket for real-time updates
document.addEventListener('DOMContentLoaded', () => {
    // Only setup WebSocket if not in a test environment
    if (window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
        setupWebSocketConnection();
    }
});