# ตัวช่วยฉุกเฉิน  

---

## import_address.php

โปรเจคนี้อ้างอิงข้อมูลแผนที่จาก jquery.Thailand.js หากมีการอัพเดตแผนที่โดยเจ้าของโปรเจคดังกล่าว ผู้ดูแลควรดำเนินการดังนี้

1. ทำการสำรองฐานข้อมูลหรืออย่างน้อยตาราง address_lookup
2. ใช้คำสั่ง `TRUNCATE TABLE address_lookup;` ในช่อง SQL
3. คัดลอกไฟล์ import_address.php วางไว้ที่ Root
4. คัดลอกไฟล์ raw_database.json จากโปรเจค jquery.Thailand.js วางไว้ที่ Root
5. เข้าสู่: http://localhost/ชื่อโปรเจค/import_address.php เพื่อนำเข้าฐานข้อมูล แล้วลบทั้ง 2 ไฟล์ทิ้งทันที
6. คัดลอก db.json จากโปรเจค jquery.Thailand.js วางทับไฟล์เก่าที่: ชื่อโปรเจค/assets/jquery.Thailand.js/database/db.json

---
