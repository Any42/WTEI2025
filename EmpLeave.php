<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header("Location: Login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "wteimain1");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$employee_id = $_SESSION['employee_id'];

// Get employee details
$stmt = $conn->prepare("SELECT EmployeeName, Department FROM empuser WHERE EmployeeID = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Get leave history
$stmt = $conn->prepare("SELECT * FROM leave_requests WHERE EmployeeID = ? ORDER BY created_at DESC");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$leave_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - WTEI</title>
    <link rel="stylesheet" href="css/employee-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-building"></i>
            <span>WTEI</span>
        </div>
        <nav class="menu">
            <a href="EmployeeHome.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="EmpAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            <a href="EmpLeave.php" class="menu-item active">
                <i class="fas fa-calendar-alt"></i>
                <span>Leave</span>
            </a>
            <a href="EmpPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payroll</span>
            </a>
            <a href="EmpReports.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span>Performance</span>
            </a>
        </nav>
        <a href="Logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Leave Management</h1>
                <p>Welcome back, <?php echo htmlspecialchars($employee['EmployeeName']); ?></p>
            </div>
            <button class="btn btn-primary" onclick="openLeaveRequestModal()">
                <i class="fas fa-plus"></i>
                Request Leave
            </button>
        </div>

        <!-- Leave History -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Leave History</h2>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date Requested</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leave_history as $leave): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($leave['created_at'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                            <td><?php echo htmlspecialchars($leave['reason']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($leave['status']); ?>">
                                    <?php echo ucfirst($leave['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Leave Request Modal -->
    <div id="leaveRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Request Leave</h2>
                <span class="close" onclick="closeLeaveRequestModal()">&times;</span>
            </div>
            <form id="leaveRequestForm" onsubmit="return submitLeaveRequest(event)">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Reason for Leave</label>
                    <textarea name="reason" class="form-control" rows="5" placeholder="Please explain your reason for requesting leave..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="location_type">Location Type</label>
                    <select name="location_type" id="location_type" class="form-control" required>
                        <option value="" disabled selected>Select type...</option>
                        <option value="Local">Local</option>
                        <option value="International">International</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="leave_location">Specific Location (City/Country)</label>
                    <input type="text" name="leave_location" id="leave_location" class="form-control" placeholder="e.g., Baguio City, Philippines / Tokyo, Japan" required>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLeaveRequestModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openLeaveRequestModal() {
            const modal = document.getElementById('leaveRequestModal');
            if (modal) {
                modal.style.display = 'block';
            } else {
                console.error('Modal element not found');
            }
        }

        function closeLeaveRequestModal() {
            const modal = document.getElementById('leaveRequestModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function submitLeaveRequest(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            // Basic validation for dates
            const startDate = new Date(formData.get('start_date'));
            const endDate = new Date(formData.get('end_date'));
            if (endDate < startDate) {
                alert('End date cannot be earlier than the start date.');
                return false;
            }

            fetch('submit_leave_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Leave request submitted successfully!');
                    closeLeaveRequestModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while submitting your request.');
            });
            
            return false;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('leaveRequestModal');
            if (event.target === modal) {
                closeLeaveRequestModal();
            }
        }

        // Add event listener to the Request Leave button
        document.addEventListener('DOMContentLoaded', function() {
            const requestButton = document.querySelector('.btn.btn-primary');
            if (requestButton) {
                requestButton.addEventListener('click', openLeaveRequestModal);
            }
        });
    </script>
</body>
</html> 