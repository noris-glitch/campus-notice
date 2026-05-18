<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Help & Support - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .help-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .help-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .help-section h3 {
            color: #333;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .help-section h4 {
            color: #555;
            margin: 15px 0 10px 0;
        }

        .help-section ul {
            margin: 10px 0;
            padding-left: 20px;
        }

        .help-section li {
            margin: 8px 0;
            line-height: 1.5;
        }

        .contact-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .contact-info p {
            margin: 5px 0;
        }

        .faq-item {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 10px 0;
            overflow: hidden;
        }

        .faq-question {
            background: #f8f9fa;
            padding: 15px;
            cursor: pointer;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .faq-answer {
            padding: 0 15px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .faq-answer.show {
            max-height: 500px;
            padding: 15px;
        }

        .faq-toggle {
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .faq-toggle.rotate {
            transform: rotate(45deg);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="content-header">
                <h1>❓ Help & Support</h1>
                <p>Get help with using the JOOUST Campus Notice System</p>
            </div>

            <div class="help-container">
                <!-- Quick Start Guide -->
                <div class="help-section">
                    <h3>🚀 Quick Start Guide</h3>
                    <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'student'): ?>
                    <h4>For Students:</h4>
                    <ul>
                        <li><strong>Registration:</strong> Create an account using your student ID and email</li>
                        <li><strong>Viewing Notices:</strong> Check the Feed page for latest announcements</li>
                        <li><strong>Bookmarks:</strong> Save important notices for later reference</li>
                        <li><strong>Profile:</strong> Update your information and club memberships</li>
                        <li><strong>Notifications:</strong> Stay updated with personalized alerts</li>
                        <li><strong>Nearby Events:</strong> Find events near your location</li>
                        <li><strong>Event Map:</strong> View all events on an interactive map</li>
                    </ul>
                    <?php elseif(isset($_SESSION['role']) && $_SESSION['role'] == 'faculty'): ?>
                    <h4>For Faculty Members:</h4>
                    <ul>
                        <li><strong>Creating Notices:</strong> Post announcements for your faculty or specific groups</li>
                        <li><strong>Event Management:</strong> Create location-based events with maps</li>
                        <li><strong>Emergency Alerts:</strong> Send urgent notifications when needed</li>
                        <li><strong>Profile Management:</strong> Update your faculty information</li>
                    </ul>
                    <?php elseif(isset($_SESSION['role']) && $_SESSION['role'] == 'super_admin'): ?>
                    <h4>For Super Administrators:</h4>
                    <ul>
                        <li><strong>User Management:</strong> Approve faculty and club leader accounts</li>
                        <li><strong>System Analytics:</strong> Monitor usage and system performance</li>
                        <li><strong>Content Moderation:</strong> Manage all notices and users</li>
                        <li><strong>Emergency Alerts:</strong> Send campus-wide emergency notifications</li>
                        <li><strong>Notice Management:</strong> Create and manage all system notices</li>
                    </ul>
                    <?php else: ?>
                    <h4>General User Guide:</h4>
                    <ul>
                        <li><strong>Registration:</strong> Create an account to access the system</li>
                        <li><strong>Viewing Notices:</strong> Check announcements and events</li>
                        <li><strong>Profile:</strong> Update your personal information</li>
                        <li><strong>Support:</strong> Contact technical support for assistance</li>
                    </ul>
                    <?php endif; ?>
                </div>

                <!-- FAQ Section -->
                <div class="help-section">
                    <h3>❓ Frequently Asked Questions</h3>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I reset my password?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Click on "Forgot Password" on the login page and follow the instructions sent to your email.
                        </div>
                    </div>

                    <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'student'): ?>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I bookmark a notice?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            On the Feed page, click the bookmark icon (🔖) next to any notice you want to save.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I share my location?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Go to your Profile page and use the "Share Location" feature to update your current location for location-based notifications.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I view nearby events?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Use the "Nearby Events" page to see events near your shared location, or check the "Event Map" for all events.
                        </div>
                    </div>
                    <?php elseif(isset($_SESSION['role']) && $_SESSION['role'] == 'faculty'): ?>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I create a notice with location?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Faculty members can use the "Create Notice with Location" option to add map-based events with specific coordinates and radius.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I send an emergency alert?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Go to the Emergency Alert page and create an urgent notification that will be sent to all users immediately.
                        </div>
                    </div>
                    <?php elseif(isset($_SESSION['role']) && $_SESSION['role'] == 'super_admin'): ?>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I approve user accounts?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Go to "Manage Users" in the admin panel to approve pending faculty and club leader registrations.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I view system analytics?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Access the Analytics page to view user statistics, notice trends, and system performance metrics.
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            How do I get technical support?
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Contact technical support at tech.support@jooust.ac.ke or call the IT department using the contact information above.
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="help-section">
                    <h3>📞 Contact Support</h3>
                    <div class="contact-info">
                        <p><strong>Main Campus Address:</strong> Bondo-Usenge Road, Bondo, Kenya</p>
                        <p><strong>Postal Address:</strong> P.O. Box 210 - 40601 Bondo – Kenya</p>
                        <p><strong>General Telephone:</strong> 057-2058000 / 057-2501804</p>
                        <p><strong>Fax:</strong> 057 2523851</p>
                        <p><strong>Email:</strong> information@jooust.ac.ke / complaints@jooust.ac.ke</p>
                        <p><strong>Academic Affairs:</strong> 0704 314 648 or 057-2058 135 (academic@jooust.ac.ke)</p>
                        <p><strong>Kisumu Campus:</strong> 057 - 2022575 (kisumu@jooust.ac.ke)</p>
                        <p><strong>Technical Support:</strong> tech.support@jooust.ac.ke</p>
                    </div>
                    <p style="margin-top: 15px; color: #666;">
                        For urgent technical issues, please contact the IT department directly or use the emergency alert system if it's a campus-wide emergency.
                    </p>
                </div>

                <!-- System Information -->
                <div class="help-section">
                    <h3>ℹ️ System Information</h3>
                    <ul>
                        <li><strong>Version:</strong> Campus Notice System v1.0</li>
                        <li><strong>Last Updated:</strong> April 2026</li>
                        <li><strong>Browser Compatibility:</strong> Chrome, Firefox, Safari, Edge (latest versions)</li>
                        <li><strong>Mobile Support:</strong> Responsive design for tablets and smartphones</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const toggle = element.querySelector('.faq-toggle');

            answer.classList.toggle('show');
            toggle.classList.toggle('rotate');
            toggle.textContent = answer.classList.contains('show') ? '−' : '+';
        }
    </script>
</body>
</html>