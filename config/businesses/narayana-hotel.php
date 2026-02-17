<?php
return [
   'business_id' => 'narayana-hotel',
   'name' => 'Narayana Hotel Karimunjawa',
   'business_type' => 'hotel',
   'database' => 'adf_narayana_hotel',
   
   'enabled_modules' => [
       'cashbook',
       'auth',
       'settings',
       'reports',
       'frontdesk',
       'divisions',
       'procurement',
       'sales',
       'investor',
       'project'
   ],
   
   'theme' => [
       'color_primary' => '#4338ca',
       'color_secondary' => '#1e1b4b',
       'icon' => '🏨'
   ],
   
   'cashbook_columns' => [
       'room_number' => ['label' => 'Room #', 'type' => 'text'],
       'guest_name' => ['label' => 'Guest Name', 'type' => 'text']
   ]
];
