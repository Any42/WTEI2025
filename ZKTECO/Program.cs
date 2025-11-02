using MySql.Data.MySqlClient;
using Newtonsoft.Json;
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using zkemkeeper;

namespace K30RealtimeSync
{
    class Program
    {
        static CZKEM device = new CZKEM();
        static HashSet<string> processedLogs = new HashSet<string>();
        static Dictionary<int, string> currentlyAtWork = new Dictionary<int, string>();
        static string connStr = BuildConnString();
        static bool deviceConnected = false;
        static HttpListener httpListener = new HttpListener();
        static bool isShuttingDown = false;
        static readonly object lockObject = new object();
        // Serialize all access to the ZKTeco device to prevent enrollment/log processing conflicts
        static readonly object deviceAccessLock = new object();
        static DateTime lastFullSync = DateTime.MinValue;
        static TimeSpan FULL_SYNC_INTERVAL = TimeSpan.FromHours(6); // Reduced frequency since we're only syncing past 1 week

        // Shift configuration with exact time windows
        static readonly Dictionary<string, ShiftConfig> shiftConfigs = new Dictionary<string, ShiftConfig>
        {
            {
                "8-5", new ShiftConfig
                {
                    Start = new TimeSpan(8, 0, 0),
                    End = new TimeSpan(17, 0, 0),
                    LunchStart = new TimeSpan(12, 0, 0),
                    LunchEnd = new TimeSpan(13, 0, 0),
                    MorningTimeoutStart = new TimeSpan(12, 0, 0),
                    MorningTimeoutEnd = new TimeSpan(12, 30, 0),
                    AfternoonTimeInStart = new TimeSpan(12, 30, 0),
                    AfternoonTimeInEnd = new TimeSpan(13, 0, 0),
                    TimeoutStart = new TimeSpan(17, 0, 0),
                    TimeoutEnd = new TimeSpan(17, 30, 0),
                    OvertimeStart = new TimeSpan(17, 30, 0)
                }
            },
            {
                "8:30-5:30", new ShiftConfig
                {
                    Start = new TimeSpan(8, 30, 0),
                    End = new TimeSpan(17, 30, 0),
                    LunchStart = new TimeSpan(12, 30, 0),
                    LunchEnd = new TimeSpan(13, 30, 0),
                    MorningTimeoutStart = new TimeSpan(12, 30, 0),
                    MorningTimeoutEnd = new TimeSpan(13, 0, 0),
                    AfternoonTimeInStart = new TimeSpan(13, 0, 0),
                    AfternoonTimeInEnd = new TimeSpan(13, 30, 0),
                    TimeoutStart = new TimeSpan(17, 30, 0),
                    TimeoutEnd = new TimeSpan(18, 0, 0),
                    OvertimeStart = new TimeSpan(18, 0, 0)
                }
            },
            {
                "9-6", new ShiftConfig
                {
                    Start = new TimeSpan(9, 0, 0),
                    End = new TimeSpan(18, 0, 0),
                    LunchStart = new TimeSpan(13, 0, 0),
                    LunchEnd = new TimeSpan(14, 0, 0),
                    MorningTimeoutStart = new TimeSpan(13, 0, 0),
                    MorningTimeoutEnd = new TimeSpan(13, 30, 0),
                    AfternoonTimeInStart = new TimeSpan(13, 30, 0),
                    AfternoonTimeInEnd = new TimeSpan(14, 0, 0),
                    TimeoutStart = new TimeSpan(18, 0, 0),
                    TimeoutEnd = new TimeSpan(18, 30, 0),
                    OvertimeStart = new TimeSpan(18, 30, 0)
                }
            },
            {
                "NSD", new ShiftConfig
                {
                    Start = new TimeSpan(22, 0, 0), // 10:00 PM
                    End = new TimeSpan(6, 0, 0),    // 6:00 AM (next day)
                    LunchStart = new TimeSpan(2, 0, 0),  // 2:00 AM (next day)
                    LunchEnd = new TimeSpan(3, 0, 0),    // 3:00 AM (next day)
                    MorningTimeoutStart = new TimeSpan(2, 0, 0),
                    MorningTimeoutEnd = new TimeSpan(2, 30, 0),
                    AfternoonTimeInStart = new TimeSpan(2, 30, 0),
                    AfternoonTimeInEnd = new TimeSpan(3, 0, 0),
                    TimeoutStart = new TimeSpan(6, 0, 0),
                    TimeoutEnd = new TimeSpan(6, 30, 0),
                    OvertimeStart = new TimeSpan(6, 30, 0)
                }
            }
        };

        class ShiftConfig
        {
            public TimeSpan Start { get; set; }
            public TimeSpan End { get; set; }
            public TimeSpan LunchStart { get; set; }
            public TimeSpan LunchEnd { get; set; }
            public TimeSpan MorningTimeoutStart { get; set; }
            public TimeSpan MorningTimeoutEnd { get; set; }
            public TimeSpan AfternoonTimeInStart { get; set; }
            public TimeSpan AfternoonTimeInEnd { get; set; }
            public TimeSpan TimeoutStart { get; set; }
            public TimeSpan TimeoutEnd { get; set; }
            public TimeSpan OvertimeStart { get; set; }
        }

        class AppConfig
        {
            public DeviceConfig Device { get; set; } = new DeviceConfig();
            public DatabaseConfig Database { get; set; } = new DatabaseConfig();
            public WebServerConfig WebServer { get; set; } = new WebServerConfig();
            public SyncConfig Sync { get; set; } = new SyncConfig();
        }

        class DeviceConfig
        {
            public string IP { get; set; } = "192.168.1.201";
            public int Port { get; set; } = 4370;
            public int MachineNumber { get; set; } = 1;
            public int CommKey { get; set; } = 0;
        }

        class DatabaseConfig
        {
            public string Host { get; set; } = "localhost";
            public int Port { get; set; } = 3306;
            public string Name { get; set; } = "wteimain1";
            public string User { get; set; } = "root";
            public string Password { get; set; } = "";
        }

        class WebServerConfig
        {
            public int Port { get; set; } = 8888;
            public string Host { get; set; } = "localhost";
        }

        class SyncConfig
        {
            public int FullSyncIntervalHours { get; set; } = 1;
            public int RealTimeCheckIntervalSeconds { get; set; } = 2;
        }

        static void Main()
        {
            Console.WriteLine("===============================================================");
            Console.WriteLine("           K30 REAL-TIME ATTENDANCE SYNC SERVICE");
            Console.WriteLine("===============================================================");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Starting K30 Real-Time Attendance Sync Service...");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Version: 1.0.0");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] .NET Framework: {Environment.Version}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] OS: {Environment.OSVersion}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Machine: {Environment.MachineName}");
            Console.WriteLine("===============================================================");
            
            // Load configuration
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Loading configuration...");
            var config = LoadConfiguration();
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Configuration loaded successfully");
            
            // Update sync intervals from config
            FULL_SYNC_INTERVAL = TimeSpan.FromHours(config.Sync.FullSyncIntervalHours);
            
            string deviceIP = config.Device.IP;
            int port = config.Device.Port;
            int machineNumber = config.Device.MachineNumber;
            int commKey = config.Device.CommKey;

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device Configuration:");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - IP: {deviceIP}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Port: {port}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Machine Number: {machineNumber}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Comm Key: {commKey}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Web Server Port: {config.WebServer.Port}");

            SetupCleanupHandlers();

            try
            {
                StartWebServer(config.WebServer);
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Web server started on {config.WebServer.Host}:{config.WebServer.Port}");

                Console.WriteLine("=== K30 Real-Time Attendance Sync ===");
                Console.WriteLine($"Current Date: {DateTime.Now:yyyy-MM-dd}");
                Console.WriteLine("===============================================================");

                Thread deviceThread = new Thread(() => {
                    InitializeDeviceConnection(deviceIP, port, commKey, machineNumber);

                    if (deviceConnected)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Performing initial limited sync (past 1 week)...");
                        PerformFullSync();
                    }

                    LoadCurrentlyAtWork();
                    DisplayCurrentlyAtWork();
                    Console.WriteLine("Waiting for attendance logs...\n");

                    MainDeviceLoop(deviceIP, port, commKey, machineNumber, config);
                });

                deviceThread.IsBackground = true;
                deviceThread.Start();

                // Keep main thread alive
                while (!isShuttingDown)
                {
                    Thread.Sleep(1000);
                }

                deviceThread.Join(5000);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Fatal error: {ex.Message}");
            }
            finally
            {
                CleanupResources();
            }
        }

        static void SetupCleanupHandlers()
        {
            Console.CancelKeyPress += (sender, e) => {
                e.Cancel = true;
                isShuttingDown = true;
                CleanupResources();
            };

            AppDomain.CurrentDomain.ProcessExit += (sender, e) => {
                isShuttingDown = true;
                CleanupResources();
            };
        }

        static void MainDeviceLoop(string deviceIP, int port, int commKey, int machineNumber, AppConfig config)
        {
            while (!isShuttingDown)
            {
                try
                {
                    if (deviceConnected)
                    {
                        // Periodic limited sync (past 1 week only)
                        if (DateTime.Now - lastFullSync > FULL_SYNC_INTERVAL)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Starting periodic limited synchronization (past 1 week)...");
                            PerformFullSync();
                        }

                        // Process real-time logs
                        ProcessAttendanceLogs();
                        device.RefreshData(machineNumber);
                    }
                    else
                    {
                        // Attempt reconnection
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Attempting to reconnect to device...");
                        InitializeDeviceConnection(deviceIP, port, commKey, machineNumber);

                        if (deviceConnected)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device reconnected successfully");
                            PerformFullSync();
                        }
                    }
                }
                catch (Exception ex)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error: {ex.Message}");
                    deviceConnected = false;
                }

                Thread.Sleep(config.Sync.RealTimeCheckIntervalSeconds * 1000);
            }
        }

        static void PerformFullSync()
        {
            try
            {
                FullSyncAllDeviceLogsToDb();
                lastFullSync = DateTime.Now;
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Limited synchronization completed");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Limited sync error: {ex.Message}");
            }
        }

        static AppConfig LoadConfiguration()
        {
            try
            {
                // Prefer config.json located alongside the executable
                string baseDir = AppDomain.CurrentDomain.BaseDirectory;
                string exeConfigPath = Path.Combine(baseDir, "config.json");
                if (File.Exists(exeConfigPath))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Loading configuration from: {exeConfigPath}");
                    string json = File.ReadAllText(exeConfigPath);
                    return JsonConvert.DeserializeObject<AppConfig>(json) ?? new AppConfig();
                }
                // Fallback: current working directory
                if (File.Exists("config.json"))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Loading configuration from working directory: {Path.GetFullPath("config.json")}");
                    string json = File.ReadAllText("config.json");
                    return JsonConvert.DeserializeObject<AppConfig>(json) ?? new AppConfig();
                }
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No config.json found; using in-code defaults");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error loading config.json: {ex.Message}. Using default configuration.");
            }
            return new AppConfig();
        }

        static void StartWebServer(WebServerConfig webConfig)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== STARTING WEB SERVER =====");

                // Stop and recreate the HTTP listener to ensure clean state
                if (httpListener != null && httpListener.IsListening)
                {
                    httpListener.Stop();
                    httpListener.Close();
                    Thread.Sleep(1000);
                }

                httpListener = new HttpListener();

                // Configure for maximum compatibility
                httpListener.IgnoreWriteExceptions = true;
                httpListener.TimeoutManager.IdleConnection = TimeSpan.FromSeconds(120);
                httpListener.TimeoutManager.HeaderWait = TimeSpan.FromSeconds(30);

                // Add both localhost and 127.0.0.1 for maximum compatibility
                string primaryPrefix = $"http://127.0.0.1:{webConfig.Port}/";
                string secondaryPrefix = $"http://localhost:{webConfig.Port}/";
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Using prefixes: {primaryPrefix} and {secondaryPrefix}");

                httpListener.Prefixes.Clear();
                httpListener.Prefixes.Add(primaryPrefix);
                httpListener.Prefixes.Add(secondaryPrefix);

                // Add exception handling for URL reservation
                try
                {
                    httpListener.Start();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: HTTP listener started on {primaryPrefix}");
                }
                catch (System.Net.HttpListenerException ex) when (ex.ErrorCode == 5)
                {
                    // Access denied - need URL reservation
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Access denied. URL reservation required.");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Run as Administrator and execute:");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] netsh http add urlacl url=http://+:{webConfig.Port}/ user=Everyone");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] OR run this application as Administrator");
                    throw;
                }

                // Start the listener thread
                Thread listenerThread = new Thread(ListenForRequests);
                listenerThread.IsBackground = true;
                listenerThread.Start();

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== WEB SERVER STARTED SUCCESSFULLY =====");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Failed to start web server: {ex.Message}");
                throw;
            }
        }

        static void ListenForRequests()
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== REQUEST LISTENER THREAD STARTED =====");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Listening for HTTP requests...");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Supported protocols: HTTP/1.0 and HTTP/1.1");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Available at: http://127.0.0.1:8888 and http://localhost:8888");
            
            int consecutiveErrors = 0;
            const int maxConsecutiveErrors = 10;
            
            while (httpListener.IsListening && !isShuttingDown)
            {
                try
                {
                    // Only log waiting message every 30 seconds to reduce spam
                    if (consecutiveErrors == 0)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Ready for HTTP requests...");
                    }
                    
                    HttpListenerContext context = httpListener.GetContext();
                    consecutiveErrors = 0; // Reset error counter on successful request
                    
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== HTTP REQUEST RECEIVED =====");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Client: {context.Request.RemoteEndPoint}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Protocol: {context.Request.ProtocolVersion}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Method: {context.Request.HttpMethod} {context.Request.Url.AbsolutePath}");
                    
                    // Set HTTP version to 1.1 explicitly
                    context.Response.ProtocolVersion = new Version(1, 1);
                    
                    Task.Run(() => ProcessRequest(context));
                }
                catch (HttpListenerException ex)
                {
                    consecutiveErrors++;
                    
                    // Common HttpListener errors: 995 (operation canceled), 50 (request not supported)
                    if (ex.ErrorCode == 995)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Listener cancel detected; stopping request listener");
                        break;
                    }
                    else if (ex.ErrorCode == 50)
                    {
                        // Error 50: Request not supported - usually HTTP/2 or protocol mismatch
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== HTTP PROTOCOL ERROR =====");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error Code: 50 - Request not supported by Http.sys");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] This is usually caused by:");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   1. Client using HTTP/2 instead of HTTP/1.1");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   2. Missing 'Expect:' header (100-continue)");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   3. Invalid protocol headers");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] PHP clients: Ensure CURLOPT_HTTP_VERSION = CURL_HTTP_VERSION_1_1");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] PHP clients: Set 'Expect:' header to empty string");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Consecutive errors: {consecutiveErrors}/{maxConsecutiveErrors}");
                        
                        if (consecutiveErrors >= maxConsecutiveErrors)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Too many consecutive protocol errors. Check PHP CURL configuration.");
                            Thread.Sleep(5000); // Wait longer before retry
                            consecutiveErrors = 0; // Reset counter
                        }
                        else
                        {
                            Thread.Sleep(100); // Short delay
                        }
                        continue;
                    }
                    else
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] HTTP listener exception: Code {ex.ErrorCode} - {ex.Message}");
                        Thread.Sleep(1000);
                    }
                }
                catch (ObjectDisposedException)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] HTTP listener was disposed, stopping request listener");
                    break;
                }
                catch (Exception ex)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Web server error in request listener: {ex.Message}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error type: {ex.GetType().Name}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Waiting 1 second before retrying...");
                    Thread.Sleep(1000);
                }
            }
            
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== REQUEST LISTENER THREAD STOPPED =====");
        }

        static void ProcessRequest(HttpListenerContext context)
        {
            HttpListenerRequest request = context.Request;
            HttpListenerResponse response = context.Response;
            string clientIP = request.RemoteEndPoint?.Address?.ToString() ?? "unknown";
            string requestId = Guid.NewGuid().ToString("N").Substring(0, 8);

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== WEB REQUEST RECEIVED =====");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Request ID: {requestId}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Client IP: {clientIP}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Method: {request.HttpMethod}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Path: {request.Url.AbsolutePath}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Query: {request.Url.Query ?? "none"}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Protocol: {request.ProtocolVersion}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] User-Agent: {request.Headers["User-Agent"] ?? "none"}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Content-Type: {request.ContentType ?? "none"}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Content-Length: {request.ContentLength64}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Connection: {request.Headers["Connection"] ?? "none"}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Expect: {request.Headers["Expect"] ?? "none"}");

            try
            {
                // Set protocol version explicitly
                response.ProtocolVersion = new Version(1, 1);
                
                // Set response headers
                response.Headers.Add("Access-Control-Allow-Origin", "*");
                response.Headers.Add("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
                response.Headers.Add("Access-Control-Allow-Headers", "Content-Type, X-Request-ID");
                response.Headers.Add("Connection", "Keep-Alive");
                response.KeepAlive = true;

                if (request.HttpMethod == "OPTIONS")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Handling CORS preflight request");
                    response.StatusCode = 200;
                    response.Close();
                    return;
                }
                if (request.HttpMethod == "GET" && request.Url.AbsolutePath == "/ping")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: PING HANDLER");
                    string json = "{\"status\":\"ok\",\"timestamp\":\"" + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "\"}";
                    byte[] buffer = Encoding.UTF8.GetBytes(json);
                    response.ContentType = "application/json";
                    response.ContentLength64 = buffer.Length;
                    response.OutputStream.Write(buffer, 0, buffer.Length);
                    response.OutputStream.Close();
                    return;
                }


                if (request.HttpMethod == "POST" && request.Url.AbsolutePath == "/enroll")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: ENROLLMENT HANDLER");
                    HandleEnrollmentRequest(request, response);
                }
                else if (request.HttpMethod == "GET" && request.Url.AbsolutePath == "/status")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: STATUS HANDLER");
                    HandleStatusRequest(response);
                }
                else if (request.HttpMethod == "POST" && request.Url.AbsolutePath == "/sync")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: SYNC HANDLER");
                    HandleSyncRequest(response);
                }
                else if (request.HttpMethod == "POST" && request.Url.AbsolutePath == "/resync")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: RESYNC HANDLER");
                    HandleResyncRequest(request, response);
                }
                else if (request.HttpMethod == "GET" && request.Url.AbsolutePath == "/testdb")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: DATABASE TEST HANDLER");
                    HandleTestDbRequest(response);
                }
                else if (request.HttpMethod == "POST" && request.Url.AbsolutePath == "/register-employee")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: REGISTER EMPLOYEE HANDLER");
                    HandleRegisterEmployeeRequest(request, response);
                }
                else if (request.HttpMethod == "GET" && request.Url.AbsolutePath == "/test")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Routing to: TEST HANDLER");
                    HandleTestRequest(response);
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Unknown endpoint - {request.HttpMethod} {request.Url.AbsolutePath}");
                    SendErrorResponse(response, 404, "Endpoint not found");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Request processing failed: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
                SendErrorResponse(response, 500, ex.Message);
            }
            finally
            {
                try
                {
                    response.Close();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Response sent to client (Status: {response.StatusCode})");
                }
                catch (Exception closeEx)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Error closing response: {closeEx.Message}");
                }
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== WEB REQUEST COMPLETE =====");
            }
        }

        // COMPLETE FIX FOR CHARACTER ENCODING ISSUES
        // Replace these methods in your Program.cs

        // 1. Update HandleEnrollmentRequest to properly decode the JSON with UTF-8
        static void HandleEnrollmentRequest(HttpListenerRequest request, HttpListenerResponse response)
        {
            string clientIP = request.RemoteEndPoint?.Address?.ToString() ?? "unknown";
            string userAgent = request.Headers["User-Agent"] ?? "unknown";
            string requestId = Guid.NewGuid().ToString("N").Substring(0, 8);

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== ENROLLMENT REQUEST RECEIVED =====");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Request ID: {requestId}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Client IP: {clientIP}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Content Encoding: {request.ContentEncoding?.EncodingName ?? "none"}");

            string requestBody = "";
            try
            {
                // CRITICAL FIX: Use UTF-8 encoding explicitly
                using (StreamReader reader = new StreamReader(request.InputStream, System.Text.Encoding.UTF8))
                {
                    requestBody = reader.ReadToEnd();
                }
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Request Body: {requestBody}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Failed to read request body: {ex.Message}");
                SendErrorResponse(response, 400, "Failed to read request body");
                return;
            }

            if (string.IsNullOrWhiteSpace(requestBody))
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Request body is empty");
                SendErrorResponse(response, 400, "Request body is empty");
                return;
            }

            try
            {
                // Parse JSON with UTF-8 awareness
                dynamic data = JsonConvert.DeserializeObject(requestBody);

                if (data?.employeeId == null || data?.employeeName == null)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Missing required fields");
                    SendErrorResponse(response, 400, "Missing employeeId or employeeName in request");
                    return;
                }

                int employeeId = (int)data.employeeId;
                string employeeName = (string)data.employeeName;

                // LOG THE ORIGINAL NAME WITH CHARACTER CODES
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== ORIGINAL NAME ANALYSIS =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Original Name: '{employeeName}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Name Length: {employeeName.Length} characters");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Character breakdown:");
                for (int i = 0; i < employeeName.Length; i++)
                {
                    char c = employeeName[i];
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   [{i}] '{c}' = Unicode U+{((int)c):X4} (decimal {(int)c})");
                }

                if (!deviceConnected)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Device not connected - attempting background reconnect");
                    Task.Run(() => {
                        try
                        {
                            var cfg = LoadConfiguration();
                            InitializeDeviceConnection(cfg.Device.IP, cfg.Device.Port, cfg.Device.CommKey, cfg.Device.MachineNumber);
                        }
                        catch (Exception rex)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Background reconnect failed: {rex.Message}");
                        }
                    });
                }

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Starting enrollment process in background...");
                Task.Run(() => ProcessEnrollmentRequest(employeeId, employeeName, requestId));

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== ENROLLMENT REQUEST ACCEPTED =====");
                string responseString = $"{{\"status\":\"success\",\"message\":\"Enrollment process started\",\"requestId\":\"{requestId}\",\"employeeId\":{employeeId},\"employeeName\":\"{employeeName}\"}}";
                SendJsonResponse(response, responseString, 200);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Unexpected error: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
                SendErrorResponse(response, 500, $"Internal server error: {ex.Message}");
            }
        }

        static void HandleRegisterEmployeeRequest(HttpListenerRequest request, HttpListenerResponse response)
        {
            try
            {
                string requestBody;
                using (StreamReader reader = new StreamReader(request.InputStream, request.ContentEncoding))
                {
                    requestBody = reader.ReadToEnd();
                }

                dynamic data = JsonConvert.DeserializeObject(requestBody);

                if (data?.employeeId == null || data?.employeeName == null)
                {
                    SendErrorResponse(response, 400, "Missing employeeId or employeeName in request");
                    return;
                }

                int employeeId = (int)data.employeeId;
                string employeeName = (string)data.employeeName;

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Register employee request - ID: {employeeId}, Name: {employeeName}");

                bool success = SendEmployeeToDevice(employeeId, employeeName);

                if (success)
                {
                    // Update database status
                    UpdateEnrollmentStatus(employeeId);
                    SendJsonResponse(response, "{\"status\":\"success\",\"message\":\"Employee data sent to device successfully\"}", 200);
                }
                else
                {
                    SendErrorResponse(response, 500, "Failed to send employee data to device");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Register employee error: {ex.Message}");
                SendErrorResponse(response, 500, $"Registration error: {ex.Message}");
            }
        }

        static void HandleStatusRequest(HttpListenerResponse response)
        {
            string responseString = $"{{\"deviceConnected\":{deviceConnected.ToString().ToLower()}}}";
            SendJsonResponse(response, responseString, 200);
        }

        static void HandleSyncRequest(HttpListenerResponse response)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Manual full sync request");

            if (!deviceConnected)
            {
                SendErrorResponse(response, 503, "Device not connected");
                return;
            }

            try
            {
                PerformFullSync();
                string responseString = "{\"status\":\"success\",\"message\":\"Full synchronization completed\"}";
                SendJsonResponse(response, responseString, 200);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Manual sync error: {ex.Message}");
                SendErrorResponse(response, 500, $"Sync failed: {ex.Message}");
            }
        }

        static void HandleResyncRequest(HttpListenerRequest request, HttpListenerResponse response)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Manual RESYNC request (overwrite DB from device logs)");

            if (!deviceConnected)
            {
                SendErrorResponse(response, 503, "Device not connected");
                return;
            }

            try
            {
                string body;
                using (var reader = new StreamReader(request.InputStream, request.ContentEncoding))
                {
                    body = reader.ReadToEnd();
                }

                DateTime startDate, endDate;
                if (!string.IsNullOrWhiteSpace(body))
                {
                    dynamic data = JsonConvert.DeserializeObject(body);
                    string startStr = data?.start?.ToString();
                    string endStr = data?.end?.ToString();

                    if (!DateTime.TryParse(startStr, out startDate)) startDate = DateTime.Today;
                    if (!DateTime.TryParse(endStr, out endDate)) endDate = startDate;
                }
                else
                {
                    startDate = DateTime.Today;
                    endDate = DateTime.Today;
                }

                if (startDate > endDate)
                {
                    var tmp = startDate; startDate = endDate; endDate = tmp;
                }

                var summary = ResyncRangeFromDevice(startDate, endDate);
                string json = JsonConvert.SerializeObject(new
                {
                    status = "success",
                    message = "Resync completed",
                    summary
                });
                SendJsonResponse(response, json, 200);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] RESYNC error: {ex.Message}");
                SendErrorResponse(response, 500, $"Resync failed: {ex.Message}");
            }
        }

        static void SendJsonResponse(HttpListenerResponse response, string json, int statusCode)
        {
            byte[] buffer = Encoding.UTF8.GetBytes(json);
            response.ContentLength64 = buffer.Length;
            response.ContentType = "application/json";
            response.StatusCode = statusCode;
            response.OutputStream.Write(buffer, 0, buffer.Length);
        }

        static void SendErrorResponse(HttpListenerResponse response, int statusCode, string message)
        {
            string responseString = $"{{\"status\":\"error\",\"message\":\"{message.Replace("\"", "\\\"")}\"}}";
            SendJsonResponse(response, responseString, statusCode);
        }

        static void HandleTestRequest(HttpListenerResponse response)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== TEST REQUEST HANDLER =====");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Web server is working correctly");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device connected: {deviceConnected}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Current time: {DateTime.Now:yyyy-MM-dd HH:mm:ss}");
            
            string responseString = $"{{\"status\":\"success\",\"message\":\"Web server is working\",\"deviceConnected\":{deviceConnected.ToString().ToLower()},\"timestamp\":\"{DateTime.Now:yyyy-MM-dd HH:mm:ss}\"}}";
            SendJsonResponse(response, responseString, 200);
        }

        static void InitializeDeviceConnection(string deviceIP, int port, int commKey, int machineNumber)
        {
            if (deviceConnected) return;

            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Connecting to K30 device at {deviceIP}:{port} using TCP...");

                // Attempt a clean disconnect before reconnecting; ignore errors if already disconnected
                try { device.Disconnect(); Thread.Sleep(1000); } catch { }

                // Try connection with retry logic
                int retryCount = 0;
                int maxRetries = 5; // Increased retry count
                bool connected = false;

                while (retryCount < maxRetries && !connected)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] TCP connection attempt {retryCount + 1}/{maxRetries} to {deviceIP}:{port}...");
                    
                    // Use Connect_Net for TCP connection (this is the correct method for TCP)
                    if (device.Connect_Net(deviceIP, port))
                    {
                        connected = true;
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: TCP connection established to K30 at {deviceIP}:{port}");
                    }
                    else
                    {
                        retryCount++;
                        int errorCode = 0;
                        device.GetLastError(ref errorCode);
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Connection attempt {retryCount}/{maxRetries} failed. Error code: {errorCode}");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Retrying in 3 seconds...");
                        if (retryCount < maxRetries)
                        {
                            Thread.Sleep(3000); // Increased wait time
                        }
                    }
                }

                if (!connected)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] FAILED: Could not establish TCP connection to K30 device after {maxRetries} attempts");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Please check:");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Device IP address: {deviceIP}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Port 4370 is open and accessible");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Device is powered on and network connected");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] - Firewall allows TCP connections on port 4370");
                    deviceConnected = false;
                    return;
                }

                // Set communication password if provided
                if (commKey > 0)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Setting communication password...");
                    device.SetCommPassword(commKey);
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Communication password set successfully");
                }

                // Enable device
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Enabling device...");
                device.EnableDevice(machineNumber, true);
                
                // Test the connection by trying to read device info
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Testing device communication...");
                int deviceInfo = 0;
                int infoType = 1;
                if (device.GetDeviceInfo(machineNumber, infoType, ref deviceInfo))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device info retrieved successfully: {deviceInfo}");
                }
                else
                {
                    int errorCode = 0;
                    device.GetLastError(ref errorCode);
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Could not retrieve device info. Error code: {errorCode}");
                }
                
                deviceConnected = true;
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device connection established and verified - Ready for enrollment and attendance sync");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device connection error: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
                deviceConnected = false;
            }
        }

        static void ProcessEnrollmentRequest(int employeeId, string employeeName, string requestId)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== PROCESSING ENROLLMENT REQUEST =====");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Request ID: {requestId}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee ID: {employeeId}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee Name: {employeeName}");
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Started at: {DateTime.Now:yyyy-MM-dd HH:mm:ss}");

            try
            {
                // Check device connection status
                if (!deviceConnected)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Device not connected. Cannot process enrollment.");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Attempting to reconnect to device...");
                    
                    var config = LoadConfiguration();
                    InitializeDeviceConnection(config.Device.IP, config.Device.Port, config.Device.CommKey, config.Device.MachineNumber);
                    
                    if (!deviceConnected)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Failed to reconnect to device. Enrollment aborted.");
                        return;
                    }
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Device reconnected successfully");
                }

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device connection status: CONNECTED");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Starting enrollment process...");

                bool enrollSuccess;
                lock (deviceAccessLock)
                {
                    enrollSuccess = DirectEnrollToDevice(employeeId, employeeName);
                }

                if (enrollSuccess)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Enrollment completed successfully");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Updating database status...");
                    
                    try
                    {
                        UpdateEnrollmentStatus(employeeId);
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Database updated successfully");
                    }
                    catch (Exception dbEx)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Database update failed: {dbEx.Message}");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Enrollment was successful on device, but database update failed");
                    }
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Enrollment failed on device");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Unexpected error during enrollment processing: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
            }
            finally
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== ENROLLMENT REQUEST PROCESSING COMPLETE =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Request ID: {requestId}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Completed at: {DateTime.Now:yyyy-MM-dd HH:mm:ss}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ================================================");
            }
        }

        static bool DirectEnrollToDevice(int employeeId, string employeeName)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing enrollment for Employee {employeeId}: {employeeName}");

                // Simply send the employee data to the device
                bool success = SendEmployeeToDevice(employeeId, employeeName);

                if (success)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Enrollment completed successfully for Employee {employeeId}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device is now ready for fingerprint enrollment on the device keypad");
                    return true;
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Enrollment failed for Employee {employeeId}");
                    return false;
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error in enrollment process: {ex.Message}");
                return false;
            }
        }

        // 3. Update SendEmployeeToDevice with better encoding handling
        static bool SendEmployeeToDevice(int employeeId, string employeeName)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== SENDING EMPLOYEE TO DEVICE =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee ID: {employeeId}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Original Name: '{employeeName}'");

                if (!deviceConnected)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device not connected. Attempting reconnect...");
                    var config = LoadConfiguration();
                    InitializeDeviceConnection(config.Device.IP, config.Device.Port, config.Device.CommKey, config.Device.MachineNumber);

                    if (!deviceConnected)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Failed to reconnect. Aborting.");
                        return false;
                    }
                }

                // Test connection
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Testing device connection...");
                int testInfo = 0;
                if (!device.GetDeviceInfo(1, 1, ref testInfo))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device connection test failed. Reconnecting...");
                    var config = LoadConfiguration();
                    InitializeDeviceConnection(config.Device.IP, config.Device.Port, config.Device.CommKey, config.Device.MachineNumber);

                    if (!deviceConnected)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Reconnect failed. Aborting.");
                        return false;
                    }
                }

                // Refresh device
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Refreshing device data...");
                device.RefreshData(1);
                Thread.Sleep(1000);

                // Sanitize name - THIS IS THE KEY STEP
                string sanitizedName = SanitizeEmployeeName(employeeName);

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Name to send to device: '{sanitizedName}'");

                // CRITICAL: Verify the name contains ONLY ASCII characters before sending
                byte[] nameBytes = System.Text.Encoding.ASCII.GetBytes(sanitizedName);
                string asciiVerified = System.Text.Encoding.ASCII.GetString(nameBytes);

                if (asciiVerified != sanitizedName)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR: Name still contains non-ASCII characters!");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Sanitized: '{sanitizedName}'");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ASCII verified: '{asciiVerified}'");
                    sanitizedName = asciiVerified;
                }

                // Send to device
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Calling SSR_SetUserInfo...");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Parameters: MachineNumber=1, EnrollNumber={employeeId}, Name='{sanitizedName}', Password='', Privilege=0, Enabled=true");

                bool success = device.SSR_SetUserInfo(1, employeeId.ToString(), sanitizedName, "", 0, true);

                if (success)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== SUCCESS =====");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee data sent to device");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ID: {employeeId}");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Name on device: '{sanitizedName}'");

                    Thread.Sleep(2000);

                    // Verify
                    string verifyName = "";
                    string verifyPassword = "";
                    int verifyPrivilege = 0;
                    bool verifyEnabled = false;

                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Verifying data on device...");
                    if (device.SSR_GetUserInfo(1, employeeId.ToString(), out verifyName, out verifyPassword, out verifyPrivilege, out verifyEnabled))
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] VERIFICATION SUCCESS");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device returned name: '{verifyName}'");
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Match: {(verifyName == sanitizedName ? "YES" : "NO")}");

                        if (verifyName != sanitizedName)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Name mismatch!");
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Sent: '{sanitizedName}'");
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Got back: '{verifyName}'");

                            // Character-by-character comparison
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Character comparison:");
                            int maxLen = Math.Max(sanitizedName.Length, verifyName.Length);
                            for (int i = 0; i < maxLen; i++)
                            {
                                char sent = i < sanitizedName.Length ? sanitizedName[i] : ' ';
                                char got = i < verifyName.Length ? verifyName[i] : ' ';
                                string match = (sent == got) ? "✓" : "✗";
                                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   [{i}] Sent: '{sent}' ({(int)sent}) | Got: '{got}' ({(int)got}) {match}");
                            }
                        }
                    }
                    else
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Could not verify data on device");
                    }

                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} ready for fingerprint enrollment on device keypad");
                    return true;
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== FAILED =====");
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SSR_SetUserInfo returned false");

                    int errorCode = 0;
                    device.GetLastError(ref errorCode);
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device error code: {errorCode}");

                    return false;
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] EXCEPTION in SendEmployeeToDevice: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
                return false;
            }
        }




        // Replace the SanitizeEmployeeName method with this improved version:

        static string ExtractFirstLastName(string employeeName)
        {
            if (string.IsNullOrEmpty(employeeName))
                return "Employee";

            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== FIRST/LAST NAME EXTRACTION START =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Input: '{employeeName}'");

                // Step 1: Trim whitespace
                string trimmed = employeeName.Trim();

                // Step 2: Split by spaces and filter out empty strings
                string[] words = trimmed.Split(new char[] { ' ' }, StringSplitOptions.RemoveEmptyEntries);

                // Step 3: Get first and last words
                string firstWord = words.Length > 0 ? words[0] : "";
                string lastWord = words.Length > 1 ? words[words.Length - 1] : "";

                // Step 4: Combine first and last name
                string extracted;
                if (!string.IsNullOrEmpty(firstWord) && !string.IsNullOrEmpty(lastWord))
                {
                    extracted = firstWord + " " + lastWord;
                }
                else if (!string.IsNullOrEmpty(firstWord))
                {
                    extracted = firstWord;
                }
                else
                {
                    extracted = "Employee";
                }

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Words found: {words.Length}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] First word: '{firstWord}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Last word: '{lastWord}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Extracted: '{extracted}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== FIRST/LAST NAME EXTRACTION END =====");

                return extracted;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR in ExtractFirstLastName: {ex.Message}");
                return "Employee";
            }
        }

        static string SanitizeEmployeeName(string employeeName)
        {
            if (string.IsNullOrEmpty(employeeName))
                return "Unknown";

            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== NAME SANITIZATION START =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Input: '{employeeName}' (Length: {employeeName.Length})");

                // Step 1: Extract first and last name only
                string sanitized = ExtractFirstLastName(employeeName);
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] After first/last extraction: '{sanitized}'");

                // Step 2: Trim whitespace
                sanitized = sanitized.Trim();

                // Step 2: Convert to uppercase for consistency (optional - remove if you want to keep original case)
                // sanitized = sanitized.ToUpper();

                // Step 3: Convert accented characters to ASCII equivalents first
                var accentReplacements = new Dictionary<char, char>
                {
                    {'á', 'a'}, {'à', 'a'}, {'ä', 'a'}, {'â', 'a'}, {'ã', 'a'}, {'å', 'a'},
                    {'Á', 'A'}, {'À', 'A'}, {'Ä', 'A'}, {'Â', 'A'}, {'Ã', 'A'}, {'Å', 'A'},
                    {'é', 'e'}, {'è', 'e'}, {'ë', 'e'}, {'ê', 'e'},
                    {'É', 'E'}, {'È', 'E'}, {'Ë', 'E'}, {'Ê', 'E'},
                    {'í', 'i'}, {'ì', 'i'}, {'ï', 'i'}, {'î', 'i'},
                    {'Í', 'I'}, {'Ì', 'I'}, {'Ï', 'I'}, {'Î', 'I'},
                    {'ó', 'o'}, {'ò', 'o'}, {'ö', 'o'}, {'ô', 'o'}, {'õ', 'o'},
                    {'Ó', 'O'}, {'Ò', 'O'}, {'Ö', 'O'}, {'Ô', 'O'}, {'Õ', 'O'},
                    {'ú', 'u'}, {'ù', 'u'}, {'ü', 'u'}, {'û', 'u'},
                    {'Ú', 'U'}, {'Ù', 'U'}, {'Ü', 'U'}, {'Û', 'U'},
                    {'ñ', 'n'}, {'Ñ', 'N'},
                    {'ç', 'c'}, {'Ç', 'C'},
                    {'ş', 's'}, {'Ş', 'S'},
                    {'ğ', 'g'}, {'Ğ', 'G'},
                    {'ı', 'i'}, {'İ', 'I'}
                };

                // Apply accent replacements
                foreach (var replacement in accentReplacements)
                {
                    sanitized = sanitized.Replace(replacement.Key, replacement.Value);
                }

                // Step 4: Keep ONLY basic ASCII letters, numbers, spaces, hyphens, and periods
                // This is the MOST IMPORTANT step - it removes ALL problematic characters
                var allowedChars = new System.Text.StringBuilder();
                foreach (char c in sanitized)
                {
                    // Allow only: A-Z, a-z, 0-9, space, hyphen, period
                    if ((c >= 'A' && c <= 'Z') ||
                        (c >= 'a' && c <= 'z') ||
                        (c >= '0' && c <= '9') ||
                        c == ' ' ||
                        c == '-' ||
                        c == '.')
                    {
                        allowedChars.Append(c);
                    }
                    else
                    {
                        // Log rejected characters
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Rejected character: '{c}' (U+{((int)c):X4})");
                    }
                }

                sanitized = allowedChars.ToString();

                // Step 5: Replace multiple consecutive spaces with single space
                while (sanitized.Contains("  "))
                {
                    sanitized = sanitized.Replace("  ", " ");
                }

                // Step 6: Trim again
                sanitized = sanitized.Trim();

                // Step 7: Ensure we have a valid name
                if (string.IsNullOrEmpty(sanitized))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: Name became empty after sanitization");
                    return "Employee";
                }

                // Step 8: Limit to 23 characters (K30 limitation)
                if (sanitized.Length > 23)
                {
                    sanitized = sanitized.Substring(0, 23).Trim();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Name truncated to 23 characters");
                }

                // Step 9: Final validation - verify ALL characters are safe
                bool allSafe = true;
                for (int i = 0; i < sanitized.Length; i++)
                {
                    char c = sanitized[i];
                    bool isSafe = (c >= 'A' && c <= 'Z') ||
                                 (c >= 'a' && c <= 'z') ||
                                 (c >= '0' && c <= '9') ||
                                 c == ' ' || c == '-' || c == '.';

                    if (!isSafe)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] UNSAFE CHARACTER DETECTED: '{c}' at position {i}");
                        allSafe = false;
                    }
                }

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ===== NAME SANITIZATION COMPLETE =====");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Original: '{employeeName}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Sanitized: '{sanitized}'");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Length: {sanitized.Length} characters");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] All characters safe: {allSafe}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Final character breakdown:");
                for (int i = 0; i < sanitized.Length; i++)
                {
                    char c = sanitized[i];
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}]   [{i}] '{c}' = ASCII {(int)c}");
                }

                return sanitized;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] ERROR in SanitizeEmployeeName: {ex.Message}");
                return "Employee";
            }
        }


        static void UpdateEnrollmentStatus(int employeeId)
        {
            try
            {
                using (MySqlConnection conn = new MySqlConnection(connStr))
                {
                    conn.Open();
                    MySqlCommand cmd = new MySqlCommand(
                        "UPDATE empuser SET fingerprint_enrolled = 'yes', fingerprint_date = NOW() WHERE EmployeeID = @employeeId",
                        conn);
                    cmd.Parameters.AddWithValue("@employeeId", employeeId);
                    int rowsAffected = cmd.ExecuteNonQuery();

                    if (rowsAffected > 0)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database updated successfully for Employee {employeeId}");
                    }
                    else
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} not found in database");
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database update error: {ex.Message}");
            }
        }

        static void LoadCurrentlyAtWork()
        {
            lock (lockObject)
            {
                currentlyAtWork.Clear();
            }

            try
            {
                using (MySqlConnection conn = new MySqlConnection(connStr))
                {
                    conn.Open();
                    MySqlCommand cmd = new MySqlCommand(
                        @"SELECT EmployeeID, time_in FROM attendance 
                          WHERE attendance_date = @today 
                          AND time_in IS NOT NULL 
                          AND time_out IS NULL", conn);
                    cmd.Parameters.AddWithValue("@today", DateTime.Today);

                    using (MySqlDataReader reader = cmd.ExecuteReader())
                    {
                        while (reader.Read())
                        {
                            int employeeId = reader.GetInt32("EmployeeID");
                            string timeIn = reader.GetString("time_in");

                            lock (lockObject)
                            {
                                currentlyAtWork[employeeId] = timeIn;
                            }
                        }
                    }
                }
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Successfully loaded currently at work employees");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error loading currently at work: {ex.Message}");
            }
        }

        static void DisplayCurrentlyAtWork()
        {
            lock (lockObject)
            {
                Console.WriteLine("\n" + new string('=', 50));
                Console.WriteLine($"CURRENTLY AT WORK ({DateTime.Now:yyyy-MM-dd HH:mm:ss})");
                Console.WriteLine(new string('=', 50));

                if (currentlyAtWork.Count == 0)
                {
                    Console.WriteLine(" No employees currently at work");
                }
                else
                {
                    Console.WriteLine($" Total: {currentlyAtWork.Count} employee(s)");
                    Console.WriteLine(" " + new string('-', 30));

                    var sortedEmployees = currentlyAtWork.OrderBy(x => x.Key);
                    foreach (var employee in sortedEmployees)
                    {
                        try
                        {
                            if (TimeSpan.TryParse(employee.Value, out TimeSpan timeIn))
                            {
                                TimeSpan workDuration = DateTime.Now.TimeOfDay - timeIn;
                                string duration = $"{(int)workDuration.TotalHours:D2}:{workDuration.Minutes:D2}";
                                Console.WriteLine($" Employee {employee.Key,3} | In: {employee.Value} | Duration: {duration}");
                            }
                            else
                            {
                                Console.WriteLine($" Employee {employee.Key,3} | In: {employee.Value} | Invalid time format");
                            }
                        }
                        catch (Exception ex)
                        {
                            Console.WriteLine($" Employee {employee.Key,3} | In: {employee.Value} | Error: {ex.Message}");
                        }
                    }
                }

                Console.WriteLine(new string('=', 50));
                Console.WriteLine("Monitoring for fingerprint scans...\n");
            }
        }

        static void ProcessAttendanceLogs()
        {
            if (!deviceConnected || isShuttingDown)
            {
                return;
            }

            try
            {
                bool success;
                lock (deviceAccessLock)
                {
                    success = ReadAndProcessAttendanceData();
                }

                if (!success)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Failed to read attendance data, refreshing device...");
                    device.RefreshData(1);
                    Thread.Sleep(1000);

                    success = ReadAndProcessAttendanceData();
                    if (!success)
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Failed to read attendance data after retry");
                        deviceConnected = false;
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error processing attendance logs: {ex.Message}");
                deviceConnected = false;
            }
        }

        static bool ReadAndProcessAttendanceData()
        {
            try
            {
                // Wrap low-level device iteration too to ensure exclusive access
                lock (deviceAccessLock)
                {
                    if (!device.ReadAllGLogData(1))
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No new attendance data available");
                        return false;
                    }
                }

                int processedCount = 0;
                int skippedCount = 0;

                using (MySqlConnection conn = new MySqlConnection(connStr))
                {
                    conn.Open();

                    string enrollNo;
                    int verifyMode = 0, inOutMode = 0, workCode = 0;
                    int year = 0, month = 0, day = 0, hour = 0, minute = 0, second = 0;

                    lock (deviceAccessLock)
                    {
                        while (device.SSR_GetGeneralLogData(1, out enrollNo, out verifyMode,
                              out inOutMode, out year, out month, out day, out hour, out minute, out second, ref workCode))
                        {
                            try
                            {
                                if (string.IsNullOrWhiteSpace(enrollNo))
                                {
                                    skippedCount++;
                                    continue;
                                }

                                DateTime logTime = new DateTime(year, month, day, hour, minute, second);
                                
                                // Skip logs older than 1 week (same as full sync)
                                DateTime oneWeekAgo = DateTime.Today.AddDays(-7);
                                if (logTime.Date < oneWeekAgo)
                                {
                                    skippedCount++;
                                    continue;
                                }
                                
                                string logIdentifier = $"{enrollNo}_{logTime:yyyyMMddHHmmss}";

                                if (processedLogs.Contains(logIdentifier))
                                {
                                    skippedCount++;
                                    continue;
                                }

                                if (logTime.Date == DateTime.Today)
                                {
                                    // Get employee shift for console display
                                    string cleanedEnrollNo = System.Text.RegularExpressions.Regex.Replace(enrollNo, @"[^0-9]", "");
                                    if (!string.IsNullOrWhiteSpace(cleanedEnrollNo) && int.TryParse(cleanedEnrollNo, out int employeeId))
                                    {
                                        string shiftType = GetShiftString(conn, employeeId);
                                        string shiftDisplay = GetShiftDisplayName(shiftType);
                                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing today's attendance: {enrollNo} at {logTime:yyyy-MM-dd HH:mm:ss} [{shiftDisplay}]");
                                    }
                                    else
                                    {
                                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing today's attendance: {enrollNo} at {logTime:yyyy-MM-dd HH:mm:ss}");
                                    }
                                }

                                processedLogs.Add(logIdentifier);
                                ProcessAttendanceLog(conn, enrollNo, logTime);
                                processedCount++;
                            }
                            catch (Exception ex)
                            {
                                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error processing individual log: {ex.Message}");
                            }
                        }
                    }

                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing complete - Processed: {processedCount}, Skipped: {skippedCount}");
                }

                return processedCount > 0 || skippedCount > 0;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error in ReadAndProcessAttendanceData: {ex.Message}");
                return false;
            }
        }

        /// <summary>
        /// Check if a specific attendance log already exists in the database
        /// </summary>
        /// <param name="conn">Database connection</param>
        /// <param name="employeeId">Employee ID</param>
        /// <param name="logTime">Log timestamp</param>
        /// <returns>True if log already exists, false otherwise</returns>
        static bool LogAlreadyExistsInDatabase(MySqlConnection conn, int employeeId, DateTime logTime)
        {
            try
            {
                string dateString = logTime.ToString("yyyy-MM-dd");
                string timeString = logTime.ToString("HH:mm:ss");
                
                // Check if any punch time matches this exact time (within 2 minutes tolerance)
                using (var cmd = new MySqlCommand(
                    "SELECT COUNT(*) FROM attendance " +
                    "WHERE EmployeeID = @empId AND attendance_date = @date " +
                    "AND (" +
                    "  (time_in_morning IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_in_morning), @logTime)) <= 120) OR " +
                    "  (time_out_morning IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_out_morning), @logTime)) <= 120) OR " +
                    "  (time_in_afternoon IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_in_afternoon), @logTime)) <= 120) OR " +
                    "  (time_out_afternoon IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_out_afternoon), @logTime)) <= 120) OR " +
                    "  (time_in IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_in), @logTime)) <= 120) OR " +
                    "  (time_out IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(attendance_date, ' ', time_out), @logTime)) <= 120)" +
                    ")", conn))
                {
                    cmd.Parameters.AddWithValue("@empId", employeeId);
                    cmd.Parameters.AddWithValue("@date", dateString);
                    cmd.Parameters.AddWithValue("@logTime", logTime.ToString("yyyy-MM-dd HH:mm:ss"));
                    
                    int count = Convert.ToInt32(cmd.ExecuteScalar());
                    return count > 0;
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error checking existing log: {ex.Message}");
                return false; // If error, assume it doesn't exist to be safe
            }
        }

        static void FullSyncAllDeviceLogsToDb()
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Starting limited attendance sync (past 1 week only)");
            if (!deviceConnected)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device not connected; skipping full sync");
                return;
            }

            int totalRead = 0;
            int totalApplied = 0;
            int totalSkipped = 0;
            int totalOutOfRange = 0;

            // Calculate date range - only past 1 week
            DateTime oneWeekAgo = DateTime.Today.AddDays(-7);
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Syncing data from {oneWeekAgo:yyyy-MM-dd} to {DateTime.Today:yyyy-MM-dd}");

            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Reading attendance data from device (past 1 week only)...");

                if (!device.ReadAllGLogData(1))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No logs available from device");
                    return;
                }

                using (MySqlConnection conn = new MySqlConnection(connStr))
                {
                    conn.Open();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database connection established");

                    string enrollNo;
                    int verifyMode = 0, inOutMode = 0, workCode = 0;
                    int year = 0, month = 0, day = 0, hour = 0, minute = 0, second = 0;

                    while (device.SSR_GetGeneralLogData(1, out enrollNo, out verifyMode,
                          out inOutMode, out year, out month, out day, out hour, out minute, out second, ref workCode))
                    {
                        totalRead++;

                        try
                        {
                            if (string.IsNullOrWhiteSpace(enrollNo))
                            {
                                totalSkipped++;
                                continue;
                            }

                            string cleanedEnrollNo = System.Text.RegularExpressions.Regex.Replace(enrollNo, @"[^0-9]", "");
                            if (string.IsNullOrWhiteSpace(cleanedEnrollNo) || !int.TryParse(cleanedEnrollNo, out int employeeId))
                            {
                                totalSkipped++;
                                continue;
                            }

                            DateTime logTime = new DateTime(year, month, day, hour, minute, second);
                            
                            // Skip logs older than 1 week
                            if (logTime.Date < oneWeekAgo)
                            {
                                totalOutOfRange++;
                                continue;
                            }

                            string logIdentifier = $"{enrollNo}_{logTime:yyyyMMddHHmmss}";

                            if (processedLogs.Contains(logIdentifier))
                            {
                                totalSkipped++;
                                continue;
                            }

                            // Check if this log already exists in database to avoid duplicates
                            if (LogAlreadyExistsInDatabase(conn, employeeId, logTime))
                            {
                                totalSkipped++;
                                processedLogs.Add(logIdentifier); // Mark as processed to avoid re-checking
                                continue;
                            }

                            bool applied = ProcessSyncLog(conn, employeeId, logTime);
                            if (applied)
                            {
                                totalApplied++;
                                processedLogs.Add(logIdentifier);

                                if (logTime.Date == DateTime.Today)
                                {
                                    // Get employee shift for console display
                                    string shiftType = GetShiftString(conn, employeeId);
                                    string shiftDisplay = GetShiftDisplayName(shiftType);
                                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Synced today's record: Employee {employeeId} at {logTime:HH:mm:ss} [{shiftDisplay}]");
                                }
                                else
                                {
                                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Synced historical record: Employee {employeeId} on {logTime:yyyy-MM-dd} at {logTime:HH:mm:ss}");
                                }
                            }
                            else
                            {
                                totalSkipped++;
                            }
                        }
                        catch (Exception ex)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error processing log entry: {ex.Message}");
                        }
                    }
                }

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Limited sync complete - Read: {totalRead}, Applied: {totalApplied}, Skipped: {totalSkipped}, Out of Range: {totalOutOfRange}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Sync period: {oneWeekAgo:yyyy-MM-dd} to {DateTime.Today:yyyy-MM-dd}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Limited sync exception: {ex.Message}");
            }
        }

        static (int totalLogs, int daysAffected, int employeesAffected) ResyncRangeFromDevice(DateTime startDate, DateTime endDate)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] RESYNC from device for range {startDate:yyyy-MM-dd} to {endDate:yyyy-MM-dd}");
            int totalApplied = 0;
            var affectedDays = new HashSet<string>();
            var affectedEmployees = new HashSet<int>();

            if (!deviceConnected)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device not connected; aborting RESYNC");
                return (0, 0, 0);
            }

            try
            {
                if (!device.ReadAllGLogData(1))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device returned no logs for RESYNC");
                    return (0, 0, 0);
                }

                var logs = new List<(int employeeId, DateTime punchTime)>();
                string enrollNo;
                int verifyMode = 0, inOutMode = 0, workCode = 0;
                int year = 0, month = 0, day = 0, hour = 0, minute = 0, second = 0;

                while (device.SSR_GetGeneralLogData(1, out enrollNo, out verifyMode,
                      out inOutMode, out year, out month, out day, out hour, out minute, out second, ref workCode))
                {
                    try
                    {
                        if (string.IsNullOrWhiteSpace(enrollNo)) continue;
                        string cleaned = System.Text.RegularExpressions.Regex.Replace(enrollNo, @"[^0-9]", "");
                        if (!int.TryParse(cleaned, out int employeeId)) continue;
                        var pt = new DateTime(year, month, day, hour, minute, second);
                        if (pt.Date < startDate.Date || pt.Date > endDate.Date) continue;
                        logs.Add((employeeId, pt));
                    }
                    catch { }
                }

                // Group logs by employee and date
                var grouped = logs
                    .GroupBy(x => new { x.employeeId, date = x.punchTime.Date })
                    .OrderBy(g => g.Key.employeeId)
                    .ThenBy(g => g.Key.date);

                using (var conn = new MySqlConnection(connStr))
                {
                    conn.Open();
                    foreach (var g in grouped)
                    {
                        int empId = g.Key.employeeId;
                        string dateString = g.Key.date.ToString("yyyy-MM-dd");
                        affectedDays.Add(dateString);
                        affectedEmployees.Add(empId);

                        // Clear existing punches for the day (keep non-time fields intact)
                        ClearAttendanceDay(conn, empId, dateString);

                        // Ensure record exists and set attendance_type to present
                        using (var ensure = new MySqlCommand(
                            "INSERT INTO attendance (EmployeeID, attendance_date, attendance_type) " +
                            "SELECT @e, @d, 'present' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM attendance WHERE EmployeeID=@e AND attendance_date=@d)", conn))
                        {
                            ensure.Parameters.AddWithValue("@e", empId);
                            ensure.Parameters.AddWithValue("@d", dateString);
                            ensure.ExecuteNonQuery();
                        }

                        // Apply punches in chronological order using existing logic
                        foreach (var rec in g.OrderBy(x => x.punchTime))
                        {
                            string targetColumn = DeterminePunchColumn(conn, empId, rec.punchTime);
                            ApplyPunchToColumn(conn, empId, rec.punchTime, targetColumn);
                            totalApplied++;
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] RESYNC exception: {ex.Message}");
            }

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] RESYNC complete - Applied: {totalApplied}, Days: {affectedDays.Count}, Employees: {affectedEmployees.Count}");
            return (totalApplied, affectedDays.Count, affectedEmployees.Count);
        }

        static void ClearAttendanceDay(MySqlConnection conn, int employeeId, string dateString)
        {
            using (var cmd = new MySqlCommand(
                "UPDATE attendance SET " +
                "time_in=NULL, time_out=NULL, " +
                "time_in_morning=NULL, time_out_morning=NULL, " +
                "time_in_afternoon=NULL, time_out_afternoon=NULL, " +
                "late_minutes=0, early_out_minutes=0, overtime_hours=0.00, is_overtime=0, status=NULL " +
                "WHERE EmployeeID=@e AND attendance_date=@d", conn))
            {
                cmd.Parameters.AddWithValue("@e", employeeId);
                cmd.Parameters.AddWithValue("@d", dateString);
                int rows = cmd.ExecuteNonQuery();
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Cleared all punches (including overall time_in/time_out) for Employee {employeeId} on {dateString} (rows: {rows})");
            }
        }

        static void HandleTestDbRequest(HttpListenerResponse response)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database test request");
            
            try
            {
                using (var conn = new MySqlConnection(connStr))
                {
                    conn.Open();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database connection successful");
                    
                    // Test basic query
                    object count = 0;
                    using (var cmd = new MySqlCommand("SELECT COUNT(*) FROM attendance", conn))
                    {
                        count = cmd.ExecuteScalar();
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Total attendance records: {count}");
                    }
                    
                    // Show recent records
                    using (var cmd = new MySqlCommand(
                        "SELECT EmployeeID, attendance_date, time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon, time_in, time_out " +
                        "FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) " +
                        "ORDER BY attendance_date DESC, EmployeeID LIMIT 10", conn))
                    {
                        var reader = cmd.ExecuteReader();
                        var records = new List<object>();
                        
                        while (reader.Read())
                        {
                            records.Add(new
                            {
                                EmployeeID = reader["EmployeeID"],
                                Date = reader["attendance_date"],
                                AM_In = reader["time_in_morning"]?.ToString() ?? "NULL",
                                AM_Out = reader["time_out_morning"]?.ToString() ?? "NULL",
                                PM_In = reader["time_in_afternoon"]?.ToString() ?? "NULL",
                                PM_Out = reader["time_out_afternoon"]?.ToString() ?? "NULL",
                                Overall_In = reader["time_in"]?.ToString() ?? "NULL",
                                Overall_Out = reader["time_out"]?.ToString() ?? "NULL"
                            });
                        }
                        reader.Close();
                        
                        string json = JsonConvert.SerializeObject(new
                        {
                            status = "success",
                            message = "Database connection successful",
                            total_records = count,
                            recent_records = records
                        }, Formatting.Indented);
                        
                        SendJsonResponse(response, json, 200);
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database test completed - showing {records.Count} recent records");
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database test failed: {ex.Message}");
                SendErrorResponse(response, 500, $"Database test failed: {ex.Message}");
            }
        }

        static bool ProcessSyncLog(MySqlConnection conn, int employeeId, DateTime deviceLogTime)
        {
            try
            {
                string employeeName = GetEmployeeName(conn, employeeId);
                if (string.IsNullOrEmpty(employeeName)) return false;

                // Use the device log time to determine and apply punches
                string targetColumn = DeterminePunchColumn(conn, employeeId, deviceLogTime);

                // If DeterminePunchColumn returns null, it means this is a duplicate that should be skipped
                if (targetColumn == null)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Skipping duplicate punch for Employee {employeeId} at {deviceLogTime:HH:mm:ss}");
                    return false;
                }

                ApplyPunchToColumn(conn, employeeId, deviceLogTime, targetColumn);
                return true;
            }
            catch
            {
                return false;
            }
        }

        // FIXED: Get existing punches with proper TimeSpan handling
        static (string time_in_morning, string time_out_morning, string time_in_afternoon, string time_out_afternoon) 
            GetExistingPunches(MySqlConnection conn, int employeeId, string dateString)
        {
            try
            {
                using (var cmd = new MySqlCommand(
                    "SELECT time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon " +
                    "FROM attendance WHERE EmployeeID = @e AND attendance_date = @d", conn))
                {
                    cmd.Parameters.AddWithValue("@e", employeeId);
                    cmd.Parameters.AddWithValue("@d", dateString);

                    using (var reader = cmd.ExecuteReader())
                    {
                        if (reader.Read())
                        {
                            // Handle TimeSpan values properly by converting to string
                            string timeInMorning = reader.IsDBNull(0) ? null : reader.GetTimeSpan(0).ToString(@"hh\:mm\:ss");
                            string timeOutMorning = reader.IsDBNull(1) ? null : reader.GetTimeSpan(1).ToString(@"hh\:mm\:ss");
                            string timeInAfternoon = reader.IsDBNull(2) ? null : reader.GetTimeSpan(2).ToString(@"hh\:mm\:ss");
                            string timeOutAfternoon = reader.IsDBNull(3) ? null : reader.GetTimeSpan(3).ToString(@"hh\:mm\:ss");
                            
                            return (timeInMorning, timeOutMorning, timeInAfternoon, timeOutAfternoon);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error getting existing punches: {ex.Message}");
            }
            return (null, null, null, null);
        }

        // NEW: Get existing punches including overtime columns
        static (string time_in_morning, string time_out_morning, string time_in_afternoon, string time_out_afternoon, 
                string overtime_time_in, string overtime_time_out) 
            GetExistingPunchesWithOvertime(MySqlConnection conn, int employeeId, string dateString)
        {
            try
            {
                using (var cmd = new MySqlCommand(
                    "SELECT time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon, " +
                    "overtime_time_in, overtime_time_out " +
                    "FROM attendance WHERE EmployeeID = @e AND attendance_date = @d", conn))
                {
                    cmd.Parameters.AddWithValue("@e", employeeId);
                    cmd.Parameters.AddWithValue("@d", dateString);

                    using (var reader = cmd.ExecuteReader())
                    {
                        if (reader.Read())
                        {
                            // Handle TimeSpan values properly by converting to string
                            string timeInMorning = reader.IsDBNull(0) ? null : reader.GetTimeSpan(0).ToString(@"hh\:mm\:ss");
                            string timeOutMorning = reader.IsDBNull(1) ? null : reader.GetTimeSpan(1).ToString(@"hh\:mm\:ss");
                            string timeInAfternoon = reader.IsDBNull(2) ? null : reader.GetTimeSpan(2).ToString(@"hh\:mm\:ss");
                            string timeOutAfternoon = reader.IsDBNull(3) ? null : reader.GetTimeSpan(3).ToString(@"hh\:mm\:ss");
                            string overtimeTimeIn = reader.IsDBNull(4) ? null : reader.GetTimeSpan(4).ToString(@"hh\:mm\:ss");
                            string overtimeTimeOut = reader.IsDBNull(5) ? null : reader.GetTimeSpan(5).ToString(@"hh\:mm\:ss");
                            
                            return (timeInMorning, timeOutMorning, timeInAfternoon, timeOutAfternoon, overtimeTimeIn, overtimeTimeOut);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error getting existing punches with overtime: {ex.Message}");
            }
            return (null, null, null, null, null, null);
        }

        // NEW: Check if punch time is outside shift schedule
        static bool IsOutsideShiftSchedule(TimeSpan punchTime, ShiftConfig shift)
        {
            // For day shifts, overtime is after shift end
            if (shift.Start < shift.End) // Day shift (e.g., 8:00-17:00)
            {
                return punchTime > shift.End;
            }
            else // Night shift (e.g., 22:00-06:00)
            {
                // For night shift, overtime is before shift start or after shift end
                return punchTime < shift.Start || punchTime > shift.End;
            }
        }

        // ENHANCED: Punch determination with time-based morning/afternoon logic
        static string DeterminePunchColumn(MySqlConnection conn, int employeeId, DateTime punchTime)
        {
            var shift = GetShiftConfig(conn, employeeId);
            var ts = punchTime.TimeOfDay;
            string dateString = punchTime.ToString("yyyy-MM-dd");

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Determining punch column - Shift: {shift.Start:hh\\:mm}-{shift.End:hh\\:mm}, Lunch: {shift.LunchStart:hh\\:mm}-{shift.LunchEnd:hh\\:mm}, Punch: {ts:hh\\:mm}");

            // Check what's already in the database - including overtime columns
            var existing = GetExistingPunchesWithOvertime(conn, employeeId, dateString);

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Existing punches - AM In: {existing.time_in_morning ?? "None"}, AM Out: {existing.time_out_morning ?? "None"}, PM In: {existing.time_in_afternoon ?? "None"}, PM Out: {existing.time_out_afternoon ?? "None"}, OT In: {existing.overtime_time_in ?? "None"}, OT Out: {existing.overtime_time_out ?? "None"}");

            // Determine if punch time is in morning or afternoon session
            // Morning: before lunch start (or at shift start if before lunch)
            // Afternoon: at or after lunch start
            bool isMorningSession = ts < shift.LunchStart;
            bool isAfternoonSession = ts >= shift.LunchStart && ts <= shift.End;
            
            // Handle night shift (e.g., 22:00-06:00) - reverse logic
            if (shift.Start > shift.End) // Night shift
            {
                // For night shift, check if time is before midnight or after
                if (ts >= shift.Start) // 22:00-23:59 (before midnight)
                {
                    isMorningSession = ts < shift.LunchStart; // Before 2:00 AM
                    isAfternoonSession = ts >= shift.LunchStart && ts <= new TimeSpan(23, 59, 59); // After 2:00 AM, before midnight
                }
                else // 00:00-06:00 (after midnight)
                {
                    isMorningSession = false; // All after midnight is afternoon for night shift
                    isAfternoonSession = ts <= shift.End; // Up to 6:00 AM
                }
            }

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Punch time analysis - IsMorning: {isMorningSession}, IsAfternoon: {isAfternoonSession}");

            // PRIORITY 1: Check for exact time matches to avoid duplicates
            if (!string.IsNullOrEmpty(existing.time_in_morning))
            {
                if (TimeSpan.TryParse(existing.time_in_morning, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_in_morning - skipping");
                    return null; // Signal to skip this punch
                }
            }
            if (!string.IsNullOrEmpty(existing.time_out_morning))
            {
                if (TimeSpan.TryParse(existing.time_out_morning, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_out_morning - skipping");
                    return null; // Signal to skip this punch
                }
            }
            if (!string.IsNullOrEmpty(existing.time_in_afternoon))
            {
                if (TimeSpan.TryParse(existing.time_in_afternoon, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_in_afternoon - skipping");
                    return null; // Signal to skip this punch
                }
            }
            if (!string.IsNullOrEmpty(existing.time_out_afternoon))
            {
                if (TimeSpan.TryParse(existing.time_out_afternoon, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_out_afternoon - skipping");
                    return null; // Signal to skip this punch
                }
            }
            if (!string.IsNullOrEmpty(existing.overtime_time_in))
            {
                if (TimeSpan.TryParse(existing.overtime_time_in, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for overtime_time_in - skipping");
                    return null; // Signal to skip this punch
                }
            }
            if (!string.IsNullOrEmpty(existing.overtime_time_out))
            {
                if (TimeSpan.TryParse(existing.overtime_time_out, out TimeSpan existingTime) && 
                    Math.Abs((ts - existingTime).TotalMinutes) < 2)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for overtime_time_out - skipping");
                    return null; // Signal to skip this punch
                }
            }

            // PRIORITY 2: Time-based session assignment
            // If punch is in afternoon session (at/after lunch start), assign to afternoon columns
            if (isAfternoonSession)
            {
                // Check afternoon session slots
                if (string.IsNullOrEmpty(existing.time_in_afternoon))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon punch - assigning to time_in_afternoon");
                    return "time_in_afternoon";
                }
                if (string.IsNullOrEmpty(existing.time_out_afternoon))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon punch - assigning to time_out_afternoon");
                    return "time_out_afternoon";
                }
                // Both afternoon slots filled - check if this is overtime
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon session complete - checking for overtime");
            }
            
            // If punch is in morning session (before lunch start), assign to morning columns
            if (isMorningSession)
            {
                // Check morning session slots
                if (string.IsNullOrEmpty(existing.time_in_morning))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning punch - assigning to time_in_morning");
                    return "time_in_morning";
                }
                if (string.IsNullOrEmpty(existing.time_out_morning))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning punch - assigning to time_out_morning");
                    return "time_out_morning";
                }
                // Both morning slots filled - check if this is afternoon (edge case: late morning punch after morning complete)
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning session complete - checking if punch belongs to afternoon");
            }

            // PRIORITY 3: Fallback - Sequential logic if time-based fails (edge cases)
            // If all slots are empty, use time-based assignment
            if (string.IsNullOrEmpty(existing.time_in_morning) && string.IsNullOrEmpty(existing.time_out_morning) && 
                string.IsNullOrEmpty(existing.time_in_afternoon) && string.IsNullOrEmpty(existing.time_out_afternoon))
            {
                if (isAfternoonSession)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] First punch of day (afternoon time) - assigning to time_in_afternoon");
                    return "time_in_afternoon";
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] First punch of day (morning time) - assigning to time_in_morning");
                    return "time_in_morning";
                }
            }

            // PRIORITY 4: Sequential fallback within same session
            // If time-based assignment didn't work, try sequential within the detected session
            if (isAfternoonSession)
            {
                if (string.IsNullOrEmpty(existing.time_in_afternoon))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon session - missing time_in_afternoon - assigning");
                    return "time_in_afternoon";
                }
                if (string.IsNullOrEmpty(existing.time_out_afternoon))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon session - missing time_out_afternoon - assigning");
                    return "time_out_afternoon";
                }
            }
            else if (isMorningSession)
            {
                if (string.IsNullOrEmpty(existing.time_in_morning))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning session - missing time_in_morning - assigning");
                    return "time_in_morning";
                }
                if (string.IsNullOrEmpty(existing.time_out_morning))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning session - missing time_out_morning - assigning");
                    return "time_out_morning";
                }
            }

            // PRIORITY 5: Overtime logic for 5th+ punches
            // Check if punch is outside shift schedule (overtime condition)
            bool isOutsideShift = IsOutsideShiftSchedule(ts, shift);
            
            if (isOutsideShift)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Punch {ts:hh\\:mm} is outside shift schedule {shift.Start:hh\\:mm}-{shift.End:hh\\:mm} - checking overtime assignment");
                
                if (string.IsNullOrEmpty(existing.overtime_time_in))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing overtime_time_in - assigning 5th punch");
                    return "overtime_time_in";
                }
                if (string.IsNullOrEmpty(existing.overtime_time_out))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing overtime_time_out - assigning 6th punch");
                    return "overtime_time_out";
                }
                
                // Both overtime slots filled - this is a 7th+ punch
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] All overtime slots filled - this is a 7th+ punch for Employee {employeeId}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Logging as additional overtime punch");
                return "overtime_additional"; // Special marker for additional punches
            }
            else
            {
                // Punch is within shift schedule but all 4 main slots are filled
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Punch {ts:hh\\:mm} is within shift schedule but all main slots filled - skipping");
                return null; // Skip this punch
            }
        }

        // NEW: Helper method to count existing punches
        static int GetPunchCount((string time_in_morning, string time_out_morning, string time_in_afternoon, string time_out_afternoon) existing)
        {
            int count = 0;
            if (!string.IsNullOrEmpty(existing.time_in_morning)) count++;
            if (!string.IsNullOrEmpty(existing.time_out_morning)) count++;
            if (!string.IsNullOrEmpty(existing.time_in_afternoon)) count++;
            if (!string.IsNullOrEmpty(existing.time_out_afternoon)) count++;
            return count;
        }

        // NEW: Log overtime punch without overwriting data
        static void LogOvertimePunch(MySqlConnection conn, int employeeId, DateTime punchTime)
        {
            try
            {
                string dateString = punchTime.ToString("yyyy-MM-dd");
                string timeString = punchTime.ToString("HH:mm:ss");
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] LOGGING OVERTIME PUNCH - Employee {employeeId} at {timeString}");
                
                // Log the overtime punch in a separate table or add to existing record
                using (var logCmd = new MySqlCommand(
                    "INSERT INTO overtime_punches (EmployeeID, punch_date, punch_time, created_at) VALUES (@e, @d, @t, NOW()) " +
                    "ON DUPLICATE KEY UPDATE punch_time = @t, created_at = NOW()", conn))
                {
                    logCmd.Parameters.AddWithValue("@e", employeeId);
                    logCmd.Parameters.AddWithValue("@d", dateString);
                    logCmd.Parameters.AddWithValue("@t", timeString);
                    logCmd.ExecuteNonQuery();
                }
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime punch logged successfully for Employee {employeeId}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error logging overtime punch: {ex.Message}");
            }
        }

        // NEW: Handle overtime time-in (5th punch)
        static void HandleOvertimeTimeIn(MySqlConnection conn, int employeeId, string dateString, TimeSpan overtimeTimeIn)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing overtime time-in for Employee {employeeId} at {overtimeTimeIn:hh\\:mm}");
                
                // Get employee's shift to determine NSD cutoff
                var shift = GetShiftConfig(conn, employeeId);
                TimeSpan nsdCutoff = GetNSDCutoffTime(shift);
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] NSD cutoff time: {nsdCutoff:hh\\:mm}");
                
                // If overtime time-in is after NSD cutoff, set it to NSD cutoff
                if (overtimeTimeIn > nsdCutoff)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-in {overtimeTimeIn:hh\\:mm} is after NSD cutoff {nsdCutoff:hh\\:mm} - adjusting to cutoff");
                    overtimeTimeIn = nsdCutoff;
                    
                    // Update the database with adjusted time
                    using (var updateCmd = new MySqlCommand(
                        "UPDATE attendance SET overtime_time_in = @t WHERE EmployeeID = @e AND attendance_date = @d", conn))
                    {
                        updateCmd.Parameters.AddWithValue("@t", overtimeTimeIn);
                        updateCmd.Parameters.AddWithValue("@e", employeeId);
                        updateCmd.Parameters.AddWithValue("@d", dateString);
                        updateCmd.ExecuteNonQuery();
                    }
                }
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-in processed successfully for Employee {employeeId}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error handling overtime time-in: {ex.Message}");
            }
        }

        // NEW: Handle overtime time-out (6th punch)
        static void HandleOvertimeTimeOut(MySqlConnection conn, int employeeId, string dateString, TimeSpan overtimeTimeOut)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Processing overtime time-out for Employee {employeeId} at {overtimeTimeOut:hh\\:mm}");
                
                // Get employee's shift to determine NSD cutoff
                var shift = GetShiftConfig(conn, employeeId);
                TimeSpan nsdCutoff = GetNSDCutoffTime(shift);
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] NSD cutoff time: {nsdCutoff:hh\\:mm}");
                
                // If overtime time-out is after NSD cutoff, set it to NSD cutoff
                if (overtimeTimeOut > nsdCutoff)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-out {overtimeTimeOut:hh\\:mm} is after NSD cutoff {nsdCutoff:hh\\:mm} - adjusting to cutoff");
                    overtimeTimeOut = nsdCutoff;
                    
                    // Update the database with adjusted time
                    using (var updateCmd = new MySqlCommand(
                        "UPDATE attendance SET overtime_time_out = @t WHERE EmployeeID = @e AND attendance_date = @d", conn))
                    {
                        updateCmd.Parameters.AddWithValue("@t", overtimeTimeOut);
                        updateCmd.Parameters.AddWithValue("@e", employeeId);
                        updateCmd.Parameters.AddWithValue("@d", dateString);
                        updateCmd.ExecuteNonQuery();
                    }
                }
                
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-out processed successfully for Employee {employeeId}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error handling overtime time-out: {ex.Message}");
            }
        }

        // NEW: Get NSD cutoff time based on shift
        static TimeSpan GetNSDCutoffTime(ShiftConfig shift)
        {
            // NSD cutoff is typically 10:00 PM (22:00) for day shifts
            if (shift.Start < shift.End) // Day shift
            {
                return new TimeSpan(22, 0, 0); // 10:00 PM
            }
            else // Night shift
            {
                return new TimeSpan(6, 0, 0); // 6:00 AM (next day)
            }
        }

        // ENHANCED: Apply punch to column with overtime support
        static void ApplyPunchToColumn(MySqlConnection conn, int employeeId, DateTime punchTime, string targetColumn)
        {
            string dateString = punchTime.ToString("yyyy-MM-dd");
            string timeString = punchTime.ToString("HH:mm:ss");

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Applying punch - Employee {employeeId}, Date: {dateString}, Time: {timeString}, Column: {targetColumn}");

            // Ensure attendance record exists
            using (var ensureCmd = new MySqlCommand(
                "INSERT INTO attendance (EmployeeID, attendance_date, attendance_type) " +
                "SELECT @e, @d, 'present' FROM DUAL " +
                "WHERE NOT EXISTS (SELECT 1 FROM attendance WHERE EmployeeID=@e AND attendance_date=@d)",
                conn))
            {
                ensureCmd.Parameters.AddWithValue("@e", employeeId);
                ensureCmd.Parameters.AddWithValue("@d", dateString);
                int rowsInserted = ensureCmd.ExecuteNonQuery();
                if (rowsInserted > 0)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Created new attendance record for Employee {employeeId} on {dateString}");
                }
            }

            // Update the specific column using TimeSpan value
            using (var updateCmd = new MySqlCommand(
                $"UPDATE attendance SET {targetColumn} = @t WHERE EmployeeID = @e AND attendance_date = @d",
                conn))
            {
                updateCmd.Parameters.AddWithValue("@t", punchTime.TimeOfDay); // Use TimeSpan directly
                updateCmd.Parameters.AddWithValue("@e", employeeId);
                updateCmd.Parameters.AddWithValue("@d", dateString);
                int rowsUpdated = updateCmd.ExecuteNonQuery();

                if (rowsUpdated > 0)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Updated {targetColumn} for Employee {employeeId}: {timeString}");
                    
                    // Calculate and set status (early/late) only when morning time-in is recorded
                    // Note: Afternoon-only halfdays will have status calculated by attendance_calculations.php
                    // to properly handle halfday detection
                    if (targetColumn == "time_in_morning")
                    {
                        CalculateAndSetStatus(conn, employeeId, dateString, punchTime.TimeOfDay);
                    }
                    // For afternoon punches, status will be calculated by attendance_calculations.php
                    // which properly handles halfday detection
                    
                    // Handle overtime logic for 5th punch (overtime_time_in)
                    if (targetColumn == "overtime_time_in")
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-in recorded for Employee {employeeId} at {timeString}");
                        HandleOvertimeTimeIn(conn, employeeId, dateString, punchTime.TimeOfDay);
                    }
                    
                    // Handle overtime logic for 6th punch (overtime_time_out)
                    if (targetColumn == "overtime_time_out")
                    {
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overtime time-out recorded for Employee {employeeId} at {timeString}");
                        HandleOvertimeTimeOut(conn, employeeId, dateString, punchTime.TimeOfDay);
                    }
                    
                    // Update overall time_in/time_out fields when appropriate
                    UpdateOverallTimeFields(conn, employeeId, dateString, targetColumn, punchTime.TimeOfDay);
                    
                    // Update currently at work status for main time_in/time_out
                    UpdateCurrentlyAtWorkStatus(conn, employeeId, dateString, targetColumn, timeString);

                    // Display updated status if it's today's attendance
                    if (punchTime.Date == DateTime.Today)
                    {
                        DisplayCurrentlyAtWork();
                    }
                }
                else
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No rows updated for Employee {employeeId}");
                }
            }
        }

        // FIXED: Calculate and set status based on shift timing
        static void CalculateAndSetStatus(MySqlConnection conn, int employeeId, string dateString, TimeSpan punchTime)
        {
            try
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Calculating status for Employee {employeeId} - Punch time: {punchTime:hh\\:mm}");
                
                // Get employee's shift configuration
                var shift = GetShiftConfig(conn, employeeId);
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} shift: {shift.Start:hh\\:mm} - {shift.End:hh\\:mm}");
                
                // Use the punch time directly for comparison
                TimeSpan actualTimeIn = punchTime;
                
                string status = null;
                
                // Compare actual time in with shift start time
                if (actualTimeIn < shift.Start)
                {
                    // Employee arrived before shift start - mark as early
                    status = "early";
                    TimeSpan earlyMinutes = shift.Start - actualTimeIn;
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} arrived early: {actualTimeIn:hh\\:mm} (before shift start {shift.Start:hh\\:mm}, {earlyMinutes.TotalMinutes:F0} minutes early)");
                }
                else if (actualTimeIn > shift.Start)
                {
                    // Employee arrived after shift start - mark as late
                    status = "late";
                    TimeSpan lateMinutes = actualTimeIn - shift.Start;
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} arrived late: {actualTimeIn:hh\\:mm} (after shift start {shift.Start:hh\\:mm}, {lateMinutes.TotalMinutes:F0} minutes late)");
                }
                else
                {
                    // Employee arrived exactly on time - no status needed
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} arrived on time: {actualTimeIn:hh\\:mm}");
                }
                
                // Update the status in the database if we determined one
                if (!string.IsNullOrEmpty(status))
                {
                    using (var updateStatusCmd = new MySqlCommand(
                        "UPDATE attendance SET status = @status WHERE EmployeeID = @e AND attendance_date = @d", conn))
                    {
                        updateStatusCmd.Parameters.AddWithValue("@status", status);
                        updateStatusCmd.Parameters.AddWithValue("@e", employeeId);
                        updateStatusCmd.Parameters.AddWithValue("@d", dateString);
                        
                        int rowsUpdated = updateStatusCmd.ExecuteNonQuery();
                        if (rowsUpdated > 0)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Updated status to '{status}' for Employee {employeeId}");
                        }
                        else
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] WARNING: No rows updated for status - Employee {employeeId} may not have attendance record");
                        }
                    }
                }
                else
                {
                    // Clear any existing status if employee is on time
                    using (var clearStatusCmd = new MySqlCommand(
                        "UPDATE attendance SET status = NULL WHERE EmployeeID = @e AND attendance_date = @d", conn))
                    {
                        clearStatusCmd.Parameters.AddWithValue("@e", employeeId);
                        clearStatusCmd.Parameters.AddWithValue("@d", dateString);
                        clearStatusCmd.ExecuteNonQuery();
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error calculating status for Employee {employeeId}: {ex.Message}");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Stack trace: {ex.StackTrace}");
            }
        }

        // NEW: Update overall time_in/time_out fields based on specific column updates
        // Also handles halfday detection with exactly 2 punches
        static void UpdateOverallTimeFields(MySqlConnection conn, int employeeId, string dateString, string targetColumn, TimeSpan punchTime)
        {
            try
            {
                // Get all current time values to detect halfday scenarios
                using (var getAllTimesCmd = new MySqlCommand(
                    "SELECT time_in, time_out, time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon " +
                    "FROM attendance WHERE EmployeeID = @e AND attendance_date = @d", conn))
                {
                    getAllTimesCmd.Parameters.AddWithValue("@e", employeeId);
                    getAllTimesCmd.Parameters.AddWithValue("@d", dateString);
                    
                    TimeSpan? currentTimeIn = null;
                    TimeSpan? currentTimeOut = null;
                    TimeSpan? amIn = null;
                    TimeSpan? amOut = null;
                    TimeSpan? pmIn = null;
                    TimeSpan? pmOut = null;
                    
                    using (var reader = getAllTimesCmd.ExecuteReader())
                    {
                        if (reader.Read())
                        {
                            currentTimeIn = reader.IsDBNull(0) ? null : (TimeSpan?)reader.GetTimeSpan(0);
                            currentTimeOut = reader.IsDBNull(1) ? null : (TimeSpan?)reader.GetTimeSpan(1);
                            amIn = reader.IsDBNull(2) ? null : (TimeSpan?)reader.GetTimeSpan(2);
                            amOut = reader.IsDBNull(3) ? null : (TimeSpan?)reader.GetTimeSpan(3);
                            pmIn = reader.IsDBNull(4) ? null : (TimeSpan?)reader.GetTimeSpan(4);
                            pmOut = reader.IsDBNull(5) ? null : (TimeSpan?)reader.GetTimeSpan(5);
                        }
                    }
                    
                    // Account for the punch we just applied
                    bool hasAmIn = amIn.HasValue || targetColumn == "time_in_morning";
                    bool hasAmOut = amOut.HasValue || targetColumn == "time_out_morning";
                    bool hasPmIn = pmIn.HasValue || targetColumn == "time_in_afternoon";
                    bool hasPmOut = pmOut.HasValue || targetColumn == "time_out_afternoon";
                    
                    // Get actual values (use punchTime if this column was just updated, otherwise use DB value)
                    TimeSpan actualAmIn = targetColumn == "time_in_morning" ? punchTime : (amIn.HasValue ? amIn.Value : TimeSpan.Zero);
                    TimeSpan actualAmOut = targetColumn == "time_out_morning" ? punchTime : (amOut.HasValue ? amOut.Value : TimeSpan.Zero);
                    TimeSpan actualPmIn = targetColumn == "time_in_afternoon" ? punchTime : (pmIn.HasValue ? pmIn.Value : TimeSpan.Zero);
                    TimeSpan actualPmOut = targetColumn == "time_out_afternoon" ? punchTime : (pmOut.HasValue ? pmOut.Value : TimeSpan.Zero);
                    
                    // Count total punches
                    int punchCount = (hasAmIn ? 1 : 0) + (hasAmOut ? 1 : 0) + (hasPmIn ? 1 : 0) + (hasPmOut ? 1 : 0);
                    
                    // Detect halfday: exactly 2 punches, all in one session
                    bool isMorningOnlyHalfday = hasAmIn && hasAmOut && !hasPmIn && !hasPmOut;
                    bool isAfternoonOnlyHalfday = !hasAmIn && !hasAmOut && hasPmIn && hasPmOut;
                    
                    bool updateTimeIn = false;
                    bool updateTimeOut = false;
                    TimeSpan? newTimeIn = currentTimeIn;
                    TimeSpan? newTimeOut = currentTimeOut;
                    
                    // If exactly 2 punches detected (halfday), automatically set time_in and time_out
                    if (punchCount == 2 && (isMorningOnlyHalfday || isAfternoonOnlyHalfday))
                    {
                        if (isMorningOnlyHalfday && actualAmIn != TimeSpan.Zero && actualAmOut != TimeSpan.Zero)
                        {
                            // Morning-only halfday: set time_in and time_out from morning values
                            newTimeIn = actualAmIn;
                            newTimeOut = actualAmOut;
                            updateTimeIn = true;
                            updateTimeOut = true;
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Halfday detected (2 punches) - Morning only: time_in={actualAmIn:hh\\:mm\\:ss}, time_out={actualAmOut:hh\\:mm\\:ss}");
                        }
                        else if (isAfternoonOnlyHalfday && actualPmIn != TimeSpan.Zero && actualPmOut != TimeSpan.Zero)
                        {
                            // Afternoon-only halfday: set time_in and time_out from afternoon values
                            newTimeIn = actualPmIn;
                            newTimeOut = actualPmOut;
                            updateTimeIn = true;
                            updateTimeOut = true;
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Halfday detected (2 punches) - Afternoon only: time_in={actualPmIn:hh\\:mm\\:ss}, time_out={actualPmOut:hh\\:mm\\:ss}");
                        }
                    }
                    else
                    {
                        // Normal logic for non-halfday scenarios
                        switch (targetColumn)
                        {
                            case "time_in_morning":
                                // If this is the first time in of the day, set overall time_in
                                if (!currentTimeIn.HasValue)
                                {
                                    newTimeIn = punchTime;
                                    updateTimeIn = true;
                                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Setting overall time_in to {punchTime:hh\\:mm\\:ss} (morning time in)");
                                }
                                break;
                                
                            case "time_out_morning":
                                // Don't update overall time_out for lunch break (unless it's a halfday)
                                break;
                                
                            case "time_in_afternoon":
                                // Don't update overall time_in for return from lunch (unless it's a halfday)
                                break;
                                
                            case "time_out_afternoon":
                                // This is the final time out of the day, set overall time_out
                                newTimeOut = punchTime;
                                updateTimeOut = true;
                                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Setting overall time_out to {punchTime:hh\\:mm\\:ss} (final time out)");
                                break;
                        }
                    }
                    
                    // Update the overall time fields if needed
                    if (updateTimeIn || updateTimeOut)
                    {
                        var updateFields = new List<string>();
                        var parameters = new List<MySqlParameter>();
                        
                        if (updateTimeIn)
                        {
                            updateFields.Add("time_in = @timeIn");
                            parameters.Add(new MySqlParameter("@timeIn", newTimeIn));
                        }
                        
                        if (updateTimeOut)
                        {
                            updateFields.Add("time_out = @timeOut");
                            parameters.Add(new MySqlParameter("@timeOut", newTimeOut));
                        }
                        
                        if (updateFields.Count > 0)
                        {
                            using (var updateOverallCmd = new MySqlCommand(
                                $"UPDATE attendance SET {string.Join(", ", updateFields)} WHERE EmployeeID = @e AND attendance_date = @d", conn))
                            {
                                updateOverallCmd.Parameters.AddWithValue("@e", employeeId);
                                updateOverallCmd.Parameters.AddWithValue("@d", dateString);
                                
                                foreach (var param in parameters)
                                {
                                    updateOverallCmd.Parameters.Add(param);
                                }
                                
                                int overallRowsUpdated = updateOverallCmd.ExecuteNonQuery();
                                if (overallRowsUpdated > 0)
                                {
                                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Updated overall time fields for Employee {employeeId}");
                                }
                            }
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error updating overall time fields: {ex.Message}");
            }
        }

        // FIXED: Update currently at work status
        static void UpdateCurrentlyAtWorkStatus(MySqlConnection conn, int employeeId, string dateString, string targetColumn, string timeString)
        {
            if (dateString != DateTime.Today.ToString("yyyy-MM-dd")) return;

            lock (lockObject)
            {
                // Employee arrives when they have morning time in
                if (targetColumn == "time_in_morning")
                {
                    currentlyAtWork[employeeId] = timeString;
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} marked as AT WORK (morning arrival)");
                }
                // Employee leaves for lunch - don't remove from currentlyAtWork
                else if (targetColumn == "time_out_morning")
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} on lunch break (still at work)");
                }
                // Employee returns from lunch - ensure they're still marked as at work
                else if (targetColumn == "time_in_afternoon")
                {
                    if (!currentlyAtWork.ContainsKey(employeeId))
                    {
                        currentlyAtWork[employeeId] = timeString;
                        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} returned from lunch");
                    }
                }
                // Employee goes home for the day
                else if (targetColumn == "time_out_afternoon")
                {
                    currentlyAtWork.Remove(employeeId);
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} marked as LEFT WORK (end of day)");
                }
            }
        }

        static void ProcessAttendanceLog(MySqlConnection conn, string enrollNo, DateTime deviceLogTime)
        {
            // Always use device-provided timestamp for recording
            DateTime punchTime = deviceLogTime;

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device scan time used: {punchTime:yyyy-MM-dd HH:mm:ss}");

            if (string.IsNullOrWhiteSpace(enrollNo))
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Empty employee ID from device. Skipping log.");
                return;
            }

            string cleanedEnrollNo = System.Text.RegularExpressions.Regex.Replace(enrollNo, @"[^0-9]", "");

            if (string.IsNullOrWhiteSpace(cleanedEnrollNo) || !int.TryParse(cleanedEnrollNo, out int employeeId))
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Invalid employee ID format: '{enrollNo}'. Skipping log.");
                return;
            }

            string targetColumn = DeterminePunchColumn(conn, employeeId, punchTime);
            
            // Handle special cases
            if (targetColumn == null)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Skipping duplicate punch for Employee {employeeId} at {punchTime:HH:mm:ss}");
                return;
            }
            else if (targetColumn == "overtime_additional")
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Additional overtime punch detected for Employee {employeeId} at {punchTime:HH:mm:ss}");
                LogOvertimePunch(conn, employeeId, punchTime);
                return;
            }
            
            string action = targetColumn.Contains("in") ? "TIME IN" : "TIME OUT";
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Fingerprint detected for Employee {employeeId} (Action: {action}, Column: {targetColumn})");

            try
            {
                string employeeName = GetEmployeeName(conn, employeeId);
                if (string.IsNullOrEmpty(employeeName))
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} not found in database. Skipping log.");
                    return;
                }

                ApplyPunchToColumn(conn, employeeId, punchTime, targetColumn);

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] {action} recorded for Employee {employeeId} ({employeeName})");
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Time (device): {punchTime:HH:mm:ss}");

            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database error for Employee {employeeId}: {ex.Message}");
            }
        }

        static string GetEmployeeName(MySqlConnection conn, int employeeId)
        {
            try
            {
                using (var cmd = new MySqlCommand("SELECT EmployeeName FROM empuser WHERE EmployeeID = @employeeId", conn))
                {
                    cmd.Parameters.AddWithValue("@employeeId", employeeId);
                    object result = cmd.ExecuteScalar();
                    return result?.ToString() ?? "";
                }
            }
            catch
            {
                return "";
            }
        }

        static DateTime GetPhilippinesTime()
        {
            try
            {
                TimeZoneInfo philippinesTimeZone = TimeZoneInfo.FindSystemTimeZoneById("Singapore Standard Time");
                DateTime utcTime = DateTime.UtcNow;
                DateTime philippinesTime = TimeZoneInfo.ConvertTimeFromUtc(utcTime, philippinesTimeZone);

                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Philippines time: {philippinesTime:yyyy-MM-dd HH:mm:ss}");
                return philippinesTime;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error getting Philippines time: {ex.Message}");
                return DateTime.Now;
            }
        }

        static string BuildConnString()
        {
            try
            {
                var config = LoadConfiguration();
                var builder = new MySqlConnectionStringBuilder
                {
                    Server = Environment.GetEnvironmentVariable("WTEI_DB_HOST") ?? config.Database.Host,
                    Port = uint.TryParse(Environment.GetEnvironmentVariable("WTEI_DB_PORT"), out var p) ? p : (uint)config.Database.Port,
                    Database = Environment.GetEnvironmentVariable("WTEI_DB_NAME") ?? config.Database.Name,
                    UserID = Environment.GetEnvironmentVariable("WTEI_DB_USER") ?? config.Database.User,
                    Password = Environment.GetEnvironmentVariable("WTEI_DB_PASS") ?? config.Database.Password,
                    SslMode = MySqlSslMode.Disabled,
                    AllowUserVariables = true,
                    ConnectionTimeout = 5,
                    DefaultCommandTimeout = 30
                };
                return builder.ConnectionString;
            }
            catch
            {
                var config = LoadConfiguration();
                return $"Server={config.Database.Host};Port={config.Database.Port};Database={config.Database.Name};Uid={config.Database.User};Pwd={config.Database.Password};SslMode=None;";
            }
        }

        // NEW: Get shift configuration for employee
        static ShiftConfig GetShiftConfig(MySqlConnection conn, int employeeId)
        {
            string shiftStr = GetShiftString(conn, employeeId);
            
            if (shiftConfigs.ContainsKey(shiftStr))
            {
                return shiftConfigs[shiftStr];
            }

            // Default to 8-5 shift
            return shiftConfigs["8-5"];
        }

        static string GetShiftString(MySqlConnection conn, int employeeId)
        {
            try
            {
                using (var cmd = new MySqlCommand("SELECT Shift FROM empuser WHERE EmployeeID=@id", conn))
                {
                    cmd.Parameters.AddWithValue("@id", employeeId);
                    var v = cmd.ExecuteScalar();
                    string shift = v?.ToString() ?? "8-5";
                    
                    // Normalize shift string
                    if (shift.Contains("22:00-06:00") || shift.Contains("NSD") || shift.Contains("nsd") || shift.Contains("Night") || shift.Contains("night")) return "NSD";
                    if (shift.Contains("8:30") || shift.Contains("8.30")) return "8:30-5:30";
                    if (shift.Contains("9") && shift.Contains("6")) return "9-6";
                    return "8-5";
                }
            }
            catch 
            {
                return "8-5";
            }
        }

        static string GetShiftDisplayName(string shiftType)
        {
            switch (shiftType)
            {
                case "8-5":
                    return "Day Shift (8AM-5PM)";
                case "8:30-5:30":
                    return "Day Shift (8:30AM-5:30PM)";
                case "9-6":
                    return "Day Shift (9AM-6PM)";
                case "NSD":
                    return "Night Shift (10PM-6AM)";
                default:
                    return "Day Shift (8AM-5PM)";
            }
        }

        static void UpdateCurrentlyAtWork(int employeeId, string timeString, bool isTimeIn)
        {
            lock (lockObject)
            {
                if (isTimeIn)
                {
                    currentlyAtWork[employeeId] = timeString;
                }
                else
                {
                    currentlyAtWork.Remove(employeeId);
                }
            }
        }

        static void CleanupResources()
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Cleaning up resources...");

            try
            {
                if (httpListener != null && httpListener.IsListening)
                {
                    httpListener.Stop();
                    httpListener.Close();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Web server stopped");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error stopping web server: {ex.Message}");
            }

            try
            {
                if (deviceConnected)
                {
                    device.EnableDevice(1, false);
                    device.Disconnect();
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device disconnected");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error disconnecting device: {ex.Message}");
            }

            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Cleanup completed. Exiting...");
        }
}
}