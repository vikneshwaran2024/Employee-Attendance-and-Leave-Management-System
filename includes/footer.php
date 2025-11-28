</div> <!-- End of container from header -->

    <footer class="bg-dark text-white py-3 mt-5">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date("Y"); ?> Employee Attendance & Leave Management System</p>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Custom JS -->
    <script src="js/script.js"></script>
    
    <!-- Page-specific scripts -->
    <?php if(basename($_SERVER['PHP_SELF']) === 'attendance.php'): ?>
    <script>
    // Attendance page specific scripts
    $(document).ready(function() {
        // Calculate working days between two dates excluding weekends
        function calculateWorkingDays(startDate, endDate) {
            // Convert input strings to dates
            let start = new Date(startDate);
            let end = new Date(endDate);
            
            // Initial count and check for invalid dates
            let count = 0;
            
            // Exit if invalid dates
            if(isNaN(start.getTime()) || isNaN(end.getTime())) {
                return 0;
            }
            
            // Clone date to avoid modifying the original date
            let current = new Date(start);
            
            // Set hours to avoid time zone issues
            current.setHours(0, 0, 0, 0);
            end.setHours(0, 0, 0, 0);
            
            // Loop through days
            while(current <= end) {
                // Check if it's not a weekend (0 = Sunday, 6 = Saturday)
                const dayOfWeek = current.getDay();
                if(dayOfWeek !== 0 && dayOfWeek !== 6) {
                    count++;
                }
                
                // Move to next day
                current.setDate(current.getDate() + 1);
            }
            
            return count;
        }
        
        // Update days count display
        function updateDaysCount() {
            const startDate = $('#leave-start-date').val();
            const endDate = $('#leave-end-date').val();
            const halfDay = $('#half_day').is(':checked');
            
            if(startDate && endDate) {
                let days = calculateWorkingDays(startDate, endDate);
                
                // Adjust for half day
                if(halfDay && days > 0) {
                    days -= 0.5;
                }
                
                $('#days-count').text(days + ' day(s)');
                
                // Enable/disable submit button based on date validation
                if(new Date(startDate) > new Date(endDate)) {
                    $('#leave-submit-btn').prop('disabled', true);
                    $('#date-error-msg').text('End date must be after start date').show();
                } else {
                    $('#leave-submit-btn').prop('disabled', false);
                    $('#date-error-msg').hide();
                }
            } else {
                $('#days-count').text('0 day(s)');
            }
        }
        
        // Attach event listeners
        $('#leave-start-date, #leave-end-date').change(updateDaysCount);
        $('#half_day').change(function() {
            // Only allow half-day for single day leave
            const startDate = $('#leave-start-date').val();
            const endDate = $('#leave-end-date').val();
            
            if(startDate && endDate && startDate !== endDate) {
                $(this).prop('checked', false);
                alert('Half day option is only available for single day leave requests.');
            }
            updateDaysCount();
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>