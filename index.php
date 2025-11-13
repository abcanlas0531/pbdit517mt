<?php
session_start();

// ---------- Paths for JSON logs ----------
$logDir       = __DIR__ . '/logs';
$resultsFile  = $logDir . '/exam_results.json';
$attemptsFile = $logDir . '/attempts_count.json';
$actionsFile  = $logDir . '/actions_log.json';

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$feedback   = [];
$score      = null;
$totalItems = 20;
$percent    = null;
$submitted  = false;
$error      = "";
$attemptNo  = null;

// ---------- Handle form submit ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submitted = true;

    $studentName = trim($_POST["studentName"] ?? "");
    $studentId   = trim($_POST["studentId"] ?? "");

    if ($studentName === "" || $studentId === "") {
        $error = "Please enter your name and ID number.";
    } else {
        // Answer keys
        $multiAnswers = [
            "q1"  => ["A","B"],
            "q2"  => ["A","C","D"],
            "q3"  => ["A","C","D"],
            "q4"  => ["A","C"],
            "q5"  => ["A","C","D"],
            "q6"  => ["A","C","D"],
            "q7"  => ["A","B","C"],
            "q8"  => ["A","B","C"],
            "q9"  => ["A","B","C"],
            "q10" => ["A","B","C"]
        ];

        $textAnswers = [
            "q11" => ["TCP/IP"],
            "q12" => ["INTRANET"],
            "q13" => ["WEB SERVER"],
            "q14" => ["URL","UNIFORM RESOURCE LOCATOR"],
            "q15" => ["HTTP","HYPERTEXT TRANSFER PROTOCOL"],
            "q16" => ["HTML","HYPERTEXT MARKUP LANGUAGE"],
            "q17" => ["WEB PAGE","WEBPAGE"],
            "q18" => ["EXTENSIBLE MARKUP LANGUAGE","XML"],
            "q19" => ["TESTING"],
            "q20" => ["LAUNCH"]
        ];

        $score       = 0;
        $raw_answers = [];

        // Helper
        function normalize_multi($arr) {
            sort($arr);
            return $arr;
        }

        // Q1–Q10 (multiple answers)
        for ($i = 1; $i <= 10; $i++) {
            $key      = "q".$i;
            $selected = isset($_POST[$key]) ? (array)$_POST[$key] : [];
            $correct  = $multiAnswers[$key];

            $raw_answers[$key] = $selected;

            $selNorm = normalize_multi($selected);
            $corNorm = normalize_multi($correct);

            $isCorrect = ($selNorm == $corNorm);
            if ($isCorrect) $score++;

            $feedback[] = "Q{$i}: " . ($isCorrect ? "Correct" : "Incorrect") .
                          " (Your answer: " . (implode(", ", $selected) ?: "No answer") .
                          " | Correct: " . implode(", ", $correct) . ")";
        }

        // Q11–Q20 (text)
        for ($i = 11; $i <= 20; $i++) {
            $key        = "q".$i;
            $input      = strtoupper(trim($_POST[$key] ?? ""));
            $acceptable = $textAnswers[$key];

            $raw_answers[$key] = $input;

            $isCorrect = false;
            foreach ($acceptable as $acc) {
                if ($input === strtoupper($acc)) {
                    $isCorrect = true;
                    break;
                }
            }
            if ($isCorrect) $score++;

            $feedback[] = "Q{$i}: " . ($isCorrect ? "Correct" : "Incorrect") .
                          " (Your answer: " . ($input ?: "No answer") .
                          " | Acceptable: " . implode(" / ", $acceptable) . ")";
        }

        $percent = ($score / $totalItems) * 100;

        // ---------- Load attempts count ----------
        $attemptCounts = [];
        if (file_exists($attemptsFile)) {
            $json = file_get_contents($attemptsFile);
            $attemptCounts = json_decode($json, true);
            if (!is_array($attemptCounts)) $attemptCounts = [];
        }
        $prevCount = isset($attemptCounts[$studentId]) ? (int)$attemptCounts[$studentId] : 0;
        $attemptNo = $prevCount + 1;
        $attemptCounts[$studentId] = $attemptNo;
        file_put_contents($attemptsFile, json_encode($attemptCounts, JSON_PRETTY_PRINT), LOCK_EX);

        // ---------- Save exam attempt ----------
        $attemptRecord = [
            "timestamp"     => date('c'),
            "student_name"  => $studentName,
            "student_id"    => $studentId,
            "attempt_number"=> $attemptNo,
            "score"         => $score,
            "total"         => $totalItems,
            "percent"       => $percent,
            "answers"       => $raw_answers,
            "session_id"    => session_id(),
            "ip"            => $_SERVER['REMOTE_ADDR'] ?? null,
            "user_agent"    => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        $existingResults = [];
        if (file_exists($resultsFile)) {
            $json = file_get_contents($resultsFile);
            $existingResults = json_decode($json, true);
            if (!is_array($existingResults)) $existingResults = [];
        }
        $existingResults[] = $attemptRecord;
        file_put_contents($resultsFile, json_encode($existingResults, JSON_PRETTY_PRINT), LOCK_EX);

        // ---------- Log exam_submit action ----------
        $existingActions = [];
        if (file_exists($actionsFile)) {
            $json = file_get_contents($actionsFile);
            $existingActions = json_decode($json, true);
            if (!is_array($existingActions)) $existingActions = [];
        }

        $existingActions[] = [
            "timestamp_server" => date('c'),
            "action_type"      => "exam_submit",
            "student_name"     => $studentName,
            "student_id"       => $studentId,
            "attempt_number"   => $attemptNo,
            "score"            => $score,
            "percent"          => $percent,
            "session_id"       => session_id(),
            "ip"               => $_SERVER['REMOTE_ADDR'] ?? null,
            "user_agent"       => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        file_put_contents($actionsFile, json_encode($existingActions, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Web Development Examination</title>
  <script>
    // ------- JS logger to api.php -------
    function logAction(actionType, questionId, value) {
      var nameInput = document.getElementById("studentName");
      var idInput   = document.getElementById("studentId");

      var payload = {
        mode: "log_action",
        actionType: actionType,
        questionId: questionId || null,
        value: value || null,
        timestamp: new Date().toISOString(),
        studentName: nameInput ? nameInput.value : null,
        studentId: idInput ? idInput.value : null
      };

      fetch("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }).catch(function (err) {
        // Silent fail in UI, but you could debug in console
        console.warn("logAction failed", err);
      });
    }

    document.addEventListener("DOMContentLoaded", function () {
      logAction("page_load", null, null);

      // Log changes in all inputs
      var inputs = document.querySelectorAll('input[type="checkbox"], input[type="text"]');
      inputs.forEach(function (input) {
        input.addEventListener("change", function (e) {
          var qId = this.name || this.id;
          var val;
          if (this.type === "checkbox") {
            val = this.checked ? this.value : "unchecked";
          } else {
            val = this.value;
          }
          logAction("answer_change", qId, val);
        });
      });

      var form = document.getElementById("examForm");
      if (form) {
        form.addEventListener("submit", function () {
          logAction("form_submit", null, null);
        });
      }

      window.addEventListener("beforeunload", function () {
        logAction("page_unload", null, null);
      });
    });
  </script>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f5f5f5; margin:0; padding:20px;">
  <div style="max-width:900px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:8px; box-shadow:0 0 8px rgba(0,0,0,0.1);">
    <h1 style="text-align:center; margin-top:0;">Web Development Examination</h1>
    <p style="text-align:center; margin-bottom:20px;">Answer all questions. For Items 1–10, select all correct choices.</p>

    <?php if ($error): ?>
      <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form id="examForm" method="post" action="exam.php">
      <!-- Student Info -->
      <div style="margin-bottom:15px; padding:10px; border:1px solid #ddd; border-radius:6px;">
        <h3 style="margin-top:0;">Student Information</h3>
        <label>
          Name:
          <input id="studentName" name="studentName" type="text" style="width:60%; padding:5px; margin:5px 0;"
                 value="<?php echo isset($_POST['studentName']) ? htmlspecialchars($_POST['studentName']) : ""; ?>">
        </label>
        <br>
        <label>
          ID Number:
          <input id="studentId" name="studentId" type="text" style="width:40%; padding:5px; margin:5px 0;"
                 value="<?php echo isset($_POST['studentId']) ? htmlspecialchars($_POST['studentId']) : ""; ?>">
        </label>
      </div>

      <!-- Part I -->
      <h2 style="margin-top:10px;">Part I — Multiple Choice (Multiple Answers)</h2>
      <p style="font-size:13px; margin-top:0;">Choose <strong>ALL</strong> correct answers for each item.</p>

      <?php
      // Helper to keep checkboxes checked
      function is_checked($q, $val) {
          if (!isset($_POST[$q])) return "";
          $arr = (array)$_POST[$q];
          return in_array($val, $arr) ? "checked" : "";
      }
      ?>

      <div style="margin-bottom:10px;">
        <p><strong>1.</strong> Which of the following describe the Internet?</p>
        <label><input type="checkbox" name="q1[]" value="A" <?php echo is_checked("q1","A"); ?>> A. A global system of interconnected networks</label><br>
        <label><input type="checkbox" name="q1[]" value="B" <?php echo is_checked("q1","B"); ?>> B. Uses TCP/IP protocols</label><br>
        <label><input type="checkbox" name="q1[]" value="C" <?php echo is_checked("q1","C"); ?>> C. A system of interlinked hypertext documents</label><br>
        <label><input type="checkbox" name="q1[]" value="D" <?php echo is_checked("q1","D"); ?>> D. A type of private network</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>2.</strong> Which statements correctly describe the World Wide Web (WWW)?</p>
        <label><input type="checkbox" name="q2[]" value="A" <?php echo is_checked("q2","A"); ?>> A. Uses URLs to identify resources</label><br>
        <label><input type="checkbox" name="q2[]" value="B" <?php echo is_checked("q2","B"); ?>> B. A private internal network of organizations</label><br>
        <label><input type="checkbox" name="q2[]" value="C" <?php echo is_checked("q2","C"); ?>> C. Accessible via the Internet</label><br>
        <label><input type="checkbox" name="q2[]" value="D" <?php echo is_checked("q2","D"); ?>> D. Consists of hypertext documents</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>3.</strong> Which are examples of web browsers?</p>
        <label><input type="checkbox" name="q3[]" value="A" <?php echo is_checked("q3","A"); ?>> A. Safari</label><br>
        <label><input type="checkbox" name="q3[]" value="B" <?php echo is_checked("q3","B"); ?>> B. Microsoft Word</label><br>
        <label><input type="checkbox" name="q3[]" value="C" <?php echo is_checked("q3","C"); ?>> C. Firefox</label><br>
        <label><input type="checkbox" name="q3[]" value="D" <?php echo is_checked("q3","D"); ?>> D. Google Chrome</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>4.</strong> Which are characteristics of static web pages?</p>
        <label><input type="checkbox" name="q4[]" value="A" <?php echo is_checked("q4","A"); ?>> A. Display the same content at all times</label><br>
        <label><input type="checkbox" name="q4[]" value="B" <?php echo is_checked("q4","B"); ?>> B. Content changes dynamically</label><br>
        <label><input type="checkbox" name="q4[]" value="C" <?php echo is_checked("q4","C"); ?>> C. Written mostly in HTML</label><br>
        <label><input type="checkbox" name="q4[]" value="D" <?php echo is_checked("q4","D"); ?>> D. Requires server-side scripting</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>5.</strong> Which are characteristics of dynamic web pages?</p>
        <label><input type="checkbox" name="q5[]" value="A" <?php echo is_checked("q5","A"); ?>> A. Written in scripting languages like PHP or ASP</label><br>
        <label><input type="checkbox" name="q5[]" value="B" <?php echo is_checked("q5","B"); ?>> B. Always identical on every visit</label><br>
        <label><input type="checkbox" name="q5[]" value="C" <?php echo is_checked("q5","C"); ?>> C. Can access databases</label><br>
        <label><input type="checkbox" name="q5[]" value="D" <?php echo is_checked("q5","D"); ?>> D. Content may change on each load</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>6.</strong> Which statements describe HTML?</p>
        <label><input type="checkbox" name="q6[]" value="A" <?php echo is_checked("q6","A"); ?>> A. Used to create static web pages</label><br>
        <label><input type="checkbox" name="q6[]" value="B" <?php echo is_checked("q6","B"); ?>> B. A programming language for logic processing</label><br>
        <label><input type="checkbox" name="q6[]" value="C" <?php echo is_checked("q6","C"); ?>> C. Uses bracketed tags</label><br>
        <label><input type="checkbox" name="q6[]" value="D" <?php echo is_checked("q6","D"); ?>> D. Files end with .html or .htm</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>7.</strong> Identify the components of a website layout.</p>
        <label><input type="checkbox" name="q7[]" value="A" <?php echo is_checked("q7","A"); ?>> A. Header</label><br>
        <label><input type="checkbox" name="q7[]" value="B" <?php echo is_checked("q7","B"); ?>> B. Navigation</label><br>
        <label><input type="checkbox" name="q7[]" value="C" <?php echo is_checked("q7","C"); ?>> C. Primary content</label><br>
        <label><input type="checkbox" name="q7[]" value="D" <?php echo is_checked("q7","D"); ?>> D. Processor unit</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>8.</strong> Which are examples of protocols?</p>
        <label><input type="checkbox" name="q8[]" value="A" <?php echo is_checked("q8","A"); ?>> A. HTTP</label><br>
        <label><input type="checkbox" name="q8[]" value="B" <?php echo is_checked("q8","B"); ?>> B. SMTP</label><br>
        <label><input type="checkbox" name="q8[]" value="C" <?php echo is_checked("q8","C"); ?>> C. FTP</label><br>
        <label><input type="checkbox" name="q8[]" value="D" <?php echo is_checked("q8","D"); ?>> D. Java</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>9.</strong> Which are parts of the Web Design Process?</p>
        <label><input type="checkbox" name="q9[]" value="A" <?php echo is_checked("q9","A"); ?>> A. Goal Identification</label><br>
        <label><input type="checkbox" name="q9[]" value="B" <?php echo is_checked("q9","B"); ?>> B. Sitemap and Wireframe Creation</label><br>
        <label><input type="checkbox" name="q9[]" value="C" <?php echo is_checked("q9","C"); ?>> C. Visual Elements</label><br>
        <label><input type="checkbox" name="q9[]" value="D" <?php echo is_checked("q9","D"); ?>> D. Hardware Installation</label>
      </div>

      <div style="margin-bottom:10px;">
        <p><strong>10.</strong> Which tools are used for website creation?</p>
        <label><input type="checkbox" name="q10[]" value="A" <?php echo is_checked("q10","A"); ?>> A. IDE (Notepad++, VS Code)</label><br>
        <label><input type="checkbox" name="q10[]" value="B" <?php echo is_checked("q10","B"); ?>> B. Web browser</label><br>
        <label><input type="checkbox" name="q10[]" value="C" <?php echo is_checked("q10","C"); ?>> C. XAMPP</label><br>
        <label><input type="checkbox" name="q10[]" value="D" <?php echo is_checked("q10","D"); ?>> D. Anti-virus software</label>
      </div>

      <!-- Part II -->
      <h2 style="margin-top:20px;">Part II — Fill in the Blanks</h2>

      <?php
      function text_val($q) {
          return isset($_POST[$q]) ? htmlspecialchars($_POST[$q]) : "";
      }
      ?>

      <div style="margin-bottom:8px;">
        <p><strong>11.</strong> The Internet uses the standard __________ suite to connect billions of devices.</p>
        <input id="q11" name="q11" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q11'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>12.</strong> A private internal network of an organization is called an __________.</p>
        <input id="q12" name="q12" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q12'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>13.</strong> A __________ is a program or computer that responds to browser requests using HTTP.</p>
        <input id="q13" name="q13" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q13'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>14.</strong> A __________ identifies a web resource using a text-based string.</p>
        <input id="q14" name="q14" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q14'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>15.</strong> The protocol used by browsers and servers to communicate is called __________.</p>
        <input id="q15" name="q15" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q15'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>16.</strong> __________ is the markup language used to structure web pages.</p>
        <input id="q16" name="q16" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q16'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>17.</strong> The document displayed inside a web browser is called a __________.</p>
        <input id="q17" name="q17" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q17'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>18.</strong> XML stands for __________.</p>
        <input id="q18" name="q18" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q18'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>19.</strong> The stage where you ensure all pages work properly is called __________.</p>
        <input id="q19" name="q19" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q19'); ?>">
      </div>

      <div style="margin-bottom:8px;">
        <p><strong>20.</strong> The stage where you make the website available to the public is called __________.</p>
        <input id="q20" name="q20" type="text" style="width:60%; padding:4px;" value="<?php echo text_val('q20'); ?>">
      </div>

      <button type="submit" style="margin-top:15px; padding:8px 16px; border:none; border-radius:4px; background-color:#007bff; color:#ffffff; cursor:pointer;">
        Submit Exam
      </button>
    </form>

    <?php if ($submitted && !$error): ?>
      <div style="margin-top:20px; padding:10px; border:1px solid #ddd; border-radius:6px; background-color:#fafafa;">
        <h3 style="margin:0 0 10px 0;">Your Score</h3>
        <p style="margin:0 0 5px 0;"><strong>Score:</strong> <?php echo $score; ?> / <?php echo $totalItems; ?></p>
        <p style="margin:0 0 5px 0;"><strong>Percentage:</strong> <?php echo number_format($percent, 2); ?>%</p>
        <p style="margin:0 0 10px 0;"><strong>Attempt #:</strong> <?php echo $attemptNo; ?></p>
        <details style="margin-top:10px;">
          <summary style="cursor:pointer;">View Feedback</summary>
          <pre style="white-space:pre-wrap; font-size:13px; margin-top:5px;"><?php echo implode("\n", $feedback); ?></pre>
        </details>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
