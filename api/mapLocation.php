<?php
// ตรวจสอบว่าพารามิเตอร์ lat, long, และ type ถูกส่งมาหรือไม่
$latitude = isset($_GET['lat']) ? htmlspecialchars($_GET['lat']) : '14.439606'; // ค่าเริ่มต้นหากไม่ส่งมา
$longitude = isset($_GET['long']) ? htmlspecialchars($_GET['long']) : '101.372359'; // ค่าเริ่มต้นหากไม่ส่งมา
$type = isset($_GET['type']) ? htmlspecialchars($_GET['type']) : 'cam'; // ค่าเริ่มต้นหากไม่ส่งมา
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AI ตรวจจับช้างป่า</title>
    <!-- รวม Tailwind CSS ผ่าน CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <!-- Leaflet Control Geocoder CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <!-- Leaflet Routing Machine CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
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
    </style>
</head>

<body class="bg-gray-100 dark:bg-gray-900 transition-colors duration-300">
    <div class="container mx-auto p-4 bg-white dark:bg-gray-800 rounded-lg shadow-lg transition-colors duration-300">
        <div class="flex flex-col md:flex-row justify-between items-center mb-4">
            <h1 class="text-xl md:text-2xl font-semibold text-gray-800 dark:text-gray-100">AI ตรวจจับช้างป่า</h1>
            <div class="flex items-center space-x-2 mt-2 md:mt-0">
                <!-- ปุ่มสลับธีม -->
                <button onclick="toggleTheme()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                    <span id="themeIcon">🌙</span>
                </button>
                <!-- ปุ่มสำหรับเจ้าหน้าที่ -->
                <a href="./admin_dashboard.php">
                    <button class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        สำหรับเจ้าหน้าที่
                    </button>
                </a>
            </div>
        </div>

        <div class="flex flex-col md:flex-row justify-between items-center mb-4 bg-gray-100 dark:bg-gray-700 p-4 rounded-lg">
            <!-- ปุ่มค้นหาตำแหน่ง -->
            <button class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2" onclick="getLocation()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" />
                </svg>
                <span>ค้นหาตำแหน่งของคุณ</span>
            </button>
            <!-- ปุ่มสถานที่ใกล้เคียง -->
            <button class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2" onclick="showNearbyPlaces()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zM2.04 4.326c.325 1.329 2.532 2.54 3.717 3.19.48.263.793.434.743.484-.08.08-.162.158-.242.234-.416.396-.787.749-.758 1.266.035.634.618.824 1.214 1.017.577.188 1.168.38 1.286.983.082.417-.075.988-.22 1.52-.215.782-.406 1.48.22 1.48 1.5-.5 3.798-3.186 4-5 .138-1.243-2-2-3.5-2.5-.478-.16-.755.081-.99.284-.172.15-.322.279-.51.216-.445-.148-2.5-2-1.5-2.5.78-.39.952-.171 1.227.182.078.099.163.208.273.318.609.304.662-.132.723-.633.039-.322.081-.671.277-.867.434-.434 1.265-.791 2.028-1.12.712-.306 1.365-.587 1.579-.88A7 7 0 1 1 2.04 4.327z" />
                </svg>
                <span>สถานที่ใกล้เคียง</span>
            </button>
            <!-- ปุ่มล้างการนำทาง -->
            <button class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2" onclick="clearRoute()" id="clearRouteBtn" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z" />
                </svg>
                <span>ล้างการนำทาง</span>
            </button>
        </div>

        <!-- แผนที่ -->
        <div id="mapid" class="w-full h-80 md:h-96 rounded-lg shadow-md"></div>
        <!-- หน้าจอโหลด -->
        <div class="loading fixed inset-0 flex items-center justify-center bg-white bg-opacity-95 dark:bg-gray-800 dark:bg-opacity-95 z-50 rounded-lg hidden">
            <div class="flex flex-col items-center">
                <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                <span class="mt-2 text-gray-700 dark:text-gray-200">กำลังโหลด...</span>
            </div>
        </div>

        <!-- สถิติ -->
        <div class="stats grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div class="stat-card bg-white dark:bg-gray-700 p-4 rounded-lg shadow">
                <h3 class="text-lg font-medium text-gray-800 dark:text-gray-200">ระยะทางรวมเฉลี่ย</h3>
                <p id="totalDistance" class="text-2xl font-bold text-blue-500">- กม.</p>
            </div>
            <div class="stat-card bg-white dark:bg-gray-700 p-4 rounded-lg shadow">
                <h3 class="text-lg font-medium text-gray-800 dark:text-gray-200">เวลาเดินทางเฉลี่ย</h3>
                <p id="travelTime" class="text-2xl font-bold text-blue-500">- นาที</p>
            </div>
            <div class="stat-card bg-white dark:bg-gray-700 p-4 rounded-lg shadow">
                <h3 class="text-lg font-medium text-gray-800 dark:text-gray-200">จุดสนใจใกล้เคียง</h3>
                <p id="nearbyCount" class="text-2xl font-bold text-blue-500">- แห่ง</p>
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
        let watchId = null;

        const currentLocationIcon = L.icon({
            iconUrl: "https://cdn-icons-png.flaticon.com/512/1828/1828884.png",
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18],
        });

        function initializeMap() {
            mymap = L.map("mapid").setView([<?php echo $latitude; ?>, <?php echo $longitude; ?>], 13);
            currentTileLayer = L.tileLayer(lightTileLayer, {
                maxZoom: 19,
                attribution: "© OpenStreetMap",
            }).addTo(mymap);

            // เพิ่มมาร์กเกอร์กล้อง CCTV ตัวอย่าง
            var cameraIcon = L.icon({
                iconUrl: "https://cdn-icons-png.flaticon.com/128/45/45010.png",
                iconSize: [30, 30],
                iconAnchor: [15, 30],
                popupAnchor: [0, -30],
            });

            var camera2Marker = L.marker([14.22512, 101.40544], { icon: cameraIcon }).addTo(mymap);
            camera2Marker.bindPopup(`
                <div class="popup-content">
                    <h3 class="text-blue-500">กล้อง CCTV #2</h3>
                    <p>ละติจูด: 14.22512</p>
                    <p>ลองจิจูด: 101.40544</p>
                    <p>สถานะ: ออนไลน์</p>
                    <button class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded mt-2" onclick="viewCameraFeed(2)">
                        ดูภาพจากกล้อง
                    </button>
                </div>
            `);

            const geocoder = L.Control.Geocoder.nominatim({
                geocodingQueryParams: {
                    countrycodes: "th",
                    "accept-language": "th",
                },
            });

            const searchControl = new L.Control.Geocoder({
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

        function getLocation() {
            showLoading();
            if (navigator.geolocation) {
                // Clear any existing watch
                if (watchId) {
                    navigator.geolocation.clearWatch(watchId);
                }

                // Start watching position
                watchId = navigator.geolocation.watchPosition(
                    showPosition,
                    showError,
                    {
                        enableHighAccuracy: true,
                        timeout: 3000,
                        maximumAge: 0,
                    }
                );

                // Add stop tracking button
                addStopTrackingButton();
            } else {
                hideLoading();
                showNotification("เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง", "error");
            }
        }

        function stopTracking() {
            if (watchId) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
                if (popupUpdateInterval) {
                    clearInterval(popupUpdateInterval);
                    popupUpdateInterval = null;
                }
                showNotification("หยุดการติดตามตำแหน่งแล้ว", "info");
                removeStopTrackingButton();
            }
        }

        function addStopTrackingButton() {
            removeStopTrackingButton();

            const stopButton = document.createElement("button");
            stopButton.className = "bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2";
            stopButton.id = "stopTrackingBtn";
            stopButton.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                    <path d="M5 6.25a1.25 1.25 0 1 1 2.5 0v3.5a1.25 1.25 0 1 1-2.5 0v-3.5zm3.5 0a1.25 1.25 0 1 1 2.5 0v3.5a1.25 1.25 0 1 1-2.5 0v-3.5z"/>
                </svg>
                <span>หยุดการติดตาม</span>
            `;
            stopButton.onclick = stopTracking;

            document.querySelector(".controls").appendChild(stopButton);
        }

        function removeStopTrackingButton() {
            const existingButton = document.getElementById("stopTrackingBtn");
            if (existingButton) {
                existingButton.remove();
            }
        }

        let popupUpdateInterval = null;

        function showPosition(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const accuracy = position.coords.accuracy;

            if (currentLocationMarker) {
                mymap.removeLayer(currentLocationMarker);
            }

            // Clear existing intervals if any
            if (popupUpdateInterval) {
                clearInterval(popupUpdateInterval);
            }

            currentLocationMarker = L.marker([lat, lng], {
                icon: currentLocationIcon,
            }).addTo(mymap);

            function updatePopupTime() {
                if (currentLocationMarker && currentLocationMarker.getPopup()) {
                    currentLocationMarker.setPopupContent(`
                        <div class="popup-content">
                            <h3 class="text-blue-500">ตำแหน่งของคุณ</h3>
                            <p>ละติจูด: ${lat.toFixed(5)}</p>
                            <p>ลองจิจูด: ${lng.toFixed(5)}</p>
                            <p>อัพเดทล่าสุด: ${new Date().toLocaleTimeString()}</p>
                        </div>
                    `);
                }
            }

            // Set initial popup content
            currentLocationMarker
                .bindPopup(`
                    <div class="popup-content">
                        <h3 class="text-blue-500">ตำแหน่งของคุณ</h3>
                        <p>ละติจูด: ${lat.toFixed(5)}</p>
                        <p>ลองจิจูด: ${lng.toFixed(5)}</p>
                        <p>อัพเดทล่าสุด: ${new Date().toLocaleTimeString()}</p>
                    </div>
                `)
                .openPopup();

            // Start updating popup time every second
            popupUpdateInterval = setInterval(updatePopupTime, 1000);

            mymap.setView([lat, lng], 15);
            hideLoading();
            updateStats([lat, lng]);
            findNearbyPlaces([lat, lng]);
        }

        function showError(error) {
            hideLoading();
            let message = "";
            switch (error.code) {
                case error.PERMISSION_DENIED:
                    message = "ผู้ใช้ปฏิเสธการขอตำแหน่ง";
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = "ไม่สามารถรับข้อมูลตำแหน่งได้";
                    break;
                case error.TIMEOUT:
                    message = "หมดเวลารอการตอบกลับ";
                    break;
                case error.UNKNOWN_ERROR:
                    message = "เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ";
                    break;
            }
            showNotification(message, "error");
        }

        function handleLocationSelect(latlng) {
            if (lastMarker) {
                mymap.removeLayer(lastMarker);
            }

            lastMarker = L.marker([latlng.lat, latlng.lng]).addTo(mymap);

            const geocoder = L.Control.Geocoder.nominatim();
            geocoder.reverse(latlng, mymap.options.crs.scale(mymap.getZoom()), function (results) {
                let placeName = "ไม่พบชื่อสถานที่";
                if (results && results.length > 0) {
                    placeName = results[0].name || "ไม่พบชื่อสถานที่";
                }

                let popupContent = `
                    <div class="popup-content">
                        <h3 class="text-blue-500">${placeName}</h3>
                        <p>ละติจูด: ${latlng.lat.toFixed(5)}</p>
                        <p>ลองจิจูด: ${latlng.lng.toFixed(5)}</p>
                        <button class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded mt-2" onclick='createRoute([${latlng.lat}, ${latlng.lng}])'>
                            นำทางไปที่นี่
                        </button>
                    </div>
                `;

                lastMarker.bindPopup(popupContent).openPopup();
            });

            updateStats([latlng.lat, latlng.lng]);
        }

        function clearRoute() {
            if (currentRoute) {
                // ลบเส้นทาง
                mymap.removeControl(currentRoute);
                currentRoute = null;

                // ลบมาร์กเกอร์ปลายทาง
                if (lastMarker) {
                    mymap.removeLayer(lastMarker);
                    lastMarker = null;
                }

                // รีเซ็ตค่าสถิติการนำทาง
                document.getElementById("totalDistance").textContent = "- กม.";
                document.getElementById("travelTime").textContent = "- นาที";

                // ซ่อนปุ่มล้างการนำทาง
                document.getElementById("clearRouteBtn").style.display = "none";

                showNotification("ล้างเส้นทางการนำทางแล้ว", "info");
            }
        }

        function createRoute(end) {
            if (!currentLocationMarker) {
                showNotification("กรุณาระบุตำแหน่งปัจจุบันก่อน", "warning");
                return;
            }

            const start = currentLocationMarker.getLatLng();

            if (currentRoute) {
                mymap.removeControl(currentRoute);
            }

            currentRoute = L.Routing.control({
                waypoints: [L.latLng(start.lat, start.lng), L.latLng(end[0], end[1])],
                router: L.Routing.osrmv1({
                    serviceUrl: "https://router.project-osrm.org/route/v1",
                    profile: "driving",
                }),
                lineOptions: {
                    styles: [
                        {
                            color: isDarkMode ? "#60A5FA" : "#3B82F6",
                            opacity: 0.8,
                            weight: 6,
                        },
                    ],
                },
                showAlternatives: true,
                altLineOptions: {
                    styles: [
                        {
                            color: "#A5B4FC",
                            opacity: 0.6,
                            weight: 4,
                        },
                    ],
                },
                fitSelectedRoutes: true,
                routeWhileDragging: true,
            }).addTo(mymap);

            currentRoute.on("routesfound", function (e) {
                const routes = e.routes;
                const summary = routes[0].summary;
                updateRouteStats(summary);
            });

            // แสดงปุ่มล้างการนำทาง
            document.getElementById("clearRouteBtn").style.display = "inline-flex";
        }

        function updateRouteStats(summary) {
            document.getElementById("totalDistance").textContent =
                (summary.totalDistance / 1000).toFixed(2) + " กม.";
            document.getElementById("travelTime").textContent =
                Math.round(summary.totalTime / 60) + " นาที";
        }

        function updateStats(coords) {
            if (currentLocationMarker) {
                const currentLatLng = currentLocationMarker.getLatLng();
                const distance = mymap.distance(
                    currentLatLng,
                    L.latLng(coords[0], coords[1])
                );
                document.getElementById("totalDistance").textContent =
                    (distance / 1000).toFixed(2) + " กม.";
                const timeInMinutes = Math.round((distance / 675 / 50) * 60);
                document.getElementById("travelTime").textContent =
                    timeInMinutes + " นาที";
            }
        }

        function findNearbyPlaces(coords) {
            const nearby = Math.floor(Math.random() * 10) + 5;
            document.getElementById("nearbyCount").textContent = nearby + " แห่ง";
        }

        function showNearbyPlaces() {
            if (!currentLocationMarker) {
                showNotification("กรุณาระบุตำแหน่งปัจจุบันก่อน", "warning");
                return;
            }
            showNotification("กำลังค้นหาสถานที่ใกล้เคียง...", "info");
            findNearbyPlaces([
                currentLocationMarker.getLatLng().lat,
                currentLocationMarker.getLatLng().lng,
            ]);
        }

        function toggleTheme() {
            isDarkMode = !isDarkMode;
            document.body.classList.toggle("dark-mode");
            document.getElementById("themeIcon").textContent = isDarkMode ? "☀️" : "🌙";
            // เปลี่ยน Tile Layer ตามธีม
            if (isDarkMode) {
                mymap.removeLayer(currentTileLayer);
                currentTileLayer = L.tileLayer(darkTileLayer, {
                    maxZoom: 19,
                    attribution: "© OpenStreetMap",
                }).addTo(mymap);
            } else {
                mymap.removeLayer(currentTileLayer);
                currentTileLayer = L.tileLayer(lightTileLayer, {
                    maxZoom: 19,
                    attribution: "© OpenStreetMap",
                }).addTo(mymap);
            }
            // เปลี่ยนสีของเส้นทางถ้ามีการแสดงเส้นทางอยู่
            if (currentRoute) {
                currentRoute.options.lineOptions.styles[0].color = isDarkMode ? "#60A5FA" : "#3B82F6";
                mymap.removeControl(currentRoute);
                createRoute(currentRoute.getWaypoints()[1].latLng);
            }
        }

        function showLoading() {
            document.querySelector(".loading").classList.remove("hidden");
        }

        function hideLoading() {
            document.querySelector(".loading").classList.add("hidden");
        }

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

        function viewCameraFeed(cameraId) {
            // ฟังก์ชันนี้ควรเชื่อมโยงไปยังฟีดกล้องจริง ๆ
            showNotification(`ดูภาพจากกล้อง CCTV #${cameraId}`, "info");
        }

        // เริ่มต้นแผนที่เมื่อโหลดหน้า
        document.addEventListener("DOMContentLoaded", initializeMap);
    </script>
</body>

</html>
