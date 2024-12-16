
<?php
// admin_dashboard.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

$servername = "51.79.177.24";
$username = "aprlab_ele";
$password = "Xlzv0^372";
$dbname = "aprlab_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้งค่าการแสดงข้อมูลในตาราง
$perPage = 5;  // จำนวนข้อมูลที่แสดงต่อหน้า
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;  // หน้าเริ่มต้น
$start = ($page - 1) * $perPage;  // จุดเริ่มต้นของการดึงข้อมูล

// คิวรีดึงข้อมูลจากทั้งสองตาราง
$sql_detections = "SELECT detections.id, detections.lat_cam, detections.long_cam, detections.elephant, 
                        detections.lat_ele, detections.long_ele, detections.distance_ele, 
                        images.timestamp, images.image_path
                   FROM detections
                   JOIN images ON detections.image_id = images.id
                   ORDER BY detections.id DESC LIMIT ?, ?";
$stmt_detections = $conn->prepare($sql_detections);
$stmt_detections->bind_param("ii", $start, $perPage);
$stmt_detections->execute();
$result_detections = $stmt_detections->get_result();

// คำนวณจำนวนหน้าทั้งหมด
$sql_count = "SELECT COUNT(detections.id) AS total FROM detections";
$count_result = $conn->query($sql_count);
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $perPage);

// ข้อมูลที่จะแสดงบนแผนที่
$sql = "SELECT detections.id, detections.lat_cam, detections.long_cam, detections.elephant, 
                detections.lat_ele, detections.long_ele, detections.distance_ele, 
                images.timestamp, images.image_path
        FROM detections
        LEFT JOIN images ON detections.image_id = images.id
        ORDER BY detections.id DESC";

$result = $conn->query($sql);

// ตรวจสอบว่าได้รับผลลัพธ์หรือไม่
if ($result->num_rows > 0) {
    // สร้าง array สำหรับมาร์กเกอร์
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
            'elephant' => $row['elephant'],
            'image_path' => 'https://aprlabtop.com/Honey_test/uploads/' . $row['image_path']
        ];
    }
} else {
    $markers = [];
}

$conn->close();
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AI ตรวจจับช้างป่า</title>
    <!-- รวม Tailwind CSS ผ่าน CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <!-- Leaflet Control Geocoder CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <!-- Leaflet Routing Machine CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        /* ปรับปรุงการใช้ Tailwind กับ Leaflet */
        .leaflet-popup-content-wrapper {
            border-radius: 0.75rem; /* 12px */
            padding: 0.3125rem; /* 5px */
        }

        .leaflet-popup-content {
            padding: 0.625rem; /* 10px */
        }

        /* ซ่อน Leaflet Routing Machine Control */
        .leaflet-routing-container.leaflet-bar.leaflet-control {
            display: none !important;
        }

        /* ปรับแต่งไอคอนมาร์กเกอร์ */
        .custom-popup-content button {
            cursor: pointer;
        }

        .popup {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 9999;
            min-width: 300px;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        }

        .popup.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .popup.elephant {
            background-color: #ef4444;
        }

        .popup.motorbike,
        .popup.car {
            background-color: #10b981;
        }

        .popup.unknown {
            background-color: #3b82f6;
        }

        #closePopup {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            color: white;
        }

        #closePopup:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .alert-header {
            background-color: #ef4444;  /* สีแดง */
            color: white;
            padding: 10px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        /* สีแถวที่เปลี่ยนเมื่อเจอช้าง */
        .highlighted-row {
            background-color: #ef4444;
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 10000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto; /* 10% from the top and centered */
            padding: 20px;
            border: 1px solid #888;
            width: 80%; /* Could be more or less, depending on screen size */
            max-width: 800px;
            border-radius: 8px;
        }

        .modal-content img {
            width: 100%;
            height: auto;
            border-radius: 8px;
        }

        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close-modal:hover,
        .close-modal:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans antialiased">

