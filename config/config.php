<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'it_repair_db',
    'user' => 'root',   // CHANGE TO LIMITED-PRIVILEGE USER IN PRODUCTION
    'pass' => '',       // SET SECURE PASSWORD IN PRODUCTION
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'name' => 'NexusFix — IT Repair & Maintenance',
    'base_url' => '/it_repair/public',
  ],
];
