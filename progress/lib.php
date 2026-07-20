<?php
// /local/progress/lib.php
defined('MOODLE_INTERNAL') || die();

function local_progress_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('moodle/course:view', $context)) {
        $url = new moodle_url('/local/progress/index.php', ['courseid' => $course->id]);
        $node = navigation_node::create(
            '学习进度折线图',         // 显示名称，可自定义
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/competencies', '')
        );
        $navigation->add_node($node);
    }
}