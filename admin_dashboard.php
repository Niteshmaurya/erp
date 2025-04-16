<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Only admin can access this page
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: unauthorized.php");
    exit();
}

include 'db.php';

$message = "";
$action = $_GET['action'] ?? '';

// Handle subject addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && $action === 'add_subject') {
    $subject = $_POST['subject'];
    $branch = $_POST['branch'];
    $has_lab = isset($_POST['has_lab']) ? 1 : 0;
    $subject_credits = $_POST['subject_credits'];
    $lab_credits = $has_lab ? $_POST['lab_credits'] : 0;

    $sql = "INSERT INTO subjects (subject_name, branch, has_lab, subject_credits, lab_credits) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssiii", $subject, $branch, $has_lab, $subject_credits, $lab_credits);
    
    if ($stmt->execute()) {
        $message = "<div class='alert alert-success'>Subject added successfully.</div>";
    } else {
        $message = "<div class='alert alert-danger'>Error: " . $stmt->error . "</div>";
    }
    
    $stmt->close();
}

// Handle faculty assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && $action === 'assign_faculty') {
    $faculty_id = $_POST['faculty_id'];
    $subject_id = $_POST['subject_id'];
    $semester = $_POST['semester'];
    $branch = $_POST['branch'];

    // Check if assignment already exists
    $check_sql = "SELECT id FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ? AND semester = ? AND branch = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iiis", $faculty_id, $subject_id, $semester, $branch);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows > 0) {
        $message = "<div class='alert alert-warning'>This faculty is already assigned to this subject for the selected semester and branch.</div>";
    } else {
        $insert_sql = "INSERT INTO faculty_subjects (faculty_id, subject_id, semester, branch) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiis", $faculty_id, $subject_id, $semester, $branch);
        
        if ($insert_stmt->execute()) {
            $message = "<div class='alert alert-success'>Faculty assigned to subject successfully.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error: " . $insert_stmt->error . "</div>";
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}

// Fetch all faculty
$faculty = [];
$faculty_sql = "SELECT u.id, u.name FROM users u WHERE u.role = 'faculty'";
$faculty_result = $conn->query($faculty_sql);
while ($row = $faculty_result->fetch_assoc()) {
    $faculty[$row['id']] = $row['name'];
}

// Fetch all subjects
$subjects = [];
$subjects_sql = "SELECT id, subject_name, branch FROM subjects";
$subjects_result = $conn->query($subjects_sql);
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[$row['id']] = $row['subject_name'] . " (" . $row['branch'] . ")";
}

// Fetch current assignments
$assignments = [];
$assignments_sql = "SELECT fs.id, fs.faculty_id, fs.subject_id, fs.semester, fs.branch, 
                   u.name as faculty_name, s.subject_name
                   FROM faculty_subjects fs
                   JOIN users u ON fs.faculty_id = u.id
                   JOIN subjects s ON fs.subject_id = s.id
                   ORDER BY fs.semester, fs.branch, u.name";
$assignments_result = $conn->query($assignments_sql);
while ($row = $assignments_result->fetch_assoc()) {
    $assignments[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        function toggleLabCredits() {
            let labCheckbox = document.getElementById("has_lab");
            let labCreditsDiv = document.getElementById("lab_credits_div");
            labCreditsDiv.style.display = labCheckbox.checked ? "block" : "none";
        }
    </script>
</head>
<body>
    <div class="container mt-5">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (Admin)</h2>
        <a href="login.php" class="btn btn-danger">Logout</a>
        
        <ul class="nav nav-tabs mt-4" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="add-subject-tab" data-bs-toggle="tab" data-bs-target="#add-subject" type="button" role="tab">Add Subject</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="assign-faculty-tab" data-bs-toggle="tab" data-bs-target="#assign-faculty" type="button" role="tab">Assign Faculty</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="view-assignments-tab" data-bs-toggle="tab" data-bs-target="#view-assignments" type="button" role="tab">View Assignments</button>
            </li>
        </ul>
        
        <div class="tab-content mt-3">
            <?php echo $message; ?>
            
            <!-- Add Subject Tab -->
            <div class="tab-pane fade show active" id="add-subject" role="tabpanel">
                <h3 class="mt-4">Add Subject</h3>
                <form method="POST" action="?action=add_subject">
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch" class="form-control" required>
                            <option value="CSE">Computer Science Engineering</option>
                            <option value="ECE">Electronics and Communication Engineering</option>
                            <option value="ME">Mechanical Engineering</option>
                            <option value="EE">Electrical Engineering</option>
                            <option value="CE">Civil Engineering</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Subject Name</label>
                        <input type="text" name="subject" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Subject Credits</label>
                        <input type="number" name="subject_credits" class="form-control" min="1" max="5" required>
                    </div>

                    <div class="mb-3">
                        <input type="checkbox" name="has_lab" id="has_lab" onclick="toggleLabCredits()">
                        <label for="has_lab">Includes Lab</label>
                    </div>

                    <div class="mb-3" id="lab_credits_div" style="display: none;">
                        <label class="form-label">Lab Credits</label>
                        <input type="number" name="lab_credits" class="form-control" min="1" max="2">
                    </div>

                    <button type="submit" class="btn btn-primary">Add Subject</button>
                </form>
            </div>
            
            <!-- Assign Faculty Tab -->
            <div class="tab-pane fade" id="assign-faculty" role="tabpanel">
                <h3 class="mt-4">Assign Faculty to Subjects</h3>
                <form method="POST" action="?action=assign_faculty">
                    <div class="mb-3">
                        <label class="form-label">Faculty</label>
                        <select name="faculty_id" class="form-control" required>
                            <option value="">Select Faculty</option>
                            <?php foreach ($faculty as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-control" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-control" required>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch" class="form-control" required>
                            <option value="CSE">Computer Science Engineering</option>
                            <option value="ECE">Electronics and Communication Engineering</option>
                            <option value="ME">Mechanical Engineering</option>
                            <option value="EE">Electrical Engineering</option>
                            <option value="CE">Civil Engineering</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Assign Faculty</button>
                </form>
            </div>
            
            <!-- View Assignments Tab -->
            <div class="tab-pane fade" id="view-assignments" role="tabpanel">
                <h3 class="mt-4">Current Faculty Assignments</h3>
                <table class="table table-bordered mt-3">
                    <thead class="table-dark">
                        <tr>
                            <th>Faculty</th>
                            <th>Subject</th>
                            <th>Semester</th>
                            <th>Branch</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($assignments)): ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assignment['faculty_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['branch']); ?></td>
                                    <td>
                                        <a href="?action=remove_assignment&id=<?php echo $assignment['id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Are you sure you want to remove this assignment?')">
                                            Remove
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No faculty assignments found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>