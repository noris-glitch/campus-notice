<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function feedbackCategories(): array
{
    return ['Bug Report', 'Suggestion', 'Notice Issue', 'Account Support', 'General'];
}

function feedbackStatuses(): array
{
    return [
        'open' => 'Open',
        'in_review' => 'In Review',
        'responded' => 'Responded',
        'closed' => 'Closed',
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $isSuperAdmin = ($user['role'] ?? '') === 'super_admin';

        $sql = "
            SELECT
                fm.*,
                submitter.name AS submitter_name,
                submitter.email AS submitter_email,
                responder.name AS responder_name
            FROM feedback_messages fm
            JOIN users submitter ON submitter.id = fm.submitted_by
            LEFT JOIN users responder ON responder.id = fm.responded_by
        ";
        $params = [];

        if (!$isSuperAdmin) {
            $sql .= " WHERE fm.submitted_by = ?";
            $params[] = (int) $user['id'];
        }

        $sql .= " ORDER BY FIELD(fm.status, 'open', 'in_review', 'responded', 'closed'), fm.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $stats = [
            'open' => 0,
            'in_review' => 0,
            'responded' => 0,
            'closed' => 0,
            'total' => count($items),
        ];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'open');
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        apiRespond(200, [
            'success' => true,
            'categories' => feedbackCategories(),
            'statuses' => feedbackStatuses(),
            'can_moderate' => $isSuperAdmin,
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'submit') {
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $category = trim((string) ($data['category'] ?? 'General'));

        if ($subject === '' || $message === '') {
            apiRespond(400, ['success' => false, 'error' => 'Subject and message are required']);
        }

        if (!in_array($category, feedbackCategories(), true)) {
            $category = 'General';
        }

        $stmt = $pdo->prepare("
            INSERT INTO feedback_messages (submitted_by, category, subject, message, status)
            VALUES (?, ?, ?, ?, 'open')
        ");
        $stmt->execute([(int) $user['id'], $category, $subject, $message]);

        apiRespond(200, ['success' => true, 'message' => 'Feedback submitted successfully.']);
    }

    if (($user['role'] ?? '') !== 'super_admin') {
        apiRespond(403, ['success' => false, 'error' => 'Only super administrators can manage feedback']);
    }

    $feedbackId = isset($data['feedback_id']) ? (int) $data['feedback_id'] : 0;
    if ($feedbackId <= 0) {
        apiRespond(400, ['success' => false, 'error' => 'Feedback ID is required']);
    }

    if ($action === 'respond') {
        $responseText = trim((string) ($data['admin_response'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'responded'));

        if ($responseText === '') {
            apiRespond(400, ['success' => false, 'error' => 'Please write a response before sending it']);
        }

        if (!array_key_exists($status, feedbackStatuses())) {
            $status = 'responded';
        }

        $lookupStmt = $pdo->prepare('SELECT submitted_by, subject FROM feedback_messages WHERE id = ? LIMIT 1');
        $lookupStmt->execute([$feedbackId]);
        $feedbackRow = $lookupStmt->fetch();
        if (!$feedbackRow) {
            apiRespond(404, ['success' => false, 'error' => 'The selected feedback item was not found']);
        }

        $stmt = $pdo->prepare("
            UPDATE feedback_messages
            SET admin_response = ?, responded_by = ?, responded_at = NOW(), status = ?
            WHERE id = ?
        ");
        $stmt->execute([$responseText, (int) $user['id'], $status, $feedbackId]);

        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message)
            VALUES (?, ?, ?)
        ");
        $notifStmt->execute([
            (int) $feedbackRow['submitted_by'],
            'Feedback response',
            'A super administrator responded to your feedback: "' . ($feedbackRow['subject'] ?? 'Campus feedback') . '".',
        ]);

        apiRespond(200, ['success' => true, 'message' => 'Feedback response sent successfully.']);
    }

    if ($action === 'set_status') {
        $status = trim((string) ($data['status'] ?? ''));
        if (!array_key_exists($status, feedbackStatuses())) {
            apiRespond(400, ['success' => false, 'error' => 'Please choose a valid feedback status']);
        }

        $stmt = $pdo->prepare('UPDATE feedback_messages SET status = ? WHERE id = ?');
        $stmt->execute([$status, $feedbackId]);

        if ($stmt->rowCount() === 0) {
            apiRespond(404, ['success' => false, 'error' => 'The selected feedback item could not be updated']);
        }

        apiRespond(200, ['success' => true, 'message' => 'Feedback status updated successfully.']);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported feedback action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to process feedback right now']);
}
?>
