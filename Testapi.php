<?php
//Testapi.php

// เปิดแสดง Error (Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// เชื่อมต่อฐานข้อมูล
include 'db.php';

// อ่าน JSON จาก Client
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// Debug ดู JSON
file_put_contents("debug_log.txt", "Raw JSON:\n".$json_data."\n\nDecoded:\n".print_r($data,true)."\n", FILE_APPEND);

// ตรวจสอบ DB
if (!$conn) {
    die(json_encode([
        "status" => "error",
        "message" => "DB connection failed: ".$conn->connect_error
    ]));
}

/*
  ตัวอย่างโครงสร้าง JSON ที่คาดหวัง:
  {
    "camera_id": "SOURCE0_CAM_001",
    "camera_lat": 13.736717,
    "camera_long": 100.523186,
    "elephant": true,
    "elephant_lat": 14.22711,
    "elephant_long": 101.40447,
    "elephant_distance": 20,
    "image": null,   <-- สำคัญ: ถ้าเป็น null ก็ต้องบันทึกลง images พร้อม image_path = NULL
    "alert": false,
    "timestamp": "2024-12-17 18:05:20"
  }
*/

// ----------------------------------------------------------------------------
// 1) ตรวจสอบฟิลด์บังคับ
// ----------------------------------------------------------------------------
$required_fields = ['camera_id','camera_lat','camera_long','elephant','timestamp'];
$errors = [];

foreach ($required_fields as $field) {
    if (!isset($data[$field])) {
        $errors[$field] = "Missing required field";
    } elseif ($data[$field] === null) {
        $errors[$field] = "Field cannot be null";
    } else {
        // เช็ค type อย่างง่าย
        switch($field) {
            case 'camera_id':
                if (!is_string($data[$field]) || trim($data[$field])==='') {
                    $errors[$field] = "Must be a non-empty string";
                }
                break;
            case 'camera_lat':
            case 'camera_long':
                if (!is_numeric($data[$field])) {
                    $errors[$field] = "Must be numeric";
                }
                break;
            case 'elephant':
                // รองรับ true/false, 1/0, "true"/"false"
                if (!is_bool($data[$field])) {
                    if (!in_array(strtolower($data[$field]), ['true','false','1','0'], true)) {
                        $errors[$field] = "Must be boolean";
                    }
                }
                break;
            case 'timestamp':
                // ตรวจสอบรูปแบบวันที่
                if (!is_string($data[$field]) || !strtotime($data[$field])) {
                    $errors[$field] = "Invalid datetime format";
                }
                break;
        }
    }
}

if (!empty($errors)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid data",
        "errors" => $errors
    ]);
    exit;
}

// ----------------------------------------------------------------------------
// 2) เก็บค่าลงตัวแปร (optional field ใช้ null ได้)
// ----------------------------------------------------------------------------
$camera_id    = $data['camera_id']; 
$camera_lat   = floatval($data['camera_lat']);
$camera_long  = floatval($data['camera_long']);
$timestamp    = $data['timestamp'];

$elephant     = $data['elephant'];
$elephant_val = ($elephant === true || $elephant === 1 || strtolower($elephant)==='true') ? 1 : 0;

$elephant_lat      = isset($data['elephant_lat'])      ? $data['elephant_lat']      : null;
$elephant_long     = isset($data['elephant_long'])     ? $data['elephant_long']     : null;
$elephant_distance = isset($data['elephant_distance']) ? $data['elephant_distance'] : null;
$alert             = isset($data['alert'])             ? $data['alert']             : null;

// image (single field) - จะเป็น null หรือ base64
$image_data        = array_key_exists('image', $data)  ? $data['image'] : null;

// ----------------------------------------------------------------------------
// 3) ฟังก์ชันแปลงค่าที่อาจเป็น null ให้เป็น SQL
// ----------------------------------------------------------------------------
function sql_nullable_float($value) {
    if ($value === null) return "NULL";  // หากค่าเป็น null ให้แปลงเป็น NULL
    return floatval($value);  // แปลงเป็น float
}

// ฟังก์ชันแปลงค่าที่อาจเป็น null ให้เป็น SQL สำหรับ boolean
function sql_nullable_bool($value) {
    if ($value === null) return "NULL";  // หากเป็น null ให้แปลงเป็น NULL
    $v = (strtolower($value) === 'true' || $value === true || $value === '1' || $value === 1) ? 1 : 0;
    return $v;  // ส่งค่าเป็น 1 (true) หรือ 0 (false)
}


$elephant_lat      = isset($data['elephant_lat'])      ? $data['elephant_lat']      : null;
$elephant_long     = isset($data['elephant_long'])     ? $data['elephant_long']     : null;


// ----------------------------------------------------------------------------
// 4) สร้างโฟลเดอร์ uploads (เผื่อ save รูปถ้าไม่ null)
// ----------------------------------------------------------------------------
$upload_dir = "uploads";
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        die(json_encode(["status"=>"error","message"=>"Cannot create upload dir."]));
    }
}

// ----------------------------------------------------------------------------
// 5) Insert ลงตาราง images เสมอ (ไม่ว่ารูปจะเป็น null หรือ base64)
// ----------------------------------------------------------------------------
$image_path_to_save = null;

if ($image_data === null) {
    // รูปเป็น null -> ไม่ถอดรหัสรูป -> image_path_to_save = NULL
    // (ค่า default ของตัวแปร $image_path_to_save = null อยู่แล้ว)
} else {
    // ถือว่าเป็น base64 -> ลองถอดรหัสและบันทึกไฟล์
    $base64_clean = preg_replace('/\s+/', '', $image_data);
    $decoded_img  = base64_decode($base64_clean, true);
    if ($decoded_img === false) {
        die(json_encode(["status"=>"error","message"=>"Invalid base64 in 'image'."]));
    }

    // ตั้งชื่อไฟล์
    $filename = $upload_dir . "/" . date("Ymd_His") . "_".uniqid().".jpg";
    if (!file_put_contents($filename, $decoded_img)) {
        die(json_encode(["status"=>"error","message"=>"Cannot save image file."]));
    }

    // เก็บ path ไว้ในตัวแปร
    $image_path_to_save = $filename;
}

// เตรียม insert ลงตาราง images
$stmt_img = $conn->prepare("
    INSERT INTO images (timestamp, image_path, cam_id)
    VALUES (?, ?, ?)
");
if (!$stmt_img) {
    die(json_encode([
        "status"=>"error",
        "message"=>"Prepare images failed: ".$conn->error
    ]));
}
// ถ้า $image_path_to_save เป็น null -> จะ bind เป็น null
$stmt_img->bind_param("sss", $timestamp, $image_path_to_save, $camera_id);
if (!$stmt_img->execute()) {
    die(json_encode(["status"=>"error","message"=>"Execute images failed: ".$stmt_img->error]));
}
$image_id = $stmt_img->insert_id;
$stmt_img->close();

// ----------------------------------------------------------------------------
// 6) Insert ลงตาราง detections พร้อม image_id
// ----------------------------------------------------------------------------
$sql_det = "
    INSERT INTO detections
    SET
        image_id      = ".intval($image_id).",
        id_cam        = '".$conn->real_escape_string($camera_id)."',
        lat_cam       = ".floatval($camera_lat).",
        long_cam      = ".floatval($camera_long).",
        elephant      = ".intval($elephant_val).",
        lat_ele       = ".floatval($elephant_lat).",
        long_ele      = ".floatval($elephant_long).",
        distance_ele  = ".sql_nullable_float($elephant_distance).",
        time          = '".$conn->real_escape_string($timestamp)."',
        alert         = ".sql_nullable_bool($alert)."
";
if (!$conn->query($sql_det)) {
    die(json_encode(["status"=>"error","message"=>"Insert detections failed: ".$conn->error]));
}

$conn->close();

// ส่งผลลัพธ์
echo json_encode([
    "status"  => "success",
    "message" => "Data stored successfully.",
    "image_id"=> $image_id
]);
