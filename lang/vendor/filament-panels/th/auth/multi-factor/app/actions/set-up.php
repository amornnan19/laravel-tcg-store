<?php

// Overrides only the keys Filament's own Thai translation lacks or names differently; every other key still comes from the package.
return [
    'modal' => [
        'content' => [
            'qr_code' => [
                'instruction' => 'สแกนคิวอาร์โค้ดนี้ด้วยแอปยืนยันตัวตน',
                'alt' => 'คิวอาร์โค้ดสำหรับสแกนด้วยแอปยืนยันตัวตน',
            ],
            'text_code' => [
                'instruction' => 'หรือกรอกรหัสนี้ด้วยตนเอง',
                'messages' => [
                    'copied' => 'คัดลอกแล้ว',
                ],
            ],
            'recovery_codes' => [
                'instruction' => 'โปรดเก็บรหัสกู้คืนต่อไปนี้ไว้ในที่ปลอดภัย รหัสจะแสดงเพียงครั้งเดียว และจำเป็นต้องใช้หากคุณเข้าแอปยืนยันตัวตนไม่ได้',
            ],
        ],
        'form' => [
            'code' => [
                'label' => 'กรอกรหัส 6 หลักจากแอปยืนยันตัวตน',
                'validation_attribute' => 'รหัส',
                'below_content' => 'คุณจะต้องกรอกรหัส 6 หลักจากแอปยืนยันตัวตนทุกครั้งที่เข้าสู่ระบบ',
                'messages' => [
                    'invalid' => 'รหัสที่กรอกไม่ถูกต้อง',
                    'rate_limited' => 'พยายามหลายครั้งเกินไป โปรดลองใหม่ภายหลัง',
                ],
            ],
        ],
        'actions' => [
            'submit' => [
                'label' => 'เปิดใช้งานแอปยืนยันตัวตน',
            ],
        ],
    ],
    'notifications' => [
        'enabled' => [
            'title' => 'เปิดใช้งานแอปยืนยันตัวตนแล้ว',
        ],
    ],
];