<div class="container mx-auto py-10">
    <div class="flex">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-800 text-white h-screen p-6">
            <h2 class="text-2xl font-semibold mb-6">Admin Menu</h2>
            <ul class="space-y-4">
                <li><a href="admin_dashboard.php" class="flex items-center hover:bg-gray-700 p-2 rounded"><i class="fas fa-tachometer-alt mr-3"></i> Dashboard</a></li>
                <li><a href="manage_images.php" class="flex items-center hover:bg-gray-700 p-2 rounded"><i class="fas fa-images mr-3"></i> Manage Images</a></li>
                <li><a href="mapLocation.php" class="flex items-center hover:bg-gray-700 p-2 rounded"><i class="fas fa-map-marked-alt mr-3"></i> Map</a></li>
                <li><a href="settings.php" class="flex items-center hover:bg-gray-700 p-2 rounded"><i class="fas fa-cogs mr-3"></i> Settings</a></li>
                <li><a href="admin_logout.php" class="flex items-center hover:bg-gray-700 p-2 rounded"><i class="fas fa-sign-out-alt mr-3"></i> Logout</a></li>
            </ul>
        </div>
        
        <!-- Popup for Notifications -->
        <div id="animalPopup" class="popup">
            <span id="popupMessage"></span>
            <button id="closePopup" class="ml-4 px-2 py-1 rounded">✕</button>
        </div>

        <!-- Modal for Viewing Images -->
        <div id="imageModal" class="modal">
            <div class="modal-content">
                <span class="close-modal">&times;</span>
                <img id="modalImage" src="" alt="Detection Image">
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6">
            <!-- Header Section -->
            <header class="flex justify-between items-center mb-10">
                <h1 class="text-4xl font-bold text-gray-800">Admin Dashboard</h1>
            </header>

            <!-- Detections Table -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Detection Data</h2>

                <!-- Table for Detections -->
                <table class="min-w-full table-auto border-collapse border border-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">ID</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Timestamp</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Camera Location</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Elephant Location</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Distance</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Image</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="detection-table-body">
<?php if (count($markers) > 0): ?>
    <?php foreach ($markers as $marker): ?>
        <tr id="row-<?= htmlspecialchars($marker['id']) ?>" class="<?= $marker['elephant'] ? 'highlighted-row' : '' ?>">
            <td class="border px-4 py-2"><?= htmlspecialchars($marker['id']) ?></td>
            <td class="border px-4 py-2"><?= htmlspecialchars($marker['timestamp']) ?></td>
            <td class="border px-4 py-2"><?= htmlspecialchars($marker['lat_cam']) ?>, <?= htmlspecialchars($marker['long_cam']) ?></td>
            <td class="border px-4 py-2"><?= htmlspecialchars($marker['lat_ele']) ?>, <?= htmlspecialchars($marker['long_ele']) ?></td>
            <td class="border px-4 py-2"><?= htmlspecialchars($marker['distance_ele']) ?> m</td>
            <td class="border px-4 py-2">
                <?php if (!empty($marker['image_path'])): ?>
                    <button onclick="openImageModal('<?= htmlspecialchars($marker['image_path']) ?>')" class="bg-purple-500 text-white px-3 py-1 rounded">View Image</button>
                <?php else: ?>
                    <span class="text-gray-500">No Image</span>
                <?php endif; ?>
            </td>
            <td class="border px-4 py-2">
                <button onclick="focusOnMarker(<?= htmlspecialchars($marker['id']) ?>, 'cam')" class="bg-blue-500 text-white px-3 py-1 rounded">Focus Camera</button>
                <?php if ($marker['elephant']): ?>
                    <button onclick="focusOnMarker(<?= htmlspecialchars($marker['id']) ?>, 'ele')" class="bg-green-500 text-white px-3 py-1 rounded">Focus Elephant</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
        <tr>
            <td colspan="7" class="border px-4 py-2 text-center">No data available.</td>
        </tr>
