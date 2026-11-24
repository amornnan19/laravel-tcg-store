<?php

// Overrides only the keys Filament's own Thai translation lacks; every other key still comes from the package.
return [
    'modal' => [
        'form' => [
            'file' => [
                'rules' => [
                    'duplicate_columns' => '{0} ไฟล์ต้องไม่มีหัวคอลัมน์ว่างมากกว่าหนึ่งคอลัมน์|{1,*} ไฟล์ต้องไม่มีหัวคอลัมน์ซ้ำกัน: :columns',
                ],
            ],
            'columns' => [
                'placeholder' => 'เลือกคอลัมน์',
            ],
        ],
        'actions' => [
            'download_example' => [
                'label' => 'ดาวน์โหลดไฟล์ CSV ตัวอย่าง',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'actions' => [
                'download_failed_rows_csv' => [
                    'label' => 'ดาวน์โหลดข้อมูลแถวที่นำเข้าไม่สำเร็จ|ดาวน์โหลดข้อมูลแถวที่นำเข้าไม่สำเร็จ',
                ],
            ],
        ],
    ],
    'example_csv' => [
        'file_name' => ':importer-example',
    ],
    'failure_csv' => [
        'error_header' => 'ข้อผิดพลาด',
        'system_error' => 'ระบบขัดข้อง กรุณาติดต่อผู้ดูแล',
        'column_mapping_required_for_new_record' => 'คอลัมน์ :attribute ยังไม่ได้จับคู่กับคอลัมน์ในไฟล์ แต่จำเป็นสำหรับการสร้างรายการใหม่',
    ],
];
