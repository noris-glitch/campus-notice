<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiRequireAdminRole(array $user): void
{
    if (($user['role'] ?? '') !== 'admin' && ($user['role'] ?? '') !== 'super_admin') {
        apiRespond(403, ['success' => false, 'error' => 'Administrator access is required']);
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        apiRequireAdminRole($user);

        apiRespond(200, [
            'success' => true,
            'notices' => apiFetchAdminNotices($pdo, $user),
            'faculties' => apiFetchFaculties($pdo),
            'categories' => getAvailableNoticeCategories(),
            'templates' => fetchVisibleTemplates($pdo, (int) $user['id'], (string) $user['role']),
            'priorities' => apiAdminPriorities(),
            'years' => apiAdminYears(),
            'recurrence_options' => apiRecurrenceOptions(),
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    apiRequireAdminRole($user);

    $action = trim((string) ($data['action'] ?? ''));
    $noticeId = isset($data['notice_id']) ? (int) $data['notice_id'] : 0;
    $userRole = (string) $user['role'];
    $userId = (int) $user['id'];
    $adminFaculty = apiNullableInt($user['faculty_id'] ?? null);

    if ($action === 'create') {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $category = (string) ($data['category'] ?? 'Academic');
        $priority = (string) ($data['priority'] ?? 'normal');
        $facultyTarget = apiNullableInt($data['faculty_target'] ?? null);
        $yearTarget = apiNullableInt($data['year_target'] ?? null);
        $publishAt = apiDateTimeOrNull($data['schedule_date'] ?? null) ?: date('Y-m-d H:i:s');
        $expireAt = apiDateTimeOrNull($data['expire_date'] ?? null, true);
        $requiresAcknowledgement = !empty($data['requires_acknowledgement']);
        $ackDueAt = apiDateTimeOrNull($data['acknowledgement_due_at'] ?? null);
        $isPinned = !empty($data['is_pinned']) ? 1 : 0;
        $saveAsTemplate = !empty($data['save_as_template']);
        $templateName = trim((string) ($data['template_name'] ?? ''));
        $isRecurringTemplate = !empty($data['is_recurring_template']);
        $recurrencePattern = trim((string) ($data['recurrence_pattern'] ?? ''));
        $deliveryChannels = normalizeDeliveryChannels($data['delivery_channels'] ?? ['in_app']);
        $submissionAction = (string) ($data['submission_action'] ?? ($userRole === 'super_admin' ? 'publish' : 'submit'));
        $templateId = apiNullableInt($data['template_id'] ?? null);

        if ($userRole === 'admin' && $adminFaculty) {
            $facultyTarget = $facultyTarget ?: $adminFaculty;
        }

        if ($title === '' || $content === '') {
            apiRespond(400, ['success' => false, 'error' => 'Title and content are required']);
        }

        if (strlen($title) > 200) {
            apiRespond(400, ['success' => false, 'error' => 'Title cannot exceed 200 characters']);
        }

        if (!in_array($category, getAvailableNoticeCategories(), true)) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid category']);
        }

        if (!array_key_exists($priority, apiAdminPriorities())) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid priority']);
        }

        if ($saveAsTemplate && $templateName === '') {
            apiRespond(400, ['success' => false, 'error' => 'Template name is required when saving a template']);
        }

        if ($requiresAcknowledgement && $ackDueAt && strtotime($ackDueAt) < strtotime($publishAt)) {
            apiRespond(400, ['success' => false, 'error' => 'Acknowledgement deadline should be after the publish time']);
        }

        $status = 'draft';
        $approvalStatus = 'draft';
        $reviewedBy = null;
        $reviewedAt = null;
        $futurePublish = strtotime($publishAt) > time();

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

        $stmt->execute([
            $title,
            $content,
            $category,
            $priority,
            null,
            $userId,
            $facultyTarget,
            $yearTarget,
            $isPinned,
            $requiresAcknowledgement ? 1 : 0,
            $ackDueAt,
            $expireAt,
            $publishAt,
            $status,
            $approvalStatus,
            $reviewedBy,
            $reviewedAt,
            arrayToCsvValue($deliveryChannels),
            $templateId,
            $isRecurringTemplate ? ($recurrencePattern ?: null) : null,
        ]);

        $newNoticeId = (int) $pdo->lastInsertId();
        $deliverySummary = null;

        if ($saveAsTemplate) {
            saveNoticeTemplate($pdo, [
                'name' => $templateName,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'faculty_target' => $facultyTarget,
                'year_target' => $yearTarget,
                'is_pinned' => $isPinned,
                'priority' => $priority,
                'requires_acknowledgement' => $requiresAcknowledgement ? 1 : 0,
                'delivery_channels' => $deliveryChannels,
                'is_recurring' => $isRecurringTemplate ? 1 : 0,
                'recurrence_pattern' => $recurrencePattern ?: null,
                'created_by' => $userId,
            ]);
        }

        if ($status === 'published') {
            $deliverySummary = deliverNoticeToAudience($pdo, $newNoticeId);
        }

        logActivity($pdo, $userId, 'mobile_notice_created', 'Notice ID ' . $newNoticeId . ' created with status ' . $status);

        apiRespond(200, [
            'success' => true,
            'message' => 'Notice saved successfully',
            'notice_id' => $newNoticeId,
            'status' => $status,
            'delivery_summary' => $deliverySummary,
        ]);
    }

    if ($noticeId <= 0) {
        apiRespond(400, ['success' => false, 'error' => 'Notice ID is required']);
    }

    $notice = apiFetchNoticeById($pdo, $noticeId);
    if (!$notice) {
        apiRespond(404, ['success' => false, 'error' => 'The selected notice could not be found']);
    }

    if (!apiCanManageNotice($user, $notice)) {
        apiRespond(403, ['success' => false, 'error' => 'You do not have permission to modify that notice']);
    }

    $reviewNotes = trim((string) ($data['review_notes'] ?? ''));

    if ($action === 'delete') {
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
        apiRespond(200, ['success' => true, 'message' => 'Notice deleted successfully']);
    }

    if ($action === 'archive') {
        $stmt = $pdo->prepare("
            UPDATE notices
            SET status = 'archived',
                approval_status = CASE WHEN approval_status = 'pending' THEN 'approved' ELSE approval_status END
            WHERE id = ?
        ");
        $stmt->execute([$noticeId]);
        apiRespond(200, ['success' => true, 'message' => 'Notice archived successfully']);
    }

    if ($action === 'submit_for_review') {
        $stmt = $pdo->prepare("
            UPDATE notices
            SET status = 'pending_review',
                approval_status = 'pending'
            WHERE id = ? AND posted_by = ?
        ");
        $stmt->execute([$noticeId, $userId]);
        apiRespond(200, ['success' => true, 'message' => 'Draft submitted for approval']);
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

        $deliverySummary = null;
        if ($newStatus === 'published') {
            $deliverySummary = deliverNoticeToAudience($pdo, $noticeId);
        }

        apiRespond(200, [
            'success' => true,
            'message' => $newStatus === 'scheduled' ? 'Notice approved and scheduled' : 'Notice published successfully',
            'delivery_summary' => $deliverySummary,
        ]);
    }

    if (($action === 'approve' || $action === 'reject') && $userRole === 'super_admin') {
        $result = saveNoticeReviewDecision($pdo, $noticeId, $userId, $action, $reviewNotes ?: null);
        if (!$result) {
            apiRespond(400, ['success' => false, 'error' => 'The requested review action could not be completed']);
        }

        apiRespond(200, [
            'success' => true,
            'message' => $action === 'approve' ? 'Notice approved successfully' : 'Notice rejected successfully',
            'result' => $result,
        ]);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported notice action']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    apiRespond(500, ['success' => false, 'error' => 'Unable to update notices right now']);
}
?>
