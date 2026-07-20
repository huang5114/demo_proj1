<?php
// /local/leaderboard/lib.php
defined('MOODLE_INTERNAL') || die();

/**
 * 在课程导航菜单中添加“考试优胜榜”入口
 */
function local_leaderboard_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('moodle/course:view', $context)) {
        $url = new moodle_url('/local/leaderboard/index.php', ['courseid' => $course->id]);
        $node = navigation_node::create(
            '考试优胜榜',
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/competencies', '')
        );
        $navigation->add_node($node);
    }
}