<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'exam_cell') {
    header("Location: login.php");
    exit();
}

include 'db.php'; // Database connection

$message = '';
$error = '';

// Function to check if all subjects for a student are verified
function areAllSubjectsVerified($conn, $student_id) {
    // Get all subjects for the student
    $sql = "SELECT ss.subject_id 
            FROM student_subjects ss
            WHERE ss.student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $all_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $all_subjects[] = $row['subject_id'];
    }
    
    if (empty($all_subjects)) {
        return false; // No subjects assigned
    }
    
    // Check verification status for each subject
    foreach ($all_subjects as $subject_id) {
        $sql_verified = "SELECT is_verified FROM exam_cell_entries 
                        WHERE student_id = ? AND subject_id = ? AND is_verified = 1";
        $stmt_verified = $conn->prepare($sql_verified);
        $stmt_verified->bind_param("ii", $student_id, $subject_id);
        $stmt_verified->execute();
        $result_verified = $stmt_verified->get_result();
        
        if ($result_verified->num_rows === 0) {
            return false; // At least one subject not verified
        }
    }
    
    return true; // All subjects verified
}

// Function to increment student's semester
function incrementStudentSemester($conn, $student_id) {
    // Get current semester
    $sql = "SELECT semester FROM students WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_semester = $result->fetch_assoc()['semester'];
        $new_semester = $current_semester + 1;
        
        // Update semester
        $update_sql = "UPDATE students SET semester = ? WHERE user_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $new_semester, $student_id);
        return $update_stmt->execute();
    }
    
    return false;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['student_id'], $_POST['subject_id'], $_POST['theory_marks'])) {
        $student_id = (int)$_POST['student_id'];
        $subject_id = (int)$_POST['subject_id'];
        $theory_marks = (int)$_POST['theory_marks'];
        $lab_marks = isset($_POST['lab_marks']) ? (int)$_POST['lab_marks'] : null;

        // Validate marks
        $valid = true;
        if ($theory_marks < 0 || $theory_marks > 100) {
            $error = "Theory marks must be between 0 and 100";
            $valid = false;
        }
        if ($lab_marks !== null && ($lab_marks < 0 || $lab_marks > 50)) {
            $error = "Lab marks must be between 0 and 50";
            $valid = false;
        }

        if ($valid) {
            // Get faculty-entered marks for comparison
            $sql = "SELECT marks, lab_marks FROM student_marks 
                    WHERE student_id = ? AND subject_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $student_id, $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $faculty_marks = $result->fetch_assoc();
                
                // Compare marks
                if ($faculty_marks['marks'] == $theory_marks && 
                    ($lab_marks === null || $faculty_marks['lab_marks'] == $lab_marks)) {
                    // Marks match - insert verified entry
                    $sql_insert = "INSERT INTO exam_cell_entries 
                                  (student_id, subject_id, entered_marks, entered_lab_marks, is_verified) 
                                  VALUES (?, ?, ?, ?, 1)
                                  ON DUPLICATE KEY UPDATE 
                                  entered_marks = VALUES(entered_marks),
                                  entered_lab_marks = VALUES(entered_lab_marks),
                                  is_verified = VALUES(is_verified)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->bind_param("iiii", $student_id, $subject_id, $theory_marks, $lab_marks);
                    
                    if ($stmt_insert->execute()) {
                        $message = "Marks verified and stored successfully!";
                        
                        // Check if all subjects are now verified
                        if (areAllSubjectsVerified($conn, $student_id)) {
                            if (incrementStudentSemester($conn, $student_id)) {
                                $message .= " All subjects verified! Student's semester has been incremented.";
                            } else {
                                $error = "Error incrementing student semester.";
                            }
                        }
                    } else {
                        $error = "Error storing verified marks: " . $conn->error;
                    }
                } else {
                    // Marks don't match
                    $error = "Marks do not match with faculty entry.";
                }
            } else {
                $error = "No faculty marks found for this student and subject.";
            }
        }
    }
}

// Fetch all students with their subjects and marks
$sql_students = "SELECT u.id, u.email, s.id AS subject_id, s.subject_name, 
s.branch, s.subject_credits, s.lab_credits, s.has_lab,
sm.marks AS faculty_marks, sm.lab_marks AS faculty_lab_marks
FROM users u
JOIN student_subjects ss ON u.id = ss.student_id
JOIN subjects s ON ss.subject_id = s.id
LEFT JOIN student_marks sm ON u.id = sm.student_id AND s.id = sm.subject_id
WHERE u.role = 'student'
ORDER BY u.id, s.id";

