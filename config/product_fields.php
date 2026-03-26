<?php



return [

    1 => [
        'key' => 'bike',
        'label' => 'Bike',
        'fields' => [
            ['key' => 'engine_capacity', 'label' => 'Engine Capacity', 'type' => 'number'],
            ['key' => 'engine_no', 'label' => 'Engine Number', 'type' => 'text'],
            ['key' => 'serial_no', 'label' => 'Engine Number', 'type' => 'text'],
            ['key' => 'vin_number', 'label' => 'VIN Number', 'type' => 'text'],
            [
                'key' => 'color',
                'label' => 'color',
                'type' => 'dropdown',
                'options' => ['Red', 'Blue', 'Green', 'Black']
            ],
        ],
    ],

    2 => [
        'key' => 'computer',
        'label' => 'Computer',
        'fields' => [
            ['key' => 'ram', 'label' => 'RAM', 'type' => 'text'],
            ['key' => 'rom', 'label' => 'ROM', 'type' => 'text'],
            ['key' => 'processor', 'label' => 'Processor', 'type' => 'text'],
            
        ],
    ],

    3 => [
        'key' => 'mobile',
        'label' => 'Mobile',
        'fields' => [
            ['key' => 'storage', 'label' => 'Storage', 'type' => 'text'],
            ['key' => 'ram', 'label' => 'RAM', 'type' => 'text'],
            ['key' => 'battery', 'label' => 'Battery Capacity', 'type' => 'number'],
            ['key' => 'color', 'label' => 'Color', 'type' => 'text'],
        ],
    ],

];

?>