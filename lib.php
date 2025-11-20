<?php
defined('MOODLE_INTERNAL') || die();

function local_completiondashboard_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (!has_capability('local/completiondashboard:view', $context)) {
        return;
    }
    $url = new moodle_url('/local/completiondashboard/index.php', ['courseid' => $course->id]);
    $node = navigation_node::create(
        get_string('pluginname', 'local_completiondashboard'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_completiondashboard',
        new pix_icon('i/report', '')
    );
    $navigation->add_node($node);
}