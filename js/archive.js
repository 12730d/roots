// Toast notification system
function showToast(message, type = 'success') {
    // Remove existing toast if present
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
        existingToast.remove();
    }

    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
            <span>${message}</span>
        </div>
    `;

    // Add toast styles
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 300px;
        background: ${type === 'success' ? 'linear-gradient(145deg, #006400, #00ff00)' : 'linear-gradient(145deg, #cc0000, #ff0000)'};
        border: 1px solid ${type === 'success' ? 'rgba(0, 255, 0, 0.3)' : 'rgba(255, 0, 0, 0.3)'};
    `;

    document.body.appendChild(toast);

    // Animate in
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 100);

    // Auto remove after 3 seconds
    setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 300);
    }, 3000);
}

// Archive functionality
function toggleArchive(recordId) {
    const button = document.querySelector(`button[onclick="toggleArchive(${recordId})"]`);
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }

    fetch('archived_records', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            record_id: recordId,
            action: 'toggle'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);

            // Update button text based on archive status
            if (button) {
                if (data.archived) {
                    button.innerHTML = '<i class="fas fa-check"></i> Archived';
                    button.className = 'btn btn-sm btn-success';
                } else {
                    button.innerHTML = '<i class="fas fa-archive"></i> Archive';
                    button.className = 'btn btn-sm btn-warning';
                }
                button.disabled = false;
            }
        } else {
            showToast('Error: ' + data.message, 'error');
            if (button) {
                button.innerHTML = '<i class="fas fa-archive"></i> Archive';
                button.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error occurred', 'error');
        if (button) {
            button.innerHTML = '<i class="fas fa-archive"></i> Archive';
            button.disabled = false;
        }
    });
}

// Check archive status on page load
function checkArchiveStatus() {
    const archiveButtons = document.querySelectorAll('button[onclick*="toggleArchive"]');

    archiveButtons.forEach(button => {
        const onclickAttr = button.getAttribute('onclick');
        const recordIdMatch = /toggleArchive\((\d+)\)/.exec(onclickAttr);
        const recordId = recordIdMatch ? recordIdMatch[1] : null;

        fetch('archived_records.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                record_id: Number.parseInt(recordId),
                action: 'check'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.archived) {
                button.innerHTML = '<i class="fas fa-check"></i> Archived';
                button.className = 'btn btn-sm btn-success';
            }
        })
        .catch(error => {
            console.error('Error checking archive status:', error);
        });
    });
}

// Initialize archive status checking when page loads
document.addEventListener('DOMContentLoaded', function() {
    checkArchiveStatus();
});

function deleteArchivedRecord(recordId) {
    if (!confirm('Are you sure you want to permanently delete this archived record?')) {
        return;
    }

    fetch('archived_records', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            record_id: recordId,
            action: 'delete'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Record deleted from archive');
            location.reload();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    });
}

function restoreArchivedRecord(recordId) {
    fetch('archived_records', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            record_id: recordId,
            action: 'restore'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Record restored from archive');
            location.reload();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    });
}

function clearAllArchives() {
    if (!confirm('Are you sure you want to clear ALL archived records? This action cannot be undone.')) {
        return;
    }

    fetch('clear_archives', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showToast('Error clearing archives: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error clearing archives', 'error');
    });
}