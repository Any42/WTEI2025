<?php
/**
 * K30 Device & Service Configuration
 * 
 * This file contains configuration settings for the K30 fingerprint device
 * and the C# service that communicates with it.
 * 
 * Modify these settings based on your setup.
 */

// K30 C# Service Configuration
define('K30_SERVICE_HOST', '127.0.0.1');  // The host where the C# service is running
define('K30_SERVICE_PORT', 18080);        // The primary port for the C# service
define('K30_SERVICE_TIMEOUT', 3);         // Maximum time to wait for response (seconds)
define('K30_CONNECT_TIMEOUT', 1);         // Maximum time to wait for connection (seconds)

// Fallback ports to try if primary port fails
// The service will try these ports in order if the primary port is not available
define('K30_FALLBACK_PORTS', [8080, 8888, 8890]);

// K30 Device Configuration (for direct TCP connection, if needed)
define('K30_DEVICE_IP', '192.168.1.201'); // IP address of the K30 device
define('K30_DEVICE_PORT', 4370);          // TCP port of the K30 device
define('K30_COMM_KEY', 0);                // Communication key (usually 0)
define('K30_DEVICE_ID', 1);               // Device ID (usually 1)

// Debug Mode
define('K30_DEBUG_MODE', false);          // Set to true to enable detailed logging

/**
 * Get all configured ports (primary + fallbacks)
 * @return array List of ports to try
 */
function getK30ServicePorts() {
    $ports = [K30_SERVICE_PORT];
    foreach (K30_FALLBACK_PORTS as $port) {
        if ($port != K30_SERVICE_PORT) {
            $ports[] = $port;
        }
    }
    return $ports;
}

/**
 * Log K30 debug message
 * @param string $message The message to log
 */
function k30_log($message) {
    if (K30_DEBUG_MODE) {
        error_log("[K30] " . $message);
    }
}

