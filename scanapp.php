<?php
/*
    scanapp.php - HTML5 Camera-based Ticket Scanner for osConcert
    @author: m.schatz@tauronik.de
    @version: 1.5.1

    This is a mobile-first ticket scanning application that uses:
    - HTML5 getUserMedia API for camera access
    - ZXing Library for barcode/QR code decoding
    - Web Audio API for sound feedback (no external files needed)

    Copyright (c) 2009-2025 osConcert
    Released under the GNU General Public License

    FEATURES (v1.5.1):
    - Configurable security modes: none, login, or PIN
    - Box office login authentication (country_id = 999)
    - Static PIN authentication (5-12 digits)
    - AJAX authentication with session management
    - Mobile-optimized auth forms
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

/** SECURITY MODE **/
// 'none'    - No security (direct access, for trusted environments)
// 'login'   - Box office login required (email + password with country_id = 999)
// PIN value - 5-12 digits (truncated to 12 if longer, invalid chars default to 'login')
$security = 'login';
/*** DO NOT CHANGE ANYTHING BELOW THIS LINE ***/
if (isset($security)) {
    $security = trim((string)$security);
    if ($security === '') {
        $security = 'login';
    } elseif ($security !== 'none' && $security !== 'login') {
        if (preg_match('/^\d+$/', $security)) {
            if (strlen($security) > 12) {
                $security = substr($security, 0, 12);
            }
        } else {
            $security = 'login';
        }
    }
}
/** SECURITY MODE **/

// Initialize response variables
$class = '';
$message = '' . TEXT_SCAN_TICKETS;
$save = false;

// Security check based on mode
$security_authenticated = false;

