<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiRequireSuperAdminForUsers(array $user): void
{
    if (($user['role'] ?? '') !== 'super_admin') {
        apiRespond(403, ['success' => false, 'error' => 'Super administrator access is required']);
    }
}

function apiManageUserRoles(): array
{
    return ['super_admin', 'admin', 'student'];
}

function apiManageUserAdminTypes(): array
{
    return [
        'faculty' => 'Faculty Member',
        'hod' => 'Head of Faculty',
        'dean_of_students' => 'Dean of Students',
        'student_leader' => 'Student Leader',
        'club_leader' => 'Club Leader',
    ];
}

function apiFetchManagedUsers(PDO $pdo, int $currentUserId): array
{
    $stmt = $pdo->prepare("
        SELECT
            u.*,
            f.name AS faculty_name,
            d.name AS department_name
        FROM users u
        LEFT JOIN faculties f ON u.faculty_id = f.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.id != ?
        ORDER BY FIELD(u.role, 'super_admin', 'admin', 'student'), u.name
    ");
    $stmt->execute([$currentUserId]);

    return $stmt->fetchAll();
}

function apiFetchManagedUserStats(PDO $pdo, int $currentUserId): array
{
    $totalUsersStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id != ?');
    $totalUsersStmt->execute([$currentUserId]);

    $superAdminsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND id != ?");
    $superAdminsStmt->execute([$currentUserId]);

    return [
        'total_users' => (int) $totalUsersStmt->fetchColumn(),
        'total_super_admins' => (int) $superAdminsStmt->fetchColumn(),
        'total_admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
        'total_students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
        'active_users' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn(),
        'students_missing_departments' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (department_id IS NULL OR department_id = 0)")->fetchColumn(),
        'students_missing_phone_numbers' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (phone_number IS NULL OR TRIM(phone_number) = '')")->fetchColumn(),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        apiRequireSuperAdminForUsers($user);

        apiRespond(200, [
            'success' => true,
            'admin_types' => apiManageUserAdminTypes(),
            'faculties' => apiFetchFaculties($pdo),
            'departments' => apiFetchDepartments($pdo),
            'stats' => apiFetchManagedUserStats($pdo, (int) $user['id']),
            'users' => apiFetchManagedUsers($pdo, (int) $user['id']),
            'years' => apiAdminYears(),
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    apiRequireSuperAdminForUsers($user);

    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'create') {
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $studentId = trim((string) ($data['student_id'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = trim((string) ($data['role'] ?? 'student'));
        $adminType = trim((string) ($data['admin_type'] ?? ''));
        $facultyId = apiNullableInt($data['faculty_id'] ?? null);
        $phoneNumberRaw = trim((string) ($data['phone_number'] ?? ''));
        $phoneNumber = normalizePhoneNumber($phoneNumberRaw !== '' ? $phoneNumberRaw : null);
        $year = apiNullableInt($data['year'] ?? null);
        $membership = apiNullableString($data['membership'] ?? null);
        $departmentInput = apiNullableString($data['department_name'] ?? ($data['department_id'] ?? null));
        $departmentId = null;

        if ($name === '' || $email === '' || $studentId === '' || $password === '') {
            apiRespond(400, ['success' => false, 'error' => 'Name, email, student or staff ID, and password are required']);
        }

        if (strlen($password) < 6) {
            apiRespond(400, ['success' => false, 'error' => 'Password must be at least 6 characters']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid email address']);
        }

        if ($phoneNumberRaw !== '' && $phoneNumber === null) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid phone number']);
        }

        if (!in_array($role, apiManageUserRoles(), true)) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid role']);
        }

        if ($role !== 'admin') {
            $adminType = '';
        } elseif ($adminType !== '' && !array_key_exists($adminType, apiManageUserAdminTypes())) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid admin type']);
        }

        $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $existsStmt->execute([$email]);
        if ((int) $existsStmt->fetchColumn() > 0) {
            apiRespond(400, ['success' => false, 'error' => 'Email already exists']);
        }

        $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE student_id = ?');
        $existsStmt->execute([$studentId]);
        if ((int) $existsStmt->fetchColumn() > 0) {
            apiRespond(400, ['success' => false, 'error' => 'Student or staff ID already exists']);
        }

        if ($departmentInput !== null) {
            $departmentId = resolveDepartmentId($pdo, $departmentInput, $facultyId, true);
            if (!$departmentId) {
                apiRespond(400, ['success' => false, 'error' => 'Choose a valid department']);
            }

            $department = fetchDepartmentById($pdo, $departmentId);
            if ($department && $facultyId !== null && !empty($department['faculty_id']) && (int) $department['faculty_id'] !== $facultyId) {
                apiRespond(400, ['success' => false, 'error' => 'That department does not belong to the selected faculty']);
            }
        }

        $insert = $pdo->prepare("
            INSERT INTO users (
                name, email, student_id, password, role, admin_type,
                faculty_id, department_id, phone_number, year, membership, is_approved, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        $insert->execute([
            $name,
            $email,
            $studentId,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $adminType !== '' ? $adminType : null,
            $facultyId,
            $departmentId,
            $phoneNumber,
            $year,
            $membership,
        ]);

        logActivity($pdo, (int) $user['id'], 'mobile_user_created', 'Managed user ID ' . $pdo->lastInsertId());

        apiRespond(200, [
            'success' => true,
            'message' => 'User added successfully.',
        ]);
    }

    if ($action === 'update') {
        $managedUserId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $role = trim((string) ($data['role'] ?? 'student'));
        $adminType = trim((string) ($data['admin_type'] ?? ''));
        $facultyId = apiNullableInt($data['faculty_id'] ?? null);
        $phoneNumberRaw = trim((string) ($data['phone_number'] ?? ''));
        $phoneNumber = normalizePhoneNumber($phoneNumberRaw !== '' ? $phoneNumberRaw : null);
        $year = apiNullableInt($data['year'] ?? null);
        $membership = apiNullableString($data['membership'] ?? null);
        $departmentInput = apiNullableString($data['department_name'] ?? ($data['department_id'] ?? null));
        $departmentId = null;
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($managedUserId <= 0 || $name === '' || $email === '') {
            apiRespond(400, ['success' => false, 'error' => 'User, name, and email are required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid email address']);
        }

        if ($phoneNumberRaw !== '' && $phoneNumber === null) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid phone number']);
        }

        if (!in_array($role, apiManageUserRoles(), true)) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid role']);
        }

        if ($managedUserId === (int) $user['id'] && $role !== 'super_admin') {
            apiRespond(400, ['success' => false, 'error' => 'You cannot change your own role']);
        }

        if ($role !== 'admin') {
            $adminType = '';
        } elseif ($adminType !== '' && !array_key_exists($adminType, apiManageUserAdminTypes())) {
            apiRespond(400, ['success' => false, 'error' => 'Choose a valid admin type']);
        }

        $existingStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $existingStmt->execute([$managedUserId]);
        $existing = $existingStmt->fetch();

        if (!$existing) {
            apiRespond(404, ['success' => false, 'error' => 'User not found']);
        }

        $emailStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
        $emailStmt->execute([$email, $managedUserId]);
        if ((int) $emailStmt->fetchColumn() > 0) {
            apiRespond(400, ['success' => false, 'error' => 'Email already exists']);
        }

        if ($departmentInput !== null) {
            $departmentId = resolveDepartmentId($pdo, $departmentInput, $facultyId, true);
            if (!$departmentId) {
                apiRespond(400, ['success' => false, 'error' => 'Choose a valid department']);
            }

            $department = fetchDepartmentById($pdo, $departmentId);
            if ($department && $facultyId !== null && !empty($department['faculty_id']) && (int) $department['faculty_id'] !== $facultyId) {
                apiRespond(400, ['success' => false, 'error' => 'That department does not belong to the selected faculty']);
            }
        }

        $update = $pdo->prepare("
            UPDATE users
            SET name = ?, email = ?, role = ?, admin_type = ?, faculty_id = ?, department_id = ?, phone_number = ?, year = ?, membership = ?, is_active = ?
            WHERE id = ?
        ");
        $update->execute([
            $name,
            $email,
            $role,
            $adminType !== '' ? $adminType : null,
            $facultyId,
            $departmentId,
            $phoneNumber,
            $year,
            $membership,
            $isActive,
            $managedUserId,
        ]);

        logActivity($pdo, (int) $user['id'], 'mobile_user_updated', 'Managed user ID ' . $managedUserId);

        apiRespond(200, [
            'success' => true,
            'message' => 'User updated successfully.',
        ]);
    }

    if ($action === 'delete') {
        $managedUserId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        if ($managedUserId <= 0) {
            apiRespond(400, ['success' => false, 'error' => 'User ID is required']);
        }

        if ($managedUserId === (int) $user['id']) {
            apiRespond(400, ['success' => false, 'error' => 'You cannot delete your own account']);
        }

        $existingStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $existingStmt->execute([$managedUserId]);
        $existing = $existingStmt->fetch();

        if (!$existing) {
            apiRespond(404, ['success' => false, 'error' => 'User not found']);
        }

        $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $delete->execute([$managedUserId]);

        logActivity($pdo, (int) $user['id'], 'mobile_user_deleted', 'Managed user ID ' . $managedUserId);

        apiRespond(200, [
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported user management action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'User management action failed right now']);
}
?>
