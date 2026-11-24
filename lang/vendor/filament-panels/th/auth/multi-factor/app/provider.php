<?php

// Overrides only the keys Filament's own Thai translation lacks or names differently; every other key still comes from the package.
return [
    'management_schema' => [
        'actions' => [
            'label' => 'แอปยืนยันตัวตน',
            'below_content' => 'ใช้แอปที่ปลอดภัยเพื่อสร้างรหัสชั่วคราวสำหรับยืนยันตัวตนตอนเข้าสู่ระบบ',
            'messages' => [
                'enabled' => 'เปิดใช้งานแล้ว',
                'disabled' => 'ปิดใช้งานอยู่',
            ],
        ],
    ],
    'login_form' => [
        'label' => 'ใช้รหัสจากแอปยืนยันตัวตน',
        'code' => [
            'label' => 'กรอกรหัส 6 หลักจากแอปยืนยันตัวตน',
            'validation_attribute' => 'รหัส',
            'actions' => [
                'use_recovery_code' => [
                    'label' => 'ใช้รหัสกู้คืนแทน',
                ],
            ],
            'messages' => [
                'invalid' => 'รหัสที่กรอกไม่ถูกต้อง',
            ],
        ],
        'recovery_code' => [
            'label' => 'หรือกรอกรหัสกู้คืน',
            'validation_attribute' => 'รหัสกู้คืน',
            'messages' => [
                'invalid' => 'รหัสกู้คืนที่กรอกไม่ถูกต้อง',
            ],
        ],
    ],
];
