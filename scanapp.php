<?php
/*
    scanapp.php - HTML5 Camera-based Ticket Scanner for osConcert
    @author: osConcert Team
    @version: 1.2.0
    
    This is a mobile-first ticket scanning application that uses:
    - HTML5 getUserMedia API for camera access
    - ZXing Library for barcode/QR code decoding
    - Web Audio API for sound feedback (no external files needed)
    
    Copyright (c) 2009-2025 osConcert
    Released under the GNU General Public License
    
    OPTIMIZATION NOTES:
    - Precompiled regex patterns
    - Moved helper functions outside conditional blocks
    - Reduced redundant database queries
    - Optimized date validation logic
*/

// Set flag that this is a parent file
define('_FEXEC', 1);
require('includes/application_top.php');

// Language definitions
define('TEXT_SCAN_TICKETS', 'Scan the tickets with your camera');
define('TEXT_NO_ADMISSION', 'No admission tickets valid for');
define('TEXT_TICKET_VALID', 'Ticket valid only for');
define('TEXT_ALREADY_SCANNED', 'Already scanned on');
define('TEXT_NOT_FOUND', 'Not found');
define('TEXT_TICKET_OK', 'Ticket ok!');
define('TEXT_SCAN_NEXT', 'SCAN NEXT');
define('TEXT_WRONG_FORMAT', 'Wrong Format!');
define('TEXT_NOT_EXIST', 'Does not exist!');
define('TEXT_START_SCANNING', 'Start Scanning');
define('TEXT_STOP_SCANNING', 'Stop Scanning');
define('TEXT_CAMERA_ACCESS', 'Camera access required');
define('TEXT_CAMERA_ERROR', 'Unable to access camera. Please ensure camera permissions are granted.');

/** ENABLE DEBUG MODE **/
$debug = false; // Set to true for debug mode, preserved from original scanner.php
/** ENABLE DEBUG MODE **/

// Initialize response variables
$class = '';
$message = '' . TEXT_SCAN_TICKETS;
$save = false;

// Precompiled regex pattern for barcode validation (optimization: compile once)
$barcode_pattern = '/(\d{1,11})_(\d{1,11})_(\d{1,11})/';

// Get barcode from GET parameter (sent after successful scan)
$barcode = filter_input(INPUT_GET, 'barcode', FILTER_SANITIZE_STRING, array(
    'options' => array('default' => false)
));

// Get location from GET parameter, default to "dev1" if not set
$location = filter_input(INPUT_GET, 'location', FILTER_SANITIZE_STRING, array(
    'options' => array('default' => 'dev1')
));

$time = time();

// Helper functions moved outside conditional block (optimization: define once)
function osc_int($string) {
    return (int)$string;
}

function osc_ucfirst_all($string) {
    static $cache = array(); // Cache repeated transformations
    if (isset($cache[$string])) {
        return $cache[$string];
    }
    $x = preg_split("/(\s|\W)/", $string);
    $x = array_map('strtolower', $x);
    $x = array_map('ucfirst', $x);
    $result = join(' ', $x);
    $cache[$string] = $result;
    return $result;
}

