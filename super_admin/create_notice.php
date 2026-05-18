<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header('Location: ../login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$adminFaculty = $_SESSION['faculty_id'] ?? null;

$faculties = [];
try {
    if (featureTableExists($pdo, 'faculties')) {
        $faculties = $pdo->query('SELECT * FROM faculties ORDER BY name')->fetchAll();
    }
} catch (PDOException $e) {
    $faculties = [];
}

$adminFacultyName = '';
if ($adminFaculty) {
    $adminFacultyName = getFacultyName($pdo, (int) $adminFaculty);
}

$categories = getAvailableNoticeCategories();
$years = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
$priorities = [
    'normal' => 'Normal',
    'high' => 'High',
    'critical' => 'Critical',
];
$recurrenceOptions = [
    '' => 'One-time template',
    'weekly' => 'Weekly reminder',
    'monthly' => 'Monthly reminder',
    'semester' => 'Each semester',
];

$templates = fetchVisibleTemplates($pdo, $userId, $userRole);
$selectedTemplateId = isset($_GET['template_id']) ? (int) $_GET['template_id'] : 0;
$selectedTemplate = $selectedTemplateId ? getTemplateById($pdo, $selectedTemplateId) : null;

$formData = [
    'title' => $selectedTemplate['title'] ?? '',
    'content' => $selectedTemplate['content'] ?? '',
    'category' => $selectedTemplate['category'] ?? 'Academic',
    'faculty_target' => $selectedTemplate['faculty_target'] ?? ($userRole === 'admin' ? $adminFaculty : ''),
    'year_target' => $selectedTemplate['year_target'] ?? '',
    'schedule_date' => '',
    'expire_date' => '',
    'is_pinned' => !empty($selectedTemplate['is_pinned']) ? 1 : 0,
    'priority' => $selectedTemplate['default_priority'] ?? 'normal',
    'requires_acknowledgement' => !empty($selectedTemplate['requires_acknowledgement']) ? 1 : 0,
    'acknowledgement_due_at' => '',
    'delivery_channels' => normalizeDeliveryChannels($selectedTemplate['delivery_channels'] ?? ['in_app']),
    'save_as_template' => 0,
    'template_name' => $selectedTemplate['name'] ?? '',
    'is_recurring_template' => !empty($selectedTemplate['is_recurring']) ? 1 : 0,
    'recurrence_pattern' => $selectedTemplate['recurrence_pattern'] ?? '',
];

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'title' => trim($_POST['title'] ?? ''),
        'content' => trim($_POST['content'] ?? ''),
        'category' => $_POST['category'] ?? 'Academic',
        'faculty_target' => $_POST['faculty_target'] ?? '',
        'year_target' => $_POST['year_target'] ?? '',
        'schedule_date' => $_POST['schedule_date'] ?? '',
        'expire_date' => $_POST['expire_date'] ?? '',
        'is_pinned' => isset($_POST['is_pinned']) ? 1 : 0,
        'priority' => $_POST['priority'] ?? 'normal',
        'requires_acknowledgement' => isset($_POST['requires_acknowledgement']) ? 1 : 0,
        'acknowledgement_due_at' => $_POST['acknowledgement_due_at'] ?? '',
        'delivery_channels' => normalizeDeliveryChannels($_POST['delivery_channels'] ?? ['in_app']),
        'save_as_template' => isset($_POST['save_as_template']) ? 1 : 0,
        'template_name' => trim($_POST['template_name'] ?? ''),
        'is_recurring_template' => isset($_POST['is_recurring_template']) ? 1 : 0,
        'recurrence_pattern' => $_POST['recurrence_pattern'] ?? '',
    ];

    $submissionAction = $_POST['submission_action'] ?? ($userRole === 'super_admin' ? 'publish' : 'submit');
    $templateId = !empty($_POST['template_id']) ? (int) $_POST['template_id'] : null;

    if ($formData['title'] === '') {
        $errors[] = 'Notice title is required.';
    }
    if ($formData['content'] === '') {
        $errors[] = 'Notice content is required.';
    }
    if (strlen($formData['title']) > 200) {
        $errors[] = 'Title cannot exceed 200 characters.';
    }
    if (!in_array($formData['category'], $categories, true)) {
        $errors[] = 'Choose a valid category.';
    }
    if (!array_key_exists($formData['priority'], $priorities)) {
        $errors[] = 'Choose a valid priority.';
    }
    if ($formData['save_as_template'] && $formData['template_name'] === '') {
        $errors[] = 'Template name is required when saving a template.';
    }

    $publishAt = $formData['schedule_date'] !== '' ? date('Y-m-d H:i:s', strtotime($formData['schedule_date'])) : date('Y-m-d H:i:s');
    $expireAt = $formData['expire_date'] !== '' ? date('Y-m-d 23:59:59', strtotime($formData['expire_date'])) : null;
    $acknowledgementDueAt = $formData['acknowledgement_due_at'] !== '' ? date('Y-m-d H:i:s', strtotime($formData['acknowledgement_due_at'])) : null;
    $deliveryChannels = arrayToCsvValue($formData['delivery_channels']);
    $futurePublish = strtotime($publishAt) > time();

    if ($formData['requires_acknowledgement'] && $acknowledgementDueAt && strtotime($acknowledgementDueAt) < strtotime($publishAt)) {
        $errors[] = 'Acknowledgement deadline should be after the publish time.';
    }

    $attachment = null;
    if (isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        $filename = $_FILES['attachment']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX.';
        } else {
            $attachment = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
            $targetDir = '../assets/uploads/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            move_uploaded_file($_FILES['attachment']['tmp_name'], $targetDir . $attachment);
        }
    }

    if (empty($errors)) {
        $status = 'draft';
        $approvalStatus = 'draft';
        $reviewedBy = null;
        $reviewedAt = null;

        if ($submissionAction === 'submit' || $submissionAction === 'publish') {
            if ($userRole === 'super_admin') {
                $status = $futurePublish ? 'scheduled' : 'published';
                $approvalStatus = 'approved';
                $reviewedBy = $userId;
                $reviewedAt = date('Y-m-d H:i:s');
            } else {
                $status = 'pending_review';
                $approvalStatus = 'pending';
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO notices (
                title, content, category, priority, attachment, posted_by,
                faculty_target, year_target, is_pinned, requires_acknowledgement,
                acknowledgement_due_at, expire_at, publish_at, status, approval_status,
                reviewed_by, reviewed_at, delivery_channels, template_id, recurrence_pattern, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $formData['title'],
            $formData['content'],
            $formData['category'],
            $formData['priority'],
            $attachment,
            $userId,
            $formData['faculty_target'] !== '' ? $formData['faculty_target'] : null,
            $formData['year_target'] !== '' ? $formData['year_target'] : null,
            $formData['is_pinned'],
            $formData['requires_acknowledgement'],
            $acknowledgementDueAt,
            $expireAt,
            $publishAt,
            $status,
            $approvalStatus,
            $reviewedBy,
            $reviewedAt,
            $deliveryChannels,
            $templateId,
            $formData['is_recurring_template'] ? ($formData['recurrence_pattern'] ?: null) : null,
        ]);

        if ($result) {
            $noticeId = (int) $pdo->lastInsertId();
            $templateMessage = '';
            $deliverySummary = null;

            if ($formData['save_as_template']) {
                saveNoticeTemplate($pdo, [
                    'name' => $formData['template_name'],
                    'title' => $formData['title'],
                    'content' => $formData['content'],
                    'category' => $formData['category'],
                    'faculty_target' => $formData['faculty_target'],
                    'year_target' => $formData['year_target'],
                    'is_pinned' => $formData['is_pinned'],
                    'priority' => $formData['priority'],
                    'requires_acknowledgement' => $formData['requires_acknowledgement'],
                    'delivery_channels' => $formData['delivery_channels'],
                    'is_recurring' => $formData['is_recurring_template'],
                    'recurrence_pattern' => $formData['recurrence_pattern'],
                    'created_by' => $userId,
                ]);
                $templateMessage = ' Template saved for reuse.';
            }

            if ($status === 'published') {
                $deliverySummary = deliverNoticeToAudience($pdo, $noticeId);
            }

            if ($status === 'pending_review') {
                $success = 'Notice submitted for approval successfully.' . $templateMessage;
            } elseif ($status === 'scheduled') {
                $success = 'Notice approved and scheduled successfully.' . $templateMessage;
            } elseif ($status === 'published') {
                $success = 'Notice published successfully.' . $templateMessage;
                if ($deliverySummary) {
                    $success .= ' Delivered to ' . $deliverySummary['users'] . ' target students (' . $deliverySummary['in_app'] . ' in-app, ' . $deliverySummary['email_sent'] . ' email sent, ' . $deliverySummary['email_failed'] . ' email failed).';
                }
            } else {
                $success = 'Notice saved as draft successfully.' . $templateMessage;
            }

            logActivity($pdo, $userId, 'notice_created', 'Notice ID ' . $noticeId . ' created with status ' . $status);

            $templates = fetchVisibleTemplates($pdo, $userId, $userRole);
            $selectedTemplate = null;
            $selectedTemplateId = 0;
            $formData = [
                'title' => '',
                'content' => '',
                'category' => 'Academic',
                'faculty_target' => $userRole === 'admin' ? $adminFaculty : '',
                'year_target' => '',
                'schedule_date' => '',
                'expire_date' => '',
                'is_pinned' => 0,
                'priority' => 'normal',
                'requires_acknowledgement' => 0,
                'acknowledgement_due_at' => '',
                'delivery_channels' => ['in_app'],
                'save_as_template' => 0,
                'template_name' => '',
                'is_recurring_template' => 0,
                'recurrence_pattern' => '',
            ];
        } else {
            $errors[] = 'Failed to save the notice. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Notice - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .form-container,
        .template-panel {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .checkbox-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 14px;
            background: #fafbff;
        }

        .checkbox-card label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-weight: 500;
        }

        .checkbox-card input {
            width: auto;
        }

        .btn-submit,
        .btn-secondary,
        .btn-template {
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-template {
            background: #0f766e;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .char-count,
        .info-text {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
        }

        .template-meta,
        .feature-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .tag {
            display: inline-block;
            background: #eef2ff;
            color: #4338ca;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .faculty-badge {
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-left: 10px;
        }

        .section-title {
            margin-bottom: 10px;
            color: #1f2937;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">
                    Create Notice
                    <?php if ($adminFacultyName): ?>
                        <span class="faculty-badge"><?php echo htmlspecialchars(substr($adminFacultyName, 0, 30)); ?></span>
                    <?php endif; ?>
                </h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <div class="template-panel">
                    <h3 class="section-title">Templates and Recurring Drafts</h3>
                    <form method="GET" class="form-row">
                        <div class="form-group">
                            <label>Load a saved template</label>
                            <select name="template_id" onchange="this.form.submit()">
                                <option value="">Start from a blank notice</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo (int) $template['id']; ?>" <?php echo $selectedTemplateId === (int) $template['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($template['name']); ?> by <?php echo htmlspecialchars($template['author_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Template help</label>
                            <div class="info-text">Templates can also be marked recurring so admins can reuse weekly or monthly announcement patterns quickly.</div>
                            <?php if ($selectedTemplate): ?>
                                <div class="template-meta">
                                    <span class="tag"><?php echo htmlspecialchars($selectedTemplate['category'] ?: 'General'); ?></span>
                                    <?php if (!empty($selectedTemplate['is_recurring']) && !empty($selectedTemplate['recurrence_pattern'])): ?>
                                        <span class="tag"><?php echo htmlspecialchars(ucfirst($selectedTemplate['recurrence_pattern'])); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($selectedTemplate['requires_acknowledgement'])): ?>
                                        <span class="tag">Acknowledgement</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="form-container">
                    <?php if ($success): ?>
                        <div class="success-message">
                            <?php echo htmlspecialchars($success); ?>
                            <br><br>
                            <a href="manage_notices.php" style="color: #155724;">Review all notices</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="error-message">
                            <strong>Please fix the following:</strong>
                            <ul style="margin: 10px 0 0 20px;">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="template_id" value="<?php echo $selectedTemplateId ?: ''; ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Notice Title</label>
                                <input
                                    type="text"
                                    name="title"
                                    id="title"
                                    value="<?php echo htmlspecialchars($formData['title']); ?>"
                                    maxlength="200"
                                    required
                                >
                                <div class="char-count"><span id="charCount">0</span>/200 characters</div>
                            </div>

                            <div class="form-group">
                                <label>Category</label>
                                <select name="category" required>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $formData['category'] === $category ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Content</label>
                            <textarea name="content" rows="10" required><?php echo htmlspecialchars($formData['content']); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Faculty</label>
                                <select name="faculty_target">
                                    <option value="">All Faculties</option>
                                    <?php foreach ($faculties as $faculty): ?>
                                        <option value="<?php echo (int) $faculty['id']; ?>" <?php echo (string) $formData['faculty_target'] === (string) $faculty['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($faculty['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Target Year</label>
                                <select name="year_target">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo (string) $formData['year_target'] === (string) $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority">
                                    <?php foreach ($priorities as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $formData['priority'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="info-text">High and critical notices rise higher in the student feed and can bypass quiet hours.</div>
                            </div>

                            <div class="form-group">
                                <label>Delivery Channels</label>
                                <div class="checkbox-grid">
                                    <div class="checkbox-card">
                                        <label><input type="checkbox" name="delivery_channels[]" value="in_app" <?php echo in_array('in_app', $formData['delivery_channels'], true) ? 'checked' : ''; ?>> In-app notification</label>
                                    </div>
                                    <div class="checkbox-card">
                                        <label><input type="checkbox" name="delivery_channels[]" value="email" <?php echo in_array('email', $formData['delivery_channels'], true) ? 'checked' : ''; ?>> Email delivery</label>
                                    </div>
                                </div>
                                <div class="info-text">Email delivery is attempted immediately when the server mail configuration is available.</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Schedule Publishing</label>
                                <input type="datetime-local" name="schedule_date" value="<?php echo htmlspecialchars($formData['schedule_date']); ?>">
                                <div class="info-text">Future dates become scheduled notices and publish automatically when the system is next opened after that time.</div>
                            </div>

                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" name="expire_date" value="<?php echo htmlspecialchars($formData['expire_date']); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Acknowledgement</label>
                                <div class="checkbox-card">
                                    <label><input type="checkbox" name="requires_acknowledgement" <?php echo !empty($formData['requires_acknowledgement']) ? 'checked' : ''; ?>> Students must acknowledge this notice</label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Acknowledgement Deadline</label>
                                <input type="datetime-local" name="acknowledgement_due_at" value="<?php echo htmlspecialchars($formData['acknowledgement_due_at']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Attachment</label>
                            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        </div>

                        <div class="form-group">
                            <div class="checkbox-card">
                                <label><input type="checkbox" name="is_pinned" <?php echo !empty($formData['is_pinned']) ? 'checked' : ''; ?>> Pin this notice to the top of the feed</label>
                            </div>
                        </div>

                        <div class="template-panel" style="padding: 20px; margin-top: 20px;">
                            <h3 class="section-title">Save as Template</h3>
                            <div class="form-row">
                                <div class="form-group">
                                    <div class="checkbox-card">
                                        <label><input type="checkbox" name="save_as_template" <?php echo !empty($formData['save_as_template']) ? 'checked' : ''; ?>> Save this notice as a reusable template</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Template Name</label>
                                    <input type="text" name="template_name" value="<?php echo htmlspecialchars($formData['template_name']); ?>" placeholder="Example: Weekly exam reminder">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <div class="checkbox-card">
                                        <label><input type="checkbox" name="is_recurring_template" <?php echo !empty($formData['is_recurring_template']) ? 'checked' : ''; ?>> Mark template as recurring</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Recurring Pattern</label>
                                    <select name="recurrence_pattern">
                                        <?php foreach ($recurrenceOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $formData['recurrence_pattern'] === $value ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="actions">
                            <button type="submit" name="submission_action" value="save_draft" class="btn-secondary">Save Draft</button>
                            <?php if ($userRole === 'super_admin'): ?>
                                <button type="submit" name="submission_action" value="publish" class="btn-submit">Publish Notice</button>
                            <?php else: ?>
                                <button type="submit" name="submission_action" value="submit" class="btn-submit">Submit for Approval</button>
                            <?php endif; ?>
                            <a href="dashboard.php" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const titleInput = document.getElementById('title');
        const charCount = document.getElementById('charCount');

        function updateCharCount() {
            if (!titleInput || !charCount) {
                return;
            }

            charCount.textContent = titleInput.value.length;
        }

        if (titleInput) {
            titleInput.addEventListener('input', updateCharCount);
            updateCharCount();
        }
    </script>
</body>
</html>
