/**
 * Employee Attendance and Leave Management System
 * Main JavaScript File
 */

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeTimeDisplay();
    initializeSidebar();
    initializeTooltips();
    initializeAlerts();
});

/**
 * Initialize real-time clock display
 */
function initializeTimeDisplay() {
    const timeElement = document.getElementById('current-time');
    const dateElement = document.getElementById('current-date');
    
    if (timeElement) {
        updateTimeDisplay();
        setInterval(updateTimeDisplay, 1000);
    }
    
    function updateTimeDisplay() {
        const now = new Date();
        
        if (timeElement) {
            timeElement.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
        }
        
        if (dateElement) {
            dateElement.textContent = now.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
    }
}

/**
 * Initialize sidebar toggle functionality
 */
function initializeSidebar() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth < 768) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });
    }
}

/**
 * Initialize Bootstrap tooltips
 */
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

/**
 * Initialize auto-dismissing alerts
 */
function initializeAlerts() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
}

/**
 * Check-in function
 */
function checkIn() {
    showLoading();
    
    fetch('api/attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'check_in'
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showAlert('success', 'Checked in successfully at ' + data.time);
            location.reload();
        } else {
            showAlert('danger', data.message || 'Failed to check in');
        }
    })
    .catch(error => {
        hideLoading();
        showAlert('danger', 'An error occurred. Please try again.');
        console.error('Error:', error);
    });
}

/**
 * Check-out function
 */
function checkOut() {
    showLoading();
    
    fetch('api/attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'check_out'
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showAlert('success', 'Checked out successfully. Total work hours: ' + data.work_hours);
            location.reload();
        } else {
            showAlert('danger', data.message || 'Failed to check out');
        }
    })
    .catch(error => {
        hideLoading();
        showAlert('danger', 'An error occurred. Please try again.');
        console.error('Error:', error);
    });
}

/**
 * Show loading spinner
 */
function showLoading() {
    const spinner = document.querySelector('.loading-spinner');
    if (spinner) {
        spinner.classList.add('show');
    }
}

/**
 * Hide loading spinner
 */
function hideLoading() {
    const spinner = document.querySelector('.loading-spinner');
    if (spinner) {
        spinner.classList.remove('show');
    }
}

/**
 * Show alert message
 */
function showAlert(type, message) {
    const alertContainer = document.getElementById('alert-container');
    if (!alertContainer) return;
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.setAttribute('role', 'alert');
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    alertContainer.appendChild(alertDiv);
    
    // Auto dismiss after 5 seconds
    setTimeout(function() {
        if (alertDiv.parentNode) {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        }
    }, 5000);
}

/**
 * Confirm action dialog
 */
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

/**
 * Format date for display
 */
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

/**
 * Format time for display
 */
function formatTime(timeString) {
    if (!timeString) return '-';
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
}

/**
 * Leave request form validation
 */
function validateLeaveForm() {
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    const reason = document.getElementById('reason');
    
    if (startDate && endDate) {
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        
        if (end < start) {
            showAlert('danger', 'End date cannot be before start date');
            return false;
        }
        
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (start < today) {
            showAlert('danger', 'Start date cannot be in the past');
            return false;
        }
    }
    
    if (reason && reason.value.trim().length < 10) {
        showAlert('danger', 'Please provide a detailed reason (at least 10 characters)');
        return false;
    }
    
    return true;
}

/**
 * Calculate leave days between dates
 */
function calculateLeaveDays() {
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    const daysCount = document.getElementById('days_count');
    
    if (startDate && endDate && daysCount && startDate.value && endDate.value) {
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        
        if (end >= start) {
            let count = 0;
            let current = new Date(start);
            
            while (current <= end) {
                const dayOfWeek = current.getDay();
                if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                    count++;
                }
                current.setDate(current.getDate() + 1);
            }
            
            daysCount.value = count;
        }
    }
}

/**
 * Delete confirmation
 */
function confirmDelete(formId, itemName) {
    if (confirm(`Are you sure you want to delete this ${itemName}? This action cannot be undone.`)) {
        document.getElementById(formId).submit();
    }
}

/**
 * Print page content
 */
function printPage() {
    window.print();
}

/**
 * Export table to CSV
 */
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(function(row) {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(function(col) {
            let text = col.innerText.replace(/"/g, '""');
            rowData.push('"' + text + '"');
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    link.href = URL.createObjectURL(blob);
    link.download = filename || 'export.csv';
    link.style.display = 'none';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Toggle password visibility
 */
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.querySelector(`#${inputId} + .input-group-text i`);
    
    if (input) {
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            if (icon) icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
}

/**
 * Form submit with loading
 */
function submitFormWithLoading(formId) {
    showLoading();
    document.getElementById(formId).submit();
}

/**
 * Filter table rows
 */
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    const filter = input.value.toLowerCase();
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        let found = false;
        
        for (let j = 0; j < cells.length; j++) {
            if (cells[j].innerText.toLowerCase().includes(filter)) {
                found = true;
                break;
            }
        }
        
        rows[i].style.display = found ? '' : 'none';
    }
}
