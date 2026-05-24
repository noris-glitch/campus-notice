<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET']);

try {
    apiRespond(200, [
        'success' => true,
        'landing_page' => featureLandingPageSettings($pdo),
    ]);
} catch (PDOException $e) {
    apiRespond(500, [
        'success' => false,
        'error' => 'Could not load public settings right now.',
    ]);
}
?>
