<?php
// index.php

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include 'db.php';

// Query ดึงข้อมูลจากตาราง images
$sql_images = "SELECT id, timestamp, image_path FROM images ORDER BY id DESC";
$result_images = $conn->query($sql_images);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detection Results</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .image-container {
            border: 1px solid #ccc;
            margin-bottom: 20px;
            padding: 10px;
        }
        .image-container h3 {
            margin: 0;
        }
        .detections-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .detections-table th, .detections-table td {
            border: 1px solid #ccc;
            padding: 5px;
            text-align: left;
        }
        .detections-table th {
            background-color: #f2f2f2;
        }
        img.detected {
            max-width: 400px;
            display: block;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<h1>Detection Results</h1>

<?php
if ($result_images && $result_images->num_rows > 0) {
    // แสดงผลข้อมูลของแต่ละภาพ
    while ($row_img = $result_images->fetch_assoc()) {
        $image_id = $row_img['id'];
        $timestamp = $row_img['timestamp'];
        $image_path = $row_img['image_path'];

        echo "<div class='image-container'>";
        echo "<h3>Image #$image_id</h3>";
        echo "<p>Timestamp: $timestamp</p>";
        
        // แสดงภาพหากไฟล์มีอยู่
        if (file_exists($image_path)) {
            echo "<img class='detected' src='$image_path' alt='Detected Image'>";
        } else {
            echo "<p><em>Image file not found.</em></p>";
        }

        // ดึงข้อมูล detections ที่เกี่ยวกับภาพนี้
        $sql_det = "SELECT label, id_cam, lat_cam, long_cam, elephant, lat_ele, long_ele, distance_ele, time FROM detections WHERE image_id = $image_id";

        $result_det = $conn->query($sql_det);

        if ($result_det && $result_det->num_rows > 0) {
            echo "<table class='detections-table'>";
            echo "<tr><th>Label</th><th>Camera ID</th><th>Lat (Cam)</th><th>Long (Cam)</th><th>Elephant</th><th>Lat (Ele)</th><th>Long (Ele)</th><th>Distance (Ele)</th><th>Time</th></tr>";
            while ($det = $result_det->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$det['label']}</td>";
                echo "<td>{$det['id_cam']}</td>";
                echo "<td>{$det['lat_cam']}</td>";
                echo "<td>{$det['long_cam']}</td>";
                echo "<td>{$det['elephant']}</td>";
                echo "<td>{$det['lat_ele']}</td>";
                echo "<td>{$det['long_ele']}</td>";
                echo "<td>{$det['distance_ele']}</td>";
                echo "<td>{$det['time']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No detections for this image.</p>";
        }

        echo "</div>";
    }
} else {
    echo "<p>No images found in the database.</p>";
}

// ปิดการเชื่อมต่อฐานข้อมูล
$conn->close();
?>

</body>
</html>
