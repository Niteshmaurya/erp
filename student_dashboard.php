<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit();
}

include 'db.php'; // Database connection

$student_id = $_SESSION['user_id'];

// Fetch student's semester
$sql_semester = "SELECT semester FROM students WHERE user_id = ?";
$stmt_semester = $conn->prepare($sql_semester);
$stmt_semester->bind_param("i", $student_id);
$stmt_semester->execute();
$result_semester = $stmt_semester->get_result();
$row_semester = $result_semester->fetch_assoc();

if (!$row_semester) {
    die("Student record not found or semester information missing");
}
$current_semester = $row_semester['semester'];

// Define semester-wise credit limits (with minimum of 20 credits)
$semester_credit_limits = [
    1 => 24, 2 => 24, 3 => 24, 4 => 24,
    5 => 24, 6 => 24, 7 => 24, 8 => 24
];

$current_semester_limit = $semester_credit_limits[$current_semester] ?? 24;
$min_credits_required = 20; // Minimum credits required

// Calculate current semester's used credits (only for current semester)
$sql_current_credits = "SELECT SUM(subjects.subject_credits + subjects.lab_credits) AS total_credits
                       FROM student_subjects
                       JOIN subjects ON student_subjects.subject_id = subjects.id
                       WHERE student_subjects.student_id = ? AND student_subjects.semester = ?";
$stmt_current_credits = $conn->prepare($sql_current_credits);
$stmt_current_credits->bind_param("ii", $student_id, $current_semester);
$stmt_current_credits->execute();
$result_current_credits = $stmt_current_credits->get_result();
$row_current_credits = $result_current_credits->fetch_assoc();
$current_credits_used = $row_current_credits['total_credits'] ?? 0;

// Check if the student has passed the current semester
$sql_passed_semester = "SELECT COUNT(*) as total_subjects,
                        SUM(CASE WHEN 
                            (sm.marks >= 40 OR (sm.marks IS NULL AND sm.lab_marks >= 40)) 
                            THEN 1 ELSE 0 END) as passed_subjects
                        FROM student_subjects ss
                        LEFT JOIN (
                            SELECT student_id, subject_id, marks, lab_marks
                            FROM student_marks
                            WHERE (student_id, subject_id, id) IN (
                                SELECT student_id, subject_id, MAX(id)
                                FROM student_marks
                                GROUP BY student_id, subject_id, semester
                            )
                        ) sm ON ss.student_id = sm.student_id AND ss.subject_id = sm.subject_id
                        WHERE ss.student_id = ? AND ss.semester = ?";
                        
$stmt_passed_semester = $conn->prepare($sql_passed_semester);
$stmt_passed_semester->bind_param("ii", $student_id, $current_semester);
$stmt_passed_semester->execute();
$result_passed_semester = $stmt_passed_semester->get_result();
$row_passed_semester = $result_passed_semester->fetch_assoc();

$has_passed_semester = false;
if ($row_passed_semester['total_subjects'] > 0) {
    $pass_percentage = ($row_passed_semester['passed_subjects'] / $row_passed_semester['total_subjects']) * 100;
    $has_passed_semester = ($pass_percentage > 50);
}

