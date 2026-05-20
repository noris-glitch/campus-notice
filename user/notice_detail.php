<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$noticeId = (int) ($_GET['id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$viewerProfile = [
    'role' => $userRole,
    'faculty_id' => $_SESSION['faculty_id'] ?? null,
    'department_id' => $_SESSION['department_id'] ?? null,
    'year' => $_SESSION['year'] ?? null,
    'admin_type' => $_SESSION['admin_type'] ?? null,
];
$success = '';
$errors = [];

if ($noticeId <= 0) {
    header('Location: feed.php');
    exit();
}

function fetchNoticeForViewer(PDO $pdo, int $noticeId, int $userId, string $userRole, array $viewerProfile): ?array
{
    if ($userRole === 'student') {
        [$audienceConditions, $audienceParams] = buildNoticeAudienceConditions($pdo, 'n', $viewerProfile);
        $stmt = $pdo->prepare("
            SELECT
                n.*,
                u.name AS author_name,
                (SELECT COUNT(*) FROM notice_views WHERE notice_id = n.id) AS view_count,
                (SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = n.id) AS ack_total,
                (SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = n.id AND status = 'acknowledged') AS ack_done,
                EXISTS(
                    SELECT 1 FROM notice_acknowledgements
                    WHERE notice_id = n.id AND user_id = ? AND status = 'acknowledged'
                ) AS has_acknowledged
            FROM notices n
            JOIN users u ON n.posted_by = u.id
            WHERE n.id = ?
              AND n.status = 'published'
              AND (n.publish_at IS NULL OR n.publish_at <= NOW())
              AND (n.expire_at IS NULL OR n.expire_at > NOW())
              AND " . implode("
              AND ", $audienceConditions) . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId, $noticeId], $audienceParams));
        return $stmt->fetch() ?: null;
    }

    $stmt = $pdo->prepare("
        SELECT
            n.*,
            u.name AS author_name,
            (SELECT COUNT(*) FROM notice_views WHERE notice_id = n.id) AS view_count,
            (SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = n.id) AS ack_total,
            (SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = n.id AND status = 'acknowledged') AS ack_done,
            EXISTS(
                SELECT 1 FROM notice_acknowledgements
                WHERE notice_id = n.id AND user_id = ? AND status = 'acknowledged'
            ) AS has_acknowledged
        FROM notices n
        JOIN users u ON n.posted_by = u.id
        WHERE n.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $noticeId]);
    return $stmt->fetch() ?: null;
}

$notice = fetchNoticeForViewer($pdo, $noticeId, $userId, $userRole, $viewerProfile);
if (!$notice) {
    header('Location: feed.php');
    exit();
}

$canModerateQuestions = $userRole === 'super_admin' || ($userRole === 'admin' && (int) $notice['posted_by'] === $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'acknowledge' && $userRole === 'student') {
        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO notice_acknowledgements (notice_id, user_id, status)
                VALUES (?, ?, 'pending')
            ");
            $stmt->execute([$noticeId, $userId]);

            $stmt = $pdo->prepare("
                UPDATE notice_acknowledgements
                SET status = 'acknowledged', acknowledged_at = NOW()
                WHERE notice_id = ? AND user_id = ?
            ");
            $stmt->execute([$noticeId, $userId]);

            $success = 'Notice acknowledged successfully.';
        } catch (PDOException $e) {
            $errors[] = 'Failed to acknowledge the notice.';
        }
    }

    if ($action === 'ask_question' && $userRole === 'student') {
        $question = trim($_POST['question'] ?? '');
        if ($question === '') {
            $errors[] = 'Please enter your question before submitting.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO notice_questions (notice_id, asked_by, question, status)
                VALUES (?, ?, ?, 'open')
            ");
            $stmt->execute([$noticeId, $userId, $question]);
            $success = 'Your question has been submitted.';
        }
    }

    if ($action === 'answer_question' && $canModerateQuestions) {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $answer = trim($_POST['answer'] ?? '');

        if ($answer === '') {
            $errors[] = 'Please write an answer before submitting.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE notice_questions
                SET answer = ?, answered_by = ?, status = 'answered', answered_at = NOW()
                WHERE id = ? AND notice_id = ?
            ");
            $stmt->execute([$answer, $userId, $questionId, $noticeId]);

            $askerStmt = $pdo->prepare('SELECT asked_by FROM notice_questions WHERE id = ? AND notice_id = ?');
            $askerStmt->execute([$questionId, $noticeId]);
            $askerId = $askerStmt->fetchColumn();

            if ($askerId) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, notice_id, title, message)
                    VALUES (?, ?, ?, ?)
                ");
                $notifStmt->execute([
                    (int) $askerId,
                    $noticeId,
                    'Question Answered',
                    'An administrator responded to your question on "' . $notice['title'] . '".',
                ]);
            }

            $success = 'Answer posted successfully.';
        }
    }

    $notice = fetchNoticeForViewer($pdo, $noticeId, $userId, $userRole, $viewerProfile);
}

$trackStmt = $pdo->prepare('INSERT IGNORE INTO notice_views (notice_id, user_id) VALUES (?, ?)');
$trackStmt->execute([$noticeId, $userId]);

if ($userRole === 'student' && !empty($notice['requires_acknowledgement'])) {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notice_acknowledgements (notice_id, user_id, status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->execute([$noticeId, $userId]);
}

$questionsStmt = $pdo->prepare("
    SELECT
        nq.*,
        asker.name AS asker_name,
        answerer.name AS answerer_name
    FROM notice_questions nq
    JOIN users asker ON nq.asked_by = asker.id
    LEFT JOIN users answerer ON nq.answered_by = answerer.id
    WHERE nq.notice_id = ?
    ORDER BY nq.created_at DESC
");
$questionsStmt->execute([$noticeId]);
$questions = $questionsStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($notice['title']); ?> - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .panel {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .notice-title {
            font-size: 1.8rem;
            margin-bottom: 14px;
            color: #111827;
        }

        .meta,
        .stats,
        .badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .meta,
        .stats {
            color: #6b7280;
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #eef2ff;
            color: #4338ca;
        }

        .badge.high,
        .badge.critical {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge.ack {
            background: #ede9fe;
            color: #5b21b6;
        }

        .notice-content {
            line-height: 1.8;
            color: #374151;
            margin-top: 20px;
        }

        .banner {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
        }

        .banner.warning {
            background: #fff7ed;
            color: #9a3412;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
        }

        .question-item {
            border-top: 1px solid #e5e7eb;
            padding-top: 16px;
            margin-top: 16px;
        }

        .question-answer {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #f9fafb;
        }

        textarea {
            width: 100%;
            min-height: 110px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
        }

        .btn-primary,
        .btn-secondary {
            border: none;
            border-radius: 6px;
            padding: 10px 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 12px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">Notice Details</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <?php if ($success): ?>
                    <div class="panel message success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="panel message error">
                        <ul style="margin: 0 0 0 18px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <?php if (!empty($notice['requires_acknowledgement']) && empty($notice['has_acknowledged'])): ?>
                        <div class="banner warning">
                            This notice requires your acknowledgement.
                            <?php if (!empty($notice['acknowledgement_due_at'])): ?>
                                Deadline: <?php echo date('d M Y, h:i A', strtotime($notice['acknowledgement_due_at'])); ?>.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="badges">
                        <span class="badge <?php echo htmlspecialchars(strtolower($notice['priority'] ?? 'normal')); ?>"><?php echo htmlspecialchars(ucfirst($notice['priority'] ?? 'normal')); ?></span>
                        <span class="badge"><?php echo htmlspecialchars($notice['category'] ?: 'General'); ?></span>
                        <?php if (!empty($notice['requires_acknowledgement'])): ?>
                            <span class="badge ack"><?php echo !empty($notice['has_acknowledged']) ? 'Acknowledged' : 'Needs acknowledgement'; ?></span>
                        <?php endif; ?>
                        <span class="badge"><?php echo htmlspecialchars($notice['status']); ?></span>
                    </div>

                    <h1 class="notice-title"><?php echo htmlspecialchars($notice['title']); ?></h1>

                    <div class="meta">
                        <span>Posted by <?php echo htmlspecialchars($notice['author_name']); ?></span>
                        <span><?php echo date('d M Y, h:i A', strtotime($notice['publish_at'])); ?></span>
                        <?php if (!empty($notice['delivery_channels'])): ?>
                            <span>Channels: <?php echo htmlspecialchars(str_replace(',', ' + ', $notice['delivery_channels'])); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="stats" style="margin-top: 10px;">
                        <span><?php echo (int) $notice['view_count']; ?> views</span>
                        <span><?php echo (int) $notice['ack_done']; ?>/<?php echo (int) $notice['ack_total']; ?> acknowledgements</span>
                    </div>

                    <div class="notice-content">
                        <?php echo nl2br(htmlspecialchars($notice['content'])); ?>
                    </div>

                    <?php if (!empty($notice['attachment'])): ?>
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                            <a href="../assets/uploads/<?php echo htmlspecialchars($notice['attachment']); ?>" download class="btn-primary">Download Attachment</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($userRole === 'student' && !empty($notice['requires_acknowledgement']) && empty($notice['has_acknowledged'])): ?>
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="action" value="acknowledge">
                            <button type="submit" class="btn-primary">Acknowledge Notice</button>
                        </form>
                    <?php endif; ?>

                    <a href="<?php echo $userRole === 'student' ? 'feed.php' : '../super_admin/manage_notices.php'; ?>" class="btn-secondary">Back</a>
                </div>

                <div class="panel">
                    <h3>Questions and Clarifications</h3>
                    <p style="color:#6b7280;">Students can request clarification here, and administrators can post one official answer that everyone sees.</p>

                    <?php if ($userRole === 'student'): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <input type="hidden" name="action" value="ask_question">
                            <label for="question" style="display:block; margin-bottom:8px; font-weight:600;">Ask a question</label>
                            <textarea id="question" name="question" placeholder="Write your clarification request here..."></textarea>
                            <button type="submit" class="btn-primary">Submit Question</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($questions): ?>
                        <?php foreach ($questions as $question): ?>
                            <div class="question-item">
                                <div class="meta">
                                    <span><strong><?php echo htmlspecialchars($question['asker_name']); ?></strong></span>
                                    <span><?php echo date('d M Y, h:i A', strtotime($question['created_at'])); ?></span>
                                    <span><?php echo htmlspecialchars(ucfirst($question['status'])); ?></span>
                                </div>
                                <p style="margin-top:10px; color:#374151;"><?php echo nl2br(htmlspecialchars($question['question'])); ?></p>

                                <?php if (!empty($question['answer'])): ?>
                                    <div class="question-answer">
                                        <div class="meta">
                                            <span>Answered by <?php echo htmlspecialchars($question['answerer_name'] ?? 'Administrator'); ?></span>
                                            <?php if (!empty($question['answered_at'])): ?>
                                                <span><?php echo date('d M Y, h:i A', strtotime($question['answered_at'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p style="margin-top:10px;"><?php echo nl2br(htmlspecialchars($question['answer'])); ?></p>
                                    </div>
                                <?php elseif ($canModerateQuestions): ?>
                                    <form method="POST" style="margin-top: 14px;">
                                        <input type="hidden" name="action" value="answer_question">
                                        <input type="hidden" name="question_id" value="<?php echo (int) $question['id']; ?>">
                                        <label for="answer_<?php echo (int) $question['id']; ?>" style="display:block; margin-bottom:8px; font-weight:600;">Official answer</label>
                                        <textarea id="answer_<?php echo (int) $question['id']; ?>" name="answer" placeholder="Write the official clarification..."></textarea>
                                        <button type="submit" class="btn-primary">Post Answer</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:#6b7280; margin-top:16px;">No questions yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