$result_students = $conn->query($sql_students);

$students = [];
while ($row = $result_students->fetch_assoc()) {
    $student_id = $row['id'];
    if (!isset($students[$student_id])) {
        $students[$student_id] = [
            'email' => $row['email'],
            'subjects' => []
        ];
    }
    
    $students[$student_id]['subjects'][] = [
        'subject_id' => $row['subject_id'],
        'subject_name' => $row['subject_name'],
        'branch' => $row['branch'],
        'subject_credits' => $row['subject_credits'],
        'lab_credits' => $row['lab_credits'],
        'has_lab' => $row['has_lab'],
        'faculty_marks' => $row['faculty_marks'],
        'faculty_lab_marks' => $row['faculty_lab_marks']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Cell Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .marks-container {
            display: flex;
            gap: 10px;
        }
        .marks-input {
            flex: 1;
        }
        .max-marks {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .faculty-marks {
            background-color: #f8f9fa;
            padding: 5px;
            border-radius: 4px;
            margin-top: 5px;
        }
        .verified-badge {
            background-color: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (Exam Cell)</h2>
        <a href="login.php" class="btn btn-danger">Logout</a>

        <?php if ($message): ?>
            <div class="alert alert-success mt-3"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <h3 class="mt-4">Student Marks Verification</h3>
        <div class="alert alert-info">
            <strong>Instructions:</strong> Re-enter marks to verify against faculty entries. 
            System will automatically compare and flag discrepancies.
        </div>

        <table class="table table-bordered mt-3">
            <thead class="table-dark">
                <tr>
                    <th>Student ID</th>
                    <th>Email</th>
                    <th>Subject Details</th>
                    <th>Faculty Marks</th>
                    <th>Exam Cell Verification</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($students)): ?>
                    <?php foreach ($students as $student_id => $student): ?>
                        <?php foreach ($student['subjects'] as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student_id); ?></td>
                                <td><?php echo htmlspecialchars($student['email']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($subject['subject_name'] . ' (' . $subject['branch'] . ')'); ?>
                                    <br>
                                    <small class="text-muted">
                                        Theory: <?php echo $subject['subject_credits']; ?> credits
                                        <?php if ($subject['has_lab']): ?>
                                            , Lab: <?php echo $subject['lab_credits']; ?> credits
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="faculty-marks">
                                        <strong>Theory:</strong> 
                                        <?php echo $subject['faculty_marks'] ?? 'Not entered'; ?>/100
                                        <?php if ($subject['has_lab']): ?>
                                            <br>
                                            <strong>Lab:</strong> 
                                            <?php echo $subject['faculty_lab_marks'] ?? 'Not entered'; ?>/50
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    // Check if this entry is already verified
                                    $sql_verified = "SELECT is_verified FROM exam_cell_entries 
                                                    WHERE student_id = ? AND subject_id = ?";
                                    $stmt_verified = $conn->prepare($sql_verified);
                                    $stmt_verified->bind_param("ii", $student_id, $subject['subject_id']);
                                    $stmt_verified->execute();
                                    $result_verified = $stmt_verified->get_result();
                                    $is_verified = $result_verified->num_rows > 0 ? $result_verified->fetch_assoc()['is_verified'] : false;
                                    ?>
                                    
                                    <?php if ($is_verified): ?>
                                        <span class="verified-badge">Verified</span>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                            <input type="hidden" name="subject_id" value="<?php echo $subject['subject_id']; ?>">
                                            
                                            <div class="marks-container">
                                                <div class="marks-input">
                                                    <label class="form-label">Theory</label>
                                                    <input type="number" name="theory_marks" min="0" max="100" 
                                                           class="form-control" 
                                                           value="<?php echo htmlspecialchars($subject['faculty_marks'] ?? ''); ?>" 
                                                           required>
                                                    <div class="max-marks">(Out of 100)</div>
                                                </div>
                                                <?php if ($subject['has_lab']): ?>
                                                <div class="marks-input">
                                                    <label class="form-label">Lab</label>
                                                    <input type="number" name="lab_marks" min="0" max="50" 
                                                           class="form-control" 
                                                           value="<?php echo htmlspecialchars($subject['faculty_lab_marks'] ?? ''); ?>">
                                                    <div class="max-marks">(Out of 50)</div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="submit" class="btn btn-primary mt-2">Verify Marks</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">No student records found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>