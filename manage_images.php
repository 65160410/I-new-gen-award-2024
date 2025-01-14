<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

include '../elephant_api/db.php';
if (!isset($conn) || !$conn instanceof mysqli) {
    die(json_encode([
        "status" => "error",
        "message" => "Database connection is not established."
    ]));
}

// กำหนดจำนวนข้อมูลต่อหน้า
$perPage = 10;

// รับค่า page จาก query string (ถ้าไม่มี ให้เป็น page=1)
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// คำนวณจุดเริ่มต้น
$start = ($page - 1) * $perPage;

// ดึงข้อมูลรูป โดยใส่ LIMIT
$sql = "SELECT id, timestamp, image_path 
        FROM images 
        ORDER BY id DESC
        LIMIT ?, ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("ii", $start, $perPage);
$stmt->execute();
$result = $stmt->get_result();

$images_data = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $full_image_path = '';
        if (!empty($row['image_path'])) {
            if (strpos($row['image_path'], 'uploads/') === 0) {
                $full_image_path = 'https://aprlabtop.com/elephant_api/' . $row['image_path'];
            } else {
                $full_image_path = 'https://aprlabtop.com/elephant_api/uploads/' . $row['image_path'];
            }
        }
        $images_data[] = [
            'id'         => $row['id'],
            'timestamp'  => $row['timestamp'],
            'image_path' => $full_image_path
        ];
    }
}
$stmt->close();

// หาจำนวน total_rows ทั้งหมด เพื่อนับหน้า
$sql_count = "SELECT COUNT(id) as total FROM images";
$res_count = $conn->query($sql_count);
$total_rows = 0;
if ($res_count && $res_count->num_rows > 0) {
    $total_rows = $res_count->fetch_assoc()['total'];
}
$total_pages = ceil($total_rows / $perPage);

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Manage Images (Pagination)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; }
        #imageModal { display: none; }
        #imageModal.active { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex">

<div class="w-64 bg-white border-r border-gray-200 fixed inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out z-50">
    <div class="p-6">
        <h2 class="text-2xl font-semibold mb-6 text-gray-700">Admin Menu</h2>
        <ul class="space-y-4">
            <li><a href="admin_dashboard.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                <i class="fas fa-tachometer-alt mr-3 text-gray-600"></i> Dashboard หลัก
            </a></li>
            <li><a href="ChartDashboard.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                <i class="fas fa-chart-line mr-3 text-gray-600"></i> Dashboard สรุปเหตุการณ์
            </a></li>
            <li><a href="manage_images.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                <i class="fas fa-images mr-3 text-gray-600"></i> จัดการรูปภาพ
            </a></li>
            <li><a href="test_map.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                <i class="fas fa-map-marked-alt mr-3 text-gray-600"></i> แผนที่
            </a></li>
            <li><a href="admin_logout.php" class="flex items-center p-2 rounded hover:bg-gray-100 transition-colors">
                <i class="fas fa-sign-out-alt mr-3 text-gray-600"></i> ออกจากระบบ
            </a></li>
        </ul>
    </div>
</div>

    <!-- Main Content -->
    <div class="flex-1 ml-64 p-6">
        <h1 class="text-4xl font-bold mb-6 text-gray-800">Manage Images (Pagination)</h1>

        <!-- ปุ่ม Download All Images -->
        <div class="mb-4">
            <a 
                href="download.php" 
                class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"
            >
                <i class="fas fa-download mr-1"></i> Download All Images
            </a>
        </div>

        <?php if (count($images_data) > 0): ?>
            
            <div class="overflow-x-auto bg-white rounded shadow-lg p-4">
                <table class="min-w-full border border-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-2 border-b border-gray-200 text-gray-600 font-semibold">ID</th>
                            <th class="px-4 py-2 border-b border-gray-200 text-gray-600 font-semibold">Timestamp</th>
                            <th class="px-4 py-2 border-b border-gray-200 text-gray-600 font-semibold">Preview</th>
                            <th class="px-4 py-2 border-b border-gray-200 text-gray-600 font-semibold">Download</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($images_data as $img): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 border-b border-gray-200">
                                    <?= htmlspecialchars($img['id']) ?>
                                </td>
                                <td class="px-4 py-2 border-b border-gray-200">
                                    <?= htmlspecialchars($img['timestamp']) ?>
                                </td>
                                <td class="px-4 py-2 border-b border-gray-200">
                                    <?php if (!empty($img['image_path'])): ?>
                                        <img 
                                            src="<?= htmlspecialchars($img['image_path']) ?>"
                                            alt="Image <?= htmlspecialchars($img['id']) ?>"
                                            class="w-24 h-24 object-cover rounded cursor-pointer"
                                            onclick="openModal('<?= htmlspecialchars($img['image_path']) ?>')"
                                        />
                                    <?php else: ?>
                                        <span class="text-gray-400">No Image</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 border-b border-gray-200">
                                    <?php if (!empty($img['image_path'])): ?>
                                        <a 
                                            href="<?= htmlspecialchars($img['image_path']) ?>"
                                            download="image_<?= htmlspecialchars($img['id']) ?>.jpg"
                                            class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded"
                                        >
                                            <i class="fas fa-download mr-1"></i> Download
                                        </a>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination (Prev/Next) -->
            <div class="mt-4 flex justify-between items-center">
                <div class="text-gray-600">
                    Page <?= $page ?> of <?= $total_pages ?>
                </div>
                <div class="space-x-2">
                    <?php if ($page > 1): ?>
                        <a 
                            href="?page=<?= ($page - 1) ?>" 
                            class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-3 py-1 rounded"
                        >
                            Prev
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a 
                            href="?page=<?= ($page + 1) ?>" 
                            class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-3 py-1 rounded"
                        >
                            Next
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <p class="text-gray-600 mt-4">No images found.</p>
        <?php endif; ?>
    </div>

    <!-- Modal สำหรับดูภาพใหญ่ -->
    <div id="imageModal" class="fixed inset-0 bg-black bg-opacity-75 hidden items-center justify-center z-50">
        <div class="relative max-w-4xl mx-auto">
            <button 
                class="absolute top-2 right-2 text-white text-2xl font-bold" 
                onclick="closeModal()">
                &times;
            </button>
            <img 
                id="modalImage" 
                src="" 
                alt="Full Image" 
                class="max-h-screen object-contain rounded"
            >
        </div>
    </div>

    <script>
        function openModal(imagePath) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = imagePath;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModal() {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = "";
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
</body>
</html>