if ($security !== 'none') {
    if ($security === 'login') {
        // Login mode: Check box office session
        if (isset($_SESSION['customer_id'])) {
            // Verify customer has box office access (country_id = 999)
            $check = tep_db_query("
                SELECT 1
                FROM address_book
                WHERE customers_id = '" . (int)$_SESSION['customer_id'] . "'
                AND entry_country_id = 999
                LIMIT 1
            ");
            if (tep_db_num_rows($check) > 0) {
                $security_authenticated = true;
            }
        }
    } else {
        // Numeric PIN mode: Check pin_authenticated session
        $security_authenticated = isset($_SESSION['pin_authenticated']) && $_SESSION['pin_authenticated'] === true;
    }
} else {
    // None mode: Always authenticated
    $security_authenticated = true;
}

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

            // SINGLE QUERY: Get all ticket data in one DB round-trip
            // Includes orders_barcode + orders_products + orders via JOINs
            // Verifies orders_products_status='3' (confirmed) and products_quantity > 0
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
                    `op`.`orders_products_status`,
                    `op`.`categories_name`,
                    `op`.`concert_venue`,
                    `op`.`concert_date`,
                    `op`.`concert_time`,
                    `op`.`products_model`,
                    `op`.`discount_text`,
                    `o`.`customers_name`,
                    `o`.`billing_name`,
                    `o`.`reference_id`,
                    `o`.`payment_method`,
                    `o`.`date_purchased`
                FROM
                    `orders_barcode` `qr`
                    INNER JOIN `orders_products` `op` ON `op`.`orders_id` = `qr`.`orders_id`
                        AND `op`.`products_id` = `qr`.`products_id`
                    INNER JOIN `orders` `o` ON `o`.`orders_id` = `qr`.`orders_id`
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
                // Cache int casts (used multiple times)
                $scanned = (int)$return['scanned'];
                $scanned_date = (int)$return['scanned_date'];

                // Check if ticket was already scanned or being scanned within 10 seconds window
                // This prevents duplicate scans in quick succession
                $is_fresh_scan = ($scanned === 0 && $scanned_date === 0) ||
                                 ($scanned_date + 10 > $time && $return['location'] == $location);

                if ($is_fresh_scan) {
                    // Format concert date and time
                    $concert_date = join(' ', array_filter(array($return['concert_date'], $return['concert_time'])));
                    $date = $return['products_model'];

                    // Unified date validation logic
                    $ticket_valid = false;
                    $ticket_expired = false;

                    try {
                        $cupon_date_obj = new DateTime(str_replace('/', '.', $return['products_model']));
                        $cupon_date = $cupon_date_obj->getTimestamp();

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
                        $ticket_valid = true;
                    }

                    if ($ticket_expired) {
                        $class = 'fail';
                        $message = sprintf(
                            '' . TEXT_NO_ADMISSION . '<br><span>%s</span><br><span>%s</small>',
                            $return['products_name'],
                            $date
                        );
                    } else if ($ticket_valid) {
                        if ($scanned === 0 && $scanned_date === 0) {
                            $save = true;
                        }

                        $events_type = $return['events_type'];
                        $discount_type = $return['discount_type'];

                        if ($events_type == 'G') {
                            $class = 'extra';
                        } else if (($events_type == 'P') && ($discount_type == 'C')) {
                            $class = 'like';
                        } else {
                            $class = 'success';
                        }

                        $message = sprintf(
                            '<h1>' . TEXT_TICKET_OK . '</h1>
                            <h3>%s</h3>
                            <div>%s</div>
                            <div>Order ID: %s</div>
                            <div>%s</div>
                            <div>%s</div>',
                            osc_ucfirst_all($return['billing_name']),
                            $return['categories_name'],
                            $return['orders_id'],
                            $concert_date,
                            $return['products_name']
                        );
                    } else {
                        $class = 'fail';
                        $message = sprintf(
                            '' . TEXT_TICKET_VALID . '<span>%s %s</span>',
                            $return['categories_name'],
                            $concert_date
                        );
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

// AJAX Authentication Endpoints
if (isset($_GET['auth_action'])) {
    header('Content-Type: application/json');

    if ($_GET['auth_action'] === 'login') {
        // Box office login
        $email = filter_input(INPUT_GET, 'email', FILTER_SANITIZE_EMAIL);
        $password = filter_input(INPUT_GET, 'password', FILTER_SANITIZE_STRING);

        if (!$email || !$password) {
            echo json_encode(['success' => false, 'error' => 'Email and password required']);
            exit;
        }

        $check_customer_query = tep_db_query("
            SELECT customers_id, customers_password, encryption_style
            FROM " . TABLE_CUSTOMERS . "
            WHERE is_blocked = 'N' AND customers_email_address = '" . tep_db_input($email) . "'
            LIMIT 1
        ");

        if (tep_db_num_rows($check_customer_query) === 0) {
            echo json_encode(['success' => false, 'error' => 'Login failed']);
            exit;
        }

        $check_customer = tep_db_fetch_array($check_customer_query);

        if (!tep_validate_password($password, $check_customer['customers_password'], $check_customer['encryption_style'])) {
            echo json_encode(['success' => false, 'error' => 'Login failed']);
            exit;
        }

        // Check box office access (country_id = 999)
        $check_country_query = tep_db_query("
            SELECT 1 FROM address_book
            WHERE customers_id = '" . (int)$check_customer['customers_id'] . "'
            AND entry_country_id = 999
            LIMIT 1
        ");

        if (tep_db_num_rows($check_country_query) === 0) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }

        $_SESSION['customer_id'] = (int)$check_customer['customers_id'];
        echo json_encode(['success' => true, 'type' => 'login']);
        exit;
    }

    if ($_GET['auth_action'] === 'pin') {
        // PIN validation - compare against configured security value
        $pin_raw = isset($_GET['pin']) ? trim($_GET['pin']) : '';
        $pin = (int)$pin_raw;

        // Accept PINs of 5-12 digits
        if (!$pin || $pin < 10000 || $pin > 999999999999) {
            echo json_encode(['success' => false, 'error' => 'Invalid PIN format']);
            exit;
        }

        // Compare as strings for reliable comparison
        $security_str = trim((string)$security);
        if (strcmp($pin_raw, $security_str) === 0) {
            $_SESSION['pin_authenticated'] = true;
            echo json_encode(['success' => true, 'type' => 'pin']);
        } else {
            if ($debug) {
                echo json_encode(['success' => false, 'error' => 'Debug: submitted=' . $pin_raw . ' expected=' . $security_str]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Wrong PIN']);
            }
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Logout/Clear Session
if (isset($_GET['logout'])) {
    if ($security === 'login') {
        $_SESSION['customer_id'] = null;
        unset($_SESSION['customer_id']);
    } else {
        $_SESSION['pin_authenticated'] = null;
        unset($_SESSION['pin_authenticated']);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if authentication is required
$auth_required = !$security_authenticated;

// Determine auth mode for UI
$auth_mode = ($security === 'login') ? 'login' : 'pin';

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
            font-size: 14px;
            color: #FFF;
        }
        
        /* Status-based background colors */
        body#success { background: #28a745; }      /* Green for valid tickets */
        body#fail { background: #C61010; }         /* Red for invalid/expired */
        body#warning { background: #FE7F2D; }      /* Orange for already scanned */
        body#extra { background: #2E838C; }     /* Sea green for guest list */
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

        /* PIN Modal Styles */
        #auth-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        #auth-modal .auth-container {
            text-align: center;
            padding: 40px;
        }

        #auth-modal h2 {
            margin: 0 0 10px 0;
            font-size: 1.5em;
        }

        #auth-modal p {
            margin: 0 0 20px 0;
            font-size: 1em;
            opacity: 0.8;
        }

        #auth-modal input {
            display: block;
            font-size: 1.2em;
            text-align: center;
            width: 250px;
            padding: 12px 15px;
            margin: 10px auto;
            border: 2px solid #fff;
            border-radius: 8px;
            background: transparent;
            color: #fff;
            outline: none;
        }

        #auth-modal input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        #auth-modal input:focus {
            border-color: #0f0;
        }

        #auth-modal input[type="password"],
        #auth-modal input[type="email"] {
            -webkit-text-security: text;
        }

        #auth-modal ~ #video-container,
        #auth-modal ~ #scan-overlay,
        #auth-modal ~ #controls,
        #auth-modal ~ #message,
        body:not([id="scanning"]) ~ #video-container,
        body:not([id="scanning"]) ~ #scan-overlay,
        body:not([id="scanning"]) ~ #controls {
            display: none !important;
        }

        body:not([id="scanning"]) ~ #message {
            display: none !important;
        }

        #auth-modal {
            display: flex;
        }

        body[id="scanning"] ~ #message {
            display: block;
        }

        #auth-modal button {
            display: block;
            margin: 20px auto 0;
            padding: 12px 40px;
            font-size: 1em;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid #fff;
            color: #fff;
            border-radius: 8px;
        }

        #auth-modal button:hover {
            background: #fff;
            color: #333;
        }

        #auth-modal #auth-error {
            color: #f44;
            margin-top: 15px;
            display: none;
        }

        /* Change Event Button */
        #change-event-btn {
            position: fixed;
            top: 12px;
            right: 50px;
            font-size: 1.2em;
            cursor: pointer;
            z-index: 101;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        #change-event-btn:hover {
            opacity: 1;
        }
    </style>
