<?php
// This file is part of Level Up XP.
//
// Level Up XP is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Level Up XP is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Level Up XP.  If not, see <https://www.gnu.org/licenses/>.
//
// https://levelup.plus

/**
 * Ladder controller.
 *
 * @package    block_xp
 * @copyright  2017 Frédéric Massart
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_xp\local\controller;

use block_xp\di;
use block_xp\local\division\division;
use block_xp\local\division\group_division;
use block_xp\local\shortcode\handler;
use block_xp\local\utils\text_utils;
use html_writer;

class ladder_controller extends page_controller {

    const PAGE_SIZE_FLAG = 'ladder-pagesize';

    /** @var bool */
    protected $requiremanage = false;
    /** @var bool */
    protected $supportsgroups = true;
    /** @var string */
    protected $routename = 'ladder';

    protected function page_setup() {
        global $PAGE;
        parent::page_setup();
        $PAGE->add_body_class('block_xp-ladder');
        
        // 通过 URL 判断是否是进步榜
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, '/examreward') !== false) {
            $this->routename = 'examreward';
        }
    }

    protected function define_optional_params() {
        $params = parent::define_optional_params();
        $params[] = ['pagesize', 0, PARAM_INT, false];
        $params[] = ['examid', 0, PARAM_INT, false];
        return $params;
    }

    protected function is_visible_to_viewers() {
        return (bool) $this->world->get_config()->get('enableladder');
    }

    protected function get_division(): ?division {
        $groupid = $this->get_groupid();
        if ($groupid || $groupid === 0) {
            return new group_division($this->get_groupid());
        }
        return null;
    }

    protected function get_leaderboard() {
        $division = $this->get_division();
        $lbf = \block_xp\di::get('leaderboard_factory_maker')->get_leaderboard_factory($this->world);
        if ($division) {
            return $lbf->get_leaderboard_for_division($division);
        }
        return $lbf->get_leaderboard();
    }

    protected function get_table() {
        global $USER;
        $table = new \block_xp\output\leaderboard_table(
            $this->get_leaderboard(),
            $this->get_renderer(),
            [
                'context' => $this->world->get_context(),
                'config' => $this->world->get_config(),
            ],
            $USER->id
        );
        $table->show_pagesize_selector(true);
        $table->define_baseurl($this->pageurl);
        return $table;
    }

    protected function get_page_html_head_title() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, '/examreward') !== false) {
            return '进步榜';
        }
        return get_string('ladder', 'block_xp');
    }

    protected function get_page_heading() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, '/examreward') !== false) {
            return '进步榜';
        }
        return get_string('ladder', 'block_xp');
    }

    protected function get_page_size() {
        global $USER;

        $indicator = \block_xp\di::get('user_generic_indicator');
        $pagesizepref = $indicator->get_user_flag($USER->id, self::PAGE_SIZE_FLAG);
        $defaultpagesize = 20;

        $pagesize = $this->get_param('pagesize');

        if (empty($pagesize)) {
            $pagesize = $pagesizepref;
        }

        if (!in_array($pagesize, [20, 50, 100])) {
            $pagesize = $defaultpagesize;
        }

        if ($pagesize == $defaultpagesize) {
            if (!empty($pagesizepref)) {
                $indicator->unset_user_flag($USER->id, self::PAGE_SIZE_FLAG);
            }
        } else if ($pagesize != $pagesizepref) {
            $indicator->set_user_flag($USER->id, self::PAGE_SIZE_FLAG, $pagesize);
        }

        return (int) $pagesize;
    }

    protected function page_content() {
        global $PAGE;
        $output = $this->get_renderer();
        $PAGE->requires->js_call_amd('block_xp/modal', 'registerSimpleOpenModalActionObserver');

        $canmanage = $this->world->get_access_permissions()->can_manage();
        
        // 直接通过 URL 判断是否是进步榜
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $isexamreward = strpos($uri, '/examreward') !== false;

        if ($isexamreward) {
            echo $this->render_examreward();
            return;
        }

        if ($canmanage) {
            echo $output->advanced_heading(get_string('ladder', 'block_xp'), [
                'intro' => new \lang_string('ladderintro', 'block_xp'),
                'help' => new \help_icon('ladder', 'block_xp'),
                'visible' => $this->is_visible_to_viewers(),
                'menu' => $this->get_page_menu_items(),
            ]);
        }
        $this->page_ranking();
    }

    protected function render_examreward() {
        global $DB;
        
        $courseid = $this->world->get_courseid();
        $html = '<div class="block_xp_examreward" style="padding: 10px 0;">';
        
        $sql = "SELECT gi.*
                  FROM {grade_items} gi
                 WHERE gi.courseid = :courseid
                   AND gi.itemmodule = 'quiz'
                   AND gi.idnumber LIKE :prefix
                 ORDER BY gi.timecreated DESC";
        $exams = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'prefix' => 'EXAM_%'
        ]);
        
        if (empty($exams)) {
            $html .= '<div class="alert alert-info">暂无正式考试，请在成绩簿中设置 ID编号（以 EXAM_ 开头）</div>';
            $html .= '</div>';
            return $html;
        }
        
        $selectedexamid = $this->get_param('examid');
        if ($selectedexamid && isset($exams[$selectedexamid])) {
            $latestexam = $exams[$selectedexamid];
            $latestexamid = $selectedexamid;
        } else {
            $latestexam = reset($exams);
            $latestexamid = $latestexam->id;
        }
        
        $latestexamname = $latestexam->itemname ?? '本次考试';
        
        $sql = "SELECT gg.userid, gg.finalgrade
                  FROM {grade_grades} gg
                 WHERE gg.itemid = :itemid
                   AND gg.finalgrade IS NOT NULL";
        $currentgrades = $DB->get_records_sql($sql, ['itemid' => $latestexamid]);
        
        if (empty($currentgrades)) {
            $html .= '<div class="alert alert-info">本次考试暂无成绩数据</div>';
            $html .= '</div>';
            return $html;
        }
        
        $examids = array_keys($exams);
        $previousgrades = [];
        $currentindex = array_search($latestexamid, $examids);
        if ($currentindex !== false && $currentindex < count($examids) - 1) {
            $previousexamid = $examids[$currentindex + 1];
            $sql = "SELECT gg.userid, gg.finalgrade
                      FROM {grade_grades} gg
                     WHERE gg.itemid = :itemid
                       AND gg.finalgrade IS NOT NULL";
            $previousgrades = $DB->get_records_sql($sql, ['itemid' => $previousexamid]);
        }
        
        $improvements = [];
        foreach ($currentgrades as $userid => $currentgrade) {
            $currentscore = (float)$currentgrade->finalgrade;
            $previousscore = 0;
            if (isset($previousgrades[$userid])) {
                $previousscore = (float)$previousgrades[$userid]->finalgrade;
            }
            $improvement = $currentscore - $previousscore;
            if ($improvement > 0) {
                $improvements[$userid] = [
                    'userid' => $userid,
                    'current' => $currentscore,
                    'previous' => $previousscore,
                    'improvement' => $improvement
                ];
            }
        }
        
        uasort($improvements, function($a, $b) {
            return $b['improvement'] <=> $a['improvement'];
        });
        
        $winners = array_slice($improvements, 0, 3, true);
        
        $userids = array_keys($winners);
        $users = [];
        if (!empty($userids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($userids);
            $sql = "SELECT id, firstname, lastname
                      FROM {user}
                     WHERE id $insql";
            $users = $DB->get_records_sql($sql, $inparams);
        }
        
        $html .= '
        <style>
        .examreward-container {
            max-width: 650px;
            margin: 0 auto;
            padding: 20px 10px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .examreward-header {
            text-align: center;
            padding: 10px 0 5px 0;
            border-bottom: 2px solid #e8e8e8;
            margin-bottom: 10px;
        }
        .examreward-header .trophy {
            font-size: 28px;
        }
        .examreward-header .main-title {
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            letter-spacing: 1px;
        }
        .examreward-header .title-separator {
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            margin: 0 6px;
        }
        .examreward-header .sub-title {
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            letter-spacing: 1px;
        }
        .examreward-selector {
            text-align: center;
            padding: 12px 0 10px 0;
            border-bottom: 1px dashed #ddd;
            margin-bottom: 16px;
        }
        .examreward-selector select {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid #ddd;
            font-size: 15px;
            background: #fff;
            color: #333;
            cursor: pointer;
            outline: none;
            min-width: 180px;
        }
        .examreward-selector select:hover,
        .examreward-selector select:focus {
            border-color: #ffb300;
        }
        .examreward-selector .selector-label {
            color: #666;
            font-size: 14px;
            margin-right: 10px;
        }
        .examreward-exam-name {
            text-align: center;
            font-size: 15px;
            color: #666;
            padding: 6px 0 14px 0;
            border-bottom: 1px dashed #ddd;
            margin-bottom: 16px;
        }
        .examreward-card {
            margin: 10px 0 20px 0;
        }
        .examreward-card .item {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 8px;
            font-size: 15px;
            color: #222;
            border-left: 4px solid #1a73e8;
        }
        .examreward-card .item:last-child {
            margin-bottom: 0;
        }
        .examreward-card .name {
            font-weight: 600;
        }
        .examreward-card .arrow {
            color: #d32f2f;
            font-weight: 600;
        }
        .examreward-rules {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 14px 20px 10px 20px;
            margin: 10px 0 14px 0;
            border: 1px solid #e0e0e0;
        }
        .examreward-rules .rules-title {
            font-size: 15px;
            font-weight: 600;
            color: #222;
            margin-bottom: 6px;
        }
        .examreward-rules .rule-line {
            padding: 3px 0;
            font-size: 14px;
            color: #444;
        }
        .examreward-footer {
            text-align: center;
            padding: 14px 16px;
            background: #e8f5e9;
            border-radius: 10px;
            margin-top: 16px;
            color: #2e7d32;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid #c8e6c9;
        }
        .examreward-no-data {
            text-align: center;
            padding: 40px 20px;
            background: #fafafa;
            border-radius: 10px;
            border: 1px solid #eee;
            color: #999;
            font-size: 15px;
        }
        .rank-icon {
            font-size: 18px;
        }
        </style>';
        
        $html .= '<div class="examreward-container">';
        
        $html .= '<div class="examreward-header">';
        $html .= '<span class="trophy">🏆</span>';
        $html .= '<span class="main-title">考试奖励榜</span>';
        $html .= '<span class="title-separator">·</span>';
        $html .= '<span class="sub-title">进步之星</span>';
        $html .= '</div>';
        
        $html .= '<div class="examreward-selector">';
        $html .= '<span class="selector-label">📌 选择考试：</span>';
        $html .= '<form method="get" action="" style="display:inline-block;">';
        $html .= '<input type="hidden" name="r" value="ladder">';
        $html .= '<input type="hidden" name="courseid" value="' . $courseid . '">';
        $html .= '<input type="hidden" name="page" value="examreward">';
        $html .= '<select name="examid" onchange="this.form.submit()">';
        foreach ($exams as $exam) {
            $selected = ($exam->id == $latestexamid) ? 'selected' : '';
            $html .= '<option value="' . $exam->id . '" ' . $selected . '>';
            $html .= htmlspecialchars($exam->itemname);
            $html .= '</option>';
        }
        $html .= '</select>';
        $html .= '</form>';
        $html .= '</div>';
        
        $html .= '<div class="examreward-exam-name">';
        $html .= '本次考试：' . htmlspecialchars($latestexamname);
        $html .= '</div>';
        
        if (empty($winners)) {
            $html .= '<div class="examreward-no-data">暂无进步数据，请期待下次考试！</div>';
        } else {
            $html .= '<div class="examreward-card">';
            $rank = 1;
            foreach ($winners as $userid => $data) {
                $user = $users[$userid] ?? null;
                $fullname = $user ? fullname($user) : '未知用户';
                $icon = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉');
                
                $html .= '<div class="item">';
                $html .= '<span class="rank-icon">' . $icon . '</span>';
                $html .= '<span class="name">' . htmlspecialchars($fullname) . '</span>';
                $html .= '上次 ' . round($data['previous']) . ' → 本次 ' . round($data['current']);
                $html .= '<span class="arrow">(+' . round($data['improvement']) . ')</span>';
                $html .= '</div>';
                $rank++;
            }
            $html .= '</div>';
        }
        
        $html .= '<div class="examreward-rules">';
        $html .= '<div class="rules-title">📢 奖励规则</div>';
        $html .= '<div class="rule-line">1️⃣ 每次正式测验中进步幅度最大的同学</div>';
        $html .= '<div class="rule-line">2️⃣ 奖励一支中性笔 + "进步之星"荣誉称号</div>';
        $html .= '<div class="rule-line">3️⃣ 与上次测验相比，分数提升幅度最大者</div>';
        $html .= '<div class="rule-line">4️⃣ 由老师在课堂公布并当场颁发</div>';
        $html .= '<div class="rule-line">5️⃣ 由老师个人出资，不涉及任何收费</div>';
        $html .= '</div>';
        
        $html .= '<div class="examreward-footer">';
        $html .= '📢 所有同学都有机会获得奖励，请大家积极努力！';
        $html .= '</div>';
        
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    protected function page_ranking() {
        $this->print_group_menu();

        echo html_writer::start_div('xp-cancel-overflow');
        echo $this->get_table()->out($this->get_page_size(), false);
        echo html_writer::end_div();
    }

    protected function get_page_menu_items() {
        $config = di::get('config');
        $hasaddon = di::get('addon')->is_activated();

        $randomid = \html_writer::random_id();
        $plugman = \core_plugin_manager::instance();
        $shortcodes = $plugman->get_plugin_info('filter_shortcodes');
        $context = $this->world->get_context();
        $embeddata = [
            'isavailable' => (bool) $shortcodes && ($shortcodes->is_enabled() ?? true),
            'isenabled' => (bool) $this->world->get_config()->get('enableladder'),
            'introformatted' => text_utils::markdown_light(get_string('shortcodexpladderembedintro', 'block_xp')),
            'pluginrequiredformatted' => text_utils::markdown_light(get_string('pluginshortcodesrequiredtousefeature', 'block_xp')),
            'snippet' => "[xpladder ctx={$context->id} secret=" . handler::get_xpladder_secret($context) . "]",
        ];
        echo $this->get_renderer()->json_script($embeddata, $randomid);

        return array_filter([
            [
                'label' => get_string('pagesettings', 'block_xp'),
                'data-xp-action' => 'open-form',
                'data-form-class' => di::get('leaderboard_form_class'),
                'data-form-args__contextid' => $this->world->get_context()->id,
                'href' => '#',
            ],
            [
                'label' => get_string('embedleaderboard', 'block_xp'),
                'data-xp-action' => 'open-modal',
                'data-template' => 'block_xp/shortcode-xpladder-embed',
                'data-template-data' => $randomid,
                'data-modal-title' => get_string('embedleaderboard', 'block_xp'),
                'href' => '#',
            ],
            $config->get('enablepromoincourses') && !$hasaddon ? [
                'label' => get_string('export', 'block_xp'),
                'href' => '#',
                'disabled' => true,
                'addonrequired' => true,
            ] : null,
        ]);
    }

}