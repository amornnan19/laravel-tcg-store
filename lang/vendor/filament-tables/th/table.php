<?php

// Overrides only the keys Filament's own Thai translation lacks; every other key still comes from the package.
return [
    'result_count' => '{0} ไม่พบรายการ|{1} :count รายการ|[2,*] :count รายการ',
    'loading' => 'กำลังโหลด...',
    'reordering' => [
        'position' => ':label ตำแหน่ง :position จาก :count',
        'moved' => 'ย้าย :label ไปตำแหน่ง :position จาก :count',
    ],
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'ใช่',
                'false' => 'ไม่ใช่',
            ],
        ],
        'select' => [
            'loading_message' => 'กำลังโหลด...',
            'no_options_message' => 'ไม่มีตัวเลือก',
            'no_search_results_message' => 'ไม่พบตัวเลือกที่ตรงกัน',
            'placeholder' => 'เลือกตัวเลือก',
            'searching_message' => 'กำลังค้นหา...',
            'search_prompt' => 'พิมพ์เพื่อค้นหา',
        ],
        'editable' => [
            'saving' => 'กำลังบันทึก...',
            'saved' => 'บันทึกแล้ว',
        ],
    ],
    'filters' => [
        'select' => [
            'relationship' => [
                'empty_option_label' => 'ไม่มี',
            ],
        ],
    ],
    'column_manager' => [
        'position' => 'ตำแหน่ง :position จาก :count',
    ],
];