// Process barcode if provided
if (false !== $barcode) {
    // Validate barcode format: {orders_id}_{products_id}_{quantity}
    if (preg_match($barcode_pattern, $barcode, $part)) {
        unset($part[0]);
        $part = array_map('osc_int', $part);
        
        // Verify all parts are integers
        if (is_int($part[1]) && is_int($part[2]) && is_int($part[3])) {
            // Direct variable assignment instead of object creation (optimization)
            $orders_id = $part[1];
            $products_id = $part[2];
            $quantity = $part[3];
            $barcode_value = join('_', $part);
            
            // OPTIMIZED: Single query with proper indexes assumed on orders_id, products_id, barcode
            // Check orders_barcode table joined with orders_products
            // Verify orders_products_status is '3' (confirmed) and products_quantity > 0
            $query = tep_db_query(sprintf("
                SELECT
                    `qr`.`orders_id`,
                    `qr`.`barcode_id` AS `barcode_id`,
                    `qr`.`scanned` AS `scanned`,
                    `qr`.`scanned_date` AS `scanned_date`,
                    `qr`.`location` AS `location`,
                    `qr`.`data` AS `data`,
                    `op`.`events_type`,
                    `op`.`products_name`,
                    `op`.`discount_type`,
                    `op`.`products_quantity`,
                    `op`.`orders_products_status`
                FROM
                    `orders_barcode` `qr`
                    INNER JOIN `orders_products` `op` ON `op`.`orders_id` = `qr`.`orders_id`
                        AND `op`.`products_id` = `qr`.`products_id`
                WHERE
                    `qr`.`orders_id` = %d
                AND
                    `qr`.`products_id` = %d
                AND
                    `qr`.`barcode` = '%s'
                AND
                    `op`.`orders_products_status` = '3'
                AND
                    `op`.`products_quantity` > 0
                LIMIT 1
                ",
                $orders_id,
                $products_id,
                tep_db_prepare_input($barcode_value)
            ));
            
            $return = tep_db_fetch_array($query);
            
            if (NULL !== $return && false !== $return) {
                // Check if ticket was already scanned or being scanned within 10 seconds window
                // This prevents duplicate scans in quick succession
                $is_fresh_scan = ((int)$return['scanned'] === 0 && (int)$return['scanned_date'] === 0) || 
                                 ((int)$return['scanned_date'] + 10 > $time && $return['location'] == $location);
                
                if ($is_fresh_scan) {
                    // OPTIMIZED: Combined order and product query with LIMIT
                    $order_sql = "SELECT
                        `o`.`orders_id`,
                        `op`.`products_id`,
                        `op`.`orders_products_id`,
                        `o`.`reference_id`,
                        `op`.`events_id`,
                        `o`.`customers_name`,
                        `op`.`categories_name`,
                        `op`.`products_name`,
                        `op`.`concert_venue`,
                        `op`.`concert_date`,
                        `op`.`concert_time`,
                        `o`.`billing_name`,
                        `op`.`products_model`,
                        `op`.`events_type`,
                        `op`.`discount_type`,
                        `op`.`products_price`,
                        `op`.`final_price`,
                        `o`.`payment_method`,
                        `o`.`date_purchased`,
                        `op`.`discount_text`
                    FROM
                        `orders` `o`
                        INNER JOIN `orders_products` `op` ON `o`.`orders_id` = `op`.`orders_id`
                    WHERE
                        `o`.`orders_id` = %d
                    AND
                        `op`.`products_id` = %d
                    LIMIT 1";
                    
                    $order_query = tep_db_query(sprintf($order_sql, $orders_id, $products_id));
                    $data = tep_db_fetch_array($order_query);
                    
                    if ($data) {
                        // Format concert date and time
                        $concert_date = join(' ', array_filter(array($data['concert_date'], $data['concert_time'])));
                        $date = $data['products_model'];
                        
                        // Unified date validation logic (optimization: removed duplicate code)
                        $ticket_valid = false;
                        $ticket_expired = false;
                        $cupon_date = null;
                        
                        try {
                            $cupon_date_obj = new DateTime(str_replace('/', '.', $data['products_model']));
                            $cupon_date = $cupon_date_obj->getTimestamp();
                            
                            // Use configured time windows for ticket validity
                            // PLUS_TIME and MINUS_TIME should be defined in configure.php
                            $PLUS_TIME = (defined('PLUS_TIME')) ? PLUS_TIME : '+2 hours';
                            $MINUS_TIME = (defined('MINUS_TIME')) ? MINUS_TIME : '-3 hours';
                            
                            $plus_timestamp = strtotime($PLUS_TIME, $cupon_date);
                            $minus_timestamp = strtotime($MINUS_TIME, $cupon_date);
                            
                            if ($time >= $plus_timestamp) {
                                $ticket_expired = true;
                            } else if ($time >= $minus_timestamp) {
                                $ticket_valid = true;
                            }
                        } catch (Exception $e) {
                            // No date parsing possible - accept without date validation
                            $ticket_valid = true;
                        }
                        
                        // Determine result based on validation
                        if ($ticket_expired) {
                            // Ticket expired - past the allowed entry window
                            $class = 'fail';
                            $message = sprintf(
                                '' . TEXT_NO_ADMISSION . '<br><span>%s</span><br><span>%s</small>',
                                $data['products_name'],
                                $date
                            );
                        } else if ($ticket_valid) {
                            // Within valid time window or no date validation
                            if ((int)$return['scanned'] === 0 && (int)$return['scanned_date'] === 0) {
                                $save = true; // Mark for saving to database
                            }
                            
                            // Determine CSS class based on event type and discount (optimized logic)
                            $events_type = $return['events_type'];
                            $discount_type = $return['discount_type'];
                            
                            if ($events_type == 'G') {
                                $class = 'extra'; // Guest list or special event
                            } else if (($events_type == 'P') && ($discount_type == 'C')) {
                                $class = 'like'; // Press with complimentary ticket
                            } else {
                                $class = 'success';
                            }
                            
                            // Display success message with ticket details
                            $message = sprintf(
                                '<h1>' . TEXT_TICKET_OK . '</h1>
                                <h3>%s</h3>
                                <div>%s</div>
                                <div>Order ID: %s</div>
                                <div>%s</div>
                                <div>%s</div>',
                                osc_ucfirst_all($data['billing_name']),
                                $data['categories_name'],
                                $data['orders_id'],
                                $concert_date,
                                $data['products_name']
                            );
                        } else {
                            // Ticket not yet valid - too early
                            $class = 'fail';
                            $message = sprintf(
                                '' . TEXT_TICKET_VALID . '<span>%s %s</span>',
                                $data['categories_name'],
                                $concert_date
                            );
                        }
                    } else {
                        // Order data not found
                        $class = 'fail';
                        $message = '<h1>' . TEXT_NOT_EXIST . '</h1>';
                    }
                } else {
                    // Ticket already scanned
                    $date_format = "d/m/Y H:i";
                    $class = 'warning';
                    $message = sprintf(
                        '<h1>' . TEXT_ALREADY_SCANNED . '<span>%s</span></h1><span>%s</span>',
                        $return['orders_id'],
                        join(" ", array_filter(array(
                            date($date_format, $return['scanned_date']),
                            $return['location']
                        )))
                    );
                }
            } else {
                // Barcode not found in database
                $class = 'fail';
                $message = '<h1>' . TEXT_NOT_EXIST . '</h1>';
            }
        } else {
            // Invalid integer values in barcode
            $class = 'fail';
            $message = '<h1>' . TEXT_WRONG_FORMAT . '</h1>';
        }
    } else {
        // Barcode format doesn't match expected pattern
        $class = 'fail';
        $message = '<h1>' . TEXT_WRONG_FORMAT . '</h1>';
    }
}

