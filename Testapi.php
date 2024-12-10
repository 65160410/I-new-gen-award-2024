<?php
// Testapi.php

// เปิดการแสดงข้อผิดพลาด (สำหรับการ Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include 'db.php';

// รับ JSON input จาก Python
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// พิมพ์ข้อมูลที่ได้รับมา (สำหรับ Debug)
file_put_contents("debug_log.txt", print_r($data, true));

if (isset($data['detections']) && isset($data['image']) && isset($data['timestamp'])) {
    // Decode base64 image
    $base64_img = $data['image'];
    $img_data = base64_decode($base64_img);

    // สร้างโฟลเดอร์ uploads หากยังไม่มี
    if (!is_dir("uploads")) {
        if (!mkdir("uploads", 0777, true)) {
            die(json_encode(["status" => "error", "message" => "Failed to create uploads directory."]));
        }
    }

    // ตั้งชื่อไฟล์รูปภาพ
    $filename = "uploads/" . date("Ymd_His") . ".jpg"; 
    if (!file_put_contents($filename, $img_data)) {
        die(json_encode(["status" => "error", "message" => "Failed to save image file."]));
    }

    // บันทึกข้อมูลลงตาราง images
    $timestamp = $data['timestamp'];
    $stmt_img = $conn->prepare("INSERT INTO images (timestamp, image_path) VALUES (?, ?)");
    if (!$stmt_img) {
        die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
    }
    $stmt_img->bind_param("ss", $timestamp, $filename);
    if (!$stmt_img->execute()) {
        die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_img->error]));
    }
    $image_id = $stmt_img->insert_id;
    $stmt_img->close();

    // บันทึกข้อมูลลงตาราง detections
    $stmt_det = $conn->prepare("INSERT INTO detections (image_id, label, x1, y1, x2, y2) VALUES (?,?,?,?,?,?)");
    if (!$stmt_det) {
        die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
    }

    foreach ($data['detections'] as $d) {
        $label = $d['label'];
        $x1 = $d['bbox'][0];
        $y1 = $d['bbox'][1];
        $x2 = $d['bbox'][2];
        $y2 = $d['bbox'][3];
        $stmt_det->bind_param("issddd", $image_id, $label, $x1, $y1, $x2, $y2);
        if (!$stmt_det->execute()) {
            die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_det->error]));
        }
    }

    $stmt_det->close();
    $conn->close();

    // ส่ง response กลับไปยังฝั่ง Python
    echo json_encode(["status" => "success", "message" => "Data stored successfully."]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid data"]);
}
?>
