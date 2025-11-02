<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get filter parameters
$show_overtime = isset($_GET['show_overtime']) && $_GET['show_overtime'] === '1';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_month = isset($_GET['month']) ? $_GET['month'] : '';
$filter_year = isset($_GET['year']) ? $_GET['year'] : '';
$filter_department = isset($_GET['department']) ? $_GET['department'] : '';
$filter_shift = isset($_GET['shift']) ? $_GET['shift'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_attendance_type = isset($_GET['attendance_type']) ? $_GET['attendance_type'] : '';

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 20; // Same as in the main files
$offset = ($page - 1) * $records_per_page;

// Get system date range first
$date_range_sql = "SELECT 
    MIN(DATE(a.attendance_date)) as earliest_date,
    MAX(DATE(a.attendance_date)) as latest_date
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE e.Status='active'";
$date_range_stmt = $conn->prepare($date_range_sql);
$date_range_stmt->execute();
$date_range_result = $date_range_stmt->get_result();
$date_range_data = $date_range_result->fetch_assoc();
$date_range_stmt->close();

$system_start_date = $date_range_data['earliest_date'] ?? date('Y-m-d');
$system_end_date = $date_range_data['latest_date'] ?? date('Y-m-d');

// Build WHERE conditions for employees
$employee_where = ["e.Status='active'"];
$employee_params = [];
$employee_types = "";

// Add search condition
if (!empty($search_term)) {
    $employee_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $search_param = "%$search_term%";
    $employee_params[] = $search_param;
    $employee_params[] = $search_param;
    $employee_types .= "ss";
}

// Add department filter
if (!empty($filter_department)) {
    $employee_where[] = "e.Department = ?";
    $employee_params[] = $filter_department;
    $employee_types .= 's';
}

// Add shift filter
if (!empty($filter_shift)) {
    $employee_where[] = "e.Shift = ?";
    $employee_params[] = $filter_shift;
    $employee_types .= 's';
}

// Determine the date range for the query
$query_start_date = $system_start_date;
$query_end_date = $system_end_date;

if (!empty($filter_date)) {
    $query_start_date = $filter_date;
    $query_end_date = $filter_date;
} elseif (!empty($filter_month)) {
    $month_start = $filter_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $query_start_date = max($month_start, $system_start_date);
    $query_end_date = min($month_end, $system_end_date);
} elseif (!empty($filter_year)) {
    $year_start = $filter_year . '-01-01';
    $year_end = $filter_year . '-12-31';
    $query_start_date = max($year_start, $system_start_date);
    $query_end_date = min($year_end, $system_end_date);
}

// Build the main query with proper overtime filtering
$query = "SELECT 
            a.id,
            a.EmployeeID,
            a.attendance_date,
            a.attendance_type,
            a.status,
            a.status as db_status,
            a.time_in,
            a.time_out,
            a.time_in_morning,
            a.time_out_morning,
            a.time_in_afternoon,
            a.time_out_afternoon,
            COALESCE(a.data_source, 'biometric') as data_source,
            COALESCE(a.overtime_hours, 0) as overtime_hours,
            COALESCE(a.total_hours, 0) as total_hours,
            COALESCE(a.late_minutes, 0) as late_minutes,
            COALESCE(a.early_out_minutes, 0) as early_out_minutes,
            e.EmployeeName,
            e.Department,
            e.Shift
          FROM attendance a 
          JOIN empuser e ON a.EmployeeID = e.EmployeeID
          WHERE " . implode(' AND ', $employee_where) . "
          AND DATE(a.attendance_date) BETWEEN ? AND ?";

// Add overtime filter to the query
if ($show_overtime) {
    $query .= " AND COALESCE(a.overtime_hours, 0) > 0";
} else {
    $query .= " AND COALESCE(a.overtime_hours, 0) = 0";
}

$query .= " ORDER BY a.overtime_hours DESC, a.attendance_date DESC, a.time_in DESC";

// Add pagination
$query .= " LIMIT $records_per_page OFFSET $offset";

// Add attendance type filter
if (!empty($filter_attendance_type)) {
    if ($filter_attendance_type === 'present') {
        $query = str_replace("WHERE " . implode(' AND ', $employee_where), 
                            "WHERE " . implode(' AND ', $employee_where) . " AND a.attendance_type = 'present'", $query);
    } elseif ($filter_attendance_type === 'absent') {
        $query = str_replace("WHERE " . implode(' AND ', $employee_where), 
                            "WHERE " . implode(' AND ', $employee_where) . " AND (a.id IS NULL OR a.attendance_type = 'absent')", $query);
    }
}

// Prepare parameters for binding
$all_params = array_merge($employee_params, [$query_start_date, $query_end_date]);
$all_types = $employee_types . 'ss';

// Execute query
$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
    exit();
}

if (!$stmt->bind_param($all_types, ...$all_params)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Parameter binding failed: ' . $stmt->error]);
    exit();
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query execution failed: ' . $stmt->error]);
    exit();
}

$result = $stmt->get_result();
$attendance_records = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance_records[] = $row;
    }
}

$stmt->close();

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total_count
                FROM attendance a 
                JOIN empuser e ON a.EmployeeID = e.EmployeeID
                WHERE " . implode(' AND ', $employee_where) . "
                AND DATE(a.attendance_date) BETWEEN ? AND ?";

