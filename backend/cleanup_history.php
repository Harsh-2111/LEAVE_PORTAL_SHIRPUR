<?php
// cleanup_history.php - MODIFIED for Annual Student Cycle (June 1st)
require_once __DIR__ . '/db.php';

// --- Logic to determine the start of the current academic cycle (June 1st) ---
$today = date('Y-m-d');
$current_year = date('Y');
$cleanup_cycle_start = $current_year . '-06-01'; // June 1st of the current year

// If today's date is before June 1st, the current cycle started last year
// Example: If today is May 2026, the current cycle started June 1, 2025.
if ($today < $cleanup_cycle_start) {
    $cleanup_cycle_start = (date('Y') - 1) . '-06-01';
}

$marker_file = __DIR__ . '/cleanup_marker.txt';
$last_cleanup_date = '1970-01-01';

// 1. Check marker file to see when the script last ran
if (file_exists($marker_file)) {
    $last_cleanup_date = trim(file_get_contents($marker_file));
}

// Check if cleanup has already run this academic cycle (i.e., since June 1st)
if (strtotime($last_cleanup_date) < strtotime($cleanup_cycle_start)) {
    // 2. Cleanup Logic: Delete non-pending records whose end_date is before the current cycle's start date.
    $sql = "DELETE FROM leave_requests 
            WHERE status != 'Pending' 
            AND end_date < ?";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed to prepare SQL: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param('s', $cleanup_cycle_start); // Delete everything before this June 1st
    $stmt->execute();
    $rows_deleted = $stmt->affected_rows;
    $stmt->close();
    
    // 3. Update marker file to prevent cleanup from running again this year
    // Ensure your web server has write permission to create this file in the directory!
    file_put_contents($marker_file, date('Y-m-d'));
    
    // Return a success message
    if ($rows_deleted > 0) {
        echo json_encode([
            'success' => true, 
            'cleaned' => true, 
            'message' => "Academic year leave history has been deleted (pre-June 1st cycle).",
            'rows_deleted' => $rows_deleted
        ]);
        exit;
    } else {
        echo json_encode(['success' => true, 'cleaned' => false, 'message' => 'No old history to delete.']);
        exit;
    }
}

echo json_encode(['success' => true, 'cleaned' => false, 'message' => 'Cleanup already run this academic cycle.']);
?>