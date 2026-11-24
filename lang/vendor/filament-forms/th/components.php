<?php

// Overrides only the keys Filament's own Thai translation lacks; every other key still comes from the package.
return [
    'select' => [
        'actions' => [
            'clear' => ['label' => 'ล้างตัวเลือก'],
            'remove_option' => ['label' => 'ลบ :label'],
        ],
        'error_message' => 'โหลดตัวเลือกไม่สำเร็จ กรุณาลองอีกครั้ง',
        'required_description' => 'เลือกหนึ่งตัวเลือก',
        'search_label' => 'ค้นหา',
    ],
    'checkbox_list' => [
        'required_description' => 'เลือกอย่างน้อยหนึ่งตัวเลือก',
    ],
    'repeater' => [
        'reordering' => [
            'item' => 'รายการ',
            'position' => ':label ตำแหน่ง :position จาก :count',
            'moved' => 'ย้าย :label ไปตำแหน่ง :position จาก :count',
        ],
        'columns' => [
            'actions' => ['label' => 'การทำงาน'],
            'reorder' => ['label' => 'จัดลำดับ'],
        ],
    ],
    'key_value' => [
        'actions' => [
            'move_down' => ['label' => 'เลื่อนลง'],
            'move_up' => ['label' => 'เลื่อนขึ้น'],
        ],
        'columns' => [
            'actions' => ['label' => 'การทำงาน'],
            'reorder' => ['label' => 'จัดลำดับ'],
        ],
        'reordering' => [
            'row' => 'แถว',
            'position' => ':label ตำแหน่ง :position จาก :count',
            'moved' => 'ย้าย :label ไปตำแหน่ง :position จาก :count',
        ],
    ],
    'file_upload' => [
        'placeholder' => 'ยังไม่มีไฟล์ที่อัปโหลด',
        'actions' => [
            'download' => ['label' => 'ดาวน์โหลด'],
            'open' => ['label' => 'เปิดในแท็บใหม่'],
        ],
        'editor' => ['label' => 'แก้ไขรูปภาพ'],
    ],
];
