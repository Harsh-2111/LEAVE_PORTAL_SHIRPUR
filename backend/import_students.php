<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$user = requireRole(['admin']);
requireCsrfToken();
$resetExistingPasswords = ($_POST['reset_existing_passwords'] ?? '') === '1';

$upload = $_FILES['students_csv'] ?? null;
if (!is_array($upload) || $upload['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'The CSV exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The CSV exceeds the allowed upload size.',
        UPLOAD_ERR_PARTIAL => 'The CSV upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'Choose a CSV file to import.',
    ];
    $message = is_array($upload)
        ? ($uploadErrors[$upload['error']] ?? 'CSV file upload failed.')
        : 'Choose a CSV file to import.';
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (
    !isset($upload['tmp_name'], $upload['name'], $upload['size'])
    || strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'csv'
    || (int)$upload['size'] > 2 * 1024 * 1024
    || !is_uploaded_file($upload['tmp_name'])
    || !is_readable($upload['tmp_name'])
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload a readable CSV file smaller than 2 MB.']);
    exit;
}

$columnMap = [
    'sap_id' => ['sap_id', 'sapid', 'student_id', 'sap id'],
    'name' => ['name', 'student_name', 'full_name', 'student name'],
    'student_contact' => ['student_contact', 'student_phone', 'student mobile', 'student phone'],
    'gender' => ['gender', 'student_gender'],
    'course' => ['course', 'program_type', 'degree'],
    'year' => ['year', 'academic_year', 'student_year'],
    'branch' => ['branch', 'program', 'department'],
    'batch' => ['batch', 'section', 'class_batch'],
    'hostel_block' => ['hostel_block', 'block', 'hostel block'],
    'room_no' => ['room_no', 'room', 'room number'],
    'parent_name' => ['parent_name', 'father_name', 'guardian_name'],
    'parent_email' => ['parent_email', 'guardian_email', 'email'],
    'parent_contact' => ['parent_contact', 'parent_phone', 'guardian_phone', 'contact_number'],
];
$normalize = static function (string $value): string {
    $value = strtolower(trim($value));
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', $value), '_');
};

$handle = fopen($upload['tmp_name'], 'r');
if ($handle === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded CSV file.']);
    exit;
}

$header = fgetcsv($handle, null, ',', '"', '');
if ($header === false || count($header) === 0) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSV header row is missing.']);
    exit;
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
$headerMap = [];
foreach ($header as $index => $columnName) {
    $headerMap[$normalize((string)$columnName)] = $index;
}

$resolvedColumns = [];
foreach ($columnMap as $field => $aliases) {
    foreach ($aliases as $alias) {
        $normalizedAlias = $normalize($alias);
        if (isset($headerMap[$normalizedAlias])) {
            $resolvedColumns[$field] = $headerMap[$normalizedAlias];
            break;
        }
    }
}

$missingColumns = [];
foreach (['sap_id', 'name', 'student_contact', 'gender', 'course', 'year', 'branch', 'batch', 'parent_email', 'parent_contact'] as $requiredField) {
    if (!isset($resolvedColumns[$requiredField])) {
        $missingColumns[] = $requiredField;
    }
}
if ($missingColumns !== []) {
    fclose($handle);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CSV is missing required columns: ' . implode(', ', $missingColumns),
    ]);
    exit;
}

$records = [];
$seenSapIds = [];
$recordRows = [];
$errors = [];
$errorCount = 0;
$lineNumber = 1;
$addError = static function (int $rowNumber, string $sapId, string $message) use (&$errors, &$errorCount): void {
    $errorCount++;
    if (count($errors) < 100) {
        $errors[] = ['row' => $rowNumber, 'sap_id' => $sapId, 'message' => $message];
    }
};
$courseMap = [
    'btech' => 'BTech',
    'mbatech' => 'MBATech',
    'bpharm' => 'BPharm',
    'mpharm' => 'MPharm',
    'agriculture' => 'Agriculture',
];

