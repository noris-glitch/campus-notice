<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiFetchVisibleShorts(PDO $pdo, array $user, array $filters = []): array
{
    [$conditions, $params] = buildShortAudienceConditions($pdo, 's', $user);
    $conditions[] = "s.status = 'published'";

    if (!empty($filters['short_id'])) {
        $conditions[] = 's.id = ?';
        $params[] = (int) $filters['short_id'];
    }

    $limit = isset($filters['limit']) ? max(1, min((int) $filters['limit'], 100)) : 60;
    $userId = (int) $user['id'];

    $sql = "
        SELECT
            s.*,
            u.name AS author_name,
            u.role AS author_role,
            f.name AS faculty_name,
            d.name AS department_name,
            CASE WHEN sv.id IS NULL THEN 0 ELSE 1 END AS has_viewed,
            (SELECT COUNT(*) FROM short_views all_sv WHERE all_sv.short_id = s.id) AS view_count
        FROM shorts s
        JOIN users u ON s.posted_by = u.id
        LEFT JOIN faculties f ON s.faculty_target = f.id
        LEFT JOIN departments d ON s.department_id = d.id
        LEFT JOIN short_views sv ON sv.short_id = s.id AND sv.user_id = ?
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY s.created_at DESC
        LIMIT {$limit}
    ";

    array_unshift($params, $userId);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shorts = $stmt->fetchAll();

    foreach ($shorts as &$short) {
        $short['duration_seconds'] = (int) ($short['duration_seconds'] ?? 0);
        $short['has_viewed'] = apiBool($short['has_viewed'] ?? 0);
        $short['view_count'] = (int) ($short['view_count'] ?? 0);
        $short['can_manage'] = apiCanManageShort($user, $short) ? 1 : 0;
    }

    return $shorts;
}

function apiFetchShortById(PDO $pdo, int $shortId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            s.*,
            u.name AS author_name,
            u.role AS author_role,
            f.name AS faculty_name,
            d.name AS department_name
        FROM shorts s
        JOIN users u ON s.posted_by = u.id
        LEFT JOIN faculties f ON s.faculty_target = f.id
        LEFT JOIN departments d ON s.department_id = d.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$shortId]);
    $short = $stmt->fetch();

    return $short ?: null;
}

