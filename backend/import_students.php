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
$resendPendingInvitations = ($_POST['resend_pending_invitations'] ?? '') === '1';

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
    'student_email' => ['student_email', 'official_email', 'student_email_address'],
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
foreach (['sap_id', 'name', 'student_contact', 'student_email', 'gender', 'course', 'year', 'branch', 'batch', 'parent_email', 'parent_contact'] as $requiredField) {
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
$seenStudentEmails = [];
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
    $studentEmail = strtolower($record['student_email'] ?? '');
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
        || strlen($parentEmail) > 255
        || strlen($studentEmail) > 255;

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
    if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($studentEmail, '@nmims.in')) {
        $addError($lineNumber, $sapId, 'Student email must be a valid @nmims.in address.');
        continue;
    }
    if (isset($seenStudentEmails[$studentEmail])) {
        $addError($lineNumber, $sapId, 'This student email appears more than once in the CSV.');
        continue;
    }

    $seenSapIds[$sapId] = true;
    $seenStudentEmails[$studentEmail] = true;
    $recordRows[$sapId] = $lineNumber;
    $records[$sapId] = [
        'sap_id' => $sapId,
        'name' => $studentName,
        'student_contact' => $studentContact,
        'student_email' => $studentEmail,
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

$emails = array_values(array_unique(array_column($records, 'student_email')));
$emailOwners = [];
foreach (array_chunk($emails, 300) as $emailChunk) {
    if ($emailChunk === []) {
        continue;
    }
    $placeholders = implode(', ', array_fill(0, count($emailChunk), '?'));
    $emailStmt = $pdo->prepare("SELECT sap_id, LOWER(student_email) AS student_email FROM students WHERE LOWER(student_email) IN ($placeholders)");
    $emailStmt->execute($emailChunk);
    foreach ($emailStmt->fetchAll() as $owner) {
        $emailOwners[$owner['student_email']] = $owner['sap_id'];
    }
}
foreach ($records as $sapId => $record) {
    $owner = $emailOwners[$record['student_email']] ?? null;
    if ($owner !== null && trim((string)$owner) !== $sapId) {
        $addError($recordRows[$sapId], $sapId, 'This official email is already assigned to a different student SAP ID.');
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
        'message' => 'No records were imported because the official email addresses are already assigned to other students.',
    ]);
    exit;
}

$accounts = [];
$idChunks = array_chunk(array_keys($records), 300);
foreach ($idChunks as $ids) {
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $accountStmt = $pdo->prepare("SELECT login_id, role, must_change_password FROM users WHERE login_id IN ($placeholders)");
    $accountStmt->execute($ids);
    foreach ($accountStmt->fetchAll() as $account) {
        $accounts[$account['login_id']] = [
            'role' => $account['role'],
            'must_change_password' => $account['must_change_password'],
        ];
    }
}

foreach ($records as $sapId => $record) {
    if (isset($accounts[$sapId]) && $accounts[$sapId]['role'] !== 'student') {
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

$newAccountIds = [];
$pendingResendIds = [];
foreach ($records as $sapId => $record) {
    if (!isset($accounts[$sapId])) {
        $newAccountIds[] = $sapId;
    } elseif (
        $resendPendingInvitations
        && databaseBool($accounts[$sapId]['must_change_password'])
    ) {
        $pendingResendIds[] = $sapId;
    }
}
$invitationCandidateIds = array_values(array_unique(array_merge($newAccountIds, $pendingResendIds)));
$newAccountHashes = [];
foreach ($newAccountIds as $sapId) {
    $newAccountHashes[$sapId] = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 5]);
}

$chunkSize = 100;
$studentColumns = [
    'sap_id', 'name', 'student_contact', 'student_email', 'gender', 'course', 'year', 'branch', 'batch',
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
            $params[] = $newAccountHashes[$sapId];
            $params[] = 'student';
            $params[] = $records[$sapId]['name'];
        }
        $userSql = 'INSERT INTO users (login_id, password_hash, role, name, must_change_password, is_active) VALUES '
            . implode(', ', $userValues)
            . (DB_DRIVER === 'pgsql' ? ' ON CONFLICT (login_id) DO NOTHING' : ' ON DUPLICATE KEY UPDATE login_id = VALUES(login_id)');
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute($params);
    }

    $invitationRows = [];
    $inviteUsers = [];
    foreach (array_chunk($invitationCandidateIds, 300) as $ids) {
        if ($ids === []) {
            continue;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $accountStmt = $pdo->prepare(
            "SELECT id, login_id FROM users WHERE role = 'student' AND is_active = "
            . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
            . " AND must_change_password = " . (DB_DRIVER === 'pgsql' ? 'TRUE' : '1')
            . " AND login_id IN ($placeholders)"
        );
        $accountStmt->execute($ids);
        foreach ($accountStmt->fetchAll() as $account) {
            $sapId = $account['login_id'];
            $token = bin2hex(random_bytes(32));
            $inviteUsers[] = [
                'user_id' => (int)$account['id'],
                'sap_id' => $sapId,
                'token' => $token,
            ];
            $invitationRows[] = [
                'user_id' => (int)$account['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            ];
        }
    }

    foreach (array_chunk($invitationRows, 100) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $invitation) {
            $values[] = '(?, ?, ?, NULL)';
            $params[] = $invitation['user_id'];
            $params[] = $invitation['token_hash'];
            $params[] = $invitation['expires_at'];
        }
        $tokenSql = 'INSERT INTO student_activation_tokens (user_id, token_hash, expires_at, activated_at) VALUES '
            . implode(', ', $values)
            . (DB_DRIVER === 'pgsql'
                ? ' ON CONFLICT (user_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, expires_at = EXCLUDED.expires_at, activated_at = NULL'
                : ' ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), activated_at = NULL');
        $tokenStmt = $pdo->prepare($tokenSql);
        $tokenStmt->execute($params);
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
            'activation_invitations' => count($invitationRows),
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

$emailInvitations = [];
foreach ($inviteUsers as $invite) {
    $record = $records[$invite['sap_id']];
    $emailInvitations[] = [
        'name' => $record['name'],
        'email' => $record['student_email'],
        'token' => $invite['token'],
    ];
}

require_once __DIR__ . '/student_activation_mail.php';
$delivery = deliverStudentActivationEmails($emailInvitations);

$message = count($records) . ' student record(s) imported or updated. '
    . $delivery['sent'] . ' activation email(s) sent.';
if ($delivery['failed'] > 0) {
    $message .= ' ' . $delivery['failed'] . ' activation email(s) could not be sent. Configure the email settings, then re-import this CSV with "Resend activation links for students still awaiting activation" selected.';
}
if ($resendPendingInvitations && $emailInvitations === []) {
    $message .= ' No matching accounts were awaiting activation.';
}
if ($errorCount > 0) {
    $message .= ' Some CSV rows were skipped; review the row errors.';
}

echo json_encode([
    'success' => true,
    'imported' => count($records),
    'new_accounts_count' => count($newAccountIds),
    'activation_email_sent_count' => $delivery['sent'],
    'activation_email_failed_count' => $delivery['failed'],
    'errors' => $errors,
    'error_count' => $errorCount,
    'message' => $message,
]);
