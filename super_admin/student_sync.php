<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header('Location: ../login.php');
    exit();
}

$userRole = $_SESSION['user_role'];
$adminFaculty = $_SESSION['faculty_id'] ?? null;
$results = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    } else {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            $errors[] = 'The CSV file could not be opened.';
        } else {
            $header = fgetcsv($handle);
            if (!$header) {
                $errors[] = 'The CSV file appears to be empty.';
            } else {
                $columns = array_map(static function ($value) {
                    return strtolower(trim((string) $value));
                }, $header);

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

                    if ($studentId === '' && $email === '') {
                        $results['skipped']++;
                        $results['issues'][] = 'Skipped a row without student_id or email.';
                        continue;
                    }

                    $query = 'SELECT * FROM users WHERE role = \'student\' AND (student_id = ?';
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

                    $facultyId = $facultyRaw !== '' ? resolveFacultyId($pdo, $facultyRaw) : ($userRole === 'admin' ? (int) $adminFaculty : ($existing['faculty_id'] ?? null));
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

                    $updateStmt = $pdo->prepare("
                        UPDATE users
                        SET name = ?,
                            email = ?,
                            faculty_id = ?,
                            year = ?,
                            membership = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $name !== '' ? $name : $existing['name'],
                        $email !== '' ? $email : $existing['email'],
                        $facultyId ?: ($existing['faculty_id'] ?? null),
                        $year ?: ($existing['year'] ?? null),
                        $membership !== null && $membership !== '' ? $membership : ($existing['membership'] ?? null),
                        $existing['id'],
                    ]);

                    $results['updated']++;
                }
            }

            fclose($handle);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Sync - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .panel {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .message {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 14px;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">Student Sync</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <div class="panel">
                    <h3>Sync Student Records from CSV</h3>
                    <p style="color:#6b7280;">Upload registrar-style student data to update existing campus profiles by <code>student_id</code> or <code>email</code>. This sync is update-only, so unmatched rows are skipped instead of creating new accounts silently.</p>

                    <?php foreach ($errors as $error): ?>
                        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>

                    <?php if ($results): ?>
                        <div class="message success">
                            Updated <?php echo (int) $results['updated']; ?> student records.
                            Skipped <?php echo (int) $results['skipped']; ?> rows.
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div style="margin:20px 0;">
                            <label for="csv_file" style="display:block; margin-bottom:10px; font-weight:600;">CSV File</label>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn-primary">Upload and Sync</button>
                    </form>
                </div>

                <div class="panel">
                    <h3>Expected CSV Columns</h3>
                    <p style="color:#6b7280;">Use headers such as <code>student_id</code>, <code>email</code>, <code>name</code>, <code>faculty</code> or <code>faculty_id</code>, <code>year</code>, and <code>membership</code>.</p>
                    <pre style="background:#f8f9fa; padding:14px; border-radius:8px; overflow:auto;">student_id,email,name,faculty,year,membership
SIT-001,student1@example.com,Achieng Atieno,School of Informatics and innovative systems,4,Tech Club
SIT-002,student2@example.com,Otieno Mark,7,2,Science Club</pre>
                </div>

                <?php if ($results && !empty($results['issues'])): ?>
                    <div class="panel">
                        <h3>Sync Notes</h3>
                        <ul style="margin:0 0 0 18px; color:#6b7280;">
                            <?php foreach (array_slice($results['issues'], 0, 20) as $issue): ?>
                                <li><?php echo htmlspecialchars($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