while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
    $lineNumber++;
    if (count($row) === 1 && trim((string)$row[0]) === '') {
        continue;
    }
    if ($lineNumber > 2501) {
        $addError($lineNumber, '', 'Import is limited to 2,500 data rows per CSV.');
        break;
    }

    $record = [];
    foreach ($resolvedColumns as $field => $index) {
        $record[$field] = trim((string)($row[$index] ?? ''));
    }
    $sapId = $record['sap_id'] ?? '';
    if (!preg_match('/^\d{11}$/', $sapId)) {
        $addError($lineNumber, $sapId, 'SAP ID must be exactly 11 digits.');
        continue;
    }
    if (isset($seenSapIds[$sapId])) {
        $addError($lineNumber, $sapId, 'Duplicate SAP ID in this CSV; the first valid row is used.');
        continue;
    }

    $studentName = $record['name'] ?? '';
    $yearInput = strtolower(trim($record['year'] ?? ''));
    $year = '';
    if (preg_match('/^([1-5])(?:st|nd|rd|th)?(?:\s+year)?$/', $yearInput, $yearMatch) === 1) {
        $yearSuffix = ['1' => 'st', '2' => 'nd', '3' => 'rd'][$yearMatch[1]] ?? 'th';
        $year = $yearMatch[1] . $yearSuffix . ' Year';
    }
    $branch = $record['branch'] ?? '';
    $batch = $record['batch'] ?? '';
    $studentContact = $record['student_contact'] ?? '';
    $gender = strtolower($record['gender'] ?? '');
    $courseInput = (string)preg_replace('/[^a-z]/', '', strtolower($record['course'] ?? ''));
    $course = $courseMap[$courseInput] ?? '';
    $parentEmail = $record['parent_email'] ?? '';
    $parentContact = $record['parent_contact'] ?? '';
    $parentName = $record['parent_name'] ?? '';
    $hostelBlock = $record['hostel_block'] ?? '';
    $roomNo = $record['room_no'] ?? '';
    $hasOversizedField = strlen($studentName) > 150
        || strlen($branch) > 100
        || strlen($batch) > 50
        || strlen($hostelBlock) > 50
        || strlen($roomNo) > 50
        || strlen($parentName) > 150
        || strlen($parentEmail) > 255;

    if (
        $studentName === '' || $hasOversizedField || $year === ''
        || $branch === '' || $batch === '' || $studentContact === ''
        || !in_array($gender, ['male', 'female'], true) || $course === ''
    ) {
        $addError($lineNumber, $sapId, 'Required fields are missing or too long, or the year, gender, or course is invalid.');
        continue;
    }
    if (
        !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[0-9+() -]{7,20}$/', $studentContact)
        || !preg_match('/^[0-9+() -]{7,20}$/', $parentContact)
    ) {
        $addError($lineNumber, $sapId, 'Student phone, parent email, or parent contact is invalid.');
        continue;
    }
    $seenSapIds[$sapId] = true;
    $recordRows[$sapId] = $lineNumber;
    $records[$sapId] = [
        'sap_id' => $sapId,
        'name' => $studentName,
        'student_contact' => $studentContact,
        'gender' => $gender,
        'course' => $course,
        'year' => $year,
        'branch' => $branch,
        'batch' => $batch,
        'hostel_block' => $hostelBlock,
        'room_no' => $roomNo,
        'parent_name' => $parentName,
        'parent_email' => $parentEmail,
        'parent_contact' => $parentContact,
    ];
}
fclose($handle);

if ($records === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'imported' => 0,
        'errors' => $errors,
        'error_count' => $errorCount,
        'message' => 'No valid student rows were found.',
    ]);
    exit;
}

$accounts = [];
$idChunks = array_chunk(array_keys($records), 300);
foreach ($idChunks as $ids) {
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $accountStmt = $pdo->prepare("SELECT login_id, role FROM users WHERE login_id IN ($placeholders)");
    $accountStmt->execute($ids);
    foreach ($accountStmt->fetchAll() as $account) {
        $accounts[$account['login_id']] = $account['role'];
    }
}

foreach ($records as $sapId => $record) {
    if (isset($accounts[$sapId]) && $accounts[$sapId] !== 'student') {
        $addError($recordRows[$sapId], $sapId, 'This login ID belongs to a non-student account; its role was not changed.');
        unset($records[$sapId]);
    }
}
if ($records === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'imported' => 0,
        'errors' => $errors,
        'error_count' => $errorCount,
        'message' => 'No records were imported because each valid SAP ID conflicts with a staff account.',
    ]);
    exit;
}

$credentialAccounts = [];
$usedTemporaryPasswords = [];
foreach ($records as $sapId => $record) {
    if (!isset($accounts[$sapId]) || $resetExistingPasswords) {
        if (count($usedTemporaryPasswords) >= 1000) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'imported' => 0,
                'message' => 'This import needs more than 1,000 temporary passwords. Split it into smaller imports or use a stronger password format.',
            ]);
            exit;
        }
        do {
            $temporaryPassword = 'stud@' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        } while (isset($usedTemporaryPasswords[$temporaryPassword]));
        $usedTemporaryPasswords[$temporaryPassword] = true;
        $credentialAccounts[$sapId] = [
            'sap_id' => $sapId,
            'temporary_password' => $temporaryPassword,
            'password_hash' => password_hash($temporaryPassword, PASSWORD_BCRYPT, ['cost' => 5]),
        ];
    }
}

$chunkSize = 100;
$studentColumns = [
    'sap_id', 'name', 'student_contact', 'gender', 'course', 'year', 'branch', 'batch',
    'hostel_block', 'room_no', 'parent_name', 'parent_email', 'parent_contact',
];
$studentPlaceholders = '(' . implode(', ', array_fill(0, count($studentColumns), '?'))
    . ', ' . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1') . ', NOW())';
$studentUpdate = implode(', ', array_map(
    static fn(string $column): string => $column . ' = ' . (DB_DRIVER === 'pgsql' ? 'EXCLUDED.' . $column : 'VALUES(' . $column . ')'),
    array_slice($studentColumns, 1)
));

