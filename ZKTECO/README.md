# ZKTECO K30 Real-Time Attendance Sync

A C# application for real-time synchronization of attendance data from ZKTECO K30 fingerprint devices to a MySQL database.

## Features

- **Real-time Attendance Monitoring**: Continuously monitors fingerprint scans and updates attendance records
- **Fingerprint Enrollment**: Web API for enrolling new employees to the device
- **Multi-shift Support**: Supports different shift configurations (8-5, 8:30-5:30, 9-6)
- **Automatic Time Field Updates**: Automatically populates overall `time_in` and `time_out` fields based on specific punch times
- **Web API**: RESTful API for enrollment, status checking, and manual synchronization
- **Automatic Reconnection**: Handles device disconnections and automatically reconnects
- **Configurable**: JSON-based configuration for easy customization

## Configuration

The application uses `config.json` for configuration. If the file doesn't exist, default values are used.

### Sample config.json:
```json
{
  "device": {
    "ip": "192.168.1.201",
    "port": 4370,
    "machineNumber": 1,
    "commKey": 0
  },
  "database": {
    "host": "localhost",
    "port": 3306,
    "name": "wteimain1",
    "user": "root",
    "password": ""
  },
  "webServer": {
    "port": 8080,
    "host": "localhost"
  },
  "sync": {
    "fullSyncIntervalHours": 1,
    "realTimeCheckIntervalSeconds": 2
  }
}
```

## Database Schema

The application expects the following tables:

### empuser table:
- `EmployeeID` (int, primary key)
- `EmployeeName` (varchar)
- `Shift` (varchar) - e.g., "8-5", "8:30-5:30", "9-6"
- `fingerprint_enrolled` (varchar) - "yes" or "no"
- `fingerprint_date` (datetime)

### attendance table:
- `EmployeeID` (int)
- `attendance_date` (date)
- `attendance_type` (varchar) - "present", "absent", etc.
- `time_in_morning` (time) - morning arrival time
- `time_out_morning` (time) - lunch break start time
- `time_in_afternoon` (time) - return from lunch time
- `time_out_afternoon` (time) - end of day departure time
- `time_in` (time) - overall first time in of the day (automatically set when time_in_morning is recorded)
- `time_out` (time) - overall last time out of the day (automatically set when time_out_afternoon is recorded)
- `late_minutes` (int)
- `early_out_minutes` (int)
- `overtime_hours` (decimal)
- `is_overtime` (tinyint)
- `status` (varchar)

## Web API Endpoints

### POST /enroll
Enroll a new employee for fingerprint registration.
```json
{
  "employeeId": 123,
  "employeeName": "John Doe",
  "requestId": "optional-request-id"
}
```

### POST /register-employee
Register employee data to the device (without fingerprint).
```json
{
  "employeeId": 123,
  "employeeName": "John Doe"
}
```

### GET /status
Get device connection status.
Response:
```json
{
  "deviceConnected": true
}
```

### POST /sync
Trigger manual full synchronization.

### POST /resync
Resync attendance data from device for a date range.
```json
{
  "start": "2024-01-01",
  "end": "2024-01-31"
}
```

### GET /testdb
Test database connection and show recent records.

## Usage

1. **Configure the device**: Update `config.json` with your device IP and database settings
2. **Run the application**: Execute `ZKTest.exe`
3. **Enroll employees**: Use the `/enroll` API endpoint
4. **Monitor attendance**: The application will automatically sync attendance data

## Shift Configurations

The application supports three shift types:

- **8-5**: 8:00 AM - 5:00 PM (Lunch: 12:00 PM - 1:00 PM)
- **8:30-5:30**: 8:30 AM - 5:30 PM (Lunch: 12:30 PM - 1:30 PM)
- **9-6**: 9:00 AM - 6:00 PM (Lunch: 1:00 PM - 2:00 PM)

## Time Field Logic

The system automatically manages both specific time fields and overall time fields:

### Specific Time Fields:
- `time_in_morning`: First punch of the day (morning arrival)
- `time_out_morning`: Lunch break start
- `time_in_afternoon`: Return from lunch
- `time_out_afternoon`: End of day departure

### Overall Time Fields (Auto-populated):
- `time_in`: Automatically set to the same value as `time_in_morning` when the employee first clocks in
- `time_out`: Automatically set to the same value as `time_out_afternoon` when the employee clocks out for the day

This ensures that both detailed time tracking and overall daily time tracking are maintained simultaneously.

## Requirements

- .NET Framework 4.7.2 or later
- ZKTECO K30 device
- MySQL database
- Windows OS (for COM interop with ZKTECO SDK)

## Troubleshooting

1. **Device Connection Issues**: Check device IP and network connectivity
2. **Database Connection Issues**: Verify database credentials and connection string
3. **Enrollment Issues**: Ensure employee exists in `empuser` table before enrollment
4. **Port Conflicts**: Change web server port in config.json if port 8080 is in use

## Environment Variables

You can override database settings using environment variables:
- `WTEI_DB_HOST`
- `WTEI_DB_PORT`
- `WTEI_DB_NAME`
- `WTEI_DB_USER`
- `WTEI_DB_PASS`