<?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="flex justify-between items-center mt-6">
                    <div>
                        <span class="text-gray-600">Page <?= htmlspecialchars($page) ?> of <?= htmlspecialchars($total_pages) ?></span>
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= htmlspecialchars($page - 1) ?>" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Prev</a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= htmlspecialchars($page + 1) ?>" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Map Section -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Live Map</h2>
                <div id="mapid" class="w-full h-96 rounded-lg shadow-md"></div>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<!-- Leaflet Control Geocoder JS -->
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<!-- Leaflet Routing Machine JS -->
<script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.js"></script>

<script>
    let isDarkMode = false;
    const darkTileLayer = "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png";
    const lightTileLayer = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
    let currentTileLayer;
    let mymap;
    let currentLocationMarker = null;
    let lastMarker = null;
    let currentRoute = null;

    const cameraIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/45/45010.png', // ไอคอนกล้อง
        iconSize: [30, 30], // ขนาดไอคอน
        iconAnchor: [15, 30], // จุดที่เชื่อมต่อกับตำแหน่ง
        popupAnchor: [0, -30], // จุดที่เปิด popup
    });

    const elephantIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/107/107831.png', // ไอคอนช้าง
        iconSize: [30, 30],
        iconAnchor: [15, 30],
        popupAnchor: [0, -30],
    });

    const currentLocationIcon = L.icon({
        iconUrl: "https://cdn-icons-png.flaticon.com/512/1828/1828884.png",
        iconSize: [36, 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -18],
    });

    // Markers data from PHP
    const markersData = <?php echo json_encode($markers); ?>;

    // สร้าง object เพื่อเก็บมาร์กเกอร์ด้วย id
    const markersObject = {};

    // Initialize Map
    function initializeMap() {
        const initialLat = <?php echo isset($_GET['lat']) ? htmlspecialchars($_GET['lat']) : '14.439606'; ?>;
        const initialLong = <?php echo isset($_GET['long']) ? htmlspecialchars($_GET['long']) : '101.372359'; ?>;
        const initialView = [initialLat, initialLong];

        mymap = L.map("mapid").setView(initialView, 13);
        currentTileLayer = L.tileLayer(lightTileLayer, {
            maxZoom: 19,
            attribution: "© OpenStreetMap contributors",
        }).addTo(mymap);

        // เพิ่มมาร์กเกอร์จากฐานข้อมูล
        markersData.forEach(marker => {
            const camMarker = L.marker([marker.lat_cam, marker.long_cam], { icon: cameraIcon }).addTo(mymap);
            camMarker.bindPopup(`
                <div class="popup-content">
                    <h3 class="text-blue-500">กล้อง CCTV #${marker.id}</h3>
                    <p>ละติจูด: ${marker.lat_cam}</p>
                    <p>ลองจิจูด: ${marker.long_cam}</p>
                    <p>สถานะ: ออนไลน์</p>
                    <button class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded mt-2" onclick="openImageModal('${marker.image_path}')">
                        ดูภาพจากกล้อง
                    </button>
                </div>
            `);
            markersObject[marker.id + '_cam'] = camMarker;

            // มาร์กเกอร์ช้าง (ถ้ามี)
            if (marker.elephant) {
                const eleMarker = L.marker([marker.lat_ele, marker.long_ele], { icon: elephantIcon }).addTo(mymap);
                eleMarker.bindPopup(`
                    <div class="popup-content">
                        <h3 class="text-green-500">ช้าง #${marker.id}</h3>
                        <p>ละติจูด: ${marker.lat_ele}</p>
                        <p>ลองจิจูด: ${marker.long_ele}</p>
                        <p>ระยะห่าง: ${marker.distance_ele} ม.</p>
                    </div>
                `);
                markersObject[marker.id + '_ele'] = eleMarker;
            }
        });

        // เพิ่ม Geocoder Control
        const geocoder = L.Control.Geocoder.nominatim({
            geocodingQueryParams: {
                countrycodes: "th",
                "accept-language": "th",
            },
        });

        const searchControl = L.Control.geocoder({
            geocoder: geocoder,
            position: "topleft",
            placeholder: "ค้นหาสถานที่...",
            defaultMarkGeocode: false,
        })
            .on("markgeocode", function (e) {
                handleLocationSelect(e.geocode.center);
            })
            .addTo(mymap);

        mymap.on("click", function (e) {
            handleLocationSelect(e.latlng);
        });
    }

    function viewCameraFeed(cameraId) {
        // ฟังก์ชันนี้ควรเชื่อมโยงไปยังฟีดกล้องจริง ๆ หรือแสดงข้อมูลเพิ่มเติม
        showNotification(`ดูภาพจากกล้อง CCTV #${cameraId}`, "info");
    }

    // แสดงข้อความแจ้งเตือน
    function showNotification(message, type = "info") {
        const notification = document.createElement("div");
        notification.className = `notification fixed top-5 left-1/2 transform -translate-x-1/2 px-4 py-2 rounded-lg shadow-lg text-white font-medium ${
            type === "info"
                ? "bg-blue-500"
                : type === "success"
                ? "bg-green-500"
                : type === "warning"
                ? "bg-yellow-500"
                : "bg-red-500"
        } transition-opacity duration-300`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.classList.add("opacity-0");
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }

    // ฟังก์ชันเปิด Modal เพื่อดูรูปภาพ
    function openImageModal(imagePath) {
        const modal = document.getElementById("imageModal");
        const modalImage = document.getElementById("modalImage");
        modalImage.src = imagePath;
        modal.style.display = "block";
    }

    // ฟังก์ชันปิด Modal
    function closeImageModal() {
        const modal = document.getElementById("imageModal");
        const modalImage = document.getElementById("modalImage");
        modal.style.display = "none";
        modalImage.src = "";
    }

    // Event Listener สำหรับปิด Modal เมื่อคลิกที่ปุ่มปิด
    document.querySelector(".close-modal").addEventListener("click", closeImageModal);

    // Event Listener สำหรับปิด Modal เมื่อคลิกนอก Modal Content
    window.addEventListener("click", function(event) {
        const modal = document.getElementById("imageModal");
        if (event.target == modal) {
            closeImageModal();
        }
    });

    // เริ่มต้นแผนที่เมื่อโหลดหน้า
    document.addEventListener("DOMContentLoaded", () => {
        initializeMap();
        checkNewData();  // ตรวจสอบครั้งแรก
        setInterval(checkNewData, 8000);  // ตั้งเวลาตรวจสอบทุก 8 วินาที
        checkTimeout();  // เริ่มต้นการตรวจสอบการหมดเวลาของการแจ้งเตือน
    });

    function removeStopTrackingButton() {
        const existingButton = document.getElementById("stopTrackingBtn");
        if (existingButton) {
            existingButton.remove();
        }
    }

    let lastDetectionTime = Date.now();  // เวลาปัจจุบันที่ใช้ในการเช็คว่าไม่มีข้อมูลมานานแค่ไหน

    // ฟังก์ชันสำหรับแสดงการแจ้งเตือน
    function handleNewDetection(detection) {
    console.log('Handling new detection:', detection);  // เพิ่มบรรทัดนี้
    let message = '';
    let type = '';

    // ตรวจสอบว่า elephant เป็น boolean true หรือไม่
    if (detection.elephant === true || detection.elephant === 'true' || parseInt(detection.elephant) === 1) {
        message = `⚠️ เจอช้างที่ตำแหน่ง: ละติจูด ${detection.lat_ele}, ลองจิจูด ${detection.long_ele}`;
        type = 'elephant';
        showPopup(message, type);  // แสดง popup
        showHeaderAlert("แจ้งเตือน: " + message);  // แสดงการแจ้งเตือนที่หัวหน้าเว็บ
    }

    // หากต้องการจัดการกับประเภทการตรวจจับอื่นๆ เพิ่มที่นี่
    // else if (detection.elephant === false) { ... }
}


   function showPopup(message, type) {
    console.log('Showing popup:', message, type);  // เพิ่มบรรทัดนี้
    const popup = document.getElementById('animalPopup');
    const popupMessage = document.getElementById('popupMessage');

    if (!popup || !popupMessage) {
        console.error('ไม่พบองค์ประกอบ popup');
        return;
    }

    // ลบคลาสประเภทเก่าออกทั้งหมด
    popup.className = 'popup';

    // เพิ่มคลาสประเภทใหม่
    popup.classList.add(type);
    popup.classList.add('show');

    // ตั้งข้อความ
    popupMessage.textContent = message;

    // ซ่อน popup อัตโนมัติหลังจาก 5 วินาที
    setTimeout(() => {
        popup.classList.remove('show');
    }, 5000);
}

