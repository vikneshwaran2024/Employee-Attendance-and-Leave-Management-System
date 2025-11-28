/**
 * Employee Attendance and Leave Management System
 * Main JavaScript file
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize searchable tables
    initializeSearchTables();

    // Initialize attendance tracking functions
    initializeAttendanceTracking();

    // Initialize leave request calculations
    initializeLeaveRequestForm();

    // Handle notifications
    initializeNotifications();
});

/**
 * Initialize search functionality for tables
 */
function initializeSearchTables() {
    const searchInputs = document.querySelectorAll('#search-input');
    
    searchInputs.forEach(input => {
        input.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const table = this.closest('.card').querySelector('.searchable-table');
            
            if (table) {
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        });
    });
}

/**
 * Initialize attendance tracking functionality
 */
function initializeAttendanceTracking() {
    const checkInBtn = document.getElementById('check-in-btn');
    const checkOutBtn = document.getElementById('check-out-btn');
    const alertContainer = document.getElementById('alert-container');
    
    if (checkInBtn) {
        checkInBtn.addEventListener('click', function() {
            // Disable button to prevent double clicks
            this.disabled = true;
            
            // Show loading state
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            
            // Send check-in API request
            fetch('/api/attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'check-in',
                    timestamp: new Date().toISOString()
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alertContainer.innerHTML = `
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            ${data.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    
                    // Update UI
                    document.getElementById('attendance-status').textContent = data.status;
                    document.getElementById('attendance-status').className = 'badge bg-success';
                    
                    // Enable check-out button
                    if (checkOutBtn) {
                        checkOutBtn.disabled = false;
                    }
                    
                    // Reload the page after a delay to refresh data
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    // Show error message
                    alertContainer.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            ${data.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    
                    // Reset button state
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-sign-in-alt"></i> Check In';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alertContainer.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        An error occurred. Please try again later.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                
                // Reset button state
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-sign-in-alt"></i> Check In';
            });
        });
    }
    
    if (checkOutBtn) {
        checkOutBtn.addEventListener('click', function() {
            // Confirm checkout
            if (!confirm('Are you sure you want to check out now?')) {
                return;
            }
            
            // Disable button to prevent double clicks
            this.disabled = true;
            
            // Show loading state
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            
            // Send check-out API request
            fetch('/api/attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'check-out',
                    timestamp: new Date().toISOString()
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alertContainer.innerHTML = `
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            ${data.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    
                    // Update UI
                    document.getElementById('attendance-status').textContent = data.status;
                    document.getElementById('attendance-status').className = 'badge bg-secondary';
                    
                    // Reload the page after a delay to refresh data
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    // Show error message
                    alertContainer.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            ${data.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    
                    // Reset button state
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-sign-out-alt"></i> Check Out';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alertContainer.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        An error occurred. Please try again later.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                
                // Reset button state
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-sign-out-alt"></i> Check Out';
            });
        });
    }
}

/**
 * Initialize leave request form functionality
 */
function initializeLeaveRequestForm() {
    const startDateInput = document.getElementById('leave-start-date');
    const endDateInput = document.getElementById('leave-end-date');
    const halfDayCheckbox = document.getElementById('half_day');
    const daysCountSpan = document.getElementById('days-count');
    const dateErrorMsg = document.getElementById('date-error-msg');
    const submitBtn = document.getElementById('leave-submit-btn');
    
    function updateDaysCount() {
        if (!startDateInput || !endDateInput || !daysCountSpan) return;
        
        const startDate = new Date(startDateInput.value);
        const endDate = new Date(endDateInput.value);
        
        dateErrorMsg.style.display = 'none';
        submitBtn.disabled = false;
        
        // Validate dates
        if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            daysCountSpan.textContent = '0 day(s)';
            return;
        }
        
        // Check if end date is before start date
        if (endDate < startDate) {
            dateErrorMsg.textContent = 'End date cannot be before start date';
            dateErrorMsg.style.display = 'block';
            daysCountSpan.textContent = '0 day(s)';
            submitBtn.disabled = true;
            return;
        }
        
        // Calculate number of days (including both start and end date)
        const timeDiff = endDate - startDate;
        const daysDiff = Math.floor(timeDiff / (1000 * 60 * 60 * 24)) + 1;
        
        // Count only working days (excluding weekends)
        let workingDays = 0;
        let currentDate = new Date(startDate);
        
        while (currentDate <= endDate) {
            const dayOfWeek = currentDate.getDay();
            // 0 = Sunday, 6 = Saturday
            if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                workingDays++;
            }
            
            currentDate.setDate(currentDate.getDate() + 1);
        }
        
        // Adjust for half-day option
        if (halfDayCheckbox && halfDayCheckbox.checked) {
            if (startDate.getTime() === endDate.getTime()) {
                workingDays -= 0.5;
            } else {
                // If date range is selected, half day option should be disabled
                halfDayCheckbox.checked = false;
            }
        }
        
        daysCountSpan.textContent = workingDays + ' day(s)';
        
        // Disable half-day checkbox if date range is selected
        if (halfDayCheckbox) {
            halfDayCheckbox.disabled = (daysDiff > 1);
        }
    }
    
    // Event listeners for date inputs
    if (startDateInput) {
        startDateInput.addEventListener('change', updateDaysCount);
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', updateDaysCount);
    }
    
    if (halfDayCheckbox) {
        halfDayCheckbox.addEventListener('change', updateDaysCount);
    }
    
    // Initialize days count on page load
    updateDaysCount();
}

