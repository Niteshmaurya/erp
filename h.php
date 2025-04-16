I am not able to assign marks for student in below code make it correct and give full corrected version of code 

faculty_dashboard.php
<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'faculty') {
    header("Location: login.php");
    exit();
}

include 'db.php';

// Get the faculty ID
$faculty_id = $_SESSION['user_id'];

// Fetch subjects assigned to this faculty
$assigned_subjects = [];
$subject_sql = "SELECT fs.subject_id, s.subject_name, fs.semester, fs.branch 
               FROM faculty_subjects fs
               JOIN subjects s ON fs.subject_id = s.id
               WHERE fs.faculty_id = ?";
$subject_stmt = $conn->prepare($subject_sql);
$subject_stmt->bind_param("i", $faculty_id);
$subject_stmt->execute();
$subject_result = $subject_stmt->get_result();

while ($row = $subject_result->fetch_assoc()) {
    $assigned_subjects[$row['subject_id']] = $row;
}

// If no subjects assigned, show message and exit
if (empty($assigned_subjects)) {
    echo "<div class='container mt-5'>
            <h2>Welcome, " . htmlspecialchars($_SESSION['name']) . " (Faculty)</h2>
            <a href='login.php' class='btn btn-danger'>Logout</a>
            <div class='alert alert-info mt-4'>You haven't been assigned any subjects yet. Please contact the admin.</div>
          </div>";
    exit();
}

// Get list of subject IDs for query
$subject_ids = array_keys($assigned_subjects);
$subject_ids_str = implode(",", $subject_ids);

// Fetch students taking these subjects
$students = [];
$sql_students = "SELECT DISTINCT u.id, u.email, s.roll_no, s.name as student_name, ss.semester
                FROM users u
                JOIN students s ON u.id = s.user_id
                JOIN student_subjects ss ON u.id = ss.student_id
                WHERE ss.subject_id IN ($subject_ids_str)
                ORDER BY ss.semester, s.roll_no";
$result_students = $conn->query($sql_students);

while ($row = $result_students->fetch_assoc()) {
    $students[$row['id']] = [
        'email' => $row['email'],
        'roll_no' => $row['roll_no'],
        'name' => $row['student_name'],
        'semester' => $row['semester'],
        'subjects' => []
    ];
}

// Fetch subjects taken by these students (only the ones assigned to this faculty)
$sql_subjects = "SELECT 
    ss.student_id,
    ss.semester,
    s.id AS subject_id,
    s.subject_name,
    s.branch,
    s.subject_credits,
    s.lab_credits,
    s.has_lab
FROM student_subjects ss
JOIN subjects s ON ss.subject_id = s.id
WHERE ss.subject_id IN ($subject_ids_str)
ORDER BY ss.student_id, ss.semester, s.id";
$result_subjects = $conn->query($sql_subjects);

while ($row = $result_subjects->fetch_assoc()) {
    $student_id = $row['student_id'];
    if (isset($students[$student_id])) {
        $students[$student_id]['subjects'][] = [
            'subject_id' => $row['subject_id'],
            'subject_name' => $row['subject_name'],
            'branch' => $row['branch'],
            'subject_credits' => $row['subject_credits'],
            'lab_credits' => $row['lab_credits'],
            'has_lab' => $row['has_lab'],
            'semester' => $row['semester']
        ];
    }
}

