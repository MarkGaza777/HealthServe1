/**
 * Custom Modal Component
 * Replaces browser confirm() and alert() dialogs with styled modals
 */

// Create modal HTML structure
function createModalHTML() {
    if (document.getElementById('customModal')) return;
    
    const modalHTML = `
        <div id="customModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="custom-modal-header">
                    <h3 class="custom-modal-title">
                        <i class="fas fa-exclamation-triangle custom-modal-icon"></i>
                        <span id="customModalTitle">Confirm Action</span>
                    </h3>
                    <button class="custom-modal-close" onclick="closeCustomModal()">&times;</button>
                </div>
                <div class="custom-modal-body">
                    <p id="customModalMessage"></p>
                    <p id="customModalWarning" class="custom-modal-warning"></p>
                </div>
                <div class="custom-modal-actions">
                    <button class="custom-modal-btn custom-modal-btn-cancel" onclick="closeCustomModal()">Cancel</button>
                    <button class="custom-modal-btn custom-modal-btn-confirm" id="customModalConfirmBtn">
                        <i class="fas fa-trash"></i>
                        <span id="customModalConfirmText">Confirm</span>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Add modal styles
function addModalStyles() {
    if (document.getElementById('customModalStyles')) return;
    
    const styles = `
        <style id="customModalStyles">
            .custom-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(5px);
                z-index: 10000;
                align-items: center;
                justify-content: center;
                overflow-y: auto;
            }
            
            .custom-modal.active {
                display: flex;
            }
            
            .custom-modal-content {
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
                animation: modalSlideIn 0.3s ease;
            }
            
            @keyframes modalSlideIn {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .custom-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 20px 24px;
                border-bottom: 1px solid #E0E0E0;
            }
            
            .custom-modal-title {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: #333;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }
            
            .custom-modal-icon {
                color: #c62828;
                font-size: 20px;
            }
            
            .custom-modal-body strong {
                font-weight: 600;
            }
            
            .custom-modal-close {
                background: #F3F4F6;
                border: none;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                color: #666;
                font-size: 20px;
                transition: all 0.2s ease;
            }
            
            .custom-modal-close:hover {
                background: #E0E0E0;
            }
            
            .custom-modal-body {
                padding: 24px;
                color: #333;
                line-height: 1.6;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }
            
            .custom-modal-body p {
                margin: 0 0 12px 0;
            }
            
            .custom-modal-body strong {
                font-weight: 600;
                color: #333;
            }
            
            .custom-modal-warning {
                color: #666;
                font-size: 14px;
                margin-top: 8px;
            }
            
            .custom-modal-actions {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                padding: 16px 24px;
                border-top: 1px solid #E0E0E0;
            }
            
            .custom-modal-btn {
                padding: 10px 20px;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }
            
            .custom-modal-btn-cancel {
                background: #F5F5F5;
                color: #666;
                border: 1px solid #E0E0E0;
            }
            
            .custom-modal-btn-cancel:hover {
                background: #E0E0E0;
            }
            
            .custom-modal-btn-confirm {
                background: #c62828;
                color: white;
            }
            
            .custom-modal-btn-confirm:hover {
                background: #b71c1c;
            }
            
            .custom-modal-btn-confirm i {
                font-size: 14px;
            }
        </style>
    `;
    
    document.head.insertAdjacentHTML('beforeend', styles);
}

// Show custom confirm modal
function showCustomConfirm(options) {
    createModalHTML();
    addModalStyles();
    
    const modal = document.getElementById('customModal');
    const title = document.getElementById('customModalTitle');
    const message = document.getElementById('customModalMessage');
    const warning = document.getElementById('customModalWarning');
    const confirmBtn = document.getElementById('customModalConfirmBtn');
    const confirmText = document.getElementById('customModalConfirmText');
    
    // Set content
    title.textContent = options.title || 'Confirm Action';
    message.innerHTML = options.message || '';
    warning.textContent = options.warning || 'This action cannot be undone.';
    confirmText.textContent = options.confirmText || 'Confirm';
    
    // Set confirm button style based on action type
    if (options.type === 'delete') {
        confirmBtn.style.background = '#c62828';
        confirmBtn.innerHTML = '<i class="fas fa-trash"></i> <span>' + (options.confirmText || 'Delete') + '</span>';
    } else if (options.type === 'danger') {
        confirmBtn.style.background = '#c62828';
        confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>' + (options.confirmText || 'Proceed') + '</span>';
    } else {
        confirmBtn.style.background = '#2E7D32';
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> <span>' + (options.confirmText || 'Confirm') + '</span>';
    }
    
    // Remove previous event listeners
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new event listener
    newConfirmBtn.addEventListener('click', function() {
        if (options.onConfirm) {
            options.onConfirm();
        }
        closeCustomModal();
    });
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close custom modal
function closeCustomModal() {
    const modal = document.getElementById('customModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('customModal');
    if (modal && e.target === modal) {
        closeCustomModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCustomModal();
    }
});

// Helper function to show delete confirmation
function showDeleteConfirm(itemName, itemType, onConfirm, additionalWarning) {
    showCustomConfirm({
        title: `Delete ${itemType}`,
        message: `Are you sure you want to delete <strong>${itemName}</strong>?`,
        warning: additionalWarning || 'This action cannot be undone. All associated appointments and records will be affected.',
        type: 'delete',
        confirmText: `Delete ${itemType}`,
        onConfirm: onConfirm
    });
}

// Helper function for generic confirmations
function showConfirmDialog(title, message, warning, confirmText, onConfirm) {
    showCustomConfirm({
        title: title,
        message: message,
        warning: warning || 'This action cannot be undone.',
        type: 'danger',
        confirmText: confirmText || 'Confirm',
        onConfirm: onConfirm
    });
}

// Store resolve function for async confirm
let confirmResolve = null;

// Replace window.confirm with custom modal (only if not already replaced)
if (!window.confirmReplaced) {
    window.originalConfirm = window.confirm;
    window.confirmReplaced = true;
    
    window.confirm = function(message) {
        return new Promise((resolve) => {
            confirmResolve = resolve;
            
            // Parse message to extract title and details
            let title = 'Confirm Action';
            let warning = 'This action cannot be undone.';
            let confirmText = 'Confirm';
            
            // Detect delete operations
            if (message.toLowerCase().includes('delete')) {
                if (message.includes('inventory item')) {
                    title = 'Delete Inventory Item';
                    confirmText = 'Delete Item';
                } else if (message.includes('doctor')) {
                    title = 'Delete Doctor';
                    confirmText = 'Delete Doctor';
                } else if (message.includes('staff')) {
                    title = 'Delete Staff Member';
                    confirmText = 'Delete Staff Member';
                } else if (message.includes('appointment')) {
                    title = 'Cancel Appointment';
                    confirmText = 'Cancel';
                } else if (message.includes('notification')) {
                    title = 'Delete Notification';
                    confirmText = 'Delete';
                } else if (message.includes('announcement')) {
                    title = 'Delete Announcement';
                    confirmText = 'Delete';
                } else if (message.includes('backup')) {
                    title = 'Delete Backup';
                    confirmText = 'Delete';
                } else {
                    title = 'Delete';
                    confirmText = 'Delete';
                }
            } else if (message.toLowerCase().includes('cancel')) {
                title = 'Cancel Action';
                confirmText = 'Cancel Appointment';
            } else if (message.toLowerCase().includes('clear')) {
                title = 'Clear Data';
                confirmText = 'Clear';
            } else if (message.toLowerCase().includes('restore')) {
                title = 'Restore Backup';
                warning = 'This will overwrite all current data and cannot be undone.';
                confirmText = 'Restore';
            }
            
            // Extract additional warning
            if (message.includes('overwrite')) {
                warning = 'This will overwrite all current data and cannot be undone.';
            } else if (message.includes('affected')) {
                warning = 'All associated appointments and records will be affected.';
            }
            
            // Check if this is a cancellation action - hide the dismiss button
            const isCancelAction = message.toLowerCase().includes('cancel') && message.toLowerCase().includes('appointment');
            
            showCustomConfirm({
                title: title,
                message: message,
                warning: warning,
                type: 'danger',
                confirmText: confirmText,
                onConfirm: function() {
                    if (confirmResolve) confirmResolve(true);
                    confirmResolve = null;
                }
            });
            
            // Hide the cancel/dismiss button for appointment cancellations
            if (isCancelAction) {
                setTimeout(() => {
                    const cancelBtn = document.querySelector('.custom-modal-btn-cancel');
                    if (cancelBtn) {
                        cancelBtn.style.display = 'none';
                    }
                }, 100);
            }
            
            // Handle cancel button - need to set up after modal is shown
            setTimeout(() => {
                const cancelBtn = document.querySelector('.custom-modal-btn-cancel');
                if (cancelBtn && !isCancelAction) {
                    // Remove old event listeners by cloning
                    const newCancelBtn = cancelBtn.cloneNode(true);
                    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
                    newCancelBtn.addEventListener('click', function() {
                        closeCustomModal();
                        if (confirmResolve) confirmResolve(false);
                        confirmResolve = null;
                    });
                }
            }, 100);
        });
    };
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        createModalHTML();
        addModalStyles();
    });
} else {
    createModalHTML();
    addModalStyles();
}

