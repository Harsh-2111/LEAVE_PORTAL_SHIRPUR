<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

requireRole(['admin']);
requireCsrfToken();

if (!isset($_FILES['students_csv']) || $_FILES['students_csv']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSV file upload failed or was missing.']);
    exit;
}

$tmpName = $_FILES['students_csv']['tmp_name'];
if (!is_uploaded_file($tmpName) || !is_readable($tmpName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Uploaded CSV file is invalid.']);
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
    'parent_contact' => ['parent_contact', 'parent_phone', 'guardian_phone', 'contact_number']
];

$normalize = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
};

$handle = fopen($tmpName, 'r');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded CSV file.']);
    exit;
}

$header = fgetcsv($handle);
if ($header === false || count($header) === 0) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSV header row is missing.']);
    exit;
}

$headerMap = [];
foreach ($header as $index => $columnName) {
    $normalized = $normalize((string)$columnName);
    $headerMap[$normalized] = $index;
}

$resolvedColumns = [];
foreach ($columnMap as $requiredKey => $aliases) {
    foreach ($aliases as $alias) {
        if (isset($headerMap[$normalize($alias)])) {
            $resolvedColumns[$requiredKey] = $headerMap[$normalize($alias)];
            break;
        }
    }
}

$missingColumns = [];
foreach (['sap_id','name','student_contact','gender','course','year','branch','batch','parent_email','parent_contact'] as $requiredField) {
    if (!isset($resolvedColumns[$requiredField])) {
        $missingColumns[] = $requiredField;
    }
}

if (!empty($missingColumns)) {
    fclose($handle);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CSV is missing required columns: ' . implode(', ', $missingColumns),
    ]);
    exit;
}

$imported = 0;
$errors = [];
$user = requireAuth();

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < count($header)) {
        $row = array_pad($row, count($header), '');
    }

    $record = [];
    foreach ($resolvedColumns as $field => $index) {
        $record[$field] = trim((string)($row[$index] ?? ''));
    }

    $sapId = $record['sap_id'] ?? '';
    if ($sapId === '') {
        $errors[] = ['row' => ($imported + count($errors) + 2), 'message' => 'SAP ID is blank.'];
        continue;
    }

    if (!preg_match('/^\d{11}$/', $sapId)) {
        $errors[] = ['row' => ($imported + count($errors) + 2), 'sap_id' => $sapId, 'message' => 'SAP ID must be exactly 11 digits.'];
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
    $courseInput = preg_replace('/[^a-z]/', '', strtolower($record['course'] ?? ''));
    $courseMap = [
        'btech' => 'BTech',
        'mbatech' => 'MBATech',
        'bpharm' => 'BPharm',
        'mpharm' => 'MPharm',
        'agriculture' => 'Agriculture',
    ];
    $course = $courseMap[$courseInput] ?? '';

    if ($studentName === '' || $year === '' || $branch === '' || $batch === '' || $studentContact === '' || !in_array($gender, ['male', 'female'], true) || $course === '') {
        $errors[] = ['row' => ($imported + count($errors) + 2), 'sap_id' => $sapId, 'message' => 'Name, valid year (1-5), branch, batch, student phone, gender, and course are required.'];
        continue;
    }

    $parentName = $record['parent_name'] ?? '';
    $parentEmail = $record['parent_email'] ?? '';
    $parentContact = $record['parent_contact'] ?? '';
    $hostelBlock = $record['hostel_block'] ?? '';
    $roomNo = $record['room_no'] ?? '';

    if (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9+() -]{7,20}$/', $studentContact) || !preg_match('/^[0-9+() -]{7,20}$/', $parentContact)) {
        $errors[] = ['row' => ($imported + count($errors) + 2), 'sap_id' => $sapId, 'message' => 'Student phone, parent email, or parent contact is invalid.'];
        continue;
    }

    $studentStmt = $pdo->prepare('INSERT INTO students (sap_id, name, student_contact, gender, course, year, branch, batch, hostel_block, room_no, parent_name, parent_email, parent_contact, is_active, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), student_contact = VALUES(student_contact), gender = VALUES(gender), course = VALUES(course), year = VALUES(year), branch = VALUES(branch), batch = VALUES(batch), hostel_block = VALUES(hostel_block), room_no = VALUES(room_no), parent_name = VALUES(parent_name), parent_email = VALUES(parent_email), parent_contact = VALUES(parent_contact), is_active = 1, updated_at = NOW()');
    $studentStmt->execute([$sapId, $studentName, $studentContact, $gender, $course, $year, $branch, $batch, $hostelBlock, $roomNo, $parentName, $parentEmail, $parentContact]);

    $tempPassword = 'Student@' . substr($sapId, -4);
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $userStmt = $pdo->prepare('INSERT INTO users (login_id, password_hash, role, name, must_change_password, is_active) VALUES (?, ?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), name = VALUES(name), must_change_password = 1, is_active = 1');
    $userStmt->execute([$sapId, $passwordHash, 'student', $studentName]);

    writeAudit($pdo, (int)$user['id'], 'import_students', 'students', null, ['sap_id' => $sapId], $_SERVER['REMOTE_ADDR'] ?? null);
    $imported++;
}

fclose($handle);

echo json_encode([
    'success' => true,
    'imported' => $imported,
    'errors' => $errors,
    'message' => $imported . ' student record(s) imported or updated.',
]);