// Handle subject selection form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['subjects'])) {
        $selected_subjects = $_POST['subjects'];
        
        // Calculate total credits of selected subjects
        $selected_credits = 0;
        $subject_ids = implode(',', array_map('intval', $selected_subjects));
        $sql_credits = "SELECT SUM(subject_credits + lab_credits) AS total FROM subjects WHERE id IN ($subject_ids)";
        $result_credits = $conn->query($sql_credits);
        $row_credits = $result_credits->fetch_assoc();
        $selected_credits = $row_credits['total'] ?? 0;
        
        // Calculate total credits after adding new subjects
        $total_credits_after_selection = $current_credits_used + $selected_credits;
        
        // Check if selection is below minimum credits
        if ($total_credits_after_selection < $min_credits_required) {
            $needed_credits = $min_credits_required - $current_credits_used;
            echo "<script>alert('You must select at least $needed_credits more credits to reach the minimum requirement of $min_credits_required credits.');</script>";
        }
        // Check if selection exceeds credit limit
        elseif ($total_credits_after_selection > $current_semester_limit) {
            $remaining_credits = $current_semester_limit - $current_credits_used;
            echo "<script>alert('You can only select up to $remaining_credits more credits this semester.');</script>";
        } else {
            $all_subjects_added = true;
            $error_messages = [];

            // Check for dependent subjects
            foreach ($selected_subjects as $subject_id) {
                // Get prerequisite information
                $sql_prereq = "SELECT prerequisite_id FROM subjects WHERE id = ?";
                $stmt_prereq = $conn->prepare($sql_prereq);
                $stmt_prereq->bind_param("i", $subject_id);
                $stmt_prereq->execute();
                $result_prereq = $stmt_prereq->get_result();
                $row_prereq = $result_prereq->fetch_assoc();
                $prerequisite_id = $row_prereq['prerequisite_id'];

                // Check if this subject is a prerequisite for any other selected subject
                $sql_is_prereq = "SELECT id, subject_name FROM subjects WHERE prerequisite_id = ? AND id IN ($subject_ids)";
                $stmt_is_prereq = $conn->prepare($sql_is_prereq);
                $stmt_is_prereq->bind_param("i", $subject_id);
                $stmt_is_prereq->execute();
                $result_is_prereq = $stmt_is_prereq->get_result();
                
                if ($result_is_prereq->num_rows > 0) {
                    $dependent_subjects = [];
                    while ($row = $result_is_prereq->fetch_assoc()) {
                        $dependent_subjects[] = $row['subject_name'];
                    }
                    $error_messages[] = "You cannot select " . implode(' and ', $dependent_subjects) . " together with its prerequisite in the same semester.";
                    $all_subjects_added = false;
                    break;
                }

                if ($prerequisite_id) {
                    // Check if the student has completed the prerequisite in any previous semester
                    $sql_completed = "SELECT COUNT(*) AS completed FROM student_subjects 
                                    WHERE student_id = ? AND subject_id = ? AND semester < ?";
                    $stmt_completed = $conn->prepare($sql_completed);
                    $stmt_completed->bind_param("iii", $student_id, $prerequisite_id, $current_semester);
                    $stmt_completed->execute();
                    $result_completed = $stmt_completed->get_result();
                    $row_completed = $result_completed->fetch_assoc();

                    if ($row_completed['completed'] == 0) {
                        // Check if the prerequisite is in the current selection
                        if (in_array($prerequisite_id, $selected_subjects)) {
                            $sql_prereq_name = "SELECT subject_name FROM subjects WHERE id = ?";
                            $stmt_prereq_name = $conn->prepare($sql_prereq_name);
                            $stmt_prereq_name->bind_param("i", $prerequisite_id);
                            $stmt_prereq_name->execute();
                            $result_prereq_name = $stmt_prereq_name->get_result();
                            $row_prereq_name = $result_prereq_name->fetch_assoc();
                            $prereq_subject_name = $row_prereq_name['subject_name'];
                            
                            $sql_subject_name = "SELECT subject_name FROM subjects WHERE id = ?";
                            $stmt_subject_name = $conn->prepare($sql_subject_name);
                            $stmt_subject_name->bind_param("i", $subject_id);
                            $stmt_subject_name->execute();
                            $result_subject_name = $stmt_subject_name->get_result();
                            $row_subject_name = $result_subject_name->fetch_assoc();
                            $subject_name = $row_subject_name['subject_name'];
                            
                            $error_messages[] = "You cannot select $subject_name and its prerequisite $prereq_subject_name in the same semester.";
                            $all_subjects_added = false;
                            break;
                        } else {
                            // Prerequisite not completed and not in current selection
                            $sql_prereq_name = "SELECT subject_name FROM subjects WHERE id = ?";
                            $stmt_prereq_name = $conn->prepare($sql_prereq_name);
                            $stmt_prereq_name->bind_param("i", $prerequisite_id);
                            $stmt_prereq_name->execute();
                            $result_prereq_name = $stmt_prereq_name->get_result();
                            $row_prereq_name = $result_prereq_name->fetch_assoc();
                            $prereq_subject_name = $row_prereq_name['subject_name'];
                            
                            $error_messages[] = "You must first complete the prerequisite subject: $prereq_subject_name in a previous semester.";
                            $all_subjects_added = false;
                            break;
                        }
                    }
                }
            }

            if ($all_subjects_added) {
                // All checks passed, insert the subjects with current semester
                foreach ($selected_subjects as $subject_id) {
                    $sql_insert = "INSERT INTO student_subjects (student_id, subject_id, semester) VALUES (?, ?, ?)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->bind_param("iii", $student_id, $subject_id, $current_semester);
                    $stmt_insert->execute();
                }
                echo "<script>alert('Subjects added successfully!'); window.location.href='student_dashboard.php';</script>";
            } else {
                // Show all error messages
                echo "<script>alert('" . implode("\\n", $error_messages) . "');</script>";
            }
        }
    } elseif (isset($_POST['delete_subjects'])) {
        // Handle subject deletion
        if (isset($_POST['selected_subjects_to_delete'])) {
            $subjects_to_delete = $_POST['selected_subjects_to_delete'];
            
            // Calculate credits of subjects to be deleted
            $deleted_credits = 0;
            $subject_ids = implode(',', array_map('intval', $subjects_to_delete));
            $sql_credits = "SELECT SUM(subject_credits + lab_credits) AS total FROM subjects WHERE id IN ($subject_ids)";
            $result_credits = $conn->query($sql_credits);
            $row_credits = $result_credits->fetch_assoc();
            $deleted_credits = $row_credits['total'] ?? 0;
            
            // Check if deletion would go below minimum credits
            if (($current_credits_used - $deleted_credits) < $min_credits_required) {
                $remaining_after_deletion = $current_credits_used - $deleted_credits;
                $needed_credits = $min_credits_required - $remaining_after_deletion;
                echo "<script>alert('You cannot delete these subjects as it would leave you with only $remaining_after_deletion credits. You need to maintain at least $min_credits_required credits. Select additional subjects first if you want to remove these.');</script>";
            } else {
                foreach ($subjects_to_delete as $subject_id) {
                    $sql_delete = "DELETE FROM student_subjects WHERE student_id = ? AND subject_id = ? AND semester = ?";
                    $stmt_delete = $conn->prepare($sql_delete);
                    $stmt_delete->bind_param("iii", $student_id, $subject_id, $current_semester);
                    $stmt_delete->execute();
                }
                
                echo "<script>alert('Selected subjects deleted successfully!'); window.location.href='student_dashboard.php';</script>";
            }
        } else {
            echo "<script>alert('No subjects selected for deletion.');</script>";
        }
    }
}

