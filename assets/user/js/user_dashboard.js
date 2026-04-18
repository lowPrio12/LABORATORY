// =========================================================
// dashboard.js  –  EggFlow User Dashboard
// Client-side functionality for batch management
// =========================================================

// Global configuration passed from PHP
const config = window.EggFlowConfig || {
    totalBalut: 0,
    totalChicks: 0,
    totalFailed: 0,
    incubating: 0,
    complete: 0,
    dailyAnalytics: [],
    batchRemaining: {},
    BALUT_UNLOCK: 14,
    CHICK_UNLOCK: 25,
};

// Initialize charts flag
let chartsInitialized = false;

// =========================================================
// Modal Functions
// =========================================================

function openAddModal() {
    const modal = document.getElementById('addModal');
    if (modal) modal.classList.add('active');
}

function openUpdateModal(eggId, dayNumber, remaining, lockBalutChick) {
    const updateEggId = document.getElementById('updateEggId');
    const modalDay = document.getElementById('modalDay');
    const remainingText = document.getElementById('remainingText');
    const failedInput = document.getElementById('failedInput');
    const balutInput = document.getElementById('balutInput');
    const chickInput = document.getElementById('chickInput');
    const submitBtn = document.getElementById('submitUpdateBtn');
    const validationMsg = document.getElementById('validationMsg');
    const lockNotice = document.getElementById('lockNotice');
    const balutGroup = document.getElementById('balutGroup');
    const chickGroup = document.getElementById('chickGroup');

    if (updateEggId) updateEggId.value = eggId;
    if (modalDay) modalDay.textContent = dayNumber;
    if (remainingText) remainingText.textContent = remaining + ' eggs remaining';
    if (failedInput) failedInput.value = 0;
    if (balutInput) balutInput.value = 0;
    if (chickInput) chickInput.value = 0;
    if (submitBtn) submitBtn.disabled = false;
    if (validationMsg) validationMsg.style.display = 'none';

    if (lockBalutChick === true || lockBalutChick === 'true') {
        if (lockNotice) lockNotice.style.display = 'flex';
        if (balutInput) balutInput.disabled = true;
        if (chickInput) chickInput.disabled = true;
        if (balutGroup) balutGroup.style.opacity = '0.45';
        if (chickGroup) chickGroup.style.opacity = '0.45';
    } else {
        if (lockNotice) lockNotice.style.display = 'none';
        if (balutInput) balutInput.disabled = false;
        if (chickInput) chickInput.disabled = false;
        if (balutGroup) balutGroup.style.opacity = '1';
        if (chickGroup) chickGroup.style.opacity = '1';
    }

    const modal = document.getElementById('updateModal');
    if (modal) modal.classList.add('active');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
}

// =========================================================
// Validation Functions
// =========================================================

function checkTotal() {
    const eggId = document.getElementById('updateEggId')?.value;
    const failed = parseInt(document.getElementById('failedInput')?.value) || 0;
    const balut = parseInt(document.getElementById('balutInput')?.value) || 0;
    const chick = parseInt(document.getElementById('chickInput')?.value) || 0;
    const total = failed + balut + chick;
    const remaining = (config.batchRemaining && eggId && config.batchRemaining[eggId]) ? config.batchRemaining[eggId] : 0;
    const validationMsg = document.getElementById('validationMsg');
    const submitBtn = document.getElementById('submitUpdateBtn');

    if (total > remaining) {
        if (validationMsg) {
            validationMsg.textContent = 'Total entered exceeds remaining eggs.';
            validationMsg.style.display = 'flex';
        }
        if (submitBtn) submitBtn.disabled = true;
    } else {
        if (validationMsg) validationMsg.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
    }
}

function validateUpdate() {
    const failed = parseInt(document.getElementById('failedInput')?.value) || 0;
    const balut = parseInt(document.getElementById('balutInput')?.value) || 0;
    const chick = parseInt(document.getElementById('chickInput')?.value) || 0;
    const validationMsg = document.getElementById('validationMsg');

    if (failed + balut + chick === 0) {
        if (validationMsg) {
            validationMsg.textContent = 'Enter at least one value greater than 0.';
            validationMsg.style.display = 'flex';
        }
        return false;
    }
    return true;
}

// =========================================================
// Tab Navigation
// =========================================================

