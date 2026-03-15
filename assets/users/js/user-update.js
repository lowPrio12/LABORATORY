// User Update JavaScript - Only for updating existing users

// Edit User Modal
function openEditModal(userId, username, role) {
    document.getElementById('edit_user_id').value = userId;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_password').value = '';

    document.getElementById('editModal').classList.add('active');

    // Prevent body scrolling
    document.body.style.overflow = 'hidden';

    // Clear any previous error messages
    const errorDiv = document.getElementById('editUserError');
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.innerHTML = '';
    }
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Show notification function
function showNotification(message, type = 'success') {
    // Remove any existing notifications with the same message to prevent duplicates
    const existingNotifications = document.querySelectorAll('.notification-item');
    for (let notif of existingNotifications) {
        if (notif.innerText.includes(message)) {
            notif.remove();
        }
    }

    // Check if notification container exists
    let container = document.getElementById('notificationContainer');

    if (!container) {
        container = document.createElement('div');
        container.id = 'notificationContainer';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        `;
        document.body.appendChild(container);
    }

    // Create notification
    const notification = document.createElement('div');
    notification.className = 'notification-item';
    notification.style.cssText = `
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: slideIn 0.3s ease;
        font-family: 'Inter', sans-serif;
        min-width: 300px;
        position: relative;
        z-index: 10000;
    `;

    // Create message based on type
    let icon = 'fa-check-circle';
    let title = 'Success';

    if (type === 'warning') {
        icon = 'fa-exclamation-triangle';
        title = 'Warning';
    } else if (type === 'error') {
        icon = 'fa-exclamation-circle';
        title = 'Error';
    } else if (type === 'info') {
        icon = 'fa-info-circle';
        title = 'Info';
    }

    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 1rem; width: 100%;">
            <i class="fas ${icon}" style="font-size: 1.25rem;"></i>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 0.25rem;">${title}</div>
                <div style="font-size: 0.875rem; opacity: 0.9;">${message}</div>
            </div>
            <i class="fas fa-times" style="cursor: pointer; opacity: 0.7; hover:opacity: 1;" onclick="this.parentElement.parentElement.remove()"></i>
        </div>
    `;

    container.appendChild(notification);

    // Remove after 3 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }
    }, 3000);
}

// Update User Function with AJAX
function updateUser(event) {
    event.preventDefault();

    // Prevent multiple submissions
    if (window.isUpdating) {
        return;
    }
    window.isUpdating = true;

    // Get form data
    const userId = document.getElementById('edit_user_id').value;
    const username = document.getElementById('edit_username').value.trim();
    const password = document.getElementById('edit_password').value;
    const role = document.getElementById('edit_role').value;

    // Store original values for comparison
    const originalUsername = document.getElementById('edit_username').defaultValue;
    const originalRole = document.getElementById('edit_role').defaultValue;

    // Basic client-side validation
    if (!username || username.length < 3) {
        showEditUserError('Username must be at least 3 characters');
        window.isUpdating = false;
        return;
    }

    if (username.length > 50) {
        showEditUserError('Username must be less than 50 characters');
        window.isUpdating = false;
        return;
    }

    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        showEditUserError('Username can only contain letters, numbers, and underscores');
        window.isUpdating = false;
        return;
    }

    // Validate password only if provided
    if (password && password.length < 6) {
        showEditUserError('Password must be at least 6 characters');
        window.isUpdating = false;
        return;
    }

    // Check if anything changed
    if (username === originalUsername && !password && role === originalRole) {
        showNotification('No changes were made to update.', 'info');
        closeEditModal();
        window.isUpdating = false;
        return;
    }

    // Show loading state
    const submitBtn = document.querySelector('#editModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;

    // Create form data
    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('username', username);
    formData.append('password', password);
    formData.append('user_role', role);

    // Send AJAX request
    fetch('../../view/users/user-update.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Build success message based on what was changed
                let changes = [];
                if (username !== originalUsername) {
                    changes.push('username');
                }
                if (password) {
                    changes.push('password');
                }
                if (role !== originalRole) {
                    changes.push('role');
                }

                let message = 'User updated successfully!';
                if (changes.length > 0) {
                    message = `User updated: ${changes.join(', ')} changed.`;
                }

                // Show success notification
                showNotification(message, 'success');

                // Close modal
                closeEditModal();

                // RELOAD THE PAGE to show the updated user
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // Show error message
                if (data.errors) {
                    showEditUserError(data.errors.join('\n'));
                } else {
                    showEditUserError(data.message || 'Failed to update user');
                }

                // Reset button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                window.isUpdating = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showEditUserError('Error: ' + error.message);

            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            window.isUpdating = false;
        });
}

// Helper function to show error in edit user modal
function showEditUserError(message) {
    // Remove any existing error messages first
    const existingError = document.getElementById('editUserError');
    if (existingError) {
        existingError.remove();
    }

    let errorDiv = document.createElement('div');
    errorDiv.id = 'editUserError';
    errorDiv.style.cssText = `
        background: #fee2e2;
        color: #991b1b;
        padding: 1rem;
        border-radius: 10px;
        margin-bottom: 1rem;
        font-size: 0.875rem;
        border: 1px solid #fecaca;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    `;

    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;

    const modalBody = document.querySelector('#editModal .modal-body');
    modalBody.insertBefore(errorDiv, modalBody.firstChild);
}

// Close modal when clicking outside
window.addEventListener('click', function (event) {
    if (event.target.classList.contains('modal')) {
        if (event.target.id === 'editModal') {
            closeEditModal();
        }
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('editModal');
        if (modal && modal.classList.contains('active')) {
            closeEditModal();
        }
    }
});

// Initialize on page load - ONLY ONCE
document.addEventListener('DOMContentLoaded', function () {
    console.log('User Update initialized');

    // Remove any existing event listeners by cloning and replacing the form
    const editUserForm = document.querySelector('#editModal form');
    if (editUserForm) {
        // Remove all existing event listeners
        const newForm = editUserForm.cloneNode(true);
        editUserForm.parentNode.replaceChild(newForm, editUserForm);

        // Attach fresh event listener
        newForm.addEventListener('submit', updateUser);
    }
});

// Add animations if not already present
if (!document.querySelector('#notificationStyles')) {
    const style = document.createElement('style');
    style.id = 'notificationStyles';
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
}

// Export functions for global use
window.openEditModal = openEditModal;
window.closeEditModal = closeEditModal;
window.updateUser = updateUser;