</head>
<body id="<?php echo !empty($class) ? $class : 'scanning'; ?>">

<?php if ($auth_required && $security === 'login'): ?>
<div id="auth-modal">
    <div class="auth-container">
        <h2>Box Office Login</h2>
        <p>Enter your box office credentials</p>
        <input type="email" id="auth-email" placeholder="Email address" autocomplete="off">
        <input type="password" id="auth-password" placeholder="Password" autocomplete="off">
        <button id="auth-submit">Login</button>
        <p id="auth-error">Login failed</p>
    </div>
</div>
<?php elseif ($auth_required): ?>
<div id="auth-modal">
    <div class="auth-container">
        <h2>Event Scanner</h2>
        <p>Enter the event PIN</p>
        <input type="text" id="auth-pin" maxlength="12" pattern="[0-9]{5,12}" inputmode="numeric" placeholder="PIN" autocomplete="off">
        <button id="auth-submit">OK</button>
        <p id="auth-error">Wrong PIN</p>
    </div>
</div>
<?php endif; ?>

<?php if ($security_authenticated && $security !== 'none' && $security !== 'login'): ?>
<span id="change-event-btn" onclick="logout()" title="Logout">🔄</span>
<?php elseif ($security_authenticated && $security === 'login'): ?>
<span id="change-event-btn" onclick="logout()" title="Logout">🔄</span>
<?php endif; ?>

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

            // Precompiled regex for barcode validation
            var barcodeRegex = /^\d+_\d+_\d+$/;
            
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
             * Exposed to window for inline event handler access
             */
            var inputJustOpened = false;

            window.editLocation = function() {
                var display = document.getElementById('location-display');
                var input = document.getElementById('location-input');
                var icon = document.querySelector('.edit-icon');

                display.style.display = 'none';
                icon.style.display = 'none';
                input.style.display = 'inline-block';
                input.focus();
                input.select();
                inputJustOpened = true;
                setTimeout(function() { inputJustOpened = false; }, 100);
            };

            window.handleLocationKey = function(event) {
                if (event.key === 'Enter') {
                    window.saveLocation();
                } else if (event.key === 'Escape') {
                    window.cancelEditLocation();
                }
            };

            window.saveLocation = function() {
                if (inputJustOpened) return;
                var input = document.getElementById('location-input');
                var newValue = input.value.trim();

                if (newValue && newValue !== locationParam) {
                    locationParam = newValue;
                    setCookie('scanner_location', locationParam, 365);

                    document.getElementById('location-display').textContent = 'Location: ' + locationParam;
                    window.cancelEditLocation();

                    var newUrl = urlBase + '?location=' + encodeURIComponent(locationParam);
                    window.history.replaceState({path: newUrl}, '', newUrl);

                    if (debugMode) console.log('Location saved to cookie:', locationParam);
                } else {
                    window.cancelEditLocation();
                }
            };

            window.cancelEditLocation = function() {
                var display = document.getElementById('location-display');
                var input = document.getElementById('location-input');
                var icon = document.querySelector('.edit-icon');

                input.style.display = 'none';
                display.style.display = 'inline';
                icon.style.display = 'inline';
                input.value = locationParam;
            };
            
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

                if (!authValidated) {
                    if (authMode === 'login') {
                        alert('Please login first');
                    } else {
                        alert('Please enter the event PIN first');
                    }
                    return;
                }

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
                        if (barcodeRegex.test(code)) {
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
            });

            // Authentication Variables
            var authMode = '<?php echo $auth_mode; ?>';
            var authValidated = <?php echo $security_authenticated ? 'true' : 'false'; ?>;

            window.logout = function() {
                window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?logout=1';
            };

            function showAuthError(msg) {
                var errorEl = document.getElementById('auth-error');
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.style.display = 'block';
                }
            }

            function hideAuthError() {
                var errorEl = document.getElementById('auth-error');
                if (errorEl) {
                    errorEl.style.display = 'none';
                }
            }

            function validateLogin(email, password) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '<?php echo $_SERVER['PHP_SELF']; ?>?auth_action=login&email=' + encodeURIComponent(email) + '&password=' + encodeURIComponent(password), true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    window.location.reload();
                                } else {
                                    showAuthError(response.error || 'Login failed');
                                    document.getElementById('auth-password').value = '';
                                    document.getElementById('auth-email').focus();
                                }
                            } catch (e) {
                                showAuthError('Login failed');
                                document.getElementById('auth-password').value = '';
                            }
                        } else {
                            showAuthError('Login failed');
                            document.getElementById('auth-password').value = '';
                        }
                    }
                };
                xhr.send();
            }

            function validatePin(pin) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '<?php echo $_SERVER['PHP_SELF']; ?>?auth_action=pin&pin=' + encodeURIComponent(pin), true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    window.location.reload();
                                } else {
                                    showAuthError(response.error || 'Wrong PIN');
                                    document.getElementById('auth-pin').value = '';
                                    document.getElementById('auth-pin').focus();
                                }
                            } catch (e) {
                                showAuthError('Wrong PIN');
                                document.getElementById('auth-pin').value = '';
                            }
                        } else {
                            showAuthError('Wrong PIN');
                            document.getElementById('auth-pin').value = '';
                        }
                    }
                };
                xhr.send();
            }

            // Auth Modal Event Listeners
            document.addEventListener('DOMContentLoaded', function() {
                var emailInput = document.getElementById('auth-email');
                var passwordInput = document.getElementById('auth-password');
                var pinInput = document.getElementById('auth-pin');
                var authSubmit = document.getElementById('auth-submit');

                if (authMode === 'login') {
                    if (emailInput && passwordInput && authSubmit) {
                        function doLogin() {
                            var email = emailInput.value.trim();
                            var password = passwordInput.value;
                            if (email && password) {
                                validateLogin(email, password);
                            } else {
                                showAuthError('Enter email and password');
                            }
                        }

                        passwordInput.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                doLogin();
                            }
                        });

                        emailInput.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                passwordInput.focus();
                            }
                        });

                        authSubmit.addEventListener('click', doLogin);
                        emailInput.focus();
                    }
                } else {
                    if (pinInput && authSubmit) {
                        pinInput.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                authSubmit.click();
                            }
                        });

                        pinInput.addEventListener('input', function() {
                            this.value = this.value.replace(/[^0-9]/g, '');
                            hideAuthError();
                        });

authSubmit.addEventListener('click', function() {
                            var pin = pinInput.value.trim();
                            if (pin.length >= 5 && pin.length <= 12 && /^\d+$/.test(pin)) {
                                validatePin(pin);
                            } else {
                                showAuthError('Enter PIN (5-12 digits)');
                                pinInput.value = '';
                                pinInput.focus();
                            }
                        });

                        pinInput.focus();
                    }
                }
            });

        })();
    </script>
</body>

</html>