function switchTab(name) {
    const titles = {
        overview: 'Overview of your incubation batches',
        batches: 'Manage all your egg batches',
        analytics: 'Your production analytics',
        guide: 'Incubation guide & reference',
    };

    // Update nav items
    document.querySelectorAll('.nav-item[data-tab]').forEach(li => {
        li.classList.toggle('active', li.dataset.tab === name);
    });

    // Update sections
    document.querySelectorAll('.tab-section').forEach(sec => {
        sec.classList.toggle('active', sec.id === name + '-section');
    });

    // Update subtitle
    const subtitle = document.getElementById('page-subtitle');
    if (subtitle) subtitle.textContent = titles[name] || '';

    // Initialize charts when analytics tab is opened
    if (name === 'analytics') initCharts();
}

// =========================================================
// Chart Initialization
// =========================================================

function initCharts() {
    if (chartsInitialized) return;
    if (typeof Chart === 'undefined') {
        // Chart.js not loaded yet, wait a bit
        setTimeout(initCharts, 500);
        return;
    }
    chartsInitialized = true;

    // Pie Chart - Outcome Distribution
    const pieEl = document.getElementById('pieChart');
    if (pieEl && (config.totalBalut + config.totalChicks + config.totalFailed) > 0) {
        new Chart(pieEl, {
            type: 'doughnut',
            data: {
                labels: ['Balut', 'Chicks', 'Failed'],
                datasets: [{
                    data: [config.totalBalut, config.totalChicks, config.totalFailed],
                    backgroundColor: ['#f59e0b', '#10b981', '#ef4444']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '65%'
            }
        });
    }

    // Bar Chart - Daily Production
    const dayEl = document.getElementById('dailyChart');
    if (dayEl && config.dailyAnalytics && config.dailyAnalytics.length) {
        new Chart(dayEl, {
            type: 'bar',
            data: {
                labels: config.dailyAnalytics.map(r => 'Day ' + r.day_number),
                datasets: [
                    {
                        label: 'Balut',
                        data: config.dailyAnalytics.map(r => parseInt(r.balut) || 0),
                        backgroundColor: 'rgba(245,158,11,0.75)',
                        borderRadius: 6
                    },
                    {
                        label: 'Chicks',
                        data: config.dailyAnalytics.map(r => parseInt(r.chicks) || 0),
                        backgroundColor: 'rgba(16,185,129,0.75)',
                        borderRadius: 6
                    },
                    {
                        label: 'Failed',
                        data: config.dailyAnalytics.map(r => parseInt(r.failed) || 0),
                        backgroundColor: 'rgba(239,68,68,0.75)',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Eggs'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Incubation Day'
                        }
                    }
                }
            }
        });
    }

    // Status Chart
    const statEl = document.getElementById('statusChart');
    if (statEl && (config.incubating + config.complete) > 0) {
        new Chart(statEl, {
            type: 'doughnut',
            data: {
                labels: ['Incubating', 'Complete'],
                datasets: [{
                    data: [config.incubating, config.complete],
                    backgroundColor: ['#f59e0b', '#10b981']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '60%'
            }
        });
    }
}

// =========================================================
// Event Listeners
// =========================================================

// Close modals when clicking outside
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});

// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => {
            if (el && el.parentNode) el.remove();
        }, 500);
    }, 5000);
});

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', () => {
    // Set up tab click handlers
    document.querySelectorAll('.nav-item[data-tab]').forEach(li => {
        const link = li.querySelector('a');
        if (link) {
            link.addEventListener('click', e => {
                e.preventDefault();
                switchTab(li.dataset.tab);
            });
        }
    });

    // Set active tab to overview
    switchTab('overview');
});

// =========================================================
// Sidebar Toggle for Mobile
// =========================================================

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

// Add hamburger menu button for mobile
document.addEventListener('DOMContentLoaded', () => {
    const topBar = document.querySelector('.top-bar');
    if (topBar && window.innerWidth <= 768) {
        const hamburgerBtn = document.createElement('button');
        hamburgerBtn.innerHTML = '<i class="fas fa-bars"></i>';
        hamburgerBtn.className = 'btn btn-secondary btn-sm';
        hamburgerBtn.style.marginRight = 'auto';
        hamburgerBtn.onclick = toggleSidebar;
        topBar.insertBefore(hamburgerBtn, topBar.firstChild);
    }

    // Close sidebar when clicking on a link (mobile)
    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar) sidebar.classList.remove('open');
            }
        });
    });
});