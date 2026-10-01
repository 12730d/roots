function deletePurchase(purchaseId, recordName) {
    if (!confirm(`Are you sure you want to remove "${recordName}" from your purchases?\n\nNote: The record will be removed only from your account and will remain in the original database.`)) {
        return;
    }

    // Get CSRF token from meta tag if available
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch('delete_purchase', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            purchase_id: purchaseId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Use showToast if available (from standard layout), otherwise alert
            if (typeof showToast === 'function') {
                showToast(data.message || 'Purchase deleted successfully!', 'success');
            } else {
                alert(data.message || 'Purchase deleted successfully!');
            }
            // Delay reload slightly to let toast be seen if possible, or just reload
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert(data.message || 'Failed to delete purchase');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting');
    });
}
