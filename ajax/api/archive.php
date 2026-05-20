<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET']);

try {
    $user = apiFetchAuthenticatedUser($pdo, $_GET);
    $notices = apiFetchVisibleNotices($pdo, $user, [
        'include_archived' => true,
        'q' => $_GET['q'] ?? '',
        'category' => $_GET['category'] ?? '',
        'priority' => $_GET['priority'] ?? '',
        'status' => $_GET['status'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'limit' => 100,
    ]);

    apiRespond(200, [
        'success' => true,
        'notices' => $notices,
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to load archive notices right now']);
}
?>