// Handle adding or updating marks
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['student_id'], $_POST['subject_id'], $_POST['theory_marks'], $_POST['semester'])) {
        $student_id = $_POST['student_id'];
        $subject_id = $_POST['subject_id'];
        $theory_marks = $_POST['theory_marks'];
        $lab_marks = isset($_POST['lab_marks']) ? $_POST['lab_marks'] : null;
        $semester = $_POST['semester'];

        // Validate that this faculty is assigned to this subject
        if (!array_key_exists($subject_id, $assigned_subjects)) {
            $errors[] = "You are not authorized to enter marks for this subject";
        } else {
            // Validate marks
            if ($theory_marks < 0 || $theory_marks > 100) {
                $errors[] = "Theory marks must be between 0 and 100";
            }
            if ($lab_marks !== null && ($lab_marks < 0 || $lab_marks > 50)) {
                $errors[] = "Lab marks must be between 0 and 50";
            }

            if (empty($errors)) {
                // Check if marks already exist
                $sql_check_marks = "SELECT id FROM student_marks 
                                  WHERE student_id = ? AND subject_id = ? AND semester = ?";
                $stmt_check_marks = $conn->prepare($sql_check_marks);
                $stmt_check_marks->bind_param("iii", $student_id, $subject_id, $semester);
                $stmt_check_marks->execute();
                $result_check_marks = $stmt_check_marks->get_result();

                if ($result_check_marks->num_rows > 0) {
                    // Update existing marks
                    $row = $result_check_marks->fetch_assoc();
                    $mark_id = $row['id'];
                    
                    if ($lab_marks !== null) {
                        $sql_update_marks = "UPDATE student_marks 
                                           SET marks = ?, lab_marks = ? 
                                           WHERE id = ?";
                        $stmt_update_marks = $conn->prepare($sql_update_marks);
                        $stmt_update_marks->bind_param("iii", $theory_marks, $lab_marks, $mark_id);
                    } else {
                        $sql_update_marks = "UPDATE student_marks 
                                           SET marks = ? 
                                           WHERE id = ?";
                        $stmt_update_marks = $conn->prepare($sql_update_marks);
                        $stmt_update_marks->bind_param("ii", $theory_marks, $mark_id);
                    }
                    $stmt_update_marks->execute();
                    $stmt_update_marks->close();
                    $success_message = "Marks updated successfully!";
                } else {
                    // Insert new marks with semester
                    if ($lab_marks !== null) {
                        $sql_insert_marks = "INSERT INTO student_marks 
                                           (student_id, subject_id, marks, lab_marks, semester, faculty_id) 
                                           VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_insert_marks = $conn->prepare($sql_insert_marks);
                        $stmt_insert_marks->bind_param("iiiiii", $student_id, $subject_id, $theory_marks, $lab_marks, $semester, $faculty_id);
                    } else {
                        $sql_insert_marks = "INSERT INTO student_marks 
                                           (student_id, subject_id, marks, semester, faculty_id) 
                                           VALUES (?, ?, ?, ?, ?)";
                        $stmt_insert_marks = $conn->prepare($sql_insert_marks);
                        $stmt_insert_marks->bind_param("iiiii", $student_id, $subject_id, $theory_marks, $semester, $faculty_id);
                    }
                    $stmt_insert_marks->execute();
                    $stmt_insert_marks->close();
                    $success_message = "Marks added successfully!";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard</title>
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
        .semester-badge {
            font-size: 0.8rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (Faculty)</h2>
        <a href="login.php" class="btn btn-danger">Logout</a>

        <h3 class="mt-4">My Assigned Subjects</h3>
        <div class="row mb-4">
            <?php foreach ($assigned_subjects as $subject): ?>
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($subject['subject_name']); ?></h5>
                            <p class="card-text">
                                Semester <?php echo htmlspecialchars($subject['semester']); ?><br>
                                Branch: <?php echo htmlspecialchars($subject['branch']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mt-3">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success mt-3">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <h3 class="mt-4">Students in My Subjects</h3>
        <table class="table table-bordered mt-3">
            <thead class="table-dark">
                <tr>
                    <th>Roll No</th>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Subject Details</th>
                    <th>Semester</th>
                    <th>Marks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($students)) {
                    foreach ($students as $id => $student) { ?>
                        <tr>
                            <td rowspan="<?php echo max(1, count($student['subjects'])); ?>">
                                <?php echo htmlspecialchars($student['roll_no']); ?>
                            </td>
                            <td rowspan="<?php echo max(1, count($student['subjects'])); ?>">
                                <?php echo htmlspecialchars($student['name']); ?>
                            </td>
                            <td rowspan="<?php echo max(1, count($student['subjects'])); ?>">
                                <?php echo htmlspecialchars($student['email']); ?>
                            </td>
                            <?php if (!empty($student['subjects'])) {
                                foreach ($student['subjects'] as $index => $subject) { ?>
                                    <?php if ($index > 0) echo "<tr>"; ?>
                                    <td>
                                        <?php 
                                        echo htmlspecialchars($subject['subject_name'] . ' (' . $subject['branch'] . ')');
                                        echo '<br><small class="text-muted">';
                                        echo 'Theory: ' . $subject['subject_credits'] . ' credits';
                                        if ($subject['has_lab']) {
                                            echo ', Lab: ' . $subject['lab_credits'] . ' credits';
                                        }
                                        echo '</small>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($subject['semester']); ?>
                                    </td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="student_id" value="<?php echo $id; ?>">
                                            <input type="hidden" name="subject_id" value="<?php echo $subject['subject_id']; ?>">
                                            <input type="hidden" name="semester" value="<?php echo $subject['semester']; ?>">

                                            <?php
                                            // Fetch existing marks
                                            $sql_existing_marks = "SELECT marks, lab_marks FROM student_marks 
                                                                WHERE student_id = ? AND subject_id = ? AND semester = ?";
                                            $stmt_existing_marks = $conn->prepare($sql_existing_marks);
                                            $stmt_existing_marks->bind_param("iii", $id, $subject['subject_id'], $subject['semester']);
                                            $stmt_existing_marks->execute();
                                            $result_existing_marks = $stmt_existing_marks->get_result();
                                            $existing_marks = $result_existing_marks->fetch_assoc();
                                            ?>

                                            <div class="marks-container">
                                                <div class="marks-input">
                                                    <label class="form-label">Theory</label>
                                                    <input type="number" name="theory_marks" min="0" max="100" 
                                                           class="form-control" 
                                                           value="<?php echo htmlspecialchars($existing_marks['marks'] ?? ''); ?>" 
                                                           required>
                                                    <div class="max-marks">(Out of 100)</div>
                                                </div>
                                                <?php if ($subject['has_lab']) { ?>
                                                <div class="marks-input">
                                                    <label class="form-label">Lab</label>
                                                    <input type="number" name="lab_marks" min="0" max="50" 
                                                           class="form-control" 
                                                           value="<?php echo htmlspecialchars($existing_marks['lab_marks'] ?? ''); ?>">
                                                    <div class="max-marks">(Out of 50)</div>
                                                </div>
                                                <?php } ?>
                                            </div>
                                            <button type="submit" class="btn btn-primary mt-2">Submit Marks</button>
                                        </form>
                                    </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <td colspan="3" class="text-center">No subjects found</td>
                            </tr>
                            <?php }
                    } 
                } else { ?>
                    <tr><td colspan="6" class="text-center">No students found in your assigned subjects</td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>
