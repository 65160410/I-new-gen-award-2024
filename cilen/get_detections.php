<?php
// get_detections.php

header('Content-Type: application/json');

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include './db.php';

// ดึงข้อมูลล่าสุด 1 รายการ
$sql = "SELECT detections.id, detections.lat_cam, detections.long_cam, detections.elephant, 
                detections.lat_ele, detections.long_ele, detections.distance_ele, detections.alert,
                images.timestamp, images.image_path
        FROM detections
        LEFT JOIN images ON detections.image_id = images.id
        ORDER BY detections.id DESC LIMIT 1";

$result = $conn->query($sql);

$markers = [];

// ตรวจสอบว่าได้รับผลลัพธ์หรือไม่
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // ตรวจสอบว่า image_path มีค่าและไม่เป็น null
        if (!empty($row['image_path'])) {
            if (strpos($row['image_path'], 'uploads/') === 0) {
                $full_image_path = 'https://aprlabtop.com/elephant_api/' . $row['image_path'];
            } else {
                $full_image_path = 'https://aprlabtop.com/elephant_api/uploads/' . $row['image_path'];
            }
        } else {
            $full_image_path = ''; // ตั้งค่าเป็นค่าว่างถ้า image_path เป็น null หรือไม่มีค่า
        }

        $markers[] = [
            'id' => $row['id'],
            'lat_cam' => $row['lat_cam'],
            'long_cam' => $row['long_cam'],
            'lat_ele' => $row['lat_ele'],
            'long_ele' => $row['long_ele'],
            'distance_ele' => $row['distance_ele'],
            'timestamp' => $row['timestamp'],
            'elephant' => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN),
            'image_path' => $full_image_path,
            'alert' => filter_var($row['alert'], FILTER_VALIDATE_BOOLEAN)
        ];
    }
}

echo json_encode(['status' => 'success', 'data' => $markers]);

$conn->close();
?>
