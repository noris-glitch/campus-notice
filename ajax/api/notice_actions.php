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

        apiRespond(200, [
            'success' => true,
            'notice' => $notices[0],
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

    apiRespond(400, ['success' => false, 'error' => 'Unsupported notice action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to complete that action right now']);
}
?>
