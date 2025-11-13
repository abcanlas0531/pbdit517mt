<?php
// admin.php
$logDir       = __DIR__ . '/logs';
$resultsFile  = $logDir . '/exam_results.json';
$attemptsFile = $logDir . '/attempts_count.json';
$actionsFile  = $logDir . '/actions_log.json';

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Load exam results
$results = [];
if (file_exists($resultsFile)) {
    $json = file_get_contents($resultsFile);
    $results = json_decode($json, true);
    if (!is_array($results)) $results = [];
}

// Load attempt counts
$attemptCounts = [];
if (file_exists($attemptsFile)) {
    $json = file_get_contents($attemptsFile);
    $attemptCounts = json_decode($json, true);
    if (!is_array($attemptCounts)) $attemptCounts = [];
}

// Load actions
$actions = [];
if (file_exists($actionsFile)) {
    $json = file_get_contents($actionsFile);
    $actions = json_decode($json, true);
    if (!is_array($actions)) $actions = [];
}

// Build student list from results
$students = []; // key: student_id
foreach ($results as $r) {
    $sid = $r['student_id'] ?? 'UNKNOWN';
    if (!isset($students[$sid])) {
        $students[$sid] = [
            'student_id'   => $sid,
            'student_name' => $r['student_name'] ?? 'Unknown',
            'attempts'     => [],
        ];
    }
    $students[$sid]['attempts'][] = $r;
}

// Sort attempts per student by timestamp desc
foreach ($students as &$st) {
    usort($st['attempts'], function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });
}
unset($st);

// Sort students by name
usort($students, function($a, $b) {
    return strcmp($a['student_name'], $b['student_name']);
});

// Handle selected student for detailed view
$selectedStudentId = isset($_GET['student_id']) ? $_GET['student_id'] : null;
$selectedStudent   = null;
$selectedAttempts  = [];
$selectedActions   = [];