/**
 * Initialize notifications functionality
 */
function initializeNotifications() {
    const notificationBell = document.getElementById('notification-bell');
    const notificationDropdown = document.getElementById('notification-dropdown');
    const notificationCount = document.getElementById('notification-count');
    const notificationList = document.getElementById('notification-list');
    const markAllReadBtn = document.getElementById('mark-all-read');
    
    if (notificationBell && notificationList) {
        // Fetch notifications when bell is clicked
        notificationBell.addEventListener('click', function(e) {
            e.preventDefault();
            
            fetch('/api/notifications.php?action=get')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update notification count
                        if (notificationCount) {
                            notificationCount.textContent = data.unread;
                            
                            if (data.unread > 0) {
                                notificationCount.style.display = 'inline-block';
                            } else {
                                notificationCount.style.display = 'none';
                            }
                        }
                        
                        // Update notification list
                        if (data.notifications.length > 0) {
                            notificationList.innerHTML = '';
                            
                            data.notifications.forEach(notification => {
                                const readClass = notification.is_read ? '' : 'unread-notification';
                                
                                notificationList.innerHTML += `
                                    <a href="#" class="dropdown-item ${readClass}" data-id="${notification.id}">
                                        <div class="d-flex align-items-center">
                                            <div class="notification-icon bg-primary text-white">
                                                <i class="fas fa-bell"></i>
                                            </div>
                                            <div class="notification-content">
                                                <p class="mb-1">${notification.message}</p>
                                                <small class="text-muted">${notification.time_ago}</small>
                                            </div>
                                        </div>
                                    </a>
                                `;
                            });
                            
                            // Add event listeners to notification items
                            document.querySelectorAll('#notification-list .dropdown-item').forEach(item => {
                                item.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    
                                    const notificationId = this.getAttribute('data-id');
                                    
                                    fetch('/api/notifications.php', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json'
                                        },
                                        body: JSON.stringify({
                                            action: 'read',
                                            id: notificationId
                                        })
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            this.classList.remove('unread-notification');
                                            
                                            // Update notification count
                                            if (notificationCount) {
                                                const currentCount = parseInt(notificationCount.textContent) - 1;
                                                notificationCount.textContent = currentCount;
                                                
                                                if (currentCount <= 0) {
                                                    notificationCount.style.display = 'none';
                                                }
                                            }
                                        }
                                    });
                                });
                            });
                        } else {
                            notificationList.innerHTML = '<div class="dropdown-item text-center">No notifications</div>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching notifications:', error);
                });
        });
        
        // Mark all notifications as read
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                fetch('/api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'read_all'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update UI
                        document.querySelectorAll('#notification-list .dropdown-item').forEach(item => {
                            item.classList.remove('unread-notification');
                        });
                        
                        // Reset notification count
                        if (notificationCount) {
                            notificationCount.textContent = '0';
                            notificationCount.style.display = 'none';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error marking all notifications as read:', error);
                });
            });
        }
    }
}

/**
 * Export attendance data to CSV
 */
function exportAttendance() {
    const table = document.getElementById('attendance-history-table');
    
    if (table) {
        // Headers
        let csv = [];
        const headers = [];
        const headerCells = table.querySelectorAll('thead th');
        
        headerCells.forEach(headerCell => {
            headers.push(`"${headerCell.textContent.trim()}"`);
        });
        
        csv.push(headers.join(','));
        
        // Rows
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const rowData = [];
            const cells = row.querySelectorAll('td');
            
            cells.forEach(cell => {
                rowData.push(`"${cell.textContent.trim().replace(/"/g, '""')}"`);
            });
            
            csv.push(rowData.join(','));
        });
        
        // Download CSV
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        
        link.setAttribute('href', url);
        link.setAttribute('download', `attendance_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

/**
 * Change attendance month display
 */
function changeMonth(direction) {
    const urlParams = new URLSearchParams(window.location.search);
    let month = parseInt(urlParams.get('month')) || new Date().getMonth() + 1; // Current month by default
    let year = parseInt(urlParams.get('year')) || new Date().getFullYear(); // Current year by default
    
    // Calculate new month and year
    month += direction;
    
    // Handle year change
    if (month < 1) {
        month = 12;
        year--;
    } else if (month > 12) {
        month = 1;
        year++;
    }
    
    // Redirect with new parameters
    window.location.href = `attendance.php?month=${month}&year=${year}`;
}