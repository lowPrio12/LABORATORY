// Chart instances
let dailyChart = null;
let hourlyChart = null;
let batchStatusChart = null;
let userRoleChart = null;
let reportChart = null;
let currentChartType = 'line';
let globalChartData = null;

// Initialize dashboard with data from PHP
function initializeDashboard(data) {
    console.log('initializeDashboard called with data:', data);
    globalChartData = data;
    setupEventListeners();
    updateAllTimestamps();
    startAutoRefresh();
    
    // Initialize charts for the active tab only
    const activeTab = document.querySelector('.tab-pane.active');
    console.log('Active tab:', activeTab ? activeTab.id : 'none');
    
    if (activeTab && activeTab.id === 'analyticsTab') {
        initializeAnalyticsCharts();
    } else if (activeTab && activeTab.id === 'reportsTab') {
        initializeReportCharts();
    }
}

// Setup event listeners
function setupEventListeners() {
    // Mobile menu toggle
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    }
    
    // Date range handler
    const dateRangeSelect = document.getElementById('dateRange');
    if (dateRangeSelect) {
        dateRangeSelect.addEventListener('change', function() {
            const customRange = document.getElementById('customDateRange');
            if (customRange) {
                customRange.style.display = this.value === 'custom' ? 'flex' : 'none';
            }
        });
    }
}

// Initialize Analytics Charts
function initializeAnalyticsCharts() {
    console.log('initializeAnalyticsCharts called');
    if (!globalChartData) {
        console.error('No chart data available');
        return;
    }
    
    setTimeout(() => {
        initializeDailyChart();
        initializeHourlyChart();
        initializeBatchStatusChart();
        initializeUserRoleChart();
    }, 100);
}

// Initialize Report Charts
function initializeReportCharts() {
    console.log('initializeReportCharts called');
    if (!globalChartData) {
        console.error('No chart data available');
        return;
    }
    
    setTimeout(() => {
        initializeReportChart();
    }, 100);
}

// Tab switching function (available globally)
window.switchTab = function(tabName) {
    console.log('Switching to tab:', tabName);
    
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
    
    // Update active states in sidebar
    document.querySelectorAll('.sidebar li').forEach(li => {
        li.classList.remove('active');
    });
    
    // Find and activate the correct sidebar item
    const sidebarItems = document.querySelectorAll('.sidebar li');
    if (tabName === 'dashboard') sidebarItems[0].classList.add('active');
    if (tabName === 'analytics') sidebarItems[2].classList.add('active');
    if (tabName === 'reports') sidebarItems[3].classList.add('active');
    
    // Update tab panes
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.remove('active');
    });
    const targetTab = document.getElementById(tabName + 'Tab');
    if (targetTab) {
        targetTab.classList.add('active');
    } else {
        console.error('Tab not found:', tabName + 'Tab');
    }
    
    // Update page title
    const titles = {
        dashboard: { title: 'Dashboard', subtitle: 'System overview and key metrics', icon: 'chart-pie' },
        analytics: { title: 'Analytics', subtitle: 'Comprehensive system analytics and insights', icon: 'chart-line' },
        reports: { title: 'Reports', subtitle: 'Generate and export detailed reports', icon: 'file-alt' }
    };
    const pageTitle = document.getElementById('pageTitle');
    const pageSubtitle = document.getElementById('pageSubtitle');
    if (pageTitle) pageTitle.innerText = titles[tabName].title;
    if (pageSubtitle) pageSubtitle.innerHTML = `<i class="fas fa-${titles[tabName].icon}"></i> ${titles[tabName].subtitle}`;
    
    // Initialize charts for the newly active tab
    if (tabName === 'analytics') {
        initializeAnalyticsCharts();
    } else if (tabName === 'reports') {
        initializeReportCharts();
    }
    
    // Resize charts if they exist
    setTimeout(() => {
        if (tabName === 'analytics') {
            if (dailyChart) { dailyChart.resize(); console.log('Daily chart resized'); }
            if (hourlyChart) { hourlyChart.resize(); console.log('Hourly chart resized'); }
            if (batchStatusChart) { batchStatusChart.resize(); console.log('Batch status chart resized'); }
            if (userRoleChart) { userRoleChart.resize(); console.log('User role chart resized'); }
        } else if (tabName === 'reports') {
            if (reportChart) { reportChart.resize(); console.log('Report chart resized'); }
        }
    }, 200);
    
    // Close mobile sidebar if open
    const sidebar = document.getElementById('sidebar');
    if (sidebar) sidebar.classList.remove('active');
};

