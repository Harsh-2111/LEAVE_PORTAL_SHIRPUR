<?php
function notificationRecipients(PDO $pdo, int $leaveId): array
{
    $stmt = $pdo->prepare('SELECT lr.sap_id, lr.hostel, s.gender FROM leave_requests lr INNER JOIN students s ON s.sap_id = lr.sap_id WHERE lr.id = ? LIMIT 1');
    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch();
    if (!$leave) return [];

    $recipientIds = [];
    $student = $pdo->prepare('SELECT id FROM users WHERE login_id = ? AND role = ? LIMIT 1');
    $student->execute([(string)$leave['sap_id'], 'student']);
    if ($row = $student->fetch()) $recipientIds[] = (int)$row['id'];

    $warden = $pdo->prepare('SELECT id FROM users WHERE role = ? AND warden_gender = ? AND warden_hostel = ? AND is_active = TRUE');
    $warden->execute(['warden', (string)$leave['gender'], (string)$leave['hostel']]);
    foreach ($warden->fetchAll() as $row) $recipientIds[] = (int)$row['id'];

    return array_values(array_unique($recipientIds));
}

function notifyStudent(PDO $pdo, int $leaveId, string $type, string $title, string $message): void
{
    $stmt = $pdo->prepare('SELECT lr.sap_id FROM leave_requests lr WHERE lr.id = ? LIMIT 1');
    $stmt->execute([$leaveId]);
    if (!$sapId = $stmt->fetchColumn()) return;
    $student = $pdo->prepare('SELECT id FROM users WHERE login_id = ? AND role = ? LIMIT 1');
    $student->execute([(string)$sapId, 'student']);
    if ($row = $student->fetch()) insertNotifications($pdo, [(int)$row['id']], $leaveId, $type, $title, $message);
}

function notifyWarden(PDO $pdo, int $leaveId, string $type, string $title, string $message): void
{
    $recipients = notificationRecipients($pdo, $leaveId);
    $stmt = $pdo->prepare('SELECT lr.sap_id FROM leave_requests lr WHERE lr.id = ? LIMIT 1');
    $stmt->execute([$leaveId]);
    $sapId = $stmt->fetchColumn();
    $student = $pdo->prepare('SELECT id FROM users WHERE login_id = ? AND role = ? LIMIT 1');
    $student->execute([(string)$sapId, 'student']);
    $studentId = $student->fetchColumn();
    if ($studentId !== false) $recipients = array_values(array_diff($recipients, [(int)$studentId]));
    insertNotifications($pdo, $recipients, $leaveId, $type, $title, $message);
}

function notifyLeave(PDO $pdo, int $leaveId, string $type, string $title, string $message): void
{
    insertNotifications($pdo, notificationRecipients($pdo, $leaveId), $leaveId, $type, $title, $message);
}

function insertNotifications(PDO $pdo, array $recipientIds, int $leaveId, string $type, string $title, string $message): void
{
    if (!$recipientIds) return;
    $sql = 'INSERT INTO notifications (user_id, leave_id, type, title, message, link, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())';
    $insert = $pdo->prepare($sql);
    foreach (array_unique($recipientIds) as $recipientId) {
        if ((int)$recipientId > 0) $insert->execute([(int)$recipientId, $leaveId, $type, $title, $message, '#leave-' . $leaveId]);
    }
}
