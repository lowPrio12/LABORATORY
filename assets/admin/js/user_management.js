// User Management JavaScript

// Filter users based on search and role
function filterUsers() {
    const searchInput = document.getElementById('searchInput');
    const roleFilter = document.getElementById('roleFilter');
    const table = document.getElementById('usersTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    const searchTerm = searchInput.value.toLowerCase();
    const roleValue = roleFilter.value;

    let visibleCount = 0;

    for (let row of rows) {
        const userName = row.getAttribute('data-name') || '';
        const userRole = row.getAttribute('data-role') || '';

        const matchesSearch = userName.includes(searchTerm);
        const matchesRole = roleValue === 'all' || userRole === roleValue;

        if (matchesSearch && matchesRole) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    }

    // Update table subtitle with visible count
    const subtitle = document.querySelector('.table-subtitle');
    if (subtitle) {
        subtitle.innerHTML = `<i class="fas fa-user-check"></i> ${visibleCount} users visible`;
    }
}

// Edit User Modal
function openEditModal(userId, username, role) {
    document.getElementById('edit_user_id').value = userId;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_password').value = '';

    document.getElementById('editModal').classList.add('active');

    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Add User Modal
function openAddUserModal() {
    document.getElementById('addModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Reset form
    document.getElementById('add_username').value = '';
    document.getElementById('add_password').value = '';
    document.getElementById('add_role').value = 'user';
}

function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
    document.body.style.overflow = '';
}

// View User Details Modal
function viewUserDetails(userId) {
    const user = userData.users.find(u => u.user_id == userId);

    if (!user) {
        console.error('User not found');
        return;
    }

    const detailsHtml = `
        <div class="user-details-grid">
            <div class="detail-item">
                <span class="detail-label">User ID</span>
                <span class="detail-value">${user.user_id}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Username</span>
                <span class="detail-value">${escapeHtml(user.username)}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Role</span>
                <span class="detail-value">
                    <span class="role-badge ${user.user_role}">
                        <i class="fas ${getRoleIconClass(user.user_role)}"></i>
                        ${capitalizeFirst(user.user_role)}
                    </span>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Joined</span>
                <span class="detail-value">${formatDate(user.created_at)}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Activity</span>
                <span class="detail-value">${user.activity_count} total actions</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Batches</span>
                <span class="detail-value">${user.batch_count} egg batches</span>
            </div>
        </div>
    `;

    document.getElementById('viewUserDetails').innerHTML = detailsHtml;
    document.getElementById('viewModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Confirm delete
function confirmDelete() {
    return confirm('⚠️ Are you sure you want to delete this user? This action cannot be undone.');
}

// Helper Functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function capitalizeFirst(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getRoleIconClass(role) {
    switch (role) {
        case 'admin': return 'fa-crown';
        case 'manager': return 'fa-user-tie';
        default: return 'fa-user';
    }
}

// Close modals when clicking outside
window.addEventListener('click', function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.active');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    console.log('User Management initialized');

    // Add animation to table rows
    const rows = document.querySelectorAll('#usersTable tbody tr');
    rows.forEach((row, index) => {
        row.style.animation = `fadeIn 0.3s ease ${index * 0.05}s forwards`;
        row.style.opacity = '0';
    });
});

// Add fadeIn animation
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(style);

// Export functions for global use
window.filterUsers = filterUsers;
window.openEditModal = openEditModal;
window.closeEditModal = closeEditModal;
window.openAddUserModal = openAddUserModal;
window.closeAddModal = closeAddModal;
window.viewUserDetails = viewUserDetails;
window.closeViewModal = closeViewModal;
window.confirmDelete = confirmDelete;