/**
 * Custom Notification/Alert System
 * Replaces browser alert() dialogs with styled notifications
 */

// Create notification container
function createNotificationContainer() {
    if (document.getElementById('customNotificationContainer')) return;
    
    const container = document.createElement('div');
    container.id = 'customNotificationContainer';
    container.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10001;
        display: flex;
        flex-direction: column;
        gap: 12px;
        pointer-events: none;
    `;
    document.body.appendChild(container);
}

// Show custom notification
function showCustomNotification(message, type = 'success', duration = 3000) {
    createNotificationContainer();
    const container = document.getElementById('customNotificationContainer');
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `custom-notification custom-notification-${type}`;
    notification.style.cssText = `
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        min-width: 300px;
        max-width: 400px;
        display: flex;
        align-items: center;
        gap: 12px;
        pointer-events: auto;
        animation: slideInRight 0.3s ease;
        border-left: 4px solid ${type === 'success' ? '#2E7D32' : type === 'error' ? '#c62828' : type === 'warning' ? '#F57C00' : '#2196F3'};
    `;
    
    // Icon based on type
    let icon = 'fa-check-circle';
    let iconColor = '#2E7D32';
    if (type === 'error') {
        icon = 'fa-exclamation-circle';
        iconColor = '#c62828';
    } else if (type === 'warning') {
        icon = 'fa-exclamation-triangle';
        iconColor = '#F57C00';
    } else if (type === 'info') {
        icon = 'fa-info-circle';
        iconColor = '#2196F3';
    }
    
    notification.innerHTML = `
        <i class="fas ${icon}" style="color: ${iconColor}; font-size: 20px;"></i>
        <div style="flex: 1; color: #333; font-size: 14px; line-height: 1.5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
            ${message}
        </div>
        <button class="custom-notification-close" onclick="this.parentElement.remove()" style="
            background: transparent;
            border: none;
            color: #999;
            cursor: pointer;
            padding: 4px;
            font-size: 18px;
            line-height: 1;
            transition: color 0.2s;
        " onmouseover="this.style.color='#666'" onmouseout="this.style.color='#999'">
            &times;
        </button>
    `;
    
    // Add animation styles if not already added
    if (!document.getElementById('customNotificationStyles')) {
        const styles = document.createElement('style');
        styles.id = 'customNotificationStyles';
        styles.textContent = `
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(100%);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
            
            @keyframes slideOutRight {
                from {
                    opacity: 1;
                    transform: translateX(0);
                }
                to {
                    opacity: 0;
                    transform: translateX(100%);
                }
            }
            
            .custom-notification {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }
        `;
        document.head.appendChild(styles);
    }
    
    container.appendChild(notification);
    
    // Auto remove after duration
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, duration);
    
    return notification;
}

// Replace window.alert
window.originalAlert = window.alert;
window.alert = function(message) {
    // Determine notification type based on message
    let type = 'success';
    const msg = String(message).toLowerCase();
    
    if (msg.includes('error') || msg.includes('failed') || msg.includes('invalid')) {
        type = 'error';
    } else if (msg.includes('warning') || msg.includes('caution')) {
        type = 'warning';
    } else if (msg.includes('info') || msg.includes('information')) {
        type = 'info';
    } else if (msg.includes('success') || msg.includes('successfully') || msg.includes('saved') || msg.includes('updated') || msg.includes('created') || msg.includes('deleted') || msg.includes('added')) {
        type = 'success';
    }
    
    showCustomNotification(message, type);
};

// Helper functions for different notification types
function showSuccess(message, duration = 3000) {
    showCustomNotification(message, 'success', duration);
}

function showError(message, duration = 4000) {
    showCustomNotification(message, 'error', duration);
}

function showWarning(message, duration = 3500) {
    showCustomNotification(message, 'warning', duration);
}

function showInfo(message, duration = 3000) {
    showCustomNotification(message, 'info', duration);
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        createNotificationContainer();
    });
} else {
    createNotificationContainer();
}