if ($selectedStudentId !== null) {
    foreach ($students as $st) {
        if ($st['student_id'] === $selectedStudentId) {
            $selectedStudent  = $st;
            $selectedAttempts = $st['attempts'];
            break;
        }
    }

    // Filter actions for this student
    foreach ($actions as $act) {
        if (($act['student_id'] ?? null) === $selectedStudentId) {
            $selectedActions[] = $act;
        }
    }
    usort($selectedActions, function($a, $b) {
        return strcmp($a['timestamp_server'] ?? '', $b['timestamp_server'] ?? '');
    });
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Exam Admin Dashboard (JSON)</title>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f5f5f5; margin:0; padding:20px;">
  <div style="max-width:1100px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:8px; box-shadow:0 0 8px rgba(0,0,0,0.1);">
    <h1 style="margin-top:0; text-align:center;">Exam Admin Dashboard</h1>
    <p style="text-align:center; font-size:14px; margin-bottom:15px;">
      Data source: JSON files in <code>logs/</code> (<code>exam_results.json</code>, <code>attempts_count.json</code>, <code>actions_log.json</code>).
    </p>

    <div style="display:flex; gap:20px; align-items:flex-start;">

      <!-- Left: Student list -->
      <div style="flex:1;">
        <h2 style="margin-top:0;">Exam Takers</h2>
        <?php if (empty($students)): ?>
          <p>No exam attempts found.</p>
        <?php else: ?>
          <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
              <tr>
                <th style="border:1px solid #ccc; padding:6px;">Name</th>
                <th style="border:1px solid #ccc; padding:6px;">ID</th>
                <th style="border:1px solid #ccc; padding:6px;">Attempts</th>
                <th style="border:1px solid #ccc; padding:6px;">Best Score</th>
                <th style="border:1px solid #ccc; padding:6px;">Last Attempt</th>
                <th style="border:1px solid #ccc; padding:6px;">View</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $st): ?>
                <?php
                  $sid      = $st['student_id'];
                  $name     = $st['student_name'];
                  $attempts = $st['attempts'];
                  $count    = isset($attemptCounts[$sid]) ? (int)$attemptCounts[$sid] : count($attempts);
                  $best     = null;
                  $lastTime = null;
                  foreach ($attempts as $a) {
                      if ($best === null || $a['percent'] > $best) {
                          $best = $a['percent'];
                      }
                      if ($lastTime === null || strcmp($a['timestamp'], $lastTime) > 0) {
                          $lastTime = $a['timestamp'];
                      }
                  }
                ?>
                <tr>
                  <td style="border:1px solid #ccc; padding:6px;"><?php echo h($name); ?></td>
                  <td style="border:1px solid #ccc; padding:6px;"><?php echo h($sid); ?></td>
                  <td style="border:1px solid #ccc; padding:6px; text-align:center;"><?php echo $count; ?></td>
                  <td style="border:1px solid #ccc; padding:6px; text-align:center;">
                    <?php echo $best !== null ? h(number_format($best, 2))."%" : "-"; ?>
                  </td>
                  <td style="border:1px solid #ccc; padding:6px; font-size:11px;">
                    <?php echo $lastTime ? h($lastTime) : "-"; ?>
                  </td>
                  <td style="border:1px solid #ccc; padding:6px; text-align:center;">
                    <a href="?student_id=<?php echo urlencode($sid); ?>" style="text-decoration:none; color:#007bff;">Details</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Right: Detail panel -->
      <div style="flex:1; border-left:1px solid #eee; padding-left:20px;">
        <h2 style="margin-top:0;">Details</h2>
        <?php if (!$selectedStudentId): ?>
          <p>Select a student on the left to view attempts and action logs.</p>
        <?php elseif (!$selectedStudent): ?>
          <p>No data found for student ID: <strong><?php echo h($selectedStudentId); ?></strong></p>
        <?php else: ?>
          <h3 style="margin-bottom:5px;"><?php echo h($selectedStudent['student_name']); ?></h3>
          <p style="margin-top:0; font-size:13px;"><strong>ID:</strong> <?php echo h($selectedStudent['student_id']); ?></p>

          <h4 style="margin-bottom:5px;">Exam Attempts</h4>
          <?php if (empty($selectedAttempts)): ?>
            <p>No attempts recorded.</p>
          <?php else: ?>
            <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:10px;">
              <thead>
                <tr>
                  <th style="border:1px solid #ccc; padding:4px;">#</th>
                  <th style="border:1px solid #ccc; padding:4px;">Timestamp</th>
                  <th style="border:1px solid #ccc; padding:4px;">Attempt #</th>
                  <th style="border:1px solid #ccc; padding:4px;">Score</th>
                  <th style="border:1px solid #ccc; padding:4px;">Percent</th>
                </tr>
              </thead>
              <tbody>
                <?php $i = 1; foreach ($selectedAttempts as $a): ?>
                  <tr>
                    <td style="border:1px solid #ccc; padding:4px; text-align:center;"><?php echo $i; ?></td>
                    <td style="border:1px solid #ccc; padding:4px;"><?php echo h($a['timestamp']); ?></td>
                    <td style="border:1px solid #ccc; padding:4px; text-align:center;"><?php echo h($a['attempt_number'] ?? "-"); ?></td>
                    <td style="border:1px solid #ccc; padding:4px; text-align:center;"><?php echo h($a['score'])." / ".h($a['total']); ?></td>
                    <td style="border:1px solid #ccc; padding:4px; text-align:center;"><?php echo h(number_format($a['percent'], 2))."%"; ?></td>
                  </tr>
                <?php $i++; endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4 style="margin-bottom:5px;">Action Timeline</h4>
          <?php if (empty($selectedActions)): ?>
            <p>No actions logged for this student.</p>
          <?php else: ?>
            <div style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:8px; border-radius:4px; background:#fafafa;">
              <ul style="list-style:none; padding-left:0; margin:0;">
                <?php foreach ($selectedActions as $act): ?>
                  <li style="margin-bottom:6px; font-size:12px;">
                    <strong><?php echo h($act['timestamp_server'] ?? ''); ?></strong>
                    &mdash;
                    <em><?php echo h($act['action_type'] ?? ''); ?></em>
                    <?php if (!empty($act['question_id'])): ?>
                      (<?php echo h($act['question_id']); ?>)
                    <?php endif; ?>
                    <?php if (isset($act['value']) && $act['value'] !== null && $act['value'] !== ""): ?>
                      : <code><?php echo h(is_array($act['value']) ? json_encode($act['value']) : $act['value']); ?></code>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div>
  </div>
</body>
</html>
