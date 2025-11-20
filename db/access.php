<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/completiondashboard:view' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager'         => CAP_ALLOW,
            'editingteacher'  => CAP_ALLOW,
            'teacher'         => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:viewparticipants',
    ],
];