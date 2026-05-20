<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET']);

try {
    $user = apiFetchAuthenticatedUser($pdo, $_GET);
    $bookmarks = apiFetchVisibleNotices($pdo, $user, [
        'bookmark_only' => true,
        'include_archived' => true,
        'limit' => 100,
    ]);

    apiRespond(200, [
        'success' => true,
        'bookmarks' => $bookmarks,
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to load bookmarks right now']);
}
?>
