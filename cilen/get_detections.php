<?php
// get_detections.php

header('Content-Type: application/json');

// เปิดการแสดงข้อผิดพลาด (สำหรับการ Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include 'db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

// ดึงข้อมูลการตรวจจับทั้งหมด
$sql = "SELECT detections.id, detections.lat_cam, detections.long_cam, detections.elephant, 
        detections.lat_ele, detections.long_ele, detections.distance_ele, 
        images.timestamp, images.image_path
        FROM detections
        LEFT JOIN images ON detections.image_id = images.id
        ORDER BY detections.id DESC";

$result = $conn->query($sql);

if ($result) {
    $markers = [];
    while ($row = $result->fetch_assoc()) {
        $markers[] = [
            'id' => $row['id'],
            'lat_cam' => $row['lat_cam'],
            'long_cam' => $row['long_cam'],
            'lat_ele' => $row['lat_ele'],
            'long_ele' => $row['long_ele'],
            'distance_ele' => $row['distance_ele'],
            'timestamp' => $row['timestamp'],
            'elephant' => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN), // แปลงค่าเป็น boolean
            'image_path' => 'https://aprlabtop.com/Honey_test/uploads/' . $row['image_path']
        ];
    }
    echo json_encode(['status' => 'success', 'data' => $markers]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve data: ' . $conn->error]);
}

$conn->close();
?>