try {
    $pdo->beginTransaction();

    foreach (array_chunk(array_values($records), $chunkSize) as $chunk) {
        $params = [];
        foreach ($chunk as $record) {
            foreach ($studentColumns as $column) {
                $params[] = $record[$column];
            }
        }
        $sql = 'INSERT INTO students (' . implode(', ', $studentColumns) . ', is_active, updated_at) VALUES '
            . implode(', ', array_fill(0, count($chunk), $studentPlaceholders))
            . (DB_DRIVER === 'pgsql'
                ? ' ON CONFLICT (sap_id) DO UPDATE SET ' . $studentUpdate . ', is_active = TRUE, updated_at = NOW()'
                : ' ON DUPLICATE KEY UPDATE ' . $studentUpdate . ', is_active = 1, updated_at = NOW()');
        $studentStmt = $pdo->prepare($sql);
        $studentStmt->execute($params);
    }

    $existingStudentIds = array_values(array_filter(
        array_keys($records),
        static fn(string $sapId): bool => isset($accounts[$sapId])
    ));
    $credentialAccountIds = array_keys($credentialAccounts);
    $newAccountIds = array_values(array_filter(
        $credentialAccountIds,
        static fn(string $sapId): bool => !isset($accounts[$sapId])
    ));
    foreach (array_chunk($existingStudentIds, 100) as $ids) {
        if ($ids === []) {
            continue;
        }
        $caseParts = [];
        $params = [];
        foreach ($ids as $sapId) {
            $caseParts[] = 'WHEN ? THEN ?';
            $params[] = $sapId;
            $params[] = $records[$sapId]['name'];
        }
        $assignments = 'name = CASE login_id ' . implode(' ', $caseParts) . ' ELSE name END';
        if ($resetExistingPasswords) {
            $passwordCases = [];
            foreach ($ids as $sapId) {
                $passwordCases[] = 'WHEN ? THEN ?';
                $params[] = $sapId;
                $params[] = $credentialAccounts[$sapId]['password_hash'];
            }
            $assignments .= ', password_hash = CASE login_id ' . implode(' ', $passwordCases)
                . ' ELSE password_hash END, must_change_password = '
                . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1');
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);
        $updateUsers = $pdo->prepare(
            'UPDATE users SET ' . $assignments . ', is_active = '
            . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
            . ', updated_at = NOW()'
            . " WHERE role = 'student' AND login_id IN ($placeholders)"
        );
        $updateUsers->execute($params);
    }

    foreach (array_chunk($newAccountIds, 100) as $ids) {
        $params = [];
        $userValues = [];
        foreach ($ids as $sapId) {
            $userValues[] = '(?, ?, ?, ?, ' . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1') . ', ' . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1') . ')';
            $params[] = $sapId;
            $params[] = $credentialAccounts[$sapId]['password_hash'];
            $params[] = 'student';
            $params[] = $records[$sapId]['name'];
        }
        $userSql = 'INSERT INTO users (login_id, password_hash, role, name, must_change_password, is_active) VALUES '
            . implode(', ', $userValues)
            . (DB_DRIVER === 'pgsql' ? ' ON CONFLICT (login_id) DO NOTHING' : ' ON DUPLICATE KEY UPDATE login_id = VALUES(login_id)');
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute($params);
    }

    foreach (array_chunk($credentialAccountIds, 300) as $ids) {
        if ($ids === []) {
            continue;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $verifyStmt = $pdo->prepare("SELECT login_id, password_hash FROM users WHERE login_id IN ($placeholders)");
        $verifyStmt->execute($ids);
        $storedHashes = [];
        foreach ($verifyStmt->fetchAll() as $row) {
            $storedHashes[$row['login_id']] = $row['password_hash'];
        }
        foreach ($ids as $sapId) {
            if (
                !isset($storedHashes[$sapId])
                || !password_verify($credentialAccounts[$sapId]['temporary_password'], $storedHashes[$sapId])
            ) {
                throw new RuntimeException('A temporary credential changed during import. No records were saved; retry the import.');
            }
        }
    }

    if ($resetExistingPasswords) {
        $pdo->exec('DELETE FROM app_sessions');
    }

    writeAudit(
        $pdo,
        (int)$user['id'],
        'import_students',
        'students',
        null,
        [
            'imported' => count($records),
            'new_accounts' => count($newAccountIds),
            'existing_password_resets' => count($credentialAccountIds) - count($newAccountIds),
            'row_errors' => $errorCount,
        ],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Student import failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Student import failed. No valid rows were saved. Please retry or contact support.']);
    exit;
}

$credentialRows = array_map(
    static fn(array $account): array => [
        'sap_id' => $account['sap_id'],
        'temporary_password' => $account['temporary_password'],
    ],
    array_values($credentialAccounts)
);

echo json_encode([
    'success' => true,
    'imported' => count($records),
    'credential_accounts_count' => count($credentialAccounts),
    'temporary_credentials' => $credentialRows,
    'errors' => $errors,
    'error_count' => $errorCount,
    'message' => count($records) . ' student record(s) imported or updated.'
        . ($resetExistingPasswords ? ' Temporary passwords were issued for all imported student accounts.' : ' Existing student passwords were not changed.')
        . ($errorCount > 0 ? ' Some CSV rows were skipped; review the row errors.' : ''),
]);