function showHeaderAlert(message) {
    console.log('Showing header alert:', message);  // เพิ่มบรรทัดนี้
    // สร้าง div สำหรับการแจ้งเตือน
    const alertDiv = document.createElement("div");
    alertDiv.className = "alert-header";
    alertDiv.textContent = message;

    // เพิ่ม alert div เข้าไปใน header
    const header = document.querySelector("header");
    header.prepend(alertDiv);

    // ลบการแจ้งเตือนหลังจาก 5 วินาที
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}


    // ฟังก์ชันตรวจสอบข้อมูลใหม่
    function checkNewData() {
    fetch('https://aprlabtop.com/elephant_api/get_detections.php')  // เปลี่ยน URL เป็น get_detections.php
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        console.log('Data fetched from API:', data);  // เพิ่มบรรทัดนี้
        // สมมติว่าโครงสร้างของ API คือ { status: 'success', data: [{...}, ...] }
        if (data && data.status === 'success' && Array.isArray(data.data) && data.data.length > 0) {
            lastDetectionTime = Date.now();  // รีเซ็ตเวลาของการตรวจจับล่าสุด
            handleNewDetection(data.data[0]);  // จัดการการตรวจจับล่าสุด
            updateMap(data.data[0]);  // อัปเดตแผนที่ด้วยการตรวจจับล่าสุด
        }
    })
    .catch(error => {
        console.error('Error fetching data:', error);
        showNotification('เกิดข้อผิดพลาดในการดึงข้อมูลใหม่: ' + error.message, 'error');
    });
}


    // ฟังก์ชันซ่อนการแจ้งเตือนจาก Header
    function hideHeaderAlert() {
        const alertDiv = document.querySelector(".alert-header");
        if (alertDiv) {
            alertDiv.remove();  // ลบการแจ้งเตือนออกจาก header
        }
    }

    // ฟังก์ชันตรวจสอบว่าไม่มีข้อมูลในระยะเวลา 10 วินาที
    function checkTimeout() {
        setInterval(() => {
            // ถ้าเวลาเกิน 10 วินาทีหลังจากได้รับข้อมูลล่าสุด
            if (Date.now() - lastDetectionTime > 10000) {
                hideHeaderAlert();  // หยุดการแจ้งเตือน
            }
        }, 1000);  // เช็คทุกๆ 1 วินาที
    }

   function updateMap(detection) {
    console.log('Updating map with detection:', detection);  // เพิ่มบรรทัดนี้
    try {
        // ลบมาร์กเกอร์เก่า (ถ้ามี)
        const oldCamMarker = markersObject[detection.id + '_cam'];
        const oldEleMarker = markersObject[detection.id + '_ele'];
        
        if (oldCamMarker) mymap.removeLayer(oldCamMarker);
        if (oldEleMarker) mymap.removeLayer(oldEleMarker);

        // เพิ่มมาร์กเกอร์ใหม่
        const camMarker = L.marker([detection.lat_cam, detection.long_cam], {
            icon: cameraIcon
        }).addTo(mymap);

        camMarker.bindPopup(`
            <div class="popup-content">
                <h3 class="text-blue-500">กล้อง CCTV #${detection.id}</h3>
                <p>พิกัด: ${detection.lat_cam}, ${detection.long_cam}</p>
                <p>เวลา: ${detection.timestamp}</p>
                <button class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded mt-2" onclick="openImageModal('${detection.image_path}')">
                    ดูภาพจากกล้อง
                </button>
            </div>
        `);

        if (detection.elephant) {
            const eleMarker = L.marker([detection.lat_ele, detection.long_ele], {
                icon: elephantIcon
            }).addTo(mymap);

            eleMarker.bindPopup(`
                <div class="popup-content">
                    <h3 class="text-green-500">ตำแหน่งที่ตรวจพบ #${detection.id}</h3>
                    <p>พิกัด: ${detection.lat_ele}, ${detection.long_ele}</p>
                    <p>ระยะห่าง: ${detection.distance_ele} เมตร</p>
                </div>
            `);

            // บันทึกมาร์กเกอร์ช้าง
            markersObject[detection.id + '_ele'] = eleMarker;
        }

        // บันทึกมาร์กเกอร์กล้อง
        markersObject[detection.id + '_cam'] = camMarker;

        // ซูมแผนที่ไปที่ตำแหน่งล่าสุด
        mymap.setView([detection.lat_cam, detection.long_cam], 15);

        // อัปเดตตารางข้อมูล
        updateTable(detection);
    } catch (error) {
        console.error('Error updating map:', error);
    }
}

   function updateTable(detection) {
    console.log('Updating table with detection:', detection);  // เพิ่มบรรทัดนี้
    const tableBody = document.getElementById('detection-table-body');
    const newRow = document.createElement('tr');
    newRow.id = `row-${detection.id}`;
    newRow.className = detection.elephant ? 'highlighted-row' : '';

    newRow.innerHTML = `
        <td class="border px-4 py-2">${detection.id}</td>
        <td class="border px-4 py-2">${detection.timestamp}</td>
        <td class="border px-4 py-2">${detection.lat_cam}, ${detection.long_cam}</td>
        <td class="border px-4 py-2">${detection.lat_ele}, ${detection.long_ele}</td>
        <td class="border px-4 py-2">${detection.distance_ele} m</td>
        <td class="border px-4 py-2">
            ${detection.image_path ? `<button onclick="openImageModal('${detection.image_path}')" class="bg-purple-500 text-white px-3 py-1 rounded">View Image</button>` : `<span class="text-gray-500">No Image</span>`}
        </td>
        <td class="border px-4 py-2">
            <button onclick="focusOnMarker(${detection.id}, 'cam')" class="bg-blue-500 text-white px-3 py-1 rounded">Focus Camera</button>
            ${detection.elephant ? `<button onclick="focusOnMarker(${detection.id}, 'ele')" class="bg-green-500 text-white px-3 py-1 rounded">Focus Elephant</button>` : ''}
        </td>
    `;

    // เพิ่มแถวใหม่ที่ด้านบนสุดของตาราง
    tableBody.prepend(newRow);

    // หากเกินจำนวนข้อมูลต่อหน้า ลบแถวด้านล่าง
    const currentRows = tableBody.querySelectorAll('tr');
    if (currentRows.length > <?= $perPage ?>) {
        tableBody.removeChild(currentRows[currentRows.length - 1]);
    }
}

    // ฟังก์ชันโฟกัสที่มาร์กเกอร์
    function focusOnMarker(id, type) {
        const key = id + '_' + type;
        const marker = markersObject[key];
        
        if (marker) {
            mymap.setView(marker.getLatLng(), 15);  // Zoom ไปที่มาร์กเกอร์
            marker.openPopup();  // เปิด popup ที่เกี่ยวข้อง
            
            // แสดงข้อความใน notification
            if (type === 'ele') {
                showNotification('เจอช้างที่ตำแหน่งนี้!', "warning");
            } else if (type === 'cam') {
                showNotification('โฟกัสกล้องแล้ว!', "info");
            } else {
                showNotification('ประเภทที่ไม่รู้จัก!', "error");
            }
        }
    }

    // ฟังก์ชันรีเฟรชหน้าเว็บทุกๆ 8 วินาที (ถูกลบออกแล้ว)

    // เรียกใช้ฟังก์ชันตรวจสอบการหมดเวลาของการแจ้งเตือน
    checkTimeout();  // เริ่มต้นการตรวจสอบ
