<?php
// สามารถเพิ่มการประมวลผล PHP ที่ต้องการได้ที่นี่ เช่น การดึงข้อมูลจากฐานข้อมูล
?>
<!DOCTYPE html>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>ระบบแผนที่และนำทาง</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Sarabun', sans-serif;
        }

        body {
            background-color: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
            font-size: 2em;
            font-weight: 700;
        }

        #mapid {
            height: 600px;
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .location-button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .location-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            background: linear-gradient(135deg, #45a049, #3d8b40);
        }

        .location-button:active {
            transform: translateY(0);
        }

        /* ปรับแต่ง Popup */
        .leaflet-popup-content {
            font-family: 'Sarabun', sans-serif;
            padding: 10px;
        }

        .leaflet-popup-content b {
            color: #2c3e50;
            font-size: 1.1em;
            margin-bottom: 8px;
            display: block;
        }

        /* ปรับแต่ง Control elements */
        .leaflet-control-geocoder {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .leaflet-routing-container {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* สร้าง Loading indicator */
        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ระบบแผนที่และนำทาง</h1>
        <div class="controls">
            <button class="location-button" onclick="getLocation()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
                </svg>
                ระบุตำแหน่งปัจจุบัน
            </button>
        </div>
        <div id="mapid"></div>
        <div class="loading">กำลังโหลด...</div>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.js"></script>
    <script>
        // เริ่มต้นแผนที่ที่เขาใหญ่
        var mymap = L.map('mapid').setView([14.597093, 101.377258], 13);

        // เพิ่มแผนที่จาก OpenStreetMap with custom style
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap',
        }).addTo(mymap);

        var currentLocationMarker = null;
        var lastMarker = null;
        var currentRoute = null;

        // สร้างไอคอนสำหรับกล้อง
        var cameraIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/128/45/45010.png',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });

        // Custom icon for current location
        var currentLocationIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/1828/1828884.png',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -16]
        });

        // เพิ่ม Camera 1
        var camera1Marker = L.marker([14.22282, 101.40557], { icon: cameraIcon }).addTo(mymap);
        camera1Marker.bindPopup("<b>Camera 1</b><br>ตำแหน่ง: 14.22282, 101.40557").openPopup();

        // สร้างระบบค้นหาสถานที่
        var geocoder = L.Control.Geocoder.nominatim();
        var searchControl = new L.Control.Geocoder({
            geocoder: geocoder,
            position: 'topleft',
            placeholder: 'ค้นหาสถานที่...',
            defaultMarkGeocode: false
        }).on('markgeocode', function(e) {
            var bbox = e.geocode.bbox;
            var poly = L.polygon([
                bbox.getSouthEast(),
                bbox.getNorthEast(),
                bbox.getNorthWest(),
                bbox.getSouthWest()
            ]);
            mymap.fitBounds(poly.getBounds());
        }).addTo(mymap);

        function showLoading() {
            document.querySelector('.loading').style.display = 'block';
        }

        function hideLoading() {
            document.querySelector('.loading').style.display = 'none';
        }

        function getLocation() {
            showLoading();
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    showPosition,
                    showError,
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            } else {
                hideLoading();
                alert("เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง");
            }
        }

        function showPosition(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            if (currentLocationMarker) {
                mymap.removeLayer(currentLocationMarker);
            }

            currentLocationMarker = L.marker([lat, lng], { icon: currentLocationIcon }).addTo(mymap);
            currentLocationMarker.bindPopup("<b>ตำแหน่งปัจจุบันของคุณ</b>").openPopup();

            mymap.setView([lat, lng], 15);
            hideLoading();
        }

        function showError(error) {
            hideLoading();
            let message = "";
            switch(error.code) {
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
            alert(message);
        }

        function createRoute(end) {
            if (currentLocationMarker) {
                var start = currentLocationMarker.getLatLng();
                
                if (currentRoute) {
                    mymap.removeControl(currentRoute);
                }

                currentRoute = L.Routing.control({
                    waypoints: [
                        L.latLng(start.lat, start.lng),
                        L.latLng(end[0], end[1])
                    ],
                    routeWhileDragging: true,
                    lineOptions: {
                        styles: [{ color: '#4CAF50', opacity: 0.8, weight: 6 }]
                    },
                    createMarker: function() { return null; }
                }).addTo(mymap);
            } else {
                alert("กรุณาระบุตำแหน่งปัจจุบันก่อน");
            }
        }

        mymap.on('click', function(e) {
            var lat = e.latlng.lat;
            var lng = e.latlng.lng;

            var locationDetails = "พิกัด: " + lat.toFixed(5) + ", " + lng.toFixed(5) + "<br>";
            locationDetails += "ข้อมูลที่เกี่ยวข้อง: สถานที่นี้อาจจะเป็นป่าหรือสถานที่ท่องเที่ยว";

            if (lastMarker) {
                mymap.removeLayer(lastMarker);
            }

            lastMarker = L.marker([lat, lng]).addTo(mymap)
                .bindPopup(`
                    <b>รายละเอียดสถานที่</b><br>
                    ${locationDetails}<br>
                    <button onclick='createRoute([${lat}, ${lng}])' class='location-button'>
                        นำทางไปที่นี่
                    </button>
                `).openPopup();
        });
    </script>
</body>
</html>