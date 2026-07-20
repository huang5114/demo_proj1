<?php
require_once('../../config.php');
require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('moodle/course:view', $context);

$PAGE->set_url('/local/progress/index.php', array('courseid' => $courseid));
$PAGE->set_title('学习进度折线图 - ' . $course->fullname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// ----- 获取所有可评分活动（按创建时间排序）-----
$modinfo = get_fast_modinfo($courseid);
$cms = $modinfo->get_cms();
$activities = array();
foreach ($cms as $cm) {
    $grade_item = grade_item::fetch(array(
        'itemtype' => 'mod',
        'itemmodule' => $cm->modname,
        'iteminstance' => $cm->instance,
        'courseid' => $courseid
    ));
    if ($grade_item && $grade_item->gradetype == GRADE_TYPE_VALUE) {
        $activities[] = array(
            'id' => $cm->id,
            'name' => $cm->name,
            'timecreated' => $cm->added,
            'modname' => $cm->modname,
            'instance' => $cm->instance
        );
    }
}
usort($activities, function($a, $b) {
    return $a['timecreated'] - $b['timecreated'];
});

// ----- 获取所有学生数据 -----
$students = array();
if (!empty($activities)) {
    $enrolled = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.firstname, u.lastname');
    foreach ($enrolled as $user) {
        $student_data = array(
            'userid' => $user->id,
            'fullname' => fullname($user),
            'scores' => array(),
            'cumulative' => array()
        );
        $cum = 0;
        foreach ($activities as $act) {
            $grade = grade_get_grades($courseid, 'mod', $act['modname'], $act['instance'], $user->id);
            $score = 0;
            if ($grade && isset($grade->items[0]->grades[$user->id]->grade)) {
                $score = (float)$grade->items[0]->grades[$user->id]->grade;
            }
            $student_data['scores'][] = $score;
            $cum += $score;
            $student_data['cumulative'][] = round($cum, 2);
        }
        $students[] = $student_data;
    }
}

// ----- 第一次测试排名 -----
if (!empty($students) && count($activities) > 0) {
    $first_scores = array();
    foreach ($students as $idx => $s) {
        $first_scores[$idx] = $s['scores'][0] ?? 0;
    }
    arsort($first_scores);
    $rank = 1;
    $prev = null;
    $pos = 1;
    foreach ($first_scores as $idx => $score) {
        if ($prev !== null && $score < $prev) {
            $rank = $pos;
        }
        $students[$idx]['first_rank'] = $rank;
        $prev = $score;
        $pos++;
    }
}

// ----- 最终总排名 -----
if (!empty($students)) {
    $totals = array();
    foreach ($students as $idx => $s) {
        $totals[$idx] = end($s['cumulative']);
    }
    arsort($totals);
    $rank = 1;
    $prev = null;
    $pos = 1;
    foreach ($totals as $idx => $score) {
        if ($prev !== null && $score < $prev) {
            $rank = $pos;
        }
        $students[$idx]['total_rank'] = $rank;
        $prev = $score;
        $pos++;
    }
}

// ----- 图表数据 -----
$chart_labels = array();
$chart_datasets = array();
$color_palette = [
    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
    '#FF9F40', '#FF6384', '#C9CBCF', '#FFB1C1', '#9AD0F5',
    '#FFD8B1', '#B1E6D1', '#D4B8D4', '#F4B8A4', '#A4C3D2'
];

if (!empty($students) && !empty($activities)) {
    foreach ($activities as $act) {
        $chart_labels[] = format_string($act['name']);
    }
    $color_idx = 0;
    foreach ($students as $s) {
        $color = $color_palette[$color_idx % count($color_palette)];
        $color_idx++;
        $chart_datasets[] = array(
            'label' => format_string($s['fullname']),
            'data' => $s['cumulative'],
            'borderColor' => $color,
            'backgroundColor' => $color . '33',
            'fill' => false,
            'tension' => 0.1,
            'pointRadius' => 4,
            'pointBackgroundColor' => $color,
        );
    }
}

// ----- 左右排名列表 -----
$left_list = array();
$right_list = array();
if (!empty($students)) {
    foreach ($students as $s) {
        $left_list[] = array(
            'rank' => $s['first_rank'] ?? '-',
            'name' => $s['fullname'],
        );
        $right_list[] = array(
            'rank' => $s['total_rank'] ?? '-',
            'name' => $s['fullname'],
        );
    }
    usort($left_list, function($a, $b) { return $a['rank'] - $b['rank']; });
    usort($right_list, function($a, $b) { return $a['rank'] - $b['rank']; });
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>学习进度折线图</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* 全局重置 */
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; background:#f4f7fc; }
        body { font-family: 'Segoe UI', Roboto, sans-serif; padding: 8px; display:flex; justify-content:center; align-items:flex-start; }
        .container {
            width:100%;
            max-width: 100%;        /* 取消最大宽度限制 */
            margin:0;
            background:#fff;
            border-radius:16px;
            box-shadow:0 10px 40px rgba(0,20,50,0.08);
            padding:10px 12px 16px;
            height: calc(100vh - 20px); /* 几乎占满视口高度，留一点边距 */
            display:flex;
            flex-direction:column;
        }
        .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            margin-bottom:8px;
            padding-bottom:8px;
            border-bottom:1px solid #eef2f7;
            flex-shrink:0;
        }
        .header-left h1 { font-size:20px; font-weight:700; color:#1a2634; display:flex; align-items:center; gap:8px; }
        .badge { font-size:12px; font-weight:500; background:#e8edf5; color:#2c3e50; padding:2px 12px; border-radius:30px; }
        .course-info { font-size:13px; color:#4a5a6e; background:#f0f4fa; padding:4px 14px; border-radius:30px; font-weight:500; }
        .btn-refresh { background:#eef2f7; border:none; padding:6px 16px; border-radius:30px; font-size:13px; font-weight:500; color:#2c3e50; cursor:pointer; transition:0.2s; }
        .btn-refresh:hover { background:#dce3ed; }

        /* 图表容器：flex列，图表占满剩余高度 */
        .chart-container {
            flex:1;
            display:flex;
            align-items:stretch;
            gap:6px;
            min-height:0; /* 防止flex溢出 */
        }
        .rank-side {
            flex: 0 0 65px;   /* 极窄 */
            background: #f9fbfe;
            border-radius:10px;
            padding:6px 4px;
            border:1px solid #e8eef6;
            overflow-y:auto;
            font-size:11px;
            display:flex;
            flex-direction:column;
        }
        .rank-side h4 {
            font-size:11px;
            color:#1a2634;
            margin-bottom:4px;
            border-bottom:1px solid #e2e9f3;
            padding-bottom:3px;
            text-align:center;
            font-weight:600;
            line-height:1.2;
            flex-shrink:0;
        }
        .rank-side ul {
            list-style:none;
            padding:0;
            margin:0;
            flex:1;
        }
        .rank-side li {
            display:flex;
            justify-content:space-between;
            padding:1px 0;
            font-size:10px;
            border-bottom:1px solid #f0f4fa;
        }
        .rank-side li .rank-num {
            font-weight:600;
            color:#4a5a6e;
            min-width:18px;
        }
        .rank-side li .rank-name {
            color:#1a2634;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            max-width:40px;
        }
        .rank-side li .rank-star { color:#f5a623; margin-left:1px; }
        .rank-side li:last-child { border-bottom:none; }

        .chart-wrapper {
            flex:1;
            min-width:0;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .chart-wrapper canvas {
            width:100% !important;
            height:auto !important;
            max-height:100%;
            max-width:100%;
        }

        .empty-state { text-align:center; padding:40px 20px; color:#6a7a8e; }
        .empty-state .empty-icon { font-size:40px; margin-bottom:10px; opacity:0.5; }
        .empty-state h3 { font-size:18px; color:#1a2634; margin-bottom:4px; }

        @media (max-width: 700px) {
            .rank-side { flex: 0 0 55px; font-size:10px; }
            .rank-side li .rank-name { max-width:30px; }
            .container { height:auto; min-height:100vh; }
            .chart-container { flex-direction:column; }
            .rank-side { width:100%; flex:0 0 auto; max-height:120px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-left">
            <h1>📈 学习进度折线图 <span class="badge">累加排名</span></h1>
        </div>
        <div class="header-right">
            <span class="course-info">📖 <?php echo $course->shortname; ?></span>
            <button class="btn-refresh" onclick="location.reload()">⟳ 刷新</button>
        </div>
    </div>

    <div style="flex-shrink:0; margin-bottom:4px;">
        <h2 style="font-size:16px; font-weight:500; color:#1a2634;">📊 累加成绩趋势</h2>
    </div>
    <div id="chartArea" style="flex:1; min-height:0;">
        <?php if (empty($students) || empty($activities)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>暂无数据</h3>
                <p>课程中暂无可评分的活动，或尚未有学生成绩。</p>
            </div>
        <?php else: ?>
            <div class="chart-container">
                <!-- 左侧 -->
                <div class="rank-side">
                    <h4>初始排名<br><span style="font-size:9px;font-weight:400;color:#6a7a8e;">(第一次)</span></h4>
                    <ul>
                        <?php foreach ($left_list as $item): ?>
                        <li>
                            <span class="rank-num">#<?php echo $item['rank']; ?></span>
                            <span class="rank-name">
                                <?php echo format_string($item['name']); ?>
                                <?php if ($item['rank'] == 1): ?><span class="rank-star">★</span><?php endif; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <!-- 图表 -->
                <div class="chart-wrapper">
                    <canvas id="progressChart"></canvas>
                </div>
                <!-- 右侧 -->
                <div class="rank-side">
                    <h4>最终排名<br><span style="font-size:9px;font-weight:400;color:#6a7a8e;">(累加)</span></h4>
                    <ul>
                        <?php foreach ($right_list as $item): ?>
                        <li>
                            <span class="rank-num">#<?php echo $item['rank']; ?></span>
                            <span class="rank-name">
                                <?php echo format_string($item['name']); ?>
                                <?php if ($item['rank'] == 1): ?><span class="rank-star">★</span><?php endif; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
<?php if (!empty($students) && !empty($activities)): ?>
const chartData = {
    labels: <?php echo json_encode($chart_labels); ?>,
    datasets: <?php echo json_encode($chart_datasets); ?>
};

document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('progressChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false, // 关键：允许拉伸填满容器
            plugins: {
                legend: {
                    position: 'top',
                    labels: { boxWidth: 12, padding: 8, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + ' 分';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: '累加总分', font: { size: 12 } }
                },
                x: {
                    title: { display: true, text: '测试节点', font: { size: 12 } }
                }
            }
        }
    });
});
<?php endif; ?>
</script>
</body>
</html>
<?php
echo $OUTPUT->footer();
?>