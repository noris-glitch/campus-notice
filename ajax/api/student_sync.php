<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiRequireStudentSyncRole(array $user): void
{
    $role = (string) ($user['role'] ?? '');
    if ($role !== 'admin' && $role !== 'super_admin') {
        apiRespond(403, ['success' => false, 'error' => 'Administrator access is required']);
    }
}

function apiStudentSyncMeta(): array
{
    return [
        'sample_columns' => ['student_id', 'email', 'name', 'faculty', 'faculty_id', 'department', 'department_id', 'phone_number', 'year', 'membership'],
        'note' => 'This sync updates existing student accounts by student_id or email. Use it to backfill departments and phone numbers in bulk. Unmatched rows are skipped.',
    ];
}

function apiStudentBackfillSummary(PDO $pdo, array $user): array
{
    $conditions = ["u.role = 'student'"];
    $params = [];

    if (($user['role'] ?? '') === 'admin' && !empty($user['faculty_id'])) {
        $conditions[] = 'u.faculty_id = ?';
        $params[] = (int) $user['faculty_id'];
    }

    $whereSql = implode(' AND ', $conditions);

    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_students,
            SUM(CASE WHEN u.department_id IS NULL OR u.department_id = 0 THEN 1 ELSE 0 END) AS missing_departments,
            SUM(CASE WHEN u.phone_number IS NULL OR TRIM(u.phone_number) = '' THEN 1 ELSE 0 END) AS missing_phone_numbers,
            SUM(
                CASE
                    WHEN (u.department_id IS NULL OR u.department_id = 0)
                        AND (u.phone_number IS NULL OR TRIM(u.phone_number) = '')
                    THEN 1
                    ELSE 0
                END
            ) AS missing_both
        FROM users u
        WHERE {$whereSql}
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch() ?: [];

    $samplesStmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.student_id,
            u.phone_number,
            f.name AS faculty_name
        FROM users u
        LEFT JOIN faculties f ON u.faculty_id = f.id
        WHERE {$whereSql}
            AND ((u.department_id IS NULL OR u.department_id = 0) OR (u.phone_number IS NULL OR TRIM(u.phone_number) = ''))
        ORDER BY u.name
        LIMIT 8
    ");
    $samplesStmt->execute($params);

    $totalStudents = (int) ($stats['total_students'] ?? 0);
    $missingDepartments = (int) ($stats['missing_departments'] ?? 0);
    $missingPhoneNumbers = (int) ($stats['missing_phone_numbers'] ?? 0);
    $missingBoth = (int) ($stats['missing_both'] ?? 0);

    return [
        'total_students' => $totalStudents,
        'missing_departments' => $missingDepartments,
        'missing_phone_numbers' => $missingPhoneNumbers,
        'missing_both' => $missingBoth,
        'ready_profiles' => max(0, $totalStudents - ($missingDepartments + $missingPhoneNumbers - $missingBoth)),
        'samples' => $samplesStmt->fetchAll(),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        apiRequireStudentSyncRole($user);

        apiRespond(200, array_merge([
            'success' => true,
            'updated' => 0,
            'skipped' => 0,
            'issues' => [],
            'backfill_summary' => apiStudentBackfillSummary($pdo, $user),
        ], apiStudentSyncMeta()));
    }

    $user = apiFetchAuthenticatedUser($pdo, $_POST);
    apiRequireStudentSyncRole($user);

    $action = trim((string) ($_POST['action'] ?? 'upload_csv'));
    if ($action !== 'upload_csv') {
        apiRespond(400, ['success' => false, 'error' => 'Unsupported student sync action']);
    }

    if (!isset($_FILES['csv_file']) || (int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        apiRespond(400, ['success' => false, 'error' => 'Please choose a CSV file to upload.']);
    }

    $handle = fopen((string) $_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) {
        apiRespond(400, ['success' => false, 'error' => 'The CSV file could not be opened.']);
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        apiRespond(400, ['success' => false, 'error' => 'The CSV file appears to be empty.']);
    }

    $columns = array_map(static function ($value) {
        return strtolower(trim((string) $value));
    }, $header);

    $userRole = (string) $user['role'];
    $adminFaculty = apiNullableInt($user['faculty_id'] ?? null);
    $results = [
        'updated' => 0,
        'skipped' => 0,
        'issues' => [],
    ];

    while (($row = fgetcsv($handle)) !== false) {
        $record = [];
        foreach ($columns as $index => $column) {
            $record[$column] = trim((string) ($row[$index] ?? ''));
        }

        $studentId = $record['student_id'] ?? '';
        $email = $record['email'] ?? '';
        $name = $record['name'] ?? '';
        $year = isset($record['year']) && $record['year'] !== '' ? (int) $record['year'] : null;
        $membership = $record['membership'] ?? null;
        $facultyRaw = $record['faculty'] ?? ($record['faculty_id'] ?? '');
        $departmentRaw = $record['department'] ?? ($record['department_id'] ?? '');
        $phoneRaw = $record['phone_number'] ?? '';

        if ($studentId === '' && $email === '') {
            $results['skipped']++;
            $results['issues'][] = 'Skipped a row without student_id or email.';
            continue;
        }

        $query = "SELECT * FROM users WHERE role = 'student' AND (student_id = ?";
        $params = [$studentId];
        if ($email !== '') {
            $query .= ' OR email = ?';
            $params[] = $email;
        }
        $query .= ') LIMIT 1';

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $existing = $stmt->fetch();

        if (!$existing) {
            $results['skipped']++;
            $results['issues'][] = 'No existing student matched ' . ($studentId ?: $email) . '.';
            continue;
        }

        $facultyId = $facultyRaw !== ''
            ? resolveFacultyId($pdo, $facultyRaw)
            : ($userRole === 'admin' ? $adminFaculty : ($existing['faculty_id'] ?? null));

        if ($facultyRaw !== '' && !$facultyId) {
            $results['skipped']++;
            $results['issues'][] = 'Unknown faculty "' . $facultyRaw . '" for ' . ($studentId ?: $email) . '.';
            continue;
        }

        if ($userRole === 'admin' && $adminFaculty && $facultyId && (int) $facultyId !== (int) $adminFaculty) {
            $results['skipped']++;
            $results['issues'][] = 'Skipped ' . ($studentId ?: $email) . ' because the row targets a different faculty.';
            continue;
        }

        $departmentId = null;
        if ($departmentRaw !== '') {
            $departmentId = resolveDepartmentId($pdo, $departmentRaw, $facultyId, true);
            if (!$departmentId) {
                $results['skipped']++;
                $results['issues'][] = 'Unknown department "' . $departmentRaw . '" for ' . ($studentId ?: $email) . '.';
                continue;
            }

            $department = fetchDepartmentById($pdo, $departmentId);
            if ($department && $facultyId !== null && !empty($department['faculty_id']) && (int) $department['faculty_id'] !== (int) $facultyId) {
                $results['skipped']++;
                $results['issues'][] = 'Department "' . $departmentRaw . '" does not belong to the resolved faculty for ' . ($studentId ?: $email) . '.';
                continue;
            }
        }

        $phoneNumber = null;
        if ($phoneRaw !== '') {
            $phoneNumber = normalizePhoneNumber($phoneRaw);
            if ($phoneNumber === null) {
                $results['skipped']++;
                $results['issues'][] = 'Invalid phone number "' . $phoneRaw . '" for ' . ($studentId ?: $email) . '.';
                continue;
            }
        }

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET name = ?, email = ?, faculty_id = ?, department_id = ?, phone_number = ?, year = ?, membership = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $name !== '' ? $name : $existing['name'],
            $email !== '' ? $email : $existing['email'],
            $facultyId ?: ($existing['faculty_id'] ?? null),
            $departmentId ?: ($existing['department_id'] ?? null),
            $phoneNumber ?: ($existing['phone_number'] ?? null),
            $year ?: ($existing['year'] ?? null),
            $membership !== null && $membership !== '' ? $membership : ($existing['membership'] ?? null),
            (int) $existing['id'],
        ]);

        $results['updated']++;
    }

    fclose($handle);
    logActivity($pdo, (int) $user['id'], 'mobile_student_sync', 'Updated ' . $results['updated'] . ' student records');

    apiRespond(200, array_merge([
        'success' => true,
        'message' => 'Updated ' . $results['updated'] . ' student records. Skipped ' . $results['skipped'] . ' rows.',
        'backfill_summary' => apiStudentBackfillSummary($pdo, $user),
    ], $results, apiStudentSyncMeta()));
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Student sync failed right now']);
}
?>
