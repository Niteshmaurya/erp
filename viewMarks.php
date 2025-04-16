<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit();
}

include 'db.php'; // Database connection

// Get current student's semester
$sql_semester = "SELECT semester FROM students WHERE user_id = ?";
$stmt_semester = $conn->prepare($sql_semester);
$stmt_semester->bind_param("i", $_SESSION['user_id']);
$stmt_semester->execute();
$result_semester = $stmt_semester->get_result();
$student_semester = $result_semester->fetch_assoc()['semester'];

// Fetch all semesters the student has attended (1 to current semester)
$semesters = range(1, $student_semester);

// Get semester-wise marks
$all_semester_marks = [];
$total_credits_earned = 0;
$total_weighted_points = 0;

// Track subjects that have been counted for credits and possible credits
$subjects_counted = [];
$subjects_possible_credits = [];

foreach ($semesters as $semester) {
    $sql_marks = "SELECT 
                    subjects.id as subject_id,
                    subjects.subject_name, 
                    subjects.branch, 
                    subjects.subject_credits, 
                    subjects.lab_credits,
                    subjects.has_lab,
                    student_marks.marks as theory_marks,
                    student_marks.lab_marks,
                    student_subjects.semester
                  FROM student_marks
                  JOIN subjects ON student_marks.subject_id = subjects.id
                  JOIN student_subjects ON student_marks.student_id = student_subjects.student_id 
                                      AND student_marks.subject_id = student_subjects.subject_id
                                      AND student_marks.semester = student_subjects.semester
                  WHERE student_marks.student_id = ? AND student_subjects.semester = ?
                  ORDER BY subjects.id";
    $stmt_marks = $conn->prepare($sql_marks);
    $stmt_marks->bind_param("ii", $_SESSION['user_id'], $semester);
    $stmt_marks->execute();
    $result_marks = $stmt_marks->get_result();

    $semester_marks = [];
    $semester_credits_earned = 0;
    $semester_weighted_points = 0;
    $semester_possible_credits = 0;

    while ($row = $result_marks->fetch_assoc()) {
        $subject_data = $row;
        $subject_id = $row['subject_id'];
        
        // Calculate total marks (theory + lab if exists)
        $theory_marks = $row['theory_marks'];
        $lab_marks = $row['lab_marks'] ?? 0;
        $has_lab = $row['has_lab'];
        
        // Calculate percentage for theory (out of 100)
        $theory_percentage = $theory_marks;
        
        // Calculate percentage for lab (out of 50, scaled to 100 for calculation)
        $lab_percentage = $has_lab ? ($lab_marks * 2) : 0; // Scale 50 to 100
        
        // Combined percentage (weighted average if lab exists)
        if ($has_lab) {
            // Assuming theory is 70% and lab is 30% of total marks
            $combined_percentage = ($theory_percentage * 0.7) + ($lab_percentage * 0.3);
        } else {
            $combined_percentage = $theory_percentage;
        }
        
        // Determine if passed (minimum 40% in both components)
        $theory_passed = ($theory_percentage >= 40);
        $lab_passed = $has_lab ? ($lab_marks >= 20) : true; // 20 out of 50 is 40%
        $subject_passed = $theory_passed && $lab_passed;
        
        // Calculate subject credits
        $subject_credits = $row['subject_credits'] + ($has_lab ? $row['lab_credits'] : 0);
        
        // Track possible credits (count each subject only once)
        if (!isset($subjects_possible_credits[$subject_id])) {
            $subjects_possible_credits[$subject_id] = $subject_credits;
        }
        
        // Calculate credits earned (only if passed and not already counted in previous semester)
        $credits_earned = 0;
        
        if ($subject_passed) {
            if (!isset($subjects_counted[$subject_id])) {
                // First time passing this subject - count the credits
                $credits_earned = $subject_credits;
                $subjects_counted[$subject_id] = true;
            } else {
                // Already passed this subject in previous semester - don't count credits again
                $credits_earned = 0;
            }
        } else {
            // Failed this attempt - don't count credits
            $credits_earned = 0;
        }
        
        // Get grade points based on combined percentage (out of 10)
        if ($subject_passed) {
            if ($combined_percentage >= 90) {
                $grade_points = 10.0;
            } elseif ($combined_percentage >= 80) {
                $grade_points = 9.0;
            } elseif ($combined_percentage >= 70) {
                $grade_points = 8.0;
            } elseif ($combined_percentage >= 60) {
                $grade_points = 7.0;
            } elseif ($combined_percentage >= 50) {
                $grade_points = 6.0;
            } else {
                $grade_points = 5.0; // Minimum passing grade
            }
        } else {
            $grade_points = 0.0;
        }

        // Calculate semester weighted points (using subject credits, not earned credits)
        $semester_weighted_points += ($grade_points * $subject_credits);
        
        // Calculate semester credits earned (only for passed subjects not counted before)
        $semester_credits_earned += $credits_earned;
        
        // Calculate semester possible credits (for display purposes only)
        $semester_possible_credits += $subject_credits;
        
        // Add to global totals
        $total_weighted_points += ($grade_points * $subject_credits);
        $total_credits_earned += $credits_earned;
        
        // Add additional calculated fields to the subject data
        $subject_data['theory_passed'] = $theory_passed;
        $subject_data['lab_passed'] = $lab_passed;
        $subject_data['subject_passed'] = $subject_passed;
        $subject_data['credits_earned'] = $credits_earned;
        $subject_data['subject_credits_total'] = $subject_credits;
        $subject_data['combined_percentage'] = $combined_percentage;
        $subject_data['grade_points'] = $grade_points;
        
        $semester_marks[] = $subject_data;
    }

    // Calculate SGPA for this semester
    if ($semester_possible_credits > 0) {
        $sgpa = $semester_weighted_points / $semester_possible_credits;
    } else {
        $sgpa = 0;
    }

    $all_semester_marks[$semester] = [
        'marks' => $semester_marks,
        'credits_earned' => $semester_credits_earned,
        'possible_credits' => $semester_possible_credits,
        'sgpa' => $sgpa
    ];
}

