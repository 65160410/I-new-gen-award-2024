<!-- เปิดส่วนของ PHP -->
<?php

?>
<!-- ปิดส่วนของ PHP -->

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AIตรวจจับช้างป่า</title>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.css"/>
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
	<script src="https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.js"></script>
    <style>
        :root {
            --primary-color: #3B82F6;
            --primary-dark: #2563EB;
            --success-color: #10B981;
            --bg-light: #F3F4F6;
            --bg-dark: #1F2937;
            --text-light: #374151;
            --text-dark: #F9FAFB;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Prompt', sans-serif;
        }

        html {
            font-size: 16px;
        }

        @media screen and (max-width: 768px) {
            html {
                font-size: 14px;
            }
        }

        @media screen and (max-width: 480px) {
            html {
                font-size: 12px;
            }
        }

        body {
            background-color: var(--bg-light);
            padding: clamp(10px, 2vw, 20px);
            transition: all 0.3s ease;
            min-height: 100vh;
            width: 100%;
        }

        body.dark-mode {
            background-color: var(--bg-dark);
            color: var(--text-dark);
        }

        .container {
            max-width: min(1200px, 95%);
            margin: 0 auto;
            background: white;
            border-radius: clamp(10px, 2vw, 20px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: clamp(10px, 3vw, 20px);
            transition: all 0.3s ease;
        }

        .dark-mode .container {
            background: #111827;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(10px, 2vw, 20px);
            padding: 0 clamp(5px, 1vw, 10px);
            flex-wrap: wrap;
            gap: 10px;
        }

        h1 {
            color: var(--text-light);
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 600;
        }

        .dark-mode h1 {
            color: var(--text-dark);
        }

        .controls {
            display: flex;
            gap: clamp(8px, 1.5vw, 15px);
            margin-bottom: clamp(10px, 2vw, 20px);
            padding: clamp(5px, 1vw, 10px);
            background: var(--bg-light);
            border-radius: 15px;
            flex-wrap: wrap;
            width: 100%;
        }

        .dark-mode .controls {
            background: #374151;
        }

        .modern-button {
            padding: clamp(8px, 1.5vw, 12px) clamp(16px, 2vw, 24px);
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: clamp(8px, 1.5vw, 12px);
            cursor: pointer;
            font-size: clamp(0.85rem, 2vw, 0.95rem);
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
        }

        .modern-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .theme-toggle {
            padding: 8px 16px;
            border-radius: 12px;
            background: var(--bg-light);
            border: 2px solid var(--primary-color);
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dark-mode .theme-toggle {
            background: #374151;
            color: var(--text-dark);
            border-color: var(--primary-dark);
        }

        #mapid {
            height: clamp(300px, 60vh, 600px);
            width: 100%;
            border-radius: clamp(8px, 1.5vw, 15px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: clamp(10px, 2vw, 20px);
            z-index: 1;
        }

        .dark-mode #mapid {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .info-panel {
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .dark-mode .info-panel {
            background: rgba(17, 24, 39, 0.9);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.95);
            padding: 20px 40px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            backdrop-filter: blur(5px);
            width: min(90%, 300px);
            text-align: center;
        }

        .dark-mode .loading {
            background: rgba(17, 24, 39, 0.95);
            color: var(--text-dark);
        }

        .loading::after {
            content: '';
            display: block;
            width: 30px;
            height: 30px;
            margin: 10px auto;
            border: 3px solid var(--primary-color);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));
            gap: clamp(8px, 1.5vw, 15px);
            margin-top: clamp(10px, 2vw, 20px);
        }

        .stat-card {
            background: white;
            padding: clamp(10px, 1.5vw, 15px);
            border-radius: clamp(8px, 1.5vw, 12px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .dark-mode .stat-card {
            background: #374151;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .leaflet-control-geocoder {
            border-radius: 8px !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
            background: white !important;
            border: none !important;
            overflow: hidden !important;
        }


        .leaflet-control-geocoder-form {
            padding: 0 !important;
            margin: 0 !important;
        }

        .dark-mode .leaflet-control-geocoder,
        .dark-mode .leaflet-control-geocoder-form input {
            background: #374151 !important;
            color: var(--text-dark) !important;
        }

        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            padding: 5px;
        }

        .dark-mode .leaflet-popup-content-wrapper {
            background: #374151;
            color: var(--text-dark);
        }

        .popup-content {
            padding: 10px;
        }

        .popup-content h3 {
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .dark-mode .popup-content h3 {
            color: var(--primary-dark);
        }

        .leaflet-routing-container.leaflet-bar.leaflet-control {
            display: none !important;
        }

        /* Mobile-specific adjustments */
        @media screen and (max-width: 480px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .controls {
                justify-content: center;
            }

            .modern-button {
                width: 100%;
                justify-content: center;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        /* Tablet-specific adjustments */
        @media screen and (min-width: 481px) and (max-width: 768px) {
            .controls {
                justify-content: center;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .notification {
            position: fixed;
            left: 50%;
            top: 20px;
            transform: translateX(-50%);
            padding: 15px 20px;
            border-radius: 12px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            opacity: 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 280px;
            max-width: 400px;
            animation: slideDown 0.3s ease forwards;
        }

        .notification.info {
            background-color: var(--primary-color);
        }

        .notification.success {
            background-color: var(--success-color);
        }

        .notification.warning {
            background-color: #F59E0B;
        }

        .notification.error {
            background-color: #EF4444;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translate(-50%, -100%);
            }

            100% {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        @keyframes slideUp {
            0% {
                opacity: 1;
                transform: translate(-50%, 0);
            }

            100% {
                opacity: 0;
                transform: translate(-50%, -100%);
            }
        }

        .notification.hide {
            animation: slideUp 0.3s ease forwards;
        }

        /* สำหรับมือถือ */
        @media screen and (max-width: 480px) {
            .notification {
                width: 90%;
                min-width: auto;
                top: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>AI ตรวจจับช้างป่า</h1>
            <button onclick="toggleTheme()" class="theme-toggle" id="themeToggle">
                <span id="themeIcon">🌙</span>
            </button>
        </div>

        <div class="controls">
            <button class="modern-button" onclick="getLocation()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" />
                </svg>
                ค้นหาตำแหน่งของคุณ
            </button>
            <button class="modern-button" onclick="showNearbyPlaces()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path
                        d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zM2.04 4.326c.325 1.329 2.532 2.54 3.717 3.19.48.263.793.434.743.484-.08.08-.162.158-.242.234-.416.396-.787.749-.758 1.266.035.634.618.824 1.214 1.017.577.188 1.168.38 1.286.983.082.417-.075.988-.22 1.52-.215.782-.406 1.48.22 1.48 1.5-.5 3.798-3.186 4-5 .138-1.243-2-2-3.5-2.5-.478-.16-.755.081-.99.284-.172.15-.322.279-.51.216-.445-.148-2.5-2-1.5-2.5.78-.39.952-.171 1.227.182.078.099.163.208.273.318.609.304.662-.132.723-.633.039-.322.081-.671.277-.867.434-.434 1.265-.791 2.028-1.12.712-.306 1.365-.587 1.579-.88A7 7 0 1 1 2.04 4.327z" />
                </svg>
                สถานที่ใกล้เคียง
            </button>
            <button class="modern-button" onclick="clearRoute()" id="clearRouteBtn" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path
                        d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z" />
                </svg>
                ล้างการนำทาง
            </button>
            <button class="modern-button" onclick="window.location.href='https://aprlabtop.com/Honey_test/admin_dashboard.php'">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
                    <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115l.094-.319z"/>
                </svg>
                แผงควบคุมผู้ดูแล
            </button>
        </div>

        <div id="mapid"></div>
        <div class="loading">กำลังโหลด...</div>

        <div class="stats">
            <div class="stat-card">
                <h3>ระยะทางรวมเฉลี่ย</h3>
                <p id="totalDistance">- กม.</p>
            </div>
            <div class="stat-card">
                <h3>เวลาเดินทางเฉลี่ย</h3>
                <p id="travelTime">- นาที</p>
            </div>
            <div class="stat-card">
                <h3>จุดสนใจใกล้เคียง</h3>
                <p id="nearbyCount">- แห่ง</p>
            </div>
        </div>
    </div>

    <script>
        let isDarkMode = false;
        const darkTileLayer =
            "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png";
        const lightTileLayer =
            "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
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
            mymap = L.map("mapid").setView([14.439606, 101.372359], 13);
            currentTileLayer = L.tileLayer(lightTileLayer, {
                maxZoom: 19,
                attribution: "© OpenStreetMap",
            }).addTo(mymap);

            var cameraIcon = L.icon({
                iconUrl: "https://cdn-icons-png.flaticon.com/128/45/45010.png",
                iconSize: [15, 15],
                iconAnchor: [18, 36],
                popupAnchor: [0, -36],
            });

            var camera2Marker = L.marker([14.22512, 101.40544], {
                icon: cameraIcon,
            }).addTo(mymap);
            camera2Marker.bindPopup(`
                <div class="popup-content">
                <h3>กล้อง CCTV #2</h3>
                <p>ละติจูด: 14.22512</p>
                <p>ลองจิจูด: 101.40544</p>
                <p>สถานะ: ออนไลน์</p>
                <button class="modern-button" onclick="viewCameraFeed(2)">
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
            stopButton.className = "modern-button";
            stopButton.id = "stopTrackingBtn";
            stopButton.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                    <path d="M5 6.25a1.25 1.25 0 1 1 2.5 0v3.5a1.25 1.25 0 1 1-2.5 0v-3.5zm3.5 0a1.25 1.25 0 1 1 2.5 0v3.5a1.25 1.25 0 1 1-2.5 0v-3.5z"/>
                </svg>
                หยุดการติดตาม
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

        let timeUpdateInterval = null;
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
                    <h3>ตำแหน่งของคุณ</h3>
                    <p>ละติจูด: ${lat.toFixed(5)}</p>
                    <p>ลองจิจูด: ${lng.toFixed(5)}</p>
                    <p>อัพเดทล่าสุด: ${new Date().toLocaleTimeString()}</p>
                </div>
            `);
                }
            }

            // Set initial popup content
            // <p>ความแม่นยำ: ±${accuracy.toFixed(1)} เมตร</p>
            currentLocationMarker
                .bindPopup(
                    `
        <div class="popup-content">
            <h3>ตำแหน่งของคุณ</h3>
            <p>ละติจูด: ${lat.toFixed(5)}</p>
            <p>ลองจิจูด: ${lng.toFixed(5)}</p>
            <p>อัพเดทล่าสุด: ${new Date().toLocaleTimeString()}</p>
        </div>
    `
                )
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

            // สร้าง popup content ทันที
            let popupContent = `
        <div class="popup-content">
            <h3>กำลังโหลด</h3>
            <p>ละติจูด: ${latlng.lat.toFixed(5)}</p>
            <p>ลองจิจูด: ${latlng.lng.toFixed(5)}</p>
            <button onclick='createRoute([${latlng.lat}, ${latlng.lng}])' class='modern-button'>
                นำทางไปที่นี่
            </button>
        </div>
    `;

            // ผูก popup กับ marker และแสดงทันที
            lastMarker.bindPopup(popupContent).openPopup();

            // ทำ reverse geocoding แยก
            const geocoder = L.Control.Geocoder.nominatim();
            geocoder.reverse(
                latlng,
                mymap.options.crs.scale(mymap.getZoom()),
                function (results) {
                    if (results && results.length > 0) {
                        const placeName = results[0].name || "ตำแหน่งที่เลือก";
                        // อัพเดท popup content เมื่อได้ชื่อสถานที่
                        popupContent = `
                    <div class="popup-content">
                        <h3>${placeName}</h3>
                        <p>ละติจูด: ${latlng.lat.toFixed(5)}</p>
                        <p>ลองจิจูด: ${latlng.lng.toFixed(5)}</p>
                        <button onclick='createRoute([${latlng.lat}, ${latlng.lng}])' class='modern-button'>
                            นำทางไปที่นี่
                        </button>
                    </div>
                `;
                        lastMarker.setPopupContent(popupContent);
                    }
                }
            );

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
        // แก้ไขฟังก์ชัน createRoute เพื่อให้แสดงปุ่มล้างการนำทาง
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
            document.getElementById("themeIcon").textContent = isDarkMode
                ? "☀️"
                : "🌙";
        }

        function showLoading() {
            document.querySelector(".loading").style.display = "block";
        }

        function hideLoading() {
            document.querySelector(".loading").style.display = "none";
        }

        function showNotification(message, type = "info") {
            const notification = document.createElement("div");
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        initializeMap();
    </script>
</body>

</html>