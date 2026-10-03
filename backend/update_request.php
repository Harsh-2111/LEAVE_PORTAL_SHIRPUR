<?php
// update_request.php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$action = $_POST['action'] ?? ''; // 'approve' or 'reject'
$role = $_POST['role'] ?? '';
$name = $_POST['name'] ?? '';

if (!$id || !$action || !$role || !$name) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Fetch current request
$stmt = $conn->prepare("SELECT * FROM leave_requests WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$req = $res->fetch_assoc();
$stmt->close();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

if ($action === 'reject') {
    $u = $conn->prepare("UPDATE leave_requests SET status = 'Rejected', teacher_approved=0, hod_approved=0, dean_approved=0, qr_code_data = NULL WHERE id = ?");
    $u->bind_param('i', $id);
    $u->execute();
    echo json_encode(['success' => true, 'message' => 'Request rejected']);
    $u->close();
    exit;
}

if ($action === 'approve') {
    // Behavior depends on role:
    if ($role === 'teacher') {
        // set teacher_approved true and status = 'Teacher Approved'
        $u = $conn->prepare("UPDATE leave_requests SET teacher_approved = 1, status = 'Teacher Approved' WHERE id = ?");
        $u->bind_param('i', $id); $u->execute(); $u->close();
        echo json_encode(['success' => true, 'message' => 'Teacher approved, sent to HOD']);
        exit;
    } elseif ($role === 'hod') {
        // set hod_approved true. If leave_days > 3 then set status = 'HOD Approved' (send to Dean), else set Granted and create qr_code_data
        $leave_days = intval($req['leave_days']);
        
        // Define Dean Name
        $dean_name = 'Dean of Academics'; // Used for assignment to the Dean

        if ($leave_days > 3) {
            // HOD approved - requires Dean approval
            $u = $conn->prepare("UPDATE leave_requests SET hod_approved = 1, status = 'HOD Approved', dean_assigned = ? WHERE id = ?");
            $u->bind_param('si', $dean_name, $id); $u->execute(); $u->close();
            echo json_encode(['success' => true, 'message' => 'HOD approved — requires Dean approval (leave > 3 days)']);
            exit;
        } else {
            // Grant the leave: set hod_approved and status = 'Granted', create qr_code_data
            
            // --- HOD QR DATA GENERATION (LABELS ADDED) ---
            $timestamp = round(microtime(true) * 1000);
            $qr_data = "GATE PASS\n"
                     . "Student ID: " . $req['student_id'] . "\n"
                     . "Name: " . $req['student_name'] . "\n"
                     . "From: " . $req['start_date'] . "\n"
                     . "Till: " . $req['end_date'] . "\n"
                     . "Approved by Mentor: " . $req['teacher'] . "\n"
                     . "Generated: " . $timestamp;
            // ---------------------------------------------
            
            $u = $conn->prepare("UPDATE leave_requests SET hod_approved = 1, status = 'Granted', qr_code_data = ? WHERE id = ?");
            $u->bind_param('si', $qr_data, $id); $u->execute(); $u->close();
            echo json_encode(['success' => true, 'message' => 'HOD approved and leave granted (no Dean required)']);
            exit;
        }
    } elseif ($role === 'dean') {
        // Dean approves => set dean_approved true and status = 'Granted' and qr_code_data

        // --- DEAN QR DATA GENERATION (LABELS ADDED) ---
        $timestamp = round(microtime(true) * 1000);
        $qr_data = "GATE PASS\n"
                 . "Student ID: " . $req['student_id'] . "\n"
                 . "Name: " . $req['student_name'] . "\n"
                 . "From: " . $req['start_date'] . "\n"
                 . "Till: " . $req['end_date'] . "\n"
                 . "Approved by Mentor: " . $req['teacher'] . "\n" // Use the mentor's name
                 . "Generated: " . $timestamp;
        // ---------------------------------------------
        
        $u = $conn->prepare("UPDATE leave_requests SET dean_approved = 1, status = 'Granted', qr_code_data = ? WHERE id = ?");
        $u->bind_param('si', $qr_data, $id); $u->execute(); $u->close();
        echo json_encode(['success' => true, 'message' => 'Dean approved and leave granted']);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown role']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);

?>