function apiCanManageShort(array $user, array $short): bool
{
    if (($user['role'] ?? '') === 'super_admin') {
        return true;
    }

    if ((int) ($short['posted_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return true;
    }

    if (($user['role'] ?? '') === 'admin') {
        $adminFaculty = apiNullableInt($user['faculty_id'] ?? null);
        $shortFaculty = apiNullableInt($short['faculty_target'] ?? null);
        return $adminFaculty !== null && $shortFaculty !== null && $adminFaculty === $shortFaculty;
    }

    return false;
}

function apiEnsureShortVisible(PDO $pdo, array $user, int $shortId): array
{
    $visible = apiFetchVisibleShorts($pdo, $user, ['short_id' => $shortId, 'limit' => 1]);
    if (empty($visible)) {
        apiRespond(403, ['success' => false, 'error' => 'You do not have access to this short.']);
    }

    return $visible[0];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);

        apiRespond(200, [
            'success' => true,
            'shorts' => apiFetchVisibleShorts($pdo, $user),
            'faculties' => apiFetchFaculties($pdo),
            'departments' => apiFetchDepartments($pdo, ($user['role'] ?? '') === 'admin' ? apiNullableInt($user['faculty_id'] ?? null) : null),
            'audience_roles' => getNoticeAudienceRoleOptions(),
            'years' => apiAdminYears(),
            'student_scope_locked' => ($user['role'] ?? '') === 'student',
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $action = trim((string) ($data['action'] ?? 'create'));

    if ($action === 'create') {
        $title = trim((string) ($data['title'] ?? ''));
        $caption = trim((string) ($data['caption'] ?? ''));
        $durationSeconds = isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : 0;
        $facultyTarget = apiNullableInt($data['faculty_target'] ?? null);
        $departmentInput = apiNullableString($data['department_target'] ?? ($data['department_id'] ?? null));
        $departmentId = null;
        $yearTarget = apiNullableInt($data['year_target'] ?? null);
        $audienceRoles = normalizeAudienceRoles($data['audience_roles'] ?? ($data['audience_roles_csv'] ?? []));
        $userRole = (string) ($user['role'] ?? 'student');
        $adminFaculty = apiNullableInt($user['faculty_id'] ?? null);

        if ($title === '' && $caption === '') {
            apiRespond(400, ['success' => false, 'error' => 'Add a title or caption before posting a short.']);
        }

        if (strlen($title) > 160) {
            apiRespond(400, ['success' => false, 'error' => 'Short titles cannot exceed 160 characters.']);
        }

        if (strlen($caption) > 1200) {
            apiRespond(400, ['success' => false, 'error' => 'Captions cannot exceed 1200 characters.']);
        }

        if ($durationSeconds <= 0 || $durationSeconds > 60) {
            apiRespond(400, ['success' => false, 'error' => 'Short videos must be 60 seconds or less.']);
        }

        if ($userRole === 'student') {
            $facultyTarget = apiNullableInt($user['faculty_id'] ?? null);
            $departmentId = apiNullableInt($user['department_id'] ?? null);
            $yearTarget = apiNullableInt($user['year'] ?? null);
            $audienceRoles = ['student'];
        } else {
            if ($userRole === 'admin' && $adminFaculty !== null) {
                $facultyTarget = $facultyTarget ?: $adminFaculty;
            }

            if ($departmentInput !== null) {
                $departmentId = resolveDepartmentId($pdo, $departmentInput, $facultyTarget, true);
                if (!$departmentId) {
                    apiRespond(400, ['success' => false, 'error' => 'Choose a valid target department.']);
                }

                $department = fetchDepartmentById($pdo, $departmentId);
                if ($department) {
                    $departmentFacultyId = !empty($department['faculty_id']) ? (int) $department['faculty_id'] : null;
                    if ($facultyTarget === null && $departmentFacultyId !== null) {
                        $facultyTarget = $departmentFacultyId;
                    }

                    if ($facultyTarget !== null && $departmentFacultyId !== null && $facultyTarget !== $departmentFacultyId) {
                        apiRespond(400, ['success' => false, 'error' => 'That department does not belong to the selected faculty.']);
                    }

                    if ($userRole === 'admin' && $adminFaculty !== null && $departmentFacultyId !== null && $departmentFacultyId !== $adminFaculty) {
                        apiRespond(400, ['success' => false, 'error' => 'You can only post shorts inside your faculty.']);
                    }
                }
            }
        }

        $videoFilename = apiMoveUploadedFile(
            'video',
            'uploads/shorts',
            ['mp4', 'mov', 'm4v', 'webm'],
            'short'
        );

        if (!$videoFilename) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a video to upload.']);
        }

        $stmt = $pdo->prepare("
            INSERT INTO shorts (
                title,
                caption,
                video_filename,
                duration_seconds,
                posted_by,
                faculty_target,
                department_id,
                year_target,
                audience_roles_csv,
                status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', NOW(), NOW())
        ");
        $stmt->execute([
            $title,
            $caption,
            $videoFilename,
            $durationSeconds,
            (int) $user['id'],
            $facultyTarget,
            $departmentId,
            $yearTarget,
            arrayToCsvValue($audienceRoles),
        ]);

        $shortId = (int) $pdo->lastInsertId();
        logActivity($pdo, (int) $user['id'], 'mobile_short_created', 'Short ID ' . $shortId . ' created');

        apiRespond(200, [
            'success' => true,
            'message' => 'Short posted successfully.',
            'short_id' => $shortId,
        ]);
    }

    if ($action === 'view') {
        $shortId = isset($data['short_id']) ? (int) $data['short_id'] : 0;
        if ($shortId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Short ID is required.']);
        }

        apiEnsureShortVisible($pdo, $user, $shortId);

        $stmt = $pdo->prepare('INSERT IGNORE INTO short_views (short_id, user_id) VALUES (?, ?)');
        $stmt->execute([$shortId, (int) $user['id']]);

        apiRespond(200, ['success' => true, 'message' => 'Short view recorded.']);
    }

    if ($action === 'delete') {
        $shortId = isset($data['short_id']) ? (int) $data['short_id'] : 0;
        if ($shortId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'Short ID is required.']);
        }

        $short = apiFetchShortById($pdo, $shortId);
        if (!$short) {
            apiRespond(404, ['success' => false, 'error' => 'The selected short could not be found.']);
        }

        if (!apiCanManageShort($user, $short)) {
            apiRespond(403, ['success' => false, 'error' => 'You do not have permission to delete that short.']);
        }

        $stmt = $pdo->prepare('DELETE FROM shorts WHERE id = ?');
        $stmt->execute([$shortId]);

        if (!empty($short['video_filename'])) {
            apiDeleteUploadedFile('uploads/shorts/' . $short['video_filename']);
        }

        logActivity($pdo, (int) $user['id'], 'mobile_short_deleted', 'Short ID ' . $shortId . ' deleted');

        apiRespond(200, ['success' => true, 'message' => 'Short deleted successfully.']);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported shorts action.']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to update shorts right now.']);
}
?>
