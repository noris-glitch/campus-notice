<style>
    /* Floating Help Button */
    .help-float {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 1000;
        transition: all 0.3s ease;
        border: none;
        font-size: 24px;
        color: white;
    }

    .help-float:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(0,0,0,0.4);
    }

    .help-float:active {
        transform: scale(0.95);
    }

    /* Help Modal */
    .help-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        z-index: 1001;
        animation: fadeIn 0.3s ease;
    }

    .help-modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .help-modal-content {
        background: white;
        border-radius: 12px;
        max-width: 500px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        animation: slideUp 0.3s ease;
    }

    .help-modal-header {
        padding: 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .help-modal-header h3 {
        margin: 0;
        color: #333;
    }

    .help-modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #666;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background 0.2s ease;
    }

    .help-modal-close:hover {
        background: #f5f5f5;
        color: #333;
    }

    .help-modal-body {
        padding: 20px;
    }

    .help-quick-links {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 20px;
    }

    .help-link {
        display: block;
        padding: 12px;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        text-decoration: none;
        color: #495057;
        text-align: center;
        transition: all 0.2s ease;
        font-size: 14px;
    }

    .help-link:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
        transform: translateY(-2px);
    }

    .help-contact {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .help-contact h4 {
        margin: 0 0 10px 0;
        color: #333;
    }

    .help-contact p {
        margin: 5px 0;
        font-size: 14px;
        color: #666;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideUp {
        from { transform: translateY(30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    @media (max-width: 768px) {
        .help-float {
            bottom: 15px;
            right: 15px;
            width: 50px;
            height: 50px;
            font-size: 20px;
        }

        .help-modal-content {
            width: 95%;
            margin: 10px;
        }

        .help-quick-links {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Floating Help Button -->
<?php
// Calculate path from includes directory to root
$includes_path = __DIR__; // c:\xampp\htdocs\campus_notice\includes
$root_path = dirname($includes_path); // c:\xampp\htdocs\campus_notice
$current_script_dir = dirname($_SERVER['SCRIPT_FILENAME']);

// Calculate relative path from current script to root
$relative_path = '';
if ($current_script_dir !== $root_path) {
    $relative_path = '../';
}
?>
<button class="help-float" onclick="openHelpModal()" title="Help & Support">
    ❓
</button>

<!-- Help Modal -->
<div id="helpModal" class="help-modal">
    <div class="help-modal-content">
        <div class="help-modal-header">
            <h3>❓ Help & Support</h3>
            <button class="help-modal-close" onclick="closeHelpModal()">×</button>
        </div>
        <div class="help-modal-body">
            <div class="help-quick-links">
                <a href="<?php echo $relative_path; ?>help.php" class="help-link">📚 Full Help Guide</a>
                <a href="mailto:tech.support@jooust.ac.ke" class="help-link">📧 Email Support</a>
                <a href="tel:+254572058000" class="help-link">📞 Call Support</a>
                <a href="<?php echo $relative_path; ?>user/profile.php" class="help-link">👤 Update Profile</a>
            </div>

            <div class="help-contact">
                <h4>Quick Help</h4>
                <p><strong>Need immediate help?</strong></p>
                <p>• Check the <a href="<?php echo $relative_path; ?>help.php" style="color: #667eea;">full help guide</a> for detailed instructions</p>
                <p>• Contact technical support for system issues</p>
                <p>• Use emergency alerts for urgent campus notifications</p>
                <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: 12px;">
                    <p><strong>JOOUST Contact:</strong></p>
                    <p>📧 information@jooust.ac.ke</p>
                    <p>📞 057-2058000 / 057-2501804</p>
                    <p>📍 Bondo-Usenge Road, Bondo, Kenya</p>
                </div>
                <p style="margin-top: 10px; font-size: 12px; color: #888;">
                    JOOUST Campus Notice System v1.0
                </p>
            </div>
        </div>
    </div>
</div>

<script>
    // Help modal functions
    function openHelpModal() {
        const modal = document.getElementById('helpModal');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
    }

    function closeHelpModal() {
        const modal = document.getElementById('helpModal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = ''; // Restore scrolling
        }
    }

    // Initialize help modal when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('helpModal');
        if (modal) {
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeHelpModal();
                }
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeHelpModal();
                }
            });
        }
    });
</script>