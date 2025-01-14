<?php
// update_status.php

// เปิดการแสดงข้อผิดพลาด (DEBUG) -- ปิดใน production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// เริ่มต้น session
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (Admin)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อ DB
if (!isset($conn) || !$conn instanceof mysqli) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection error']);
    exit;
}

// ตรวจสอบข้อมูลที่ได้รับจาก AJAX
if (isset($_POST['id']) && isset($_POST['status'])) {
    $id = intval($_POST['id']);
    $status = $_POST['status'];

    // ตรวจสอบค่าที่ส่งมา
    $allowed_statuses = ['pending', 'completed'];
    if (!in_array($status, $allowed_statuses)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid status value']);
        exit;
    }

    // เตรียมคำสั่ง SQL เพื่ออัปเดตสถานะ
    $stmt = $conn->prepare("UPDATE detections SET status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            // ส่งสถานะเดิมกลับมาเพื่อรีเซ็ตค่า dropdown
            // คุณสามารถดึงสถานะเดิมจากฐานข้อมูลได้ที่นี่
            $stmt_select = $conn->prepare("SELECT status FROM detections WHERE id = ?");
            $stmt_select->bind_param("i", $id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                echo json_encode(['status' => 'error', 'message' => 'Update failed', 'current_status' => $row['status']]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Update failed and unable to retrieve current status']);
            }
            $stmt_select->close();
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database query error']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
}

$conn->close();
?>