// ฟังก์ชันตรวจสอบข้อมูลใหม่
function checkNewData() {
    fetch('https://aprlabtop.com/elephant_api/get_detections.php')  // เปลี่ยน URL เป็น get_detections.php
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        console.log('Data fetched from API:', data);  // เพิ่มบรรทัดนี้
        // สมมติว่าโครงสร้างของ API คือ { status: 'success', data: [{...}, ...] }
        if (data && data.status === 'success' && Array.isArray(data.data) && data.data.length > 0) {
            lastDetectionTime = Date.now();  // รีเซ็ตเวลาของการตรวจจับล่าสุด
            handleNewDetection(data.data[0]);  // จัดการการตรวจจับล่าสุด
            updateMap(data.data[0]);  // อัปเดตแผนที่ด้วยการตรวจจับล่าสุด
        }
    })
    .catch(error => {
        console.error('Error fetching data:', error);
        showNotification('เกิดข้อผิดพลาดในการดึงข้อมูลใหม่: ' + error.message, 'error');
    });
}

// ฟังก์ชันจัดการการตรวจจับใหม่
function handleNewDetection(detection) {
    console.log('Handling new detection:', detection);  // เพิ่มบรรทัดนี้
    let message = '';
    let type = '';

    // ตรวจสอบว่า elephant เป็น boolean true หรือไม่
    if (detection.elephant === true || detection.elephant === 'true' || parseInt(detection.elephant) === 1) {
        message = `⚠️ เจอช้างที่ตำแหน่ง: ละติจูด ${detection.lat_ele}, ลองจิจูด ${detection.long_ele}`;
        type = 'elephant';
        showPopup(message, type);  // แสดง popup
        showHeaderAlert("แจ้งเตือน: " + message);  // แสดงการแจ้งเตือนที่หัวหน้าเว็บ
    }

    // หากต้องการจัดการกับประเภทการตรวจจับอื่นๆ เพิ่มที่นี่
    // else if (detection.elephant === false) { ... }
}

