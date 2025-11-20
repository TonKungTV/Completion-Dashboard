<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_completiondashboard';
$plugin->version   = 2025111900;      // YYYYMMDDHH - เวอร์ชันของปลั๊กอิน
$plugin->requires  = 2020061500;      // ตั้งขั้นต่ำเป็น Moodle 3.8 (ปรับตามรุ่นของคุณได้)
//$plugin->maturity  = MATURITY_ALPHA;
//$plugin->release   = '0.1';

$plugin->maturity  = MATURITY_STABLE; // ALPHA → BETA → RC → STABLE
$plugin->release   = '1.0.0';         // เวอร์ชันที่แสดงให้ผู้ใช้เห็น