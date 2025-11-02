<?php
        session_start();
        // Check if dept head is logged in
        if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead' || !isset($_SESSION['user_department'])) {
            header("Location: login.php");
            exit;
        }

        $managed_department = $_SESSION['user_department']; // Set this during login

        // Database connection
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "wteimain1";
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            die("Connection Failed: " . $conn->connect_error);
        }

        // Fetch employees for the managed department
        $employees_in_dept = [];
        $stmt = $conn->prepare("SELECT EmployeeID, EmployeeName, EmployeeEmail, Position, Status, Contact, base_salary FROM empuser WHERE Department = ? ORDER BY EmployeeName");
        $stmt->bind_param("s", $managed_department);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $employees_in_dept[] = $row;
            }
        }
        $stmt->close();
        $conn->close();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Manage Employees - <?php echo htmlspecialchars($managed_department); ?> - WTEI</title>
            <!-- Link to your depthead-styles.css or adapted hr-styles.css -->
            <link rel="stylesheet" href="css/depthead-styles.css?v=<?php echo time(); ?>">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <style>
                /* Custom Logout Confirmation Modal */
                .logout-modal {
                    display: none;
                    position: fixed;
                    z-index: 10000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.5);
                    backdrop-filter: blur(5px);
                }

                .logout-modal-content {
                    background-color: #fff;
                    margin: 15% auto;
                    padding: 0;
                    border-radius: 15px;
                    width: 400px;
                    max-width: 90%;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                    animation: modalSlideIn 0.3s ease-out;
                }

                @keyframes modalSlideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-50px) scale(0.9);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }

                .logout-modal-header {
                    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
                    color: white;
                    padding: 20px;
                    border-radius: 15px 15px 0 0;
                    text-align: center;
                    position: relative;
                }

                .logout-modal-header h3 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                }

                .logout-modal-header .close {
                    position: absolute;
                    right: 15px;
                    top: 15px;
                    color: white;
                    font-size: 24px;
                    font-weight: bold;
                    cursor: pointer;
                    opacity: 0.8;
                    transition: opacity 0.3s;
                }

                .logout-modal-header .close:hover {
                    opacity: 1;
                }

                .logout-modal-body {
                    padding: 25px;
                    text-align: center;
                }

                .logout-modal-body .icon {
                    font-size: 48px;
                    color: #ff6b6b;
                    margin-bottom: 15px;
                }

                .logout-modal-body p {
                    margin: 0 0 25px 0;
                    color: #555;
                    font-size: 16px;
                    line-height: 1.5;
                }

                .logout-modal-footer {
                    padding: 0 25px 25px 25px;
                    display: flex;
                    gap: 15px;
                    justify-content: center;
                }

                .logout-modal-btn {
                    padding: 12px 24px;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    min-width: 100px;
                }

                .logout-modal-btn.cancel {
                    background-color: #f8f9fa;
                    color: #6c757d;
                    border: 2px solid #dee2e6;
                }

                .logout-modal-btn.cancel:hover {
                    background-color: #e9ecef;
                    border-color: #adb5bd;
                }

                .logout-modal-btn.confirm {
                    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
                    color: white;
                    border: 2px solid transparent;
                }

                .logout-modal-btn.confirm:hover {
                    background: linear-gradient(135deg, #ee5a52, #dc3545);
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
                }

                .logout-modal-btn:active {
                    transform: translateY(0);
                }

                /* Responsive design */
                @media (max-width: 480px) {
                    .logout-modal-content {
                        width: 95%;
                        margin: 20% auto;
                    }
                    
                    .logout-modal-footer {
                        flex-direction: column;
                    }
                    
                    .logout-modal-btn {
                        width: 100%;
                    }
                }
            </style>
        </head>
        <body>
            <div class="sidebar">
                <!-- Dept Head Sidebar Content (Logo, Menu items like Dashboard, Employees, Attendance, etc.) -->
                <div class="logo">DEPT PORTAL</div>
                 <div class="menu">
                    <a href="depthead_home.php" class="menu-item"><i class="fas fa-th-large"></i> Dashboard</a>
                    <a href="depthead_employees.php" class="menu-item active"><i class="fas fa-users"></i> My Employees</a>
                    <!-- Add other relevant links: depthead_attendance.php, depthead_leave.php etc. -->
                </div>
                <a href="logout.php" class="logout-btn" onclick="return confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            <div class="main-content">
                <div class="page-header">
                    <h1>Employees in <?php echo htmlspecialchars($managed_department); ?> Department</h1>
                    <!-- Header actions if any -->
                </div>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Employee List</h2>
                        <!-- Filters for employees if needed -->
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees_in_dept)): ?>
                                    <tr><td colspan="6" style="text-align:center;">No employees found in this department.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($employees_in_dept as $emp): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($emp['EmployeeID']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['EmployeeName']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['EmployeeEmail']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['Position']); ?></td>
                                            <td>
                                                 <span class="status-badge status-<?php echo strtolower(htmlspecialchars($emp['Status'])); ?>">
                                                    <?php echo ucfirst(htmlspecialchars($emp['Status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <!-- Action buttons: View Details, etc.
                                                     These actions would likely lead to a depthead_employee_view.php?id=...
                                                     Or modals showing more details.
                                                -->
                                                <button class="btn-icon" onclick="viewEmployeeDetails(<?php echo $emp['EmployeeID']; ?>)"><i class="fas fa-eye"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <script>
        // Logout confirmation functions
        function confirmLogout() {
            document.getElementById('logoutModal').style.display = 'block';
            return false; // Prevent default link behavior
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        function proceedLogout() {
            // Close modal and proceed with logout
            closeLogoutModal();
            window.location.href = 'logout.php';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target === modal) {
                closeLogoutModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeLogoutModal();
            }
        });
                
                function viewEmployeeDetails(employeeId) {
                    // Implement view logic, e.g., redirect to a detail page or show a modal
                    alert("View details for employee ID: " + employeeId);
                    // window.location.href = `depthead_employee_view.php?id=${employeeId}`;
                }
            </script>

            <!-- Logout Confirmation Modal -->
            <div id="logoutModal" class="logout-modal">
                <div class="logout-modal-content">
                    <div class="logout-modal-header">
                        <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
                        <span class="close" onclick="closeLogoutModal()">&times;</span>
                    </div>
                    <div class="logout-modal-body">
                        <div class="icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <p>Are you sure you want to logout?<br>This will end your current session and you'll need to login again.</p>
                    </div>
                    <div class="logout-modal-footer">
                        <button class="logout-modal-btn cancel" onclick="closeLogoutModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button class="logout-modal-btn confirm" onclick="proceedLogout()">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </body>
        </html>