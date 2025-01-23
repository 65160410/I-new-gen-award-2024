<?php
/**
 * ชื่อไฟล์: show_elephant_map.php
 * หมายเหตุ:
 *  - เปิดไฟล์นี้ผ่านเบราว์เซิร์ฟเวอร์ เช่น https://your-domain/show_elephant_map.php
 *  - ไฟล์ get_detections.php อยู่ฝั่ง API ซึ่งจะส่ง JSON ที่มี elephant_lat, elephant_long กลับมา
 */
?>
<!DOCTYPE html>
<html lang="th">
  <head>
    <meta charset="UTF-8" />
    <title>Real-time Elephant Markers</title>
    <style>
      html,
      body {
        margin: 0;
        padding: 0;
        height: 100%;
      }
      #map {
        width: 100%;
        height: 100%;
      }
    </style>
  </head>
  <body>
    <!-- ส่วน Map จะแสดงใน div นี้ -->
    <div id="map"></div>

    <script>
      let map;
      const elephantMarkers = []; // เก็บ Marker ช้างที่สร้างไปแล้ว
      let lastDetectionID = 0; // ID ล่าสุดของ detection (กันสร้าง Marker ซ้ำ)

      // ฟังก์ชันเริ่มต้น เมื่อโหลด Google Maps เสร็จ
      function initMap() {
        // 1) สร้างแผนที่ กำหนดจุดเริ่มต้น
        map = new google.maps.Map(document.getElementById("map"), {
          center: { lat: 14.22512, lng: 101.40544 },
          zoom: 14,
        });

        // 2) สร้าง Marker กล้อง (ตำแหน่ง fix)
        new google.maps.Marker({
          position: { lat: 14.22512, lng: 101.40544 },
          map: map,
          icon: {
            url: "https://aprlabtop.com/Honey_test/icons/IconLocation.png", // ไฟล์ไอคอนกล้อง (ตรวจสอบว่ามีไฟล์นี้อยู่จริง)
            scaledSize: new google.maps.Size(40, 40),
          },
          title: "Camera Location",
        });

        // 3) เรียก fetchData() ครั้งแรก
        fetchData();

        // 4) ตั้ง Interval ดึงข้อมูลใหม่ทุก 5 วินาที
        setInterval(fetchData, 5000);
      }

      // ฟังก์ชันดึงข้อมูลจาก API
      function fetchData() {
        const apiUrl = `https://aprlabtop.com/elephant_api/get_detections.php?last_id=${lastDetectionID}`;
        console.log("Fetching data from:", apiUrl);

        fetch(apiUrl)
          .then((response) => {
            if (!response.ok) {
              // ถ้าขึ้น 404 หรือ 500 ให้ throw ออกไปที่ .catch
              throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
          })
          .then((json) => {
            console.log("Fetched data =", json);

            // สมมติ json เป็นโครงสร้าง:
            // {
            //    "status": "success",
            //    "data": [ {...}, {...} ]
            // }
            // อาจต้องเช็คว่าเป็น array ตรง json.data หรือไม่
            // ทั้งนี้ขึ้นกับ get_detections.php ส่งรูปแบบใด

            // ตัวอย่างนี้ assume ว่า: data = array ของ detection
            let dataArray = [];
            if (json && json.status === "success" && Array.isArray(json.data)) {
              dataArray = json.data;
            } else if (Array.isArray(json)) {
              // บางกรณี get_detections.php อาจส่ง array ตรง ๆ ไม่ห่อด้วย {status:"success"}
              dataArray = json;
            } else {
              console.warn("API response not in expected format", json);
              return;
            }

            dataArray.forEach((item) => {
              // เช่น item = {
              //   "id": "5",
              //   "elephant_lat": "[14.2271,14.2273]",
              //   "elephant_long": "[101.409,101.410]",
              //   ...
              // }
              const detectionId = parseInt(item.id, 10) || 0;

              // เช็คเฉพาะข้อมูลใหม่กว่า lastDetectionID
              if (detectionId > lastDetectionID) {
                try {
                  let latArray = item.elephant_lat;
                  let lngArray = item.elephant_long;

                  // ถ้าเป็น string เช่น "[14.22,14.23]" ให้ parse
                  if (typeof latArray === "string") {
                    latArray = JSON.parse(latArray);
                  }
                  if (typeof lngArray === "string") {
                    lngArray = JSON.parse(lngArray);
                  }

                  if (Array.isArray(latArray) && Array.isArray(lngArray)) {
                    for (let i = 0; i < latArray.length; i++) {
                      const lat = parseFloat(latArray[i]);
                      const lng = parseFloat(lngArray[i]);

                      console.log(
                        `Creating Elephant Marker at lat=${lat}, lng=${lng}`
                      );
                      // Marker ช้าง
                      const elephantMarker = new google.maps.Marker({
                        position: { lat, lng },
                        map: map,
                        icon: {
                          // ใช้ URL รูปช้าง (absolute หรือ relative ก็ได้ถ้าชัวร์ว่าถูก path)
                          url: "https://aprlabtop.com/Honey_test/icons/elephant-icon.png",
                          scaledSize: new google.maps.Size(40, 40),
                        },
                        title: `Elephant from detection #${detectionId}`,
                      });
                      elephantMarkers.push(elephantMarker);
                    }
                  }
                } catch (err) {
                  console.error(
                    "Failed to parse lat/long from item:",
                    item,
                    err
                  );
                }
                // อัปเดต lastDetectionID
                lastDetectionID = detectionId;
              }
            });
          })
          .catch((err) => {
            console.error("Error fetching data:", err);
          });
      }
    </script>

    <!-- เรียก Google Maps JavaScript API ใส่ key ของจริง -->
    <script
      src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAWO3NgFt1_1fWEN70KwMmgTuFxVmQ76aw&libraries=places&callback=initMap"
      async
      defer
    ></script>
  </body>
</html>
