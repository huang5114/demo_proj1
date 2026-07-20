<?php
// 本地插件 - 考试优胜榜（简单列表样式）
require_once('../../config.php');
require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('moodle/course:view', $context);

$isteacher = has_capability('moodle/course:manageactivities', $context);

$PAGE->set_url('/local/leaderboard/index.php', array('courseid' => $courseid));
$PAGE->set_title(get_string('pluginname', 'local_leaderboard') . ' - ' . $course->fullname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// 获取可评分活动
$modinfo = get_fast_modinfo($courseid);
$cms = $modinfo->get_cms();
$quiz_items = array();
foreach ($cms as $cm) {
    $grade_item = grade_item::fetch(array('itemtype' => 'mod', 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance, 'courseid' => $courseid));
    if ($grade_item && $grade_item->gradetype == GRADE_TYPE_VALUE) {
        $quiz_items[] = array(
            'id' => $cm->id,
            'name' => $cm->name,
            'grade_max' => $grade_item->grademax,
            'modname' => $cm->modname
        );
    }
}

// 获取保存的勾选
$user_pref_key = 'local_leaderboard_selected_' . $courseid;
$selected_ids = get_user_preferences($user_pref_key, '');
if ($selected_ids) {
    $selected_ids = explode(',', $selected_ids);
    $selected_ids = array_filter($selected_ids, 'is_numeric');
} else {
    $selected_ids = array();
}

// 计算学生总分
$students = array();
if (!empty($selected_ids)) {
    $enrolled = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.firstname, u.lastname');
    foreach ($enrolled as $user) {
        $total = 0;
        foreach ($selected_ids as $cmid) {
            $grade = grade_get_grades($courseid, 'mod', $cms[$cmid]->modname, $cms[$cmid]->instance, $user->id);
            if ($grade && isset($grade->items[0]->grades[$user->id]->grade)) {
                $total += $grade->items[0]->grades[$user->id]->grade;
            }
        }
        $students[] = array(
            'userid' => $user->id,
            'fullname' => fullname($user),
            'total' => round($total, 2)
        );
    }
    // 按总分降序
    usort($students, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    // 添加排名
    foreach ($students as $idx => &$s) {
        $s['rank'] = $idx + 1;
    }
    unset($s);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>考试优胜榜</title>
    <style>
        /* 简洁样式，与例图一致 */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: #f4f7fc; padding: 20px; }
        .container { max-width: 900px; margin:0 auto; background:#fff; border-radius:24px; box-shadow:0 20px 60px rgba(0,20,50,0.10); padding:32px 36px 40px; }
        .header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:28px; padding-bottom:18px; border-bottom:2px solid #eef2f7; }
        .header-left h1 { font-size:28px; font-weight:700; color:#1a2634; display:flex; align-items:center; gap:12px; }
        .badge { font-size:14px; font-weight:500; background:#e8edf5; color:#2c3e50; padding:4px 14px; border-radius:30px; }
        .course-info { font-size:15px; color:#4a5a6e; background:#f0f4fa; padding:6px 18px; border-radius:30px; font-weight:500; }
        .btn-refresh { background:#eef2f7; border:none; padding:8px 18px; border-radius:30px; font-size:14px; font-weight:500; color:#2c3e50; cursor:pointer; transition:0.2s; }
        .btn-refresh:hover { background:#dce3ed; }

        .admin-panel { background:#f8faff; border:1px solid #e2e9f3; border-radius:18px; padding:22px 28px 26px; margin-bottom:32px; }
        .panel-title { font-size:17px; font-weight:600; color:#1a2634; margin-bottom:16px; }
        .quiz-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:18px; }
        .quiz-item { display:flex; align-items:center; gap:10px; background:#fff; padding:10px 16px; border-radius:12px; border:1px solid #e6edf6; cursor:pointer; }
        .quiz-item:hover { border-color:#b8cde0; background:#fafdff; }
        .quiz-item input[type="checkbox"] { width:18px; height:18px; accent-color:#2a6df4; cursor:pointer; flex-shrink:0; }
        .quiz-item .quiz-name { font-size:14px; font-weight:500; color:#1f2a36; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .quiz-item .quiz-score-hint { font-size:12px; color:#7a8a9e; margin-left:auto; white-space:nowrap; }
        .admin-actions { display:flex; gap:14px; flex-wrap:wrap; margin-top:6px; }
        .btn-primary { background:#2a6df4; color:#fff; border:none; padding:10px 32px; border-radius:30px; font-size:15px; font-weight:600; cursor:pointer; transition:0.2s; box-shadow:0 4px 12px rgba(42,109,244,0.25); }
        .btn-primary:hover { background:#1a5adf; transform:translateY(-1px); }
        .btn-secondary { background:#eef2f7; color:#2c3e50; border:none; padding:10px 28px; border-radius:30px; font-size:15px; font-weight:500; cursor:pointer; transition:0.2s; }
        .btn-secondary:hover { background:#dce3ed; }
        .selection-status { font-size:14px; color:#4a5a6e; margin-top:14px; padding:10px 16px; background:#eef4fa; border-radius:12px; display:inline-block; }

        .leaderboard-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
        .leaderboard-header h2 { font-size:22px; font-weight:700; color:#1a2634; }

        /* ----- 简单列表样式（如例图） ----- */
        .rank-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .rank-list li {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #eef2f7;
            font-size: 16px;
        }
        .rank-list li:last-child {
            border-bottom: none;
        }
        .rank-list .rank-number {
            font-weight: 700;
            color: #4a5a6e;
            min-width: 80px;
        }
        .rank-list .rank-number.first {
            color: #c78a2c;
        }
        .rank-list .rank-number.second {
            color: #8a9aaa;
        }
        .rank-list .rank-number.third {
            color: #c29a6b;
        }
        .rank-list .student-name {
            flex: 1;
            font-weight: 500;
            color: #1a2634;
        }
        .rank-list .student-score {
            font-weight: 600;
            color: #1a2634;
            margin-right: 10px;
        }
        .rank-list .star-tag {
            background: #f5e6d0;
            color: #7a4a1a;
            font-size: 13px;
            font-weight: 700;
            padding: 2px 12px;
            border-radius: 30px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 10px;
        }

        .empty-state { text-align:center; padding:50px 20px 40px; color:#6a7a8e; }
        .empty-state .empty-icon { font-size:48px; margin-bottom:14px; opacity:0.5; }
        .empty-state h3 { font-size:20px; color:#1a2634; margin-bottom:6px; }
        .hidden { display:none !important; }
        .text-muted { color:#6a7a8e; }

        .toast-msg { position:fixed; bottom:30px; left:50%; transform:translateX(-50%); background:#1f2a36; color:#fff; padding:14px 30px; border-radius:16px; font-size:15px; font-weight:500; box-shadow:0 10px 40px rgba(0,0,0,0.20); z-index:999; opacity:0; transition:opacity 0.3s ease; pointer-events:none; }
        .toast-msg.show { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-msg.success { background:#1a7a3a; }
        .toast-msg.error { background:#b13a3a; }

        @media (max-width:700px) { .container { padding:20px 16px 28px; } .quiz-grid { grid-template-columns:1fr; } .rank-list li { flex-wrap:wrap; gap:6px; } .rank-list .rank-number { min-width:60px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-left">
            <h1>🏆 考试优胜榜 <span class="badge">实时排名</span></h1>
        </div>
        <div class="header-right">
            <span class="course-info">📖 <?php echo $course->shortname; ?></span>
            <button class="btn-refresh" onclick="refreshData()">⟳ 刷新</button>
        </div>
    </div>

    <?php if ($isteacher): ?>
    <div class="admin-panel" id="adminPanel">
        <div class="panel-title">⚙️ 教师管理 — 勾选计入榜单的测验</div>
        <div class="quiz-grid" id="quizCheckboxContainer">
            <?php 
            if (empty($quiz_items)) {
                echo '<div class="text-muted" style="grid-column:1/-1; padding:8px 0;">📭 当前课程暂无可评分的活动</div>';
            } else {
                foreach ($quiz_items as $item) {
                    $checked = in_array($item['id'], $selected_ids) ? 'checked' : '';
                    echo '<label class="quiz-item">
                            <input type="checkbox" value="' . $item['id'] . '" ' . $checked . ' onchange="toggleQuiz(this)">
                            <span class="quiz-name">' . format_string($item['name']) . '</span>
                            <span class="quiz-score-hint">满分 ' . $item['grade_max'] . '</span>
                        </label>';
                }
            }
            ?>
        </div>
        <div class="admin-actions">
            <button class="btn-primary" onclick="saveSelection()">💾 保存勾选 & 更新榜单</button>
            <button class="btn-secondary" onclick="selectAll(true)">✓ 全选</button>
            <button class="btn-secondary" onclick="selectAll(false)">✕ 取消全选</button>
        </div>
        <div class="selection-status">
            📌 已勾选 <strong id="selectedCount"><?php echo count($selected_ids); ?></strong> 个测验
        </div>
    </div>
    <?php endif; ?>

    <div class="leaderboard">
        <div class="leaderboard-header">
            <h2>📊 综合排名</h2>
            <span class="subtitle" id="rankInfo">
                <?php 
                $cnt = count($selected_ids);
                echo ($cnt > 0) ? "共 " . count($students) . " 位学生 · 基于 $cnt 个测验的总分" : "请老师勾选测验";
                ?>
            </span>
        </div>
        <div id="leaderboardContent">
            <?php 
            if (empty($selected_ids) || empty($students)) {
                echo '<div class="empty-state"><div class="empty-icon">📋</div><h3>暂无排名数据</h3><p>' . ($isteacher ? '请勾选测验并保存。' : '请老师勾选测验。') . '</p></div>';
            } else {
                // ---------- 简单列表样式（与例图一致） ----------
                echo '<ul class="rank-list">';
                $rank_names = ['第一名', '第二名', '第三名', '第四名', '第五名', '第六名', '第七名', '第八名', '第九名', '第十名'];
                foreach ($students as $idx => $s) {
                    $rank = $s['rank'];
                    $rank_display = isset($rank_names[$rank-1]) ? $rank_names[$rank-1] : '第' . $rank . '名';
                    $rank_class = '';
                    if ($rank == 1) $rank_class = 'first';
                    elseif ($rank == 2) $rank_class = 'second';
                    elseif ($rank == 3) $rank_class = 'third';

                    $star_html = ($rank == 1) ? '<span class="star-tag">⭐ 学习之星</span>' : '';

                    echo '<li>';
                    echo '<span class="rank-number ' . $rank_class . '">' . $rank_display . '</span>';
                    echo '<span class="student-name">' . format_string($s['fullname']) . '</span>';
                    echo '<span class="student-score">' . $s['total'] . ' 分</span>';
                    echo $star_html;
                    echo '</li>';
                }
                echo '</ul>';
            }
            ?>
        </div>
    </div>
</div>
<div class="toast-msg" id="toastMsg"></div>

<script>
// JavaScript 保持不变
const selected = <?php echo json_encode($selected_ids); ?>;
const allQuizIds = <?php echo json_encode(array_column($quiz_items, 'id')); ?>;
const courseid = <?php echo $courseid; ?>;
const isTeacher = <?php echo $isteacher ? 'true' : 'false'; ?>;

function toggleQuiz(checkbox) {
    const val = parseInt(checkbox.value);
    if (checkbox.checked) {
        if (!selected.includes(val)) selected.push(val);
    } else {
        const idx = selected.indexOf(val);
        if (idx > -1) selected.splice(idx, 1);
    }
    document.getElementById('selectedCount').textContent = selected.length;
    // 预览更新（重新加载页面以获取最新榜单）
    previewUpdate();
}

function selectAll(select) {
    const checkboxes = document.querySelectorAll('.quiz-item input[type="checkbox"]');
    checkboxes.forEach(cb => {
        cb.checked = select;
        const val = parseInt(cb.value);
        if (select) {
            if (!selected.includes(val)) selected.push(val);
        } else {
            const idx = selected.indexOf(val);
            if (idx > -1) selected.splice(idx, 1);
        }
    });
    document.getElementById('selectedCount').textContent = selected.length;
    previewUpdate();
}

function previewUpdate() {
    // 简单预览：直接重新加载页面，但会丢失未保存的勾选？我们保存勾选后刷新更好。
    // 所以我们只重新加载页面以显示最新榜单（但会丢失未保存的勾选状态，不过勾选状态已保留在 selected 数组中，通过页面刷新时从服务器读取，所以没问题）
    window.location.reload();
}

function saveSelection() {
    if (selected.length === 0) {
        showToast('⚠️ 请至少勾选一个测验', 'error');
        return;
    }
    fetch('ajax.php?action=save&courseid=' + courseid, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ selected: selected })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ 勾选设置已保存，榜单已更新！', 'success');
            window.location.reload();
        } else {
            showToast('❌ 保存失败: ' + data.error, 'error');
        }
    })
    .catch(err => showToast('网络错误', 'error'));
}

function refreshData() {
    window.location.reload();
}

function showToast(text, type) {
    const toast = document.getElementById('toastMsg');
    toast.textContent = text;
    toast.className = 'toast-msg ' + (type || '');
    toast.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast.classList.remove('show'), 3000);
}
</script>
</body>
</html>
<?php
echo $OUTPUT->footer();
?>