<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $stmt = $pdo->prepare("
            SELECT
                n.*,
                nt.title AS notice_title
            FROM notifications n
            LEFT JOIN notices nt ON n.notice_id = nt.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([(int) $user['id']]);
        $notifications = $stmt->fetchAll();

        $unreadCount = 0;
        foreach ($notifications as &$notification) {
            $notification['is_read'] = apiBool($notification['is_read'] ?? 0);
            $notification['time_ago'] = apiTimeAgo($notification['created_at'] ?? null);
            if (empty($notification['is_read'])) {
                $unreadCount++;
            }
        }

        apiRespond(200, [
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        apiRespond(200, ['success' => true]);
    }

    if ($action === 'mark_read') {
        $notificationId = isset($data['notification_id']) ? (int) $data['notification_id'] : 0;
        if ($notificationId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Notification ID is required']);
        }

        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, (int) $user['id']]);
        apiRespond(200, ['success' => true]);
    }

    if ($action === 'delete_all') {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        apiRespond(200, ['success' => true]);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported notification action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to update notifications right now']);
}
?>
