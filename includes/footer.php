<?php
/**
 * Footer Include - Common footer for all pages
 */
?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/js/app.js"></script>
    
    <?php if (isset($additionalJS)): ?>
    <?php echo $additionalJS; ?>
    <?php endif; ?>
</body>
</html>