// Initialize Daily Chart
function initializeDailyChart() {
    const canvas = document.getElementById('dailyActivityChart');
    if (!canvas) {
        console.log('Daily chart canvas not found');
        return;
    }
    
    console.log('Initializing Daily Chart with data:', globalChartData.activityTrend);
    
    const ctx = canvas.getContext('2d');
    if (dailyChart) {
        dailyChart.destroy();
        dailyChart = null;
    }

    dailyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: globalChartData.dates,
            datasets: [{
                label: 'Total Activities',
                data: globalChartData.activityTrend,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#10b981',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.4,
                fill: true
            }, {
                label: 'Unique Users',
                data: globalChartData.uniqueUsers,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 2,
                borderDash: [5, 5],
                pointRadius: 3,
                tension: 0.4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 6 } },
                tooltip: { backgroundColor: '#1e293b', titleColor: '#f8fafc', bodyColor: '#cbd5e1', padding: 12, cornerRadius: 8 }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, title: { display: true, text: 'Total Activities' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Unique Users' } }
            }
        }
    });
    console.log('Daily chart initialized successfully');
}

// Initialize Hourly Chart
function initializeHourlyChart() {
    const canvas = document.getElementById('hourlyActivityChart');
    if (!canvas) {
        console.log('Hourly chart canvas not found');
        return;
    }
    
    console.log('Initializing Hourly Chart');
    const ctx = canvas.getContext('2d');
    if (hourlyChart) {
        hourlyChart.destroy();
        hourlyChart = null;
    }

    const hourLabels = Array.from({ length: 24 }, (_, i) => `${String(i).padStart(2, '0')}:00`);

    hourlyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hourLabels,
            datasets: [{
                label: 'Activities',
                data: globalChartData.hourlyActivity,
                backgroundColor: (context) => {
                    const value = context.dataset.data[context.dataIndex];
                    const max = Math.max(...context.dataset.data);
                    const opacity = 0.3 + (value / max) * 0.7;
                    return `rgba(16, 185, 129, ${opacity})`;
                },
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display: false }, 
                tooltip: { callbacks: { title: (context) => `Hour: ${context[0].label}` } } 
            },
            scales: { 
                y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, 
                x: { grid: { display: false } } 
            }
        }
    });
    console.log('Hourly chart initialized successfully');
}

// Initialize Batch Status Chart
function initializeBatchStatusChart() {
    const canvas = document.getElementById('batchStatusChart');
    if (!canvas) {
        console.log('Batch status chart canvas not found');
        return;
    }
    
    console.log('Initializing Batch Status Chart');
    const ctx = canvas.getContext('2d');
    if (batchStatusChart) {
        batchStatusChart.destroy();
        batchStatusChart = null;
    }

    batchStatusChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Incubating', 'Complete'],
            datasets: [{
                data: [globalChartData.batchStatus.incubating, globalChartData.batchStatus.complete],
                backgroundColor: ['#10b981', '#3b82f6'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw} batches` } }
            }
        }
    });
    console.log('Batch status chart initialized successfully');
}

// Initialize User Role Chart
function initializeUserRoleChart() {
    const canvas = document.getElementById('userRoleChart');
    if (!canvas) {
        console.log('User role chart canvas not found');
        return;
    }
    
    console.log('Initializing User Role Chart');
    const ctx = canvas.getContext('2d');
    if (userRoleChart) {
        userRoleChart.destroy();
        userRoleChart = null;
    }

    userRoleChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['Admin', 'Manager', 'User'],
            datasets: [{
                data: [globalChartData.userRoles.admin, globalChartData.userRoles.manager, globalChartData.userRoles.user],
                backgroundColor: ['#ef4444', '#f59e0b', '#10b981'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw} users` } }
            }
        }
    });
    console.log('User role chart initialized successfully');
}

// Initialize Report Chart
function initializeReportChart() {
    const canvas = document.getElementById('reportChart');
    if (!canvas) {
        console.log('Report chart canvas not found');
        return;
    }
    
    console.log('Initializing Report Chart');
    const ctx = canvas.getContext('2d');
    if (reportChart) {
        reportChart.destroy();
        reportChart = null;
    }

    reportChart = new Chart(ctx, {
        type: currentChartType,
        data: {
            labels: globalChartData.dates,
            datasets: [{
                label: 'Activities',
                data: globalChartData.activityTrend,
                borderColor: '#10b981',
                backgroundColor: currentChartType === 'line' ? 'rgba(16, 185, 129, 0.1)' : '#10b981',
                borderWidth: 2,
                tension: 0.4,
                fill: currentChartType === 'line'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } }
        }
    });
    console.log('Report chart initialized successfully');
}

// Chart toggle functions (available globally)
window.toggleChartType = function(type) {
    if (dailyChart) {
        const newType = dailyChart.config.type === 'line' ? 'bar' : 'line';
        dailyChart.config.type = newType;
        dailyChart.update();
        const btn = event.currentTarget;
        const icon = btn.querySelector('i');
        if (icon) icon.className = newType === 'line' ? 'fas fa-chart-bar' : 'fas fa-chart-line';
    }
};

window.toggleReportChartType = function() {
    currentChartType = currentChartType === 'line' ? 'bar' : 'line';
    if (reportChart) {
        reportChart.config.type = currentChartType;
        reportChart.data.datasets[0].backgroundColor = currentChartType === 'line' ? 'rgba(16, 185, 129, 0.1)' : '#10b981';
        reportChart.data.datasets[0].fill = currentChartType === 'line';
        reportChart.update();
    }
};

// Refresh functions (available globally)
window.refreshLogs = function() {
    const refreshBtn = event.currentTarget;
    const originalHtml = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<div class="spinner"></div> Refreshing...';
    refreshBtn.disabled = true;

    setTimeout(() => {
        location.reload();
    }, 500);
};

window.refreshTopActions = function() {
    const refreshBtn = event.currentTarget;
    const originalHtml = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<div class="spinner"></div> Refreshing...';
    refreshBtn.disabled = true;

    setTimeout(() => {
        location.reload();
    }, 500);
};

// Formatting functions
function formatTimeAgo(datetime) {
    if (!datetime) return 'Never';
    const date = new Date(datetime.replace(' ', 'T'));
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} minute${Math.floor(diff / 60) !== 1 ? 's' : ''} ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} hour${Math.floor(diff / 3600) !== 1 ? 's' : ''} ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)} day${Math.floor(diff / 86400) !== 1 ? 's' : ''} ago`;
    return date.toLocaleDateString();
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Export functions (available globally)
window.exportTopActions = function() {
    showToast('Exporting top actions...');
    setTimeout(() => showToast('Export complete'), 1000);
};

window.exportTopUsers = function() {
    showToast('Exporting top users...');
    setTimeout(() => showToast('Export complete'), 1000);
};

// Report functions (available globally)
window.generateReport = function() {
    const reportType = document.getElementById('reportType').value;
    let title = '';
    switch(reportType) {
        case 'activity': title = 'Activity Report'; break;
        case 'users': title = 'User Report'; break;
        case 'batches': title = 'Batches Report'; break;
        case 'performance': title = 'Performance Report'; break;
    }
    
    const chartTitle = document.getElementById('reportChartTitle');
    const tableTitle = document.getElementById('reportTableTitle');
    if (chartTitle) chartTitle.innerHTML = title;
    if (tableTitle) tableTitle.innerHTML = title + ' Details';
    
    showToast(`Generating ${title}...`);
    setTimeout(() => showToast('Report generated successfully'), 1000);
};

window.exportReport = function() {
    showToast('Exporting report...');
    setTimeout(() => showToast('Report exported successfully'), 1000);
};

window.printReport = function() {
    window.print();
};

// Update timestamps
function updateAllTimestamps() {
    document.querySelectorAll('.activity-time[data-timestamp]').forEach(el => {
        const timestamp = el.dataset.timestamp;
        if (timestamp) {
            el.textContent = formatTimeAgo(timestamp);
        }
    });
}

// Auto-refresh timestamps every 30 seconds
function startAutoRefresh() {
    setInterval(updateAllTimestamps, 30000);
}

console.log('main_dashboard.js loaded successfully');