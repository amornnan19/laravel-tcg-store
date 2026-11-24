<?php

// Overrides only the keys Filament's own Thai translation lacks or names differently; every other key still comes from the package.
return [
    'modal' => [
        'form' => [
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
                    'rate_limited' => 'พยายามหลายครั้งเกินไป โปรดลองใหม่ภายหลัง',
                ],
            ],
            'recovery_code' => [
                'label' => 'หรือกรอกรหัสกู้คืน',
                'validation_attribute' => 'รหัสกู้คืน',
                'messages' => [
                    'invalid' => 'รหัสกู้คืนที่กรอกไม่ถูกต้อง',
                    'rate_limited' => 'พยายามหลายครั้งเกินไป โปรดลองใหม่ภายหลัง',
                ],
            ],
        ],
        'actions' => [
            'submit' => [
                'label' => 'ปิดแอปยืนยันตัวตน',
            ],
        ],
    ],
];
