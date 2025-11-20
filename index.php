<?php
require(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$status   = optional_param('status', 'all', PARAM_ALPHA); // all|completed|inprogress
$download = optional_param('download', 0, PARAM_BOOL);    // 1 => CSV

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/completiondashboard:view', $context);

$PAGE->set_url(new moodle_url('/local/completiondashboard/index.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'local_completiondashboard'));
$PAGE->set_heading(format_string($course->fullname));

global $DB, $OUTPUT, $CFG;

// Get all learners first - simple query
$basesql = "
    SELECT u.id, u.idnumber, u.firstname, u.lastname, u.email, 
           MAX(COALESCE(cc.timecompleted, 0)) AS timecompleted
    FROM {enrol} e
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
    JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
    LEFT JOIN {course_completions} cc ON cc.course = e.courseid AND cc.userid = u.id
    WHERE e.courseid = :courseid AND e.status = 0
    GROUP BY u.id, u.idnumber, u.firstname, u.lastname, u.email
    ORDER BY u.lastname, u.firstname
";
$learners = $DB->get_records_sql($basesql, ['courseid' => $courseid]);

// Get certificate data for these users
$cert_users_customcert = [];
$cert_users_legacy = [];

try {
    $certdata = $DB->get_records_sql("
        SELECT ci.userid, MAX(ci.timecreated) AS timecreated
        FROM {customcert_issues} ci
        JOIN {customcert} ccert ON ccert.id = ci.customcertid
        JOIN {course_modules} cm ON cm.instance = ccert.id
        JOIN {modules} m ON m.id = cm.module AND m.name = 'customcert'
        WHERE cm.course = :cid
        GROUP BY ci.userid
    ", ['cid' => $courseid]);
    foreach ($certdata as $cd) {
        $cert_users_customcert[$cd->userid] = $cd->timecreated;
    }
} catch (Exception $e) {}

try {
    $certdata2 = $DB->get_records_sql("
        SELECT ci.userid, MAX(ci.timecreated) AS timecreated
        FROM {certificate_issues} ci
        JOIN {certificate} cert ON cert.id = ci.certificateid
        JOIN {course_modules} cm ON cm.instance = cert.id
        JOIN {modules} m ON m.id = cm.module AND m.name = 'certificate'
        WHERE cm.course = :cid
        GROUP BY ci.userid
    ", ['cid' => $courseid]);
    foreach ($certdata2 as $cd) {
        $cert_users_legacy[$cd->userid] = $cd->timecreated;
    }
} catch (Exception $e) {}

// Add certificate info to learners
foreach ($learners as $u) {
    $u->cert_customcert_time = isset($cert_users_customcert[$u->id]) ? $cert_users_customcert[$u->id] : 0;
    $u->cert_legacy_time = isset($cert_users_legacy[$u->id]) ? $cert_users_legacy[$u->id] : 0;
}

// Apply status filter
if ($status === 'completed') {
    $learners = array_filter($learners, function($u) {
        return ($u->timecompleted > 0) || ($u->cert_customcert_time > 0) || ($u->cert_legacy_time > 0);
    });
} else if ($status === 'inprogress') {
    $learners = array_filter($learners, function($u) {
        return ($u->timecompleted == 0) && ($u->cert_customcert_time == 0) && ($u->cert_legacy_time == 0);
    });
}

// Summary counts - นับจำนวนนักเรียนที่จบและยังไม่จบ (รวมคนที่ได้ใบเซอร์)
// Get all enrolled users
$sumsql = "
    SELECT u.id, MAX(COALESCE(cc.timecompleted, 0)) AS timecompleted
    FROM {enrol} e
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
    JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
    LEFT JOIN {course_completions} cc ON cc.course = e.courseid AND cc.userid = u.id
    WHERE e.courseid = :courseid AND e.status = 0
    GROUP BY u.id
";
$allrecords = $DB->get_records_sql($sumsql, ['courseid' => $courseid]);

// คำนวณจำนวน (รวมคนที่มีใบเซอร์)
$completed = 0;
$notcompleted = 0;
$total = count($allrecords);

foreach ($allrecords as $rec) {
    $has_customcert = isset($cert_users_customcert[$rec->id]) && $cert_users_customcert[$rec->id] > 0;
    $has_legacy_cert = isset($cert_users_legacy[$rec->id]) && $cert_users_legacy[$rec->id] > 0;
    $is_completed = ($rec->timecompleted > 0) || $has_customcert || $has_legacy_cert;
    
    if ($is_completed) {
        $completed++;
    } else {
        $notcompleted++;
    }
}

// Certificate issued.
$certissued = 0;
try {
    $certissued += (int)$DB->count_records_sql("
        SELECT COUNT(1)
        FROM {customcert_issues} ci
        JOIN {customcert} ccert ON ccert.id = ci.customcertid
        JOIN {course_modules} cm ON cm.instance = ccert.id
        JOIN {modules} m ON m.id = cm.module AND m.name = 'customcert'
        WHERE cm.course = :cid
    ", ['cid' => $courseid]);
} catch (Throwable $e) {}
try {
    $certissued += (int)$DB->count_records_sql("
        SELECT COUNT(1)
        FROM {certificate_issues} ci
        JOIN {certificate} cert ON cert.id = ci.certificateid
        JOIN {course_modules} cm ON cm.instance = cert.id
        JOIN {modules} m ON m.id = cm.module AND m.name = 'certificate'
        WHERE cm.course = :cid
    ", ['cid' => $courseid]);
} catch (Throwable $e) {}

if ($download) {
    // CSV download with UTF-8 BOM for Thai/Excel compatibility.
    $filename = "completion_course{$courseid}_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output UTF-8 BOM for Excel to recognize Thai characters
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Student ID', 'Full name', 'Email', 'Completed', 'Time completed']);
    
    // Data rows
    foreach ($learners as $u) {
        // ถือว่าจบถ้ามี timecompleted หรือได้ใบเซอร์
        $is_completed = ($u->timecompleted > 0) || ($u->cert_customcert_time > 0) || ($u->cert_legacy_time > 0);
        $completion_time = max($u->timecompleted, $u->cert_customcert_time, $u->cert_legacy_time);
        
        fputcsv($output, [
            '="' . $u->idnumber . '"', // Excel formula to force text format
            fullname($u),
            $u->email,
            $is_completed ? 'Yes' : 'No',
            ($completion_time > 0) ? userdate($completion_time) : ''
        ]);
    }
    
    fclose($output);
    exit;
}

// Render page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading', 'local_completiondashboard'));

// Filter links.
$baseurl = new moodle_url('/local/completiondashboard/index.php', ['courseid'=>$courseid]);
$linkhtml = [];
$linkhtml[] = $OUTPUT->action_link(new moodle_url($baseurl, ['status'=>'all']), get_string('status_all', 'local_completiondashboard'));
$linkhtml[] = $OUTPUT->action_link(new moodle_url($baseurl, ['status'=>'completed']), get_string('status_completed', 'local_completiondashboard'));
$linkhtml[] = $OUTPUT->action_link(new moodle_url($baseurl, ['status'=>'inprogress']), get_string('status_inprogress', 'local_completiondashboard'));
$linkhtml[] = $OUTPUT->action_link(new moodle_url($baseurl, ['status'=>$status, 'download'=>1]), get_string('downloadcsv', 'local_completiondashboard'));

echo html_writer::start_tag('ul', ['class'=>'list-inline']);
foreach ($linkhtml as $item) {
    echo html_writer::tag('li', $item, ['class'=>'list-inline-item']);
}
echo html_writer::end_tag('ul');

// Summary.
$percent = $total > 0 ? round($completed * 100.0 / $total, 2) : 0.0;
$summarytext = get_string('summary', 'local_completiondashboard') . ": "
    . "Completed {$completed} / {$total} ({$percent}%), "
    . "In progress {$notcompleted}, "
    . get_string('cert_issued', 'local_completiondashboard') . ": {$certissued}";
echo html_writer::tag('p', s($summarytext));

// Table.
if (empty($learners)) {
    echo $OUTPUT->notification(get_string('noenrol', 'local_completiondashboard'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('col_studentid', 'local_completiondashboard'),
        get_string('col_fullname', 'local_completiondashboard'),
        get_string('col_email', 'local_completiondashboard'),
        get_string('col_completed', 'local_completiondashboard'),
        get_string('col_timecompleted', 'local_completiondashboard'),
    ];
    foreach ($learners as $u) {
        // ถือว่าจบถ้ามี timecompleted หรือได้ใบเซอร์
        $is_completed = ($u->timecompleted > 0) || ($u->cert_customcert_time > 0) || ($u->cert_legacy_time > 0);
        $completion_time = max($u->timecompleted, $u->cert_customcert_time, $u->cert_legacy_time);
        
        $table->data[] = [
            s($u->idnumber),
            s(fullname($u)),
            s($u->email),
            $is_completed ? 'Yes' : 'No',
            ($completion_time > 0) ? userdate($completion_time) : '',
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();