<?php
require_once('../../config.php');
require_login();

$action = required_param('action', PARAM_ALPHA);
$courseid = required_param('courseid', PARAM_INT);
$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);

$response = array();

if ($action === 'save') {
    $data = json_decode(file_get_contents('php://input'), true);
    $selected = isset($data['selected']) ? $data['selected'] : array();
    $selected = array_filter($selected, 'is_numeric');
    $selected = array_unique($selected);
    $pref_key = 'local_progress_selected_' . $courseid;
    set_user_preference($pref_key, implode(',', $selected));
    $response['success'] = true;
    echo json_encode($response);
    exit;
}
?>