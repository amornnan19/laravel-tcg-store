<?php

/*
| Applies to every form and every Livewire temporary upload in the app, the admin included: keep these lines generic.
| Wording for one field belongs in that component's own messages(). Every other key falls back to English.
*/

return [
    'uploaded' => 'อัปโหลดไม่สำเร็จ ไฟล์อาจใหญ่เกินไปหรือการเชื่อมต่อขัดข้อง',
    'required' => 'กรอก:attribute',
    'file' => 'ไฟล์ไม่ถูกต้อง',
    'mimes' => 'ชนิดไฟล์ต้องเป็น :values',
    'max' => [
        'file' => 'ไฟล์ใหญ่เกินไป (ไม่เกิน :max KB)',
    ],
];
