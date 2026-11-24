<?php

// Overrides only the keys Filament's own Thai translation lacks or names differently; every other key still comes from the package.
return [
    'modal' => [
        'form' => [
            'code' => [
                'label' => 'กรอกรหัส 6 หลักจากแอปยืนยันตัวตน',
                'validation_attribute' => 'รหัส',
                'messages' => [
                    'invalid' => 'รหัสที่กรอกไม่ถูกต้อง',
                    'rate_limited' => 'พยายามหลายครั้งเกินไป โปรดลองใหม่ภายหลัง',
                ],
            ],
        ],
        'actions' => [
            'submit' => [
                'label' => 'สร้างรหัสกู้คืนใหม่',
            ],
        ],
    ],
    'show_new_recovery_codes' => [
        'modal' => [
            'heading' => 'รหัสกู้คืนใหม่',
            'description' => 'โปรดเก็บรหัสกู้คืนต่อไปนี้ไว้ในที่ปลอดภัย รหัสจะแสดงเพียงครั้งเดียว และจำเป็นต้องใช้หากคุณเข้าแอปยืนยันตัวตนไม่ได้:',
            'actions' => [
                'submit' => [
                    'label' => 'ปิด',
                ],
            ],
        ],
    ],
];
