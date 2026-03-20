// Manager Dashboard JS

function openAddUserModal() {
    document.getElementById('addUserModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('addUserForm').reset();
    const errorDiv = document.getElementById('addUserError');
    if (errorDiv) errorDiv.style.display = 'none';
}

function closeAddUserModal() {
    document.getElementById('addUserModal').classList.remove('active');
    document.body.style.overflow = '';
}

function createUser(event) {
    event.preventDefault();

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;

    if (!username || username.length < 3) {
        showAddUserError('Username must be at least 3 characters');
        return;
    }
    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        showAddUserError('Username can only contain letters, numbers, and underscores');
        return;
    }
    if (!password || password.length < 6) {
        showAddUserError('Password must be at least 6 characters');
        return;
    }

    const submitBtn = document.querySelector('#addUserForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    submitBtn.disabled = true;

    const formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);
    formData.append('user_role', 'user');

    fetch('../../controller/manager/user-add.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('User created successfully!', 'success');
                closeAddUserModal();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                if (data.errors) showAddUserError(data.errors.join('\n'));
                else showAddUserError(data.message || 'Failed to create user');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error(error);
            showAddUserError('Network error occurred');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
}

function showAddUserError(message) {
    let errorDiv = document.getElementById('addUserError');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'addUserError';
        errorDiv.style.cssText = 'background:#fee2e2;color:#991b1b;padding:1rem;border-radius:10px;margin-bottom:1rem;';
        const modalBody = document.querySelector('#addUserModal .modal-body');
        modalBody.insertBefore(errorDiv, modalBody.firstChild);
    }
    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    errorDiv.style.display = 'block';
    setTimeout(() => { if (errorDiv) errorDiv.style.display = 'none'; }, 5000);
}

function showNotification(message, type = 'success') {
    let container = document.getElementById('notificationContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notificationContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999';
        document.body.appendChild(container);
    }

    const notification = document.createElement('div');
    notification.className = 'notification-item';
    notification.style.cssText = `
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
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
    `;
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
        <i class="fas fa-times" style="cursor:pointer;margin-left:auto" onclick="this.parentElement.remove()"></i>
    `;
    container.appendChild(notification);
    setTimeout(() => notification.remove(), 4000);
}

// Modal close on click outside
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) closeAddUserModal();
});

// Close on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAddUserModal();
});

// Add CSS animations
if (!document.querySelector('#managerStyles')) {
    const style = document.createElement('style');
    style.id = 'managerStyles';
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}