<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header('Location: ../login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

function redirectNoticeMessage(string $message, string $type = 'success'): void
{
    header('Location: manage_notices.php?type=' . urlencode($type) . '&msg=' . urlencode($message));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $noticeId = (int) ($_POST['notice_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reviewNotes = trim($_POST['review_notes'] ?? '');

    $notice = getNoticeById($pdo, $noticeId);
    if (!$notice) {
        redirectNoticeMessage('The selected notice could not be found.', 'error');
    }

    $ownsNotice = $userRole === 'super_admin' || (int) $notice['posted_by'] === $userId;
    if (!$ownsNotice) {
        redirectNoticeMessage('You do not have permission to modify that notice.', 'error');
    }

    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('DELETE FROM notifications WHERE notice_id = ?');
            $stmt->execute([$noticeId]);

            $stmt = $pdo->prepare('DELETE FROM bookmarks WHERE notice_id = ?');
            $stmt->execute([$noticeId]);

            $stmt = $pdo->prepare('DELETE FROM notice_views WHERE notice_id = ?');
            $stmt->execute([$noticeId]);

            if (featureTableExists($pdo, 'nearby_notifications')) {
                $stmt = $pdo->prepare('DELETE FROM nearby_notifications WHERE notice_id = ?');
                $stmt->execute([$noticeId]);
            }

            $stmt = $pdo->prepare('DELETE FROM notices WHERE id = ?');
            $stmt->execute([$noticeId]);

            $pdo->commit();
            redirectNoticeMessage('Notice deleted successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            redirectNoticeMessage('Failed to delete the notice: ' . $e->getMessage(), 'error');
        }
    }

    if ($action === 'archive') {
        $stmt = $pdo->prepare("
            UPDATE notices
            SET status = 'archived',
                approval_status = CASE WHEN approval_status = 'pending' THEN 'approved' ELSE approval_status END
            WHERE id = ?
        ");
        $stmt->execute([$noticeId]);
        redirectNoticeMessage('Notice archived successfully.');
    }

    if ($action === 'submit_for_review') {
        $stmt = $pdo->prepare("
            UPDATE notices
            SET status = 'pending_review',
                approval_status = 'pending'
            WHERE id = ? AND posted_by = ?
        ");
        $stmt->execute([$noticeId, $userId]);
        redirectNoticeMessage('Draft submitted for approval.');
    }

    if ($action === 'publish_now' && $userRole === 'super_admin') {
        $publishAt = strtotime((string) ($notice['publish_at'] ?? 'now')) > time()
            ? (string) $notice['publish_at']
            : date('Y-m-d H:i:s');
        $newStatus = strtotime($publishAt) > time() ? 'scheduled' : 'published';

        $stmt = $pdo->prepare("
            UPDATE notices
            SET status = ?,
                approval_status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $userId, $reviewNotes ?: null, $noticeId]);

        if ($newStatus === 'published') {
            deliverNoticeToAudience($pdo, $noticeId);
        }

        redirectNoticeMessage($newStatus === 'scheduled' ? 'Notice approved and scheduled.' : 'Notice published successfully.');
    }

    if (($action === 'approve' || $action === 'reject') && $userRole === 'super_admin') {
        $result = saveNoticeReviewDecision($pdo, $noticeId, $userId, $action, $reviewNotes);
        if ($result) {
            redirectNoticeMessage($action === 'approve' ? 'Notice approved successfully.' : 'Notice rejected successfully.');
        }
    }

    redirectNoticeMessage('The requested action could not be completed.', 'error');
}

$query = "
    SELECT
        n.*,
        u.name AS author_name,
        (SELECT COUNT(*) FROM notice_views nv WHERE nv.notice_id = n.id) AS view_count,
        (SELECT COUNT(*) FROM bookmarks b WHERE b.notice_id = n.id) AS bookmark_count,
        (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id) AS ack_total,
        (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id AND na.status = 'acknowledged') AS ack_done,
        (SELECT COUNT(*) FROM notice_questions nq WHERE nq.notice_id = n.id AND nq.status = 'open') AS open_questions,
        (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'sent') AS email_sent,
        (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'failed') AS email_failed
    FROM notices n
    JOIN users u ON n.posted_by = u.id
";

if ($userRole === 'super_admin') {
    $stmt = $pdo->query($query . " ORDER BY FIELD(n.status, 'pending_review', 'scheduled', 'published', 'draft', 'rejected', 'archived'), n.created_at DESC");
} else {
    $stmt = $pdo->prepare($query . " WHERE n.posted_by = ? ORDER BY FIELD(n.status, 'pending_review', 'scheduled', 'published', 'draft', 'rejected', 'archived'), n.created_at DESC");
    $stmt->execute([$userId]);
}

$notices = $stmt->fetchAll();

function renderStatusBadge(array $notice): string
{
    $status = $notice['status'] ?? 'draft';
    $approval = $notice['approval_status'] ?? 'approved';

    $map = [
        'published' => 'Published',
        'scheduled' => 'Scheduled',
        'pending_review' => 'Pending Review',
        'draft' => 'Draft',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ];

    $label = $map[$status] ?? ucfirst($status);
    if ($status === 'published' && $approval === 'approved') {
        return $label;
    }

    if ($status === 'draft' && $approval === 'draft') {
        return $label;
    }

    return $label;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Notices - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn-create,
        .btn-outline,
        .btn-danger,
        .btn-small {
            border: none;
            border-radius: 6px;
            padding: 10px 18px;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
            font-size: 14px;
        }

        .btn-create {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-outline {
            background: #e8eefc;
            color: #243b7a;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-small {
            background: #0f766e;
            color: white;
            padding: 8px 12px;
            font-size: 13px;
        }

        .message {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
        }

        .notice-list {
            display: grid;
            gap: 18px;
        }

        .notice-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .notice-header {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .notice-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }

        .badges,
        .meta,
        .stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: #eef2ff;
            color: #4338ca;
        }

        .badge.pending { background: #fff7ed; color: #c2410c; }
        .badge.published { background: #e8fff2; color: #047857; }
        .badge.rejected { background: #fee2e2; color: #b91c1c; }
        .badge.draft { background: #f3f4f6; color: #374151; }
        .badge.scheduled { background: #eff6ff; color: #1d4ed8; }
        .badge.archived { background: #f5f3ff; color: #6d28d9; }
        .badge.priority-high,
        .badge.priority-critical { background: #fee2e2; color: #b91c1c; }
        .badge.priority-normal { background: #ecfeff; color: #0f766e; }

        .notice-body {
            margin: 14px 0;
            color: #4b5563;
            line-height: 1.6;
        }

        .meta,
        .stats {
            color: #6b7280;
            font-size: 13px;
        }

        .review-box {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }

        .review-box textarea {
            width: 100%;
            min-height: 84px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .notice-header {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">Manage Notices</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <div class="page-actions">
                    <a href="create_notice.php" class="btn-create">Create Notice</a>
                </div>

                <?php if (!empty($_GET['msg'])): ?>
                    <div class="message <?php echo ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success'; ?>">
                        <?php echo htmlspecialchars($_GET['msg']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($notices): ?>
                    <div class="notice-list">
                        <?php foreach ($notices as $notice): ?>
                            <?php
                            $statusClass = str_replace('_', '-', strtolower((string) $notice['status']));
                            $priorityClass = 'priority-' . strtolower((string) ($notice['priority'] ?? 'normal'));
                            $ackLabel = ((int) $notice['ack_done']) . '/' . ((int) $notice['ack_total']);
                            ?>
                            <div class="notice-card">
                                <div class="notice-header">
                                    <div>
                                        <div class="notice-title"><?php echo htmlspecialchars($notice['title']); ?></div>
                                        <div class="badges">
                                            <span class="badge <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(renderStatusBadge($notice)); ?></span>
                                            <span class="badge <?php echo htmlspecialchars($priorityClass); ?>"><?php echo htmlspecialchars(ucfirst((string) ($notice['priority'] ?? 'normal'))); ?></span>
                                            <span class="badge"><?php echo htmlspecialchars($notice['category'] ?: 'General'); ?></span>
                                            <?php if (!empty($notice['requires_acknowledgement'])): ?>
                                                <span class="badge">Acknowledgement Required</span>
                                            <?php endif; ?>
                                            <?php if (!empty($notice['delivery_channels'])): ?>
                                                <span class="badge"><?php echo htmlspecialchars(str_replace(',', ' + ', $notice['delivery_channels'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="meta">
                                        <span>By <?php echo htmlspecialchars($notice['author_name']); ?></span>
                                        <span><?php echo date('d M Y, h:i A', strtotime($notice['created_at'])); ?></span>
                                    </div>
                                </div>

                                <div class="notice-body">
                                    <?php echo nl2br(htmlspecialchars(substr($notice['content'], 0, 260))); ?>
                                    <?php if (strlen($notice['content']) > 260): ?>...<?php endif; ?>
                                </div>

                                <div class="meta">
                                    <span>Publish: <?php echo date('d M Y, h:i A', strtotime($notice['publish_at'])); ?></span>
                                    <span>Expiry: <?php echo $notice['expire_at'] ? date('d M Y', strtotime($notice['expire_at'])) : 'No expiry'; ?></span>
                                    <?php if (!empty($notice['review_notes'])): ?>
                                        <span>Review notes: <?php echo htmlspecialchars($notice['review_notes']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="stats" style="margin-top: 12px;">
                                    <span><?php echo (int) $notice['view_count']; ?> views</span>
                                    <span><?php echo (int) $notice['bookmark_count']; ?> bookmarks</span>
                                    <span><?php echo $ackLabel; ?> acknowledgements</span>
                                    <span><?php echo (int) $notice['open_questions']; ?> open questions</span>
                                    <span><?php echo (int) $notice['email_sent']; ?> email sent</span>
                                    <?php if ((int) $notice['email_failed'] > 0): ?>
                                        <span><?php echo (int) $notice['email_failed']; ?> email failed</span>
                                    <?php endif; ?>
                                </div>

                                <div class="action-row">
                                    <a href="../user/notice_detail.php?id=<?php echo (int) $notice['id']; ?>" class="btn-outline">Open Notice</a>

                                    <?php if ($userRole !== 'super_admin' && $notice['status'] === 'draft'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="notice_id" value="<?php echo (int) $notice['id']; ?>">
                                            <input type="hidden" name="action" value="submit_for_review">
                                            <button type="submit" class="btn-small">Submit for Review</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($userRole === 'super_admin' && in_array($notice['status'], ['draft', 'rejected'], true)): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="notice_id" value="<?php echo (int) $notice['id']; ?>">
                                            <input type="hidden" name="action" value="publish_now">
                                            <input type="hidden" name="review_notes" value="">
                                            <button type="submit" class="btn-small">Publish</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!in_array($notice['status'], ['archived'], true)): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="notice_id" value="<?php echo (int) $notice['id']; ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <button type="submit" class="btn-outline">Archive</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notice permanently?');">
                                        <input type="hidden" name="notice_id" value="<?php echo (int) $notice['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn-danger">Delete</button>
                                    </form>
                                </div>

                                <?php if ($userRole === 'super_admin' && $notice['status'] === 'pending_review'): ?>
                                    <div class="review-box">
                                        <form method="POST">
                                            <input type="hidden" name="notice_id" value="<?php echo (int) $notice['id']; ?>">
                                            <label for="review_notes_<?php echo (int) $notice['id']; ?>" style="display:block; font-weight:600; margin-bottom:8px;">Review Notes</label>
                                            <textarea id="review_notes_<?php echo (int) $notice['id']; ?>" name="review_notes" placeholder="Optional notes for the author"><?php echo htmlspecialchars($notice['review_notes'] ?? ''); ?></textarea>
                                            <div class="action-row">
                                                <button type="submit" name="action" value="approve" class="btn-small">Approve</button>
                                                <button type="submit" name="action" value="reject" class="btn-danger">Reject</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>No notices yet</h3>
                        <p>Create your first notice to start using the new workflow, delivery, and acknowledgement features.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