// Fetch available subjects (excluding those already chosen in current semester)
$sql = "SELECT s1.* FROM subjects s1 
        WHERE s1.id NOT IN (
            SELECT subject_id FROM student_subjects 
            WHERE student_id = ? AND semester = ?
        )
        AND NOT EXISTS (
            SELECT 1 FROM subjects s2 
            WHERE s2.prerequisite_id = s1.id 
            AND s2.id IN (
                SELECT subject_id FROM student_subjects 
                WHERE student_id = ? AND semester = ?
            )
        )";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $student_id, $current_semester, $student_id, $current_semester);
$stmt->execute();
$result_available = $stmt->get_result();

// Fetch already chosen subjects for current semester
if ($has_passed_semester) {
    // Query to get subjects the student has failed in current semester
    $sql_chosen = "SELECT s.* FROM subjects s
                  JOIN student_subjects ss ON s.id = ss.subject_id
                  LEFT JOIN (
                      SELECT student_id, subject_id, marks, lab_marks 
                      FROM student_marks
                      WHERE (student_id, subject_id, semester, id) IN (
                          SELECT student_id, subject_id, ?, MAX(id)
                          FROM student_marks
                          WHERE semester = ?
                          GROUP BY student_id, subject_id
                      )
                  ) sm ON ss.student_id = sm.student_id AND s.id = sm.subject_id
                  WHERE ss.student_id = ? AND ss.semester = ? 
                  AND (sm.marks < 40 OR (sm.marks IS NULL AND sm.lab_marks < 40))";
    $stmt_chosen = $conn->prepare($sql_chosen);
    $stmt_chosen->bind_param("iiii", $current_semester, $current_semester, $student_id, $current_semester);
} else {
    // Normal query for all chosen subjects in current semester
    $sql_chosen = "SELECT subjects.* FROM subjects 
                  JOIN student_subjects ON subjects.id = student_subjects.subject_id 
                  WHERE student_subjects.student_id = ? AND student_subjects.semester = ?";
    $stmt_chosen = $conn->prepare($sql_chosen);
    $stmt_chosen->bind_param("ii", $student_id, $current_semester);
}