// Add overtime filter to count query
if ($show_overtime) {
    $count_query .= " AND COALESCE(a.overtime_hours, 0) > 0";
} else {
    $count_query .= " AND COALESCE(a.overtime_hours, 0) = 0";
}

$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    $count_stmt->bind_param($all_types, ...$all_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total_count'];
    $count_stmt->close();
} else {
    $total_records = count($attendance_records);
}

// Calculate pagination info
$total_pages = ceil($total_records / $records_per_page);

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply status filter
if (!empty($filter_status)) {
    $attendance_records = array_values(array_filter($attendance_records, function($rec) use ($filter_status) {
        $db_status = $rec['db_status'] ?? $rec['status'] ?? null;
        switch ($filter_status) {
            case 'late':
                return $db_status === 'late';
            case 'early_in':
                return $db_status === 'early_in';
            case 'on_time':
                return $db_status === 'on_time';
            case 'halfday':
                return $db_status === 'halfday';
            default:
                return true;
        }
    }));
}

$conn->close();

// Generate HTML for the results table
ob_start();
?>
<div class="results-container">
    <div class="results-header">
        <h2>Attendance Records <?php echo $show_overtime ? '(Overtime Only)' : '(Excluding Overtime)'; ?></h2>
        <div class="results-actions">
            <span class="record-count"><?php echo count($attendance_records); ?> of <?php echo $total_records; ?> records found</span>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Shift</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Total Hours</th>
                    <th>Status</th>
                    <th>Attendance Type</th>
                    <th>Late Minutes</th>
                    <th>Early Out Minutes</th>
                    <th>Overtime Hours</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($attendance_records)): ?>
                    <?php foreach ($attendance_records as $record): ?>
                        <tr data-employee-id="<?php echo htmlspecialchars($record['EmployeeID']); ?>" 
                            data-employee-name="<?php echo htmlspecialchars($record['EmployeeName']); ?>" 
                            data-attendance-date="<?php echo htmlspecialchars($record['attendance_date']); ?>" 
                            data-time-in="<?php echo htmlspecialchars($record['time_in'] ?? ''); ?>" 
                            data-time-out="<?php echo htmlspecialchars($record['time_out'] ?? ''); ?>" 
                            data-time-in-morning="<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>" 
                            data-time-out-morning="<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>" 
                            data-time-in-afternoon="<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>" 
                            data-time-out-afternoon="<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>" 
                            data-status="<?php echo htmlspecialchars($record['status'] ?? ''); ?>" 
                            data-late-minutes="<?php echo (int)($record['late_minutes'] ?? 0); ?>" 
                            data-early-out-minutes="<?php echo (int)($record['early_out_minutes'] ?? 0); ?>" 
                            data-overtime-hours="<?php echo (float)($record['overtime_hours'] ?? 0); ?>" 
                            data-department="<?php echo htmlspecialchars($record['Department'] ?? ''); ?>" 
                            data-shift="<?php echo htmlspecialchars($record['Shift'] ?? ''); ?>" 
                            data-source="<?php echo htmlspecialchars($record['data_source'] ?? 'biometric'); ?>" 
                            style="cursor: pointer;">
                            <td><?php echo htmlspecialchars($record['attendance_date']); ?></td>
                            <td><?php echo htmlspecialchars($record['EmployeeName']); ?></td>
                            <td><?php echo htmlspecialchars($record['Department']); ?></td>
                            <td><?php echo htmlspecialchars($record['Shift']); ?></td>
                            <td><?php echo !empty($record['time_in']) ? date('g:i A', strtotime($record['time_in'])) : '-'; ?></td>
                            <td><?php echo !empty($record['time_out']) ? date('g:i A', strtotime($record['time_out'])) : '-'; ?></td>
                            <td><?php echo number_format($record['total_hours'], 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo htmlspecialchars($record['status']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($record['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo ucfirst(htmlspecialchars($record['attendance_type'])); ?></td>
                            <td><?php echo $record['late_minutes']; ?></td>
                            <td><?php echo $record['early_out_minutes']; ?></td>
                            <td class="overtime-cell">
                                <?php if (($record['overtime_hours'] ?? 0) > 0): ?>
                                    <span class="overtime-badge"><?php echo number_format($record['overtime_hours'], 2); ?>h</span>
                                <?php else: ?>
                                    <span class="no-overtime">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="openHrViewModal('<?php echo htmlspecialchars($record['EmployeeName']); ?>','<?php echo htmlspecialchars($record['EmployeeID']); ?>','<?php echo htmlspecialchars($record['attendance_date']); ?>','<?php echo htmlspecialchars($record['time_in'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_out'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>','<?php echo htmlspecialchars($record['status'] ?? ''); ?>','<?php echo (int)($record['late_minutes'] ?? 0); ?>','<?php echo (int)($record['early_out_minutes'] ?? 0); ?>','<?php echo (float)($record['overtime_hours'] ?? 0); ?>')" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="13" class="text-center">No attendance records found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> records
        </div>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="#" class="pagination-btn" data-page="<?php echo $page - 1; ?>">&laquo; Previous</a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="#" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>" data-page="<?php echo $i; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="#" class="pagination-btn" data-page="<?php echo $page + 1; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$html_content = ob_get_clean();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'html' => $html_content,
    'count' => count($attendance_records),
    'total_records' => $total_records,
    'total_pages' => $total_pages,
    'current_page' => $page,
    'show_overtime' => $show_overtime
]);
?>
