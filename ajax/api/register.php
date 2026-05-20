<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiPasswordStrengthErrors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    if (!preg_match('/[@$!%*?&#]/', $password)) {
        $errors[] = 'Password must contain at least one special character (@$!%*?&#)';
    }

    return $errors;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        apiRespond(200, [
            'success' => true,
            'faculties' => array_values(array_filter(
                apiFetchFaculties($pdo),
                static fn(array $faculty): bool => ($faculty['name'] ?? '') !== 'Dean of Students'
            )),
            'departments' => apiFetchDepartments($pdo),
            'years' => [
                ['value' => 1, 'label' => '1st Year'],
                ['value' => 2, 'label' => '2nd Year'],
                ['value' => 3, 'label' => '3rd Year'],
                ['value' => 4, 'label' => '4th Year'],
            ],
        ]);
    }

    $data = apiRequestData();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $studentId = trim((string) ($data['student_id'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');
    $year = apiNullableInt($data['year'] ?? null);
    $facultyId = apiNullableInt($data['faculty_id'] ?? null);
    $phoneNumber = normalizePhoneNumber($data['phone_number'] ?? null);
    $membership = apiNullableString($data['membership'] ?? null);
    $departmentInput = apiNullableString($data['department_name'] ?? ($data['department_id'] ?? null));
    $departmentId = null;

    $errors = [];

    if ($name === '') {
        $errors[] = 'Full name is required';
    }
    if ($email === '') {
        $errors[] = 'Email address is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }
    if ($studentId === '') {
        $errors[] = 'Student ID is required';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    if ($year === null) {
        $errors[] = 'Please select your year of study';
    }
    if ($facultyId === null && count(apiFetchFaculties($pdo)) > 0) {
        $errors[] = 'Please select a faculty';
    }
    if (($data['phone_number'] ?? '') !== '' && $phoneNumber === null) {
        $errors[] = 'Please enter a valid phone number';
    }

    $errors = array_merge($errors, apiPasswordStrengthErrors($password));

    if ($departmentInput !== null) {
        $departmentId = resolveDepartmentId($pdo, $departmentInput, $facultyId, true);
        if (!$departmentId) {
            $errors[] = 'Please choose a valid department';
        } else {
            $department = fetchDepartmentById($pdo, $departmentId);
            if ($department && $facultyId !== null && !empty($department['faculty_id']) && (int) $department['faculty_id'] !== $facultyId) {
                $errors[] = 'That department does not belong to the selected faculty';
            }
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'Email address already registered';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE student_id = ?');
    $stmt->execute([$studentId]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'Student ID already registered';
    }

    if (!empty($errors)) {
        apiRespond(400, [
            'success' => false,
            'error' => $errors[0],
            'errors' => $errors,
        ]);
    }

    $fields = ['name', 'email', 'student_id', 'password', 'role', 'is_approved', 'is_active', 'year'];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];
    $params = [
        $name,
        $email,
        $studentId,
        password_hash($password, PASSWORD_DEFAULT),
        'student',
        1,
        1,
        $year,
    ];

    if ($facultyId !== null) {
        $fields[] = 'faculty_id';
        $placeholders[] = '?';
        $params[] = $facultyId;
    }

    if ($departmentId !== null && columnExists($pdo, 'users', 'department_id')) {
        $fields[] = 'department_id';
        $placeholders[] = '?';
        $params[] = $departmentId;
    }

    if ($phoneNumber !== null && columnExists($pdo, 'users', 'phone_number')) {
        $fields[] = 'phone_number';
        $placeholders[] = '?';
        $params[] = $phoneNumber;
    }

    if ($membership !== null && columnExists($pdo, 'users', 'membership')) {
        $fields[] = 'membership';
        $placeholders[] = '?';
        $params[] = $membership;
    }

    $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $newUserId = (int) $pdo->lastInsertId();
    logActivity($pdo, $newUserId, 'mobile_register', 'Student account created through the mobile app');

    apiRespond(200, [
        'success' => true,
        'message' => 'Account created successfully. You can now log in.',
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to create your account right now']);
}
?>
