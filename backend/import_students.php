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
    'sap_id' => ['sap_id', 'sapid', 'student_id', 'sap id', 'student number', 'student_number'],
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
    'parent_email' => ['secondary e mail id', 'secondary email id', 'secondary_email_id', 'official email id', 'official_email_id', 'official mail id', 'official_mail_id', 'parent_email', 'guardian_email', 'email'],
    'parent_contact' => ['father_contact', 'father mobile no', 'father mobile number', 'father phone', 'parent_contact', 'parent_phone', 'guardian_phone', 'contact_number'],
    'mother_contact' => ['mother_contact', 'mother mobile no', 'mother mobile number', 'mother phone'],
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
foreach (['sap_id', 'name', 'gender', 'course', 'year', 'branch', 'parent_email'] as $requiredField) {
    if (!isset($resolvedColumns[$requiredField])) {
        $missingColumns[] = $requiredField;
    }
}
if (!isset($resolvedColumns['parent_contact']) && !isset($resolvedColumns['mother_contact'])) {
    $missingColumns[] = 'father_contact or mother_contact';
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
$dataRowCount = 0;
$rowLimitExceeded = false;
$resetStudentAccounts = ($_POST['reset_student_accounts'] ?? '') === '1';
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
    $dataRowCount++;
    if ($dataRowCount > 2500) {
        if (!$rowLimitExceeded) {
            $addError($lineNumber, '', 'Import is limited to 2,500 data rows per CSV; rows after the limit were not imported.');
            $rowLimitExceeded = true;
        }
        continue;
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
    $studentContact = trim($record['student_contact'] ?? '');
    $gender = strtolower($record['gender'] ?? '');
    $courseInput = (string)preg_replace('/[^a-z]/', '', strtolower($record['course'] ?? ''));
    $course = $courseMap[$courseInput] ?? '';
    $parentEmail = $record['parent_email'] ?? '';
    $fatherContact = $record['parent_contact'] ?? '';
    $motherContact = $record['mother_contact'] ?? '';
    $parentContact = $fatherContact !== '' ? $fatherContact : $motherContact;
    $parentName = $record['parent_name'] ?? '';
    $hostelBlock = $record['hostel_block'] ?? '';
    $hasOversizedField = strlen($studentName) > 150
        || strlen($branch) > 100
        || strlen($batch) > 50
        || strlen($hostelBlock) > 50
        || strlen($parentName) > 150
        || strlen($parentEmail) > 255;

    if (
        $studentName === '' || $hasOversizedField || $year === ''
        || $branch === ''
        || !in_array($gender, ['male', 'female'], true) || $course === ''
    ) {
        $addError($lineNumber, $sapId, 'Required fields are missing or too long, or the year, gender, or course is invalid.');
        continue;
    }
    if (
        !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)
        || ($studentContact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $studentContact))
        || ($fatherContact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $fatherContact))
        || ($motherContact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $motherContact))
        || ($fatherContact === '' && $motherContact === '')
    ) {
        $addError($lineNumber, $sapId, 'Student phone or official email is invalid, or neither parent contact is valid.');
        continue;
    }
    $seenSapIds[$sapId] = true;
    $recordRows[$sapId] = $lineNumber;
    $records[$sapId] = [
        'sap_id' => $sapId,
        'name' => $studentName,
        'student_contact' => $studentContact === '' ? null : $studentContact,
        'gender' => $gender,
        'course' => $course,
        'year' => $year,
        'branch' => $branch,
        'batch' => $batch,
        'hostel_block' => $hostelBlock,
        'parent_name' => $parentName,
        'parent_email' => $parentEmail,
        'parent_contact' => $parentContact,
        'mother_contact' => $motherContact === '' ? null : $motherContact,
    ];
}
fclose($handle);

if ($records === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'imported' => 0,
        'total_rows' => $dataRowCount,
        'skipped' => $dataRowCount,
        'errors' => $errors,
        'error_count' => $errorCount,
        'message' => 'No valid student rows were found: 0 of ' . $dataRowCount . ' non-empty CSV data row(s) imported; '
            . $dataRowCount . ' row(s) skipped.',
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
        'total_rows' => $dataRowCount,
        'skipped' => $dataRowCount,
        'errors' => $errors,
        'error_count' => $errorCount,
        'message' => 'No records were imported because each valid SAP ID conflicts with a staff account: 0 of '
            . $dataRowCount . ' non-empty CSV data row(s) imported; ' . $dataRowCount . ' row(s) skipped.',
    ]);
    exit;
}

$chunkSize = 100;
$studentColumns = [
    'sap_id', 'name', 'student_contact', 'gender', 'course', 'year', 'branch', 'batch',
    'hostel_block', 'parent_name', 'parent_email', 'parent_contact', 'mother_contact',
];
$studentPlaceholders = '(' . implode(', ', array_fill(0, count($studentColumns), '?'))
    . ', ' . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1') . ', NOW())';
$studentUpdate = implode(', ', array_map(
    static function (string $column): string {
        if ($column === 'student_contact' || $column === 'mother_contact') {
            return DB_DRIVER === 'pgsql'
                ? $column . ' = COALESCE(EXCLUDED.' . $column . ', students.' . $column . ')'
                : $column . ' = COALESCE(VALUES(' . $column . '), ' . $column . ')';
        }
        return $column . ' = ' . (DB_DRIVER === 'pgsql' ? 'EXCLUDED.' . $column : 'VALUES(' . $column . ')');
    },
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

    $resetAccountCount = 0;
    if ($resetStudentAccounts) {
        $registeredStudentIds = array_values(array_filter(
            array_keys($records),
            static fn(int|string $sapId): bool => ($accounts[$sapId] ?? null) === 'student'
        ));
        if ($registeredStudentIds !== []) {
            $unusablePasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            foreach (array_chunk($registeredStudentIds, 300) as $ids) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $resetStmt = $pdo->prepare(
                    'UPDATE users SET password_hash = ?, must_change_password = '
                    . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
                    . ', updated_at = NOW() WHERE role = ? AND is_active = '
                    . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
                    . " AND login_id IN ($placeholders)"
                );
                $resetStmt->execute(array_merge([$unusablePasswordHash, 'student'], $ids));
                $resetAccountCount += $resetStmt->rowCount();
            }
        }
    }

    writeAudit(
        $pdo,
        (int)$user['id'],
        'import_students',
        'students',
        null,
        [
            'imported' => count($records),
            'total_rows' => $dataRowCount,
            'skipped_rows' => $dataRowCount - count($records),
            'row_errors' => $errorCount,
            'student_accounts_reset' => $resetAccountCount,
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

echo json_encode([
    'success' => true,
    'imported' => count($records),
    'total_rows' => $dataRowCount,
    'skipped' => $dataRowCount - count($records),
    'student_accounts_reset' => $resetAccountCount,
    'errors' => $errors,
    'error_count' => $errorCount,
    'message' => count($records) . ' of ' . $dataRowCount . ' non-empty CSV data row(s) imported or updated; '
        . ($dataRowCount - count($records)) . ' row(s) skipped.'
        . ($resetStudentAccounts
            ? ' ' . $resetAccountCount . ' existing student account(s) must sign up again; student records and leave history were preserved.'
            : ' Students can create their own account from the sign-in page.')
        . ($errorCount > 0 ? ' Review the row errors below.' : ''),
]);