$stmt_chosen->execute();
$result_chosen = $stmt_chosen->get_result();
$chosen_count = $result_chosen->num_rows;

// Calculate remaining credits
$remaining_credits = $current_semester_limit - $current_credits_used;

// Get all subjects with their prerequisites for JavaScript validation
$sql_all_subjects = "SELECT id, subject_name, prerequisite_id, (subject_credits + lab_credits) as total_credits FROM subjects";
$result_all_subjects = $conn->query($sql_all_subjects);
$subjects_info = [];
while ($row = $result_all_subjects->fetch_assoc()) {
    $subjects_info[$row['id']] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .subject-table {
            margin-bottom: 30px;
        }
        .credits-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .min-credits-warning {
            color: #dc3545;
            font-weight: bold;
        }
        .semester-passed-alert {
            background-color: #d1e7dd;
            color: #0f5132;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: bold;
        }
    </style>
    <script>
    const subjectsInfo = <?php echo json_encode($subjects_info); ?>;
    const remainingCredits = <?php echo $remaining_credits; ?>;
    const minCreditsRequired = <?php echo $min_credits_required; ?>;
    const currentCreditsUsed = <?php echo $current_credits_used; ?>;
    
    function validateSubjectSelection() {
        const checkboxes = document.querySelectorAll('input[name="subjects[]"]:checked');
        let selectedCredits = 0;
        const selectedIds = [];
        
        checkboxes.forEach(cb => {
            const subjectId = parseInt(cb.value);
            selectedIds.push(subjectId);
            selectedCredits += parseInt(subjectsInfo[subjectId].total_credits);
        });
        
        if (selectedIds.length === 0) {
            alert('Please select at least one subject.');
            return false;
        }
        
        const totalCreditsAfterSelection = currentCreditsUsed + selectedCredits;
        
        // Check if selection is below minimum credits
        if (totalCreditsAfterSelection < minCreditsRequired) {
            const neededCredits = minCreditsRequired - currentCreditsUsed;
            alert(`You must select at least ${neededCredits} more credits to reach the minimum requirement of ${minCreditsRequired} credits.`);
            return false;
        }
        
        // Check if selection exceeds credit limit
        if (totalCreditsAfterSelection > (currentCreditsUsed + remainingCredits)) {
            alert(`You can only select up to ${remainingCredits} more credits this semester. You're trying to select ${selectedCredits} credits.`);
            return false;
        }
        
        // Check for dependent subjects
        for (let i = 0; i < selectedIds.length; i++) {
            const subjectId = selectedIds[i];
            const subject = subjectsInfo[subjectId];
            
            for (let j = 0; j < selectedIds.length; j++) {
                if (i === j) continue;
                
                const otherSubject = subjectsInfo[selectedIds[j]];
                if (otherSubject.prerequisite_id == subjectId) {
                    alert(`You cannot select ${subject.subject_name} and ${otherSubject.subject_name} together in the same semester.`);
                    return false;
                }
            }
            
            if (subject.prerequisite_id && selectedIds.includes(subject.prerequisite_id)) {
                const prereqSubject = subjectsInfo[subject.prerequisite_id];
                alert(`You cannot select ${subject.subject_name} and its prerequisite ${prereqSubject.subject_name} together in the same semester.`);
                return false;
            }
        }
        
        return true;
    }
    
    function confirmDelete() {
        const checkboxes = document.querySelectorAll('input[name="selected_subjects_to_delete[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one subject to delete.');
            return false;
        }
        
        // Calculate credits of subjects to be deleted
        let deletedCredits = 0;
        checkboxes.forEach(cb => {
            const subjectId = parseInt(cb.value);
            deletedCredits += parseInt(subjectsInfo[subjectId].total_credits);
        });
        
        // Check if deletion would go below minimum credits
        if ((currentCreditsUsed - deletedCredits) < minCreditsRequired) {
            const remainingAfterDeletion = currentCreditsUsed - deletedCredits;
            const neededCredits = minCreditsRequired - remainingAfterDeletion;
            alert(`You cannot delete these subjects as it would leave you with only ${remainingAfterDeletion} credits. You need to maintain at least ${minCreditsRequired} credits. Select additional subjects first if you want to remove these.`);
            return false;
        }
        
        return confirm('Are you sure you want to delete the selected subjects?');
    }
    </script>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Welcome, <?php echo $_SESSION['name'] ?? 'Student'; ?></h2>
            <div>
                <a href="login.php" class="btn btn-danger">Logout</a>
                <a href="viewMarks.php" class="btn btn-primary">View Results</a>
            </div>
        </div>

        <?php if ($has_passed_semester): ?>
        <div class="semester-passed-alert">
            <i class="bi bi-check-circle-fill"></i> You have passed Semester <?php echo $current_semester; ?>! Only failed subjects and subjects you haven't chosen yet are shown below.
        </div>
        <?php endif; ?>

        <div class="credits-info">
            <p class="mb-1"><strong>Current Semester:</strong> <?php echo $current_semester; ?></p>
            <p class="mb-1"><strong>Credits Used:</strong> <?php echo $current_credits_used; ?> of <?php echo $current_semester_limit; ?></p>
            <p class="mb-1"><strong>Remaining Credits:</strong> <?php echo $remaining_credits; ?></p>
            <p class="mb-0"><strong>Minimum Credits Required:</strong> <?php echo $min_credits_required; ?></p>
            <?php if ($current_credits_used < $min_credits_required): ?>
                <p class="min-credits-warning mb-0">Warning: You need to select at least <?php echo ($min_credits_required - $current_credits_used); ?> more credits to meet the minimum requirement.</p>
            <?php endif; ?>
        </div>

        <!-- Available Subjects -->
        <div class="subject-table">
            <h3>Available Subjects for Semester <?php echo $current_semester; ?></h3>
            <form method="POST" onsubmit="return validateSubjectSelection()">
                <table class="table table-bordered table-hover mt-3">
                    <thead class="table-dark">
                        <tr>
                            <th width="5%">Select</th>
                            <th>Subject Name</th>
                            <th>Branch</th>
                            <th>Credits (Theory + Lab)</th>
                            <th>Lab</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_available->num_rows > 0): ?>
                            <?php while ($row = $result_available->fetch_assoc()): ?>
                                <?php
                                $total_credits = $row['subject_credits'] + $row['lab_credits'];
                                $prereq_text = '';
                                if ($row["prerequisite_id"]) {
                                    $sql_prereq_name = "SELECT subject_name FROM subjects WHERE id = ?";
                                    $stmt_prereq_name = $conn->prepare($sql_prereq_name);
                                    $stmt_prereq_name->bind_param("i", $row["prerequisite_id"]);
                                    $stmt_prereq_name->execute();
                                    $result_prereq_name = $stmt_prereq_name->get_result();
                                    if ($result_prereq_name->num_rows > 0) {
                                        $prereq_row = $result_prereq_name->fetch_assoc();
                                        $prereq_text = "<br><small class='text-muted'>Prerequisite: " . $prereq_row['subject_name'] . "</small>";
                                    }
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="subjects[]" value="<?php echo $row["id"]; ?>" data-credits="<?php echo $total_credits; ?>"></td>
                                    <td><?php echo $row["subject_name"] . $prereq_text; ?></td>
                                    <td><?php echo $row["branch"]; ?></td>
                                    <td><?php echo $row["subject_credits"] . " + " . $row["lab_credits"] . " = " . $total_credits; ?></td>
                                    <td><?php echo ($row["has_lab"] ? 'Yes' : 'No'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No subjects available for selection</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($remaining_credits > 0 && $result_available->num_rows > 0): ?>
                    <button type="submit" class="btn btn-primary">Choose Selected Subjects</button>
                <?php elseif ($remaining_credits <= 0): ?>
                    <p class="text-danger">You have reached the maximum credits allowed for this semester.</p>
                <?php endif; ?>
            </form>
        </div>

        <!-- Chosen Subjects (Failed Subjects if semester is passed) -->
        <div class="subject-table">
            <h3><?php echo $has_passed_semester ? 'Failed Subjects in Semester ' . $current_semester : 'Your Selected Subjects for Semester ' . $current_semester; ?></h3>
            <form method="POST" onsubmit="return confirmDelete()">
                <table class="table table-bordered table-hover mt-3">
                    <thead class="table-dark">
                        <tr>
                            <th width="5%">Select</th>
                            <th>Subject Name</th>
                            <th>Branch</th>
                            <th>Credits (Theory + Lab)</th>
                            <th>Lab</th>
                            <?php if ($has_passed_semester): ?>
                            <th>Marks</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_chosen->num_rows > 0): ?>
                            <?php while ($row = $result_chosen->fetch_assoc()): ?>
                                <?php 
                                $total_credits = $row['subject_credits'] + $row['lab_credits']; 
                                // Get marks if semester is passed
                                $marks_info = '';
                                if ($has_passed_semester) {
                                    $sql_marks = "SELECT marks, lab_marks FROM student_marks 
                                                 WHERE student_id = ? AND subject_id = ? AND semester = ?
                                                 ORDER BY id DESC LIMIT 1";
                                    $stmt_marks = $conn->prepare($sql_marks);
                                    $stmt_marks->bind_param("iii", $student_id, $row['id'], $current_semester);
                                    $stmt_marks->execute();
                                    $result_marks = $stmt_marks->get_result();
                                    $row_marks = $result_marks->fetch_assoc();
                                    
                                    if ($row_marks) {
                                        $theory_marks = $row_marks['marks'] !== null ? $row_marks['marks'] : 'N/A';
                                        $lab_marks = $row_marks['lab_marks'] !== null ? $row_marks['lab_marks'] : 'N/A';
                                        
                                        if ($row['has_lab']) {
                                            $marks_info = "Theory: $theory_marks / Lab: $lab_marks";
                                        } else {
                                            $marks_info = $theory_marks;
                                        }
                                    } else {
                                        $marks_info = 'Not available';
                                    }
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_subjects_to_delete[]" value="<?php echo $row["id"]; ?>"></td>
                                    <td><?php echo $row["subject_name"]; ?></td>
                                    <td><?php echo $row["branch"]; ?></td>
                                    <td><?php echo $row["subject_credits"] . " + " . $row["lab_credits"] . " = " . $total_credits; ?></td>
                                    <td><?php echo ($row["has_lab"] ? 'Yes' : 'No'); ?></td>
                                    <?php if ($has_passed_semester): ?>
                                    <td><?php echo $marks_info; ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $has_passed_semester ? '6' : '5'; ?>" class="text-center">
                                    <?php echo $has_passed_semester ? 'No failed subjects' : 'No subjects selected yet'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($result_chosen->num_rows > 0): ?>
                    <button type="submit" name="delete_subjects" class="btn btn-danger">Delete Selected Subjects</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>