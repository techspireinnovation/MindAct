<?php



return [

    1 => [
        'key' => 'bike',
        'label' => 'Bike',
        'fields' => [
            ['name' => 'engine_capacity', 'label' => 'Engine Capacity', 'type' => 'number'],
            ['name' => 'engine_no', 'label' => 'Engine Number', 'type' => 'text'],
            ['name' => 'serial_no', 'label' => 'Engine Number', 'type' => 'text'],
            ['name' => 'vin_number', 'label' => 'VIN Number', 'type' => 'text'],
            [
                'name' => 'Color',
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
            ['name' => 'ram', 'label' => 'RAM', 'type' => 'text'],
            ['name' => 'rom', 'label' => 'ROM', 'type' => 'text'],
            ['name' => 'processor', 'label' => 'Processor', 'type' => 'text'],
        ],
    ],

    3 => [
        'key' => 'mobile',
        'label' => 'Mobile',
        'fields' => [
            ['name' => 'storage', 'label' => 'Storage', 'type' => 'text'],
            ['name' => 'ram', 'label' => 'RAM', 'type' => 'text'],
            ['name' => 'battery', 'label' => 'Battery Capacity', 'type' => 'number'],
            ['name' => 'color', 'label' => 'Color', 'type' => 'text'],
        ],
    ],

];

?>