// ฟังก์ชันแสดง popup การแจ้งเตือน
function showPopup(message, type) {
    console.log('Showing popup:', message, type);  // เพิ่มบรรทัดนี้
    const popup = document.getElementById('animalPopup');
    const popupMessage = document.getElementById('popupMessage');

    if (!popup || !popupMessage) {
        console.error('ไม่พบองค์ประกอบ popup');
        return;
    }

    // ลบคลาสประเภทเก่าออกทั้งหมด
    popup.className = 'popup';

    // เพิ่มคลาสประเภทใหม่
    popup.classList.add(type);
    popup.classList.add('show');

    // ตั้งข้อความ
    popupMessage.textContent = message;

    // ซ่อน popup อัตโนมัติหลังจาก 5 วินาที
    setTimeout(() => {
        popup.classList.remove('show');
    }, 5000);
}

// ฟังก์ชันแสดงการแจ้งเตือนที่หัวหน้าเว็บ
function showHeaderAlert(message) {
    console.log('Showing header alert:', message);  // เพิ่มบรรทัดนี้
    // สร้าง div สำหรับการแจ้งเตือน
    const alertDiv = document.createElement("div");
    alertDiv.className = "alert-header";
    alertDiv.textContent = message;

    // เพิ่ม alert div เข้าไปใน header
    const header = document.querySelector("header");
    header.prepend(alertDiv);

    // ลบการแจ้งเตือนหลังจาก 5 วินาที
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

</script>

<!-- หน้าจอโหลด -->
<div class="loading fixed inset-0 flex items-center justify-center bg-white bg-opacity-95 dark:bg-gray-800 dark:bg-opacity-95 z-50 rounded-lg hidden">
    <div class="flex flex-col items-center">
        <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        <span class="mt-2 text-gray-700 dark:text-gray-200">กำลังโหลด...</span>
    </div>
</div>

</body>
</html>