// Calculate total possible credits (counting each subject only once)
$total_possible_credits = array_sum($subjects_possible_credits);

// Calculate CGPA (only considering passed subjects)
if ($total_possible_credits > 0) {
    $cgpa = $total_weighted_points / $total_possible_credits;
} else {
    $cgpa = 0;
}

// Update CGPA in the database
$sql_update_cgpa = "UPDATE students SET cgpa = ? WHERE user_id = ?";
$stmt_update_cgpa = $conn->prepare($sql_update_cgpa);
$stmt_update_cgpa->bind_param("di", $cgpa, $_SESSION['user_id']);
$stmt_update_cgpa->execute();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device- width, initial-scale=1.0">
    <title>Student Marks</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .passed { color: green; }
        .failed { color: red; }
        .progress-bar-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin-top: 5px;
        }
        .progress-bar {
            height: 20px;
            border-radius: 5px;
            background-color: #4CAF50;
            text-align: center;
            line-height: 20px;
            color: white;
        }
        .semester-section {
            margin-bottom: 40px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
        }
        .semester-header {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2>Hi, <?php echo htmlspecialchars($_SESSION['name']); ?> (Student)</h2>
        <a href="login.php" class="btn btn-danger">Logout</a>
        <a href="student_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>

        <div class="card mt-4">
            <div class="card-header">
                <h4>Academic Summary</h4>
            </div>
            <div class="card-body">
                <p><strong>Current Semester:</strong> <?php echo $student_semester; ?></p>
                <p><strong>Total Credits Earned:</strong> <?php echo $total_credits_earned; ?> out of <?php echo $total_possible_credits; ?></p>
                <p><strong>Your CGPA:</strong> <?php echo number_format($cgpa, 2); ?> (out of 10)</p>
                <p class="text-muted">* CGPA is calculated only for passed subjects with minimum 40% marks in each component</p>
                <p class="text-muted">* Credits for a subject are counted only once, in the semester it was first passed</p>
            </div>
        </div>

        <h3 class="mt-4">Semester-wise Results</h3>
        
        <ul class="nav nav-tabs mt-3" id="semesterTabs" role="tablist">
            <?php foreach ($semesters as $semester): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $semester == 1 ? 'active' : ''; ?>" 
                            id="semester-<?php echo $semester; ?>-tab" 
                            data-bs-toggle="tab" 
                            data-bs-target="#semester-<?php echo $semester; ?>" 
                            type="button" 
                            role="tab">
                        Semester <?php echo $semester; ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        
        <div class="tab-content mt-3" id="semesterTabsContent">
            <?php foreach ($semesters as $semester): 
                $semester_data = $all_semester_marks[$semester] ?? ['marks' => [], 'credits_earned' => 0, 'possible_credits' => 0, 'sgpa' => 0];
            ?>
                <div class="tab-pane fade <?php echo $semester == 1 ? 'show active' : ''; ?>" 
                     id="semester-<?php echo $semester; ?>" 
                     role="tabpanel">
                     
                    <div class="semester-section">
                        <div class="semester-header d-flex justify-content-between align-items-center">
                            <h4>Semester <?php echo $semester; ?> Results</h4>
                            <div>
                                <strong>SGPA:</strong> <?php echo number_format($semester_data['sgpa'], 2); ?> (out of 10) | 
                                <strong>Credits:</strong> <?php echo $semester_data['credits_earned']; ?>/<?php echo $semester_data['possible_credits']; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($semester_data['marks'])): ?>
                            <table class="table table-bordered mt-3">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Subject Name</th>
                                        <th>Theory Marks<br>(Out of 100)</th>
                                        <th>Lab Marks<br>(Out of 50)</th>
                                        <th>Total Credits<br>(Earned/Possible)</th>
                                        <th>Status</th>
                                        <th>Grade Points<br>(Out of 10)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($semester_data['marks'] as $mark): 
                                        $has_lab = $mark['has_lab'];
                                    ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($mark['subject_name']); ?><br>
                                                <small><?php echo htmlspecialchars($mark['branch']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($mark['theory_marks']); ?>
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar" style="width: <?php echo $mark['theory_marks']; ?>%">
                                                        <?php echo $mark['theory_marks']; ?>%
                                                    </div>
                                                </div>
                                                <span class="<?php echo $mark['theory_passed'] ? 'passed' : 'failed'; ?>">
                                                    <?php echo $mark['theory_passed'] ? 'Passed' : 'Failed'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($has_lab): 
                                                    echo htmlspecialchars($mark['lab_marks'] ?? 'N/A'); ?>
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar" style="width: <?php echo ($mark['lab_marks'] / 50 * 100); ?>%">
                                                            <?php echo round($mark['lab_marks'] / 50 * 100); ?>%
                                                        </div>
                                                    </div>
                                                    <span class="<?php echo $mark['lab_passed'] ? 'passed' : 'failed'; ?>">
                                                        <?php echo $mark['lab_passed'] ? 'Passed' : 'Failed'; ?>
                                                    </span>
                                                <?php else: 
                                                    echo 'N/A'; 
                                                endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $mark['credits_earned']; ?> / 
                                                <?php echo $mark['subject_credits_total']; ?>
                                            </td>
                                            <td class="<?php echo $mark['subject_passed'] ? 'passed' : 'failed'; ?>">
                                                <?php echo $mark['subject_passed'] ? 'Passed' : 'Failed'; ?>
                                            </td>
                                            <td>
                                                <?php echo number_format($mark['grade_points'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-info mt-3">
                                No marks available for Semester <?php echo $semester; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>    
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>