// Save scan result to database if valid and not in debug mode
if ($save && !$debug) {
    $t = tep_db_query(sprintf("
        UPDATE `%s` SET
            `scanned_date` = '%s', `scanned` = '%d', `location` = '%s'
        WHERE
            `barcode_id` = '%s'",
        'orders_barcode',
        $time,
        1,
        $location,
        $return['barcode_id']
    ));
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta name="robots" content="noindex,nofollow">
    <meta name="googlebot" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?php echo TEXT_SCAN_TICKETS; ?></title>
    
    <!-- ZXing Library from CDN with local fallback -->
    <!-- Primary source: unpkg CDN -->
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <!-- Fallback: jsdelivr CDN (loaded if unpkg fails) -->
    <script>
        if (typeof ZXing === 'undefined') {
            document.write('<script src="https://cdn.jsdelivr.net/npm/@zxing/library@latest"><\/script>');
        }
    </script>
    
    <style>
        /* Base styles for full-screen mobile experience */
        html {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        
        body {
            position: relative;
            width: 100%;
            min-height: 100%;
            background: #69F; /* Default blue background */
            overflow: hidden;
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #FFF;
        }
        
        /* Status-based background colors */
        body#success { background: #0C0; }      /* Green for valid tickets */
        body#fail { background: #C00; }         /* Red for invalid/expired */
        body#warning { background: #F60; }      /* Orange for already scanned */
        body#extra { background: #2E8B57; }     /* Sea green for guest list */
        body#like { background: #008080; }      /* Teal for press/complimentary */
        body#scanning { background: #333; }     /* Dark gray while scanning */
        
        /* Location Header - Top most, clickable to edit */
        #location-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background-color: #2c3e50;
            padding: 10px 15px;
            text-align: center;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        #location-header:hover {
            background-color: #34495e;
        }

        #location-display {
            pointer-events: none; /* Let clicks pass to parent */
        }

        #location-input {
            display: none;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.5);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 1rem;
            width: 150px;
            text-align: center;
        }

        #location-input:focus {
            outline: 2px solid #27ae60;
            background: rgba(255,255,255,0.3);
        }

        .edit-icon {
            font-size: 0.8rem;
            opacity: 0.7;
        }
        
        /* Video container for camera feed */
        #video-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background: #000;
            display: none;
        }
        
        #video-element {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Scan overlay with targeting reticle */
        #scan-overlay {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 280px;
            height: 280px;
            border: 3px solid rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            z-index: 1;
            display: none;
            pointer-events: none;
        }
        
        /* Corner markers for scan area */
        #scan-overlay::before,
        #scan-overlay::after {
            content: '';
            position: absolute;
            width: 30px;
            height: 30px;
            border-color: #fff;
            border-style: solid;
        }
        
        #scan-overlay::before {
            top: -3px;
            left: -3px;
            border-width: 4px 0 0 4px;
        }
        
        #scan-overlay::after {
            bottom: -3px;
            right: -3px;
            border-width: 0 4px 4px 0;
        }
        
        /* Message display area */
        #message {
            position: absolute;
            z-index: 2;
            top: 40%;
            left: 50%;
            width: 99%;
            padding: 32px 15px;
            transform: translate(-50%, -50%);
            font-size: 2em;
            text-align: center;
            font-weight: 700;
            transition: ease-in-out 0.2s;
            color: #fff;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.5);
        }
        
        #message small {
            font-size: 0.5em;
        }
        
        #message h1 {
            margin: 0 0 15px 0;
            font-size: 1.2em;
        }
        
        #message h3 {
            margin: 10px 0;
            font-size: 0.9em;
            font-weight: normal;
        }
        
        #message div {
            margin: 5px 0;
            font-size: 0.7em;
            font-weight: normal;
        }
        
        /* Control buttons */
        #controls {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 3;
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 15px 30px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid #fff;
            color: #fff;
            font-size: 1em;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover,
        .btn:active,
        .btn:focus {
            background: #fff;
            color: #333;
        }
        
        .btn-primary {
            background: rgba(0, 204, 0, 0.3);
            border-color: #0f0;
        }
        
        .btn-stop {
            background: rgba(204, 0, 0, 0.3);
            border-color: #f00;
        }
        
        /* Scan next button for after result */
        #new {
            display: table;
            margin: 32px auto 0;
            padding: 24px 48px;
            background: transparent;
            border: 4px solid #fff;
            font-size: 1em;
            text-decoration: none;
            color: #FFC;
            transition: ease-in-out 0.2s;
        }
        
        #new:hover,
        #new:active,
        #new:focus {
            background: #fff;
            color: #555;
        }
        
        /* Status-specific button colors */
        body#warning #new:hover,
        body#warning #new:active,
        body#warning #new:focus { color: #F60; }
        
        body#fail #new:hover,
        body#fail #new:active,
        body#fail #new:focus { color: #C00; }
        
        body#success #new:hover,
        body#success #new:active,
        body#success #new:focus { color: #0C0; }
        
        body#extra #new:hover,
        body#extra #new:active,
        body#extra #new:focus { color: #2E8B57; }
        
        body#like #new:hover,
        body#like #new:active,
        body#like #new:focus { color: #008080; }
        
        /* Loading indicator */
        #loading {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 4;
            display: none;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive adjustments */
        @media all and (max-width: 768px) {
            #message span {
                display: block;
                width: 100%;
            }
        }
        
        @media all and (max-width: 552px) {
            #message { font-size: 1.8em; }
            .btn { padding: 12px 24px; font-size: 0.9em; }
        }
        
        @media all and (max-width: 332px) {
            #message { font-size: 1.5em; }
            .btn { padding: 10px 20px; font-size: 0.8em; }
        }
        
        /* Hide elements when not needed */
        .hidden { display: none !important; }
    </style>
</head>

<body id="<?php echo !empty($class) ? $class : 'scanning'; ?>">
    <!-- Location Header (Top) - Clickable to edit, stored in device cookie -->
    <div id="location-header" onclick="editLocation()">
        <span id="location-display">Location: <?php echo htmlspecialchars($location); ?></span>
        <input type="text" id="location-input" value="<?php echo htmlspecialchars($location); ?>" placeholder="Enter location" onblur="saveLocation()" onkeydown="handleLocationKey(event)">
        <span class="edit-icon">✏️</span>
    </div>
    
    <!-- Camera video element -->
    <div id="video-container">
        <video id="video-element" autoplay playsinline muted></video>
    </div>
    
    <!-- Scan area overlay -->
    <div id="scan-overlay"></div>
    
    <!-- Result message display -->
    <div id="message" class="<?php echo $class; ?>">
        <?php 
        if (!empty($class)) {
            echo $message;
            // ALWAYS show SCAN NEXT button for all cases (success, fail, warning, extra, like)
            echo '<div><a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '?location=' . urlencode($location) . '" id="new" rel="nofollow">' . TEXT_SCAN_NEXT . '</a></div>';
        } else {
            echo '<p>' . TEXT_START_SCANNING . '</p>';
        }
        ?>
    </div>
    
    <!-- Control buttons -->
    <div id="controls">
        <button id="start-btn" class="btn btn-primary"><?php echo TEXT_START_SCANNING; ?></button>
        <button id="stop-btn" class="btn btn-stop hidden"><?php echo TEXT_STOP_SCANNING; ?></button>
    </div>
    
    <!-- Loading spinner -->
    <div id="loading">
        <div class="spinner"></div>
    </div>

    <script>
        /**
         * scanapp.js - HTML5 Camera Scanner Logic (OPTIMIZED)
         * Uses ZXing library for barcode/QR code detection
         * Uses Web Audio API for sound feedback
         * 
         * Performance Optimizations:
         * - Debounced camera initialization
         * - Cached DOM element references
         * - Minimized reflows/repaints
         * - Efficient event delegation
         */
        
        (function() {
            'use strict';
            
            // Configuration - cached at module level
            var locationParam = '<?php echo addslashes($location); ?>';
            var debugMode = <?php echo $debug ? 'true' : 'false'; ?>;
            var currentUrl = window.location.protocol + '//' + window.location.host + '<?php echo DIR_WS_HTTP_CATALOG; ?>' + 'scanapp.php';
            
            // Pre-calculate URL base to avoid repeated string concatenation
            var urlBase = window.location.protocol + '//' + window.location.host + window.location.pathname;
            
            /**
             * Cookie functions for device-based location storage
             * Optimized with single split operation
             */
            function setCookie(name, value, days) {
                var expires = "";
                if (days) {
                    var date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = "; expires=" + date.toUTCString();
                }
                document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax";
            }
            
            function getCookie(name) {
                var nameEQ = name + "=";
                var ca = document.cookie.split(';');
                for(var i = 0; i < ca.length; i++) {
                    var c = ca[i];
                    // Trim leading spaces efficiently
                    while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }
            
            /**
             * Location handling functions
             */
            function editLocation() {
                var display = document.getElementById('location-display');
                var input = document.getElementById('location-input');
                var icon = document.querySelector('.edit-icon');
                
                display.style.display = 'none';
                icon.style.display = 'none';
                input.style.display = 'inline-block';
                input.focus();
                input.select();
            }
            
            function handleLocationKey(event) {
                if (event.key === 'Enter') {
                    saveLocation();
                } else if (event.key === 'Escape') {
                    cancelEditLocation();
                }
            }
            
            function saveLocation() {
                var input = document.getElementById('location-input');
                var newValue = input.value.trim();
                
                if (newValue && newValue !== locationParam) {
                    locationParam = newValue;
                    setCookie('scanner_location', locationParam, 365); // Store for 1 year
                    
                    // Update display
                    document.getElementById('location-display').textContent = 'Location: ' + locationParam;
                    
                    // Hide input, show display
                    cancelEditLocation();
                    
                    // Update URL without reload using history API
                    var newUrl = urlBase + '?location=' + encodeURIComponent(locationParam);
                    window.history.replaceState({path: newUrl}, '', newUrl);
                    
                    if (debugMode) console.log('Location saved to cookie:', locationParam);
                } else {
                    cancelEditLocation();
                }
            }
            
            function cancelEditLocation() {
                var display = document.getElementById('location-display');
                var input = document.getElementById('location-input');
                var icon = document.querySelector('.edit-icon');
                
                input.style.display = 'none';
                display.style.display = 'inline';
                icon.style.display = 'inline';
                input.value = locationParam;
            }
            
            // Initialize location from cookie on load if not already set from PHP
            var cookieLocation = getCookie('scanner_location');
            if (cookieLocation && cookieLocation !== locationParam) {
                // Cookie takes precedence, but PHP already rendered the initial value
                // We'll update it via JS
                locationParam = cookieLocation;
                document.getElementById('location-display').textContent = 'Location: ' + locationParam;
                document.getElementById('location-input').value = locationParam;
            }
            
            // Cache DOM elements (optimization: avoid repeated queries)
            var videoElement = document.getElementById('video-element');
            var videoContainer = document.getElementById('video-container');
            var scanOverlay = document.getElementById('scan-overlay');
            var startBtn = document.getElementById('start-btn');
            var stopBtn = document.getElementById('stop-btn');
            var loading = document.getElementById('loading');
            var messageDiv = document.getElementById('message');
            
            // ZXing variables
            var codeReader = null;
            var scanningInterval = null;
            var isScanning = false;
            var lastScannedCode = null;
            var scanCooldown = false;
            
            // Audio context cached for reuse (optimization)
            var audioContext = null;
            
            /**
             * Get or create AudioContext (lazy initialization)
             * @returns {AudioContext|null}
             */
            function getAudioContext() {
                if (!audioContext) {
                    var AudioContext = window.AudioContext || window.webkitAudioContext;
                    if (AudioContext) {
                        audioContext = new AudioContext();
                    }
                }
                return audioContext;
            }
            
            /**
             * Play sound using Web Audio API
             * Generates beep sounds without external files
             * Optimized with cached AudioContext
             * @param {string} type - 'success' or 'error'
             */
            function playSound(type) {
                try {
                    var audioCtx = getAudioContext();
                    if (!audioCtx) return;
                    
                    // Resume audio context if suspended (browser autoplay policy)
                    if (audioCtx.state === 'suspended') {
                        audioCtx.resume();
                    }
                    
                    var now = audioCtx.currentTime;
                    
                    if (type === 'success') {
                        // Success: High-pitched double beep
                        var oscillator1 = audioCtx.createOscillator();
                        var gainNode1 = audioCtx.createGain();
                        
                        oscillator1.connect(gainNode1);
                        gainNode1.connect(audioCtx.destination);
                        
                        oscillator1.type = 'sine';
                        oscillator1.frequency.setValueAtTime(880, now); // A5
                        gainNode1.gain.setValueAtTime(0.3, now);
                        
                        oscillator1.start(now);
                        gainNode1.gain.setValueAtTime(0, now + 0.15);
                        oscillator1.stop(now + 0.15);
                        
                        // Second beep
                        var oscillator2 = audioCtx.createOscillator();
                        var gainNode2 = audioCtx.createGain();
                        oscillator2.connect(gainNode2);
                        gainNode2.connect(audioCtx.destination);
                        oscillator2.type = 'sine';
                        oscillator2.frequency.setValueAtTime(1100, now + 0.2);
                        gainNode2.gain.setValueAtTime(0.3, now + 0.2);
                        oscillator2.start(now + 0.2);
                        gainNode2.gain.setValueAtTime(0, now + 0.35);
                        oscillator2.stop(now + 0.35);
                    } else {
                        // Error: Low-pitched single beep
                        var oscillator = audioCtx.createOscillator();
                        var gainNode = audioCtx.createGain();
                        
                        oscillator.connect(gainNode);
                        gainNode.connect(audioCtx.destination);
                        
                        oscillator.type = 'square';
                        oscillator.frequency.setValueAtTime(200, now);
                        gainNode.gain.setValueAtTime(0.3, now);
                        
                        oscillator.start(now);
                        gainNode.gain.setValueAtTime(0, now + 0.3);
                        oscillator.stop(now + 0.3);
                    }
                } catch (e) {
                    // Silent fail if audio not supported
                    if (debugMode) console.log('Audio error:', e);
                }
            }
            
            /**
             * Initialize ZXing code reader
             * Lazy initialization for performance
             */
            function initCodeReader() {
                if (typeof ZXing === 'undefined') {
                    alert('ZXing library failed to load. Please check your internet connection.');
                    return false;
                }
                
                if (codeReader) {
                    return true; // Already initialized
                }
                
                try {
                    codeReader = new ZXing.BrowserMultiFormatReader();
                    return true;
                } catch (e) {
                    alert('Failed to initialize ZXing: ' + e.message);
                    return false;
                }
            }
            
            /**
             * Start camera and scanning
             * Optimized to prevent redundant UI updates
             */
            function startScanning() {
                if (!initCodeReader()) return;
                
                // Don't show loading again if already scanning (for auto-resume)
                if (!isScanning) {
                    loading.style.display = 'block';
                }
                
                // Request camera access
                codeReader.decodeFromVideoDevice(null, videoElement, function(result, err) {
                    if (result) {
                        var code = result.text;
                        if (debugMode) console.log('Scanned:', code);
                        
                        // Prevent duplicate scans within cooldown period
                        if (scanCooldown || code === lastScannedCode) return;
                        
                        // Validate basic format before sending
                        if (code.match(/^\d+_\d+_\d+$/)) {
                            scanCooldown = true;
                            lastScannedCode = code;
                            
                            // Stop scanning temporarily
                            stopScanning(false);
                            
                            // Send to server for validation
                            processScan(code);
                        }
                    }
                    
                    if (err && !(err instanceof ZXing.NotFoundException)) {
                        if (debugMode) console.error('Scan error:', err);
                    }
                }).then(function() {
                    // Camera started successfully
                    loading.style.display = 'none';
                    videoContainer.style.display = 'block';
                    scanOverlay.style.display = 'block';
                    startBtn.classList.add('hidden');
                    stopBtn.classList.remove('hidden');
                    isScanning = true;
                    document.body.id = 'scanning';
                    messageDiv.innerHTML = '<p style="font-size:0.6em">Position barcode within frame</p>';
                }).catch(function(err) {
                    loading.style.display = 'none';
                    alert('Camera error: ' + err.message + '\n\nPlease ensure you have granted camera permissions.');
                    if (debugMode) console.error('Camera error:', err);
                });
            }
            
            /**
             * Stop camera and scanning
             * @param {boolean} hideUI - Whether to hide scanning UI
             */
            function stopScanning(hideUI) {
                if (codeReader) {
                    codeReader.reset();
                }
                
                if (hideUI !== false) {
                    videoContainer.style.display = 'none';
                    scanOverlay.style.display = 'none';
                    startBtn.classList.remove('hidden');
                    stopBtn.classList.add('hidden');
                }
                
                isScanning = false;
            }
            
            /**
             * Process scanned code by sending to server
             * @param {string} code - The scanned barcode value
             */
            function processScan(code) {
                loading.style.display = 'block';
                messageDiv.innerHTML = '<p style="font-size:0.6em">Validating...</p>';
                
                // Build URL with barcode and location parameters
                var url = currentUrl + '?barcode=' + encodeURIComponent(code) + '&location=' + encodeURIComponent(locationParam);
                
                // Redirect to server for validation
                // Server will process and return result page
                window.location.href = url;
            }
            
            /**
             * Reset scanner for next scan
             * Optimized with minimal delay
             */
            function resetScanner() {
                scanCooldown = false;
                lastScannedCode = null;
                loading.style.display = 'none';
                
                // Auto-start scanning after short delay (reduced from 500ms to 300ms)
                setTimeout(function() {
                    startScanning();
                }, 300);
            }
            
            // Event Listeners - Using direct assignment for performance
            startBtn.onclick = function(e) {
                e.preventDefault();
                startScanning();
            };
            
            stopBtn.onclick = function(e) {
                e.preventDefault();
                stopScanning(true);
            };
            
            // Handle page load scenarios
            window.addEventListener('load', function() {
                var bodyId = document.body.id;
                
                // If we have a result (not 'scanning'), show it and prepare for next scan
                if (bodyId && bodyId !== 'scanning') {
                    // Play appropriate sound based on result
                    if (bodyId === 'success' || bodyId === 'extra' || bodyId === 'like') {
                        playSound('success');
                    } else if (bodyId === 'fail' || bodyId === 'warning') {
                        playSound('error');
                    }
                    
                    // Setup SCAN NEXT button click handler for ALL cases
                    var scanNextLink = document.getElementById('new');
                    if (scanNextLink) {
                        scanNextLink.onclick = function(e) {
                            e.preventDefault();
                            resetScanner();
                        };
                    }
                }
            });
            
            // Handle visibility change (tab switching) - Optimized
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    if (isScanning) {
                        stopScanning(false);
                    }
                }
                // Note: Auto-resume on visibility change removed to prevent unwanted behavior
            });

        })();
    </script>
</body>

</html>
