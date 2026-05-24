<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $noticeId = isset($_GET['notice_id']) ? (int) $_GET['notice_id'] : 0;

        if ($noticeId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Notice ID is required']);
        }

        apiEnsureNoticeVisible($pdo, $user, $noticeId);
        apiMarkNoticeViewed($pdo, $noticeId, (int) $user['id']);

        $notices = apiFetchVisibleNotices($pdo, $user, [
            'notice_id' => $noticeId,
            'limit' => 1,
            'include_archived' => true,
        ]);

        if (empty($notices)) {
            $manageNotice = apiFetchNoticeById($pdo, $noticeId);
            if (!$manageNotice || !apiCanManageNotice($user, $manageNotice)) {
                apiRespond(404, ['success' => false, 'error' => 'Notice not found']);
            }

            $notices = [$manageNotice];
        }

        $commentStmt = $pdo->prepare("
            SELECT
                nq.*,
                asker.name AS asker_name,
                answerer.name AS answerer_name
            FROM notice_questions nq
            JOIN users asker ON asker.id = nq.asked_by
            LEFT JOIN users answerer ON answerer.id = nq.answered_by
            WHERE nq.notice_id = ?
              AND (? = 1 OR nq.status <> 'hidden')
            ORDER BY nq.created_at DESC
        ");
        $commentStmt->execute([
            $noticeId,
            ($user['role'] ?? '') === 'super_admin' ? 1 : 0,
        ]);

        apiRespond(200, [
            'success' => true,
            'notice' => $notices[0],
            'comments' => $commentStmt->fetchAll(),
            'can_comment' => in_array(($user['role'] ?? ''), ['student', 'admin'], true),
            'can_moderate_comments' => ($user['role'] ?? '') === 'super_admin',
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $noticeId = isset($data['notice_id']) ? (int) $data['notice_id'] : 0;
    $action = trim((string) ($data['action'] ?? ''));

    if ($noticeId <= 0 || $action === '') {
        apiRespond(400, ['success' => false, 'error' => 'Notice ID and action are required']);
    }

    $notice = apiEnsureNoticeVisible($pdo, $user, $noticeId);

    if ($action === 'bookmark') {
        $bookmarkAction = apiToggleBookmark($pdo, $noticeId, (int) $user['id']);
        apiRespond(200, ['success' => true, 'action' => $bookmarkAction]);
    }

    if ($action === 'view') {
        apiMarkNoticeViewed($pdo, $noticeId, (int) $user['id']);
        apiRespond(200, ['success' => true]);
    }

    if ($action === 'acknowledge') {
        if (empty($notice['requires_acknowledgement'])) {
            apiRespond(400, ['success' => false, 'error' => 'This notice does not require acknowledgement']);
        }

        apiAcknowledgeNotice($pdo, $noticeId, (int) $user['id']);
        apiRespond(200, ['success' => true]);
    }

    if ($action === 'add_comment') {
        if (!in_array(($user['role'] ?? ''), ['student', 'admin'], true)) {
            apiRespond(403, ['success' => false, 'error' => 'Only students and admins can post notice comments']);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        if ($comment === '') {
            apiRespond(400, ['success' => false, 'error' => 'Please write a comment before submitting']);
        }

        $stmt = $pdo->prepare("
            INSERT INTO notice_questions (notice_id, asked_by, question, status)
            VALUES (?, ?, ?, 'open')
        ");
        $stmt->execute([$noticeId, (int) $user['id'], $comment]);

        apiRespond(200, ['success' => true, 'message' => 'Your comment has been posted.']);
    }

    if ($action === 'answer_comment') {
        if (($user['role'] ?? '') !== 'super_admin') {
            apiRespond(403, ['success' => false, 'error' => 'Only super administrators can answer comments']);
        }

        $commentId = isset($data['comment_id']) ? (int) $data['comment_id'] : 0;
        $answer = trim((string) ($data['answer'] ?? ''));
        if ($commentId <= 0 || $answer === '') {
            apiRespond(400, ['success' => false, 'error' => 'Comment and answer are required']);
        }

        $stmt = $pdo->prepare("
            UPDATE notice_questions
            SET answer = ?, answered_by = ?, status = 'answered', answered_at = NOW()
            WHERE id = ? AND notice_id = ?
        ");
        $stmt->execute([$answer, (int) $user['id'], $commentId, $noticeId]);

        if ($stmt->rowCount() === 0) {
            apiRespond(404, ['success' => false, 'error' => 'The selected comment could not be updated']);
        }

        $askerStmt = $pdo->prepare('SELECT asked_by FROM notice_questions WHERE id = ? AND notice_id = ? LIMIT 1');
        $askerStmt->execute([$commentId, $noticeId]);
        $askerId = (int) ($askerStmt->fetchColumn() ?: 0);

        if ($askerId > 0) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, notice_id, title, message)
                VALUES (?, ?, ?, ?)
            ");
            $notifStmt->execute([
                $askerId,
                $noticeId,
                'Notice comment answered',
                'A super administrator replied to your comment on "' . ($notice['title'] ?? 'this notice') . '".',
            ]);
        }

        apiRespond(200, ['success' => true, 'message' => 'Reply posted successfully.']);
    }

    if ($action === 'hide_comment' || $action === 'reopen_comment') {
        if (($user['role'] ?? '') !== 'super_admin') {
            apiRespond(403, ['success' => false, 'error' => 'Only super administrators can moderate comments']);
        }

        $commentId = isset($data['comment_id']) ? (int) $data['comment_id'] : 0;
        if ($commentId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Comment ID is required']);
        }

        $nextStatus = $action === 'hide_comment' ? 'hidden' : 'open';
        $stmt = $pdo->prepare("
            UPDATE notice_questions
            SET status = ?
            WHERE id = ? AND notice_id = ?
        ");
        $stmt->execute([$nextStatus, $commentId, $noticeId]);

        if ($stmt->rowCount() === 0) {
            apiRespond(404, ['success' => false, 'error' => 'The selected comment could not be updated']);
        }

        apiRespond(200, [
            'success' => true,
            'message' => $action === 'hide_comment' ? 'Comment hidden successfully.' : 'Comment reopened successfully.',
        ]);
    }

    if ($action === 'delete_comment') {
        if (($user['role'] ?? '') !== 'super_admin') {
            apiRespond(403, ['success' => false, 'error' => 'Only super administrators can delete comments']);
        }

        $commentId = isset($data['comment_id']) ? (int) $data['comment_id'] : 0;
        if ($commentId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Comment ID is required']);
        }

        $stmt = $pdo->prepare('DELETE FROM notice_questions WHERE id = ? AND notice_id = ?');
        $stmt->execute([$commentId, $noticeId]);

        if ($stmt->rowCount() === 0) {
            apiRespond(404, ['success' => false, 'error' => 'The selected comment could not be deleted']);
        }

        apiRespond(200, ['success' => true, 'message' => 'Comment deleted successfully.']);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported notice action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to complete that action right now']);
}
?>
