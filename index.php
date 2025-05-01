<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Forms Processor
// Version: 4.2 (Simple CSS UI Refactor - No Tailwind)
// Features: Combined Functionality, Simple CSS UI, DB Integration
// Backend: PHP mail(), CSRF, Honeypot, Logging, PDO Database
// ========================================================================

// --- PHP Backend Code (Keep EXACTLY the same as in the previous version) ---
// Start session
if (session_status() == PHP_SESSION_NONE) { session_start(); }
// --- Configuration ---
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com"); // CHANGE ME
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Website');             // CHANGE ME
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url');
define('ENABLE_LOGGING', true);
$baseDir = __DIR__;
define('LOG_FILE_CONTACT', $baseDir . '/logs/contact_submissions.log');
define('LOG_FILE_VOLUNTEER', $baseDir . '/logs/volunteer_submissions.log');
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
// --- Database Configuration ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'pahal_ngo_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // CHANGE ME
define('DB_CHARSET', 'utf8mb4');
// --- END CONFIG ---

// --- Helper Functions (Keep all helper functions as they were: log_message, generate_csrf_token, validate_csrf_token, sanitize_string, sanitize_email, validate_data, send_email, get_form_value, get_form_status_html, get_field_error_html, get_field_error_class, get_aria_describedby, get_db_connection) ---
// ... (Paste all the helper functions from the previous answer here) ...
/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ({$logFile}): " . $message);
            return;
        }
        @file_put_contents($logDir . '/.htaccess', 'Deny from all');
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        $error = error_get_last();
        error_log("Failed to write log: {$logFile} | Error: " . ($error['message'] ?? 'Unknown'));
        error_log("Original Log: " . $message);
    }
}
/**
 * Generates or retrieves a CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        try { $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32)); } catch (Exception $e) { $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true)); }
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}
/**
 * Validates the submitted CSRF token.
 */
function validate_csrf_token(?string $submittedToken): bool {
    if ($submittedToken === null || !isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) { return false; }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
}
/**
 * Sanitize input string.
 */
function sanitize_string(?string $input): string {
    if ($input === null) return '';
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
/**
 * Sanitize email address.
 */
function sanitize_email(?string $email): string {
    if ($email === null) return '';
    $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : '';
}
/**
 * Validates input data based on rules.
 */
function validate_data(array $data, array $rules): array {
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        $ruleList = explode('|', $ruleString);
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));
        foreach ($ruleList as $rule) {
            $params = [];
            if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }
            $isValid = true; $errorMessage = '';
            switch ($rule) {
                case 'required': if ($value === null || $value === '') { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please enter a valid email address for {$fieldNameFormatted}."; } break;
                case 'minLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)($params[0] ?? 0)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least " . ($params[0] ?? 0) . " characters long."; } break;
                case 'maxLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)($params[0] ?? 0)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed " . ($params[0] ?? 0) . " characters."; } break;
                case 'alpha_space': if ($value !== null && $value !== '' && !preg_match('/^[\p{L}\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if ($value !== null && $value !== '' && !preg_match('/^[+\d\s\-()]+$/', $value)) { $isValid = false; $errorMessage = "Please enter a valid phone number for {$fieldNameFormatted}."; } break;
                case 'in': if ($value !== null && $value !== '' && !in_array($value, $params)) { $isValid = false; $validOptions = implode(', ', $params); $errorMessage = "{$fieldNameFormatted} must be one of the following: {$validOptions}."; } break;
                case 'required_without': $otherField = $params[0] ?? null; if ($otherField && empty($data[$otherField] ?? null) && ($value === null || $value === '')) { $isValid = false; $otherFieldNameFormatted = ucfirst(str_replace('_', ' ', $otherField)); $errorMessage = "Either {$fieldNameFormatted} or {$otherFieldNameFormatted} is required."; } break;
                default: log_message("Unknown validation rule '{$rule}' for field '{$field}'", LOG_FILE_ERROR);
            }
            if (!$isValid && !isset($errors[$field])) { $errors[$field] = $errorMessage; break; }
        }
    }
    return $errors;
}
/**
 * Sends an email using the standard PHP mail() function.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext, string $logFile): bool {
    $senderName = SENDER_NAME_DEFAULT; $senderEmail = SENDER_EMAIL_DEFAULT;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid recipient email: {$to}", LOG_FILE_ERROR); return false; }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid sender email in config: {$senderEmail}", LOG_FILE_ERROR); return false; }
    $headers = "From: =?UTF-8?B?" . base64_encode($senderName) . "?= <{$senderEmail}>\r\n";
    if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) { $replyToFormatted = !empty($replyToName) ? "=?UTF-8?B?" . base64_encode($replyToName) . "?= <{$replyToEmail}>" : $replyToEmail; $headers .= "Reply-To: {$replyToFormatted}\r\n"; }
    else { $headers .= "Reply-To: =?UTF-8?B?" . base64_encode($senderName) . "?= <{$senderEmail}>\r\n"; }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n"; $headers .= "MIME-Version: 1.0\r\n"; $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?="; $wrapped_body = wordwrap($body, 70, "\r\n");
    if (@mail($to, $encodedSubject, $wrapped_body, $headers, "-f{$senderEmail}")) { log_message("{$logContext} Email sent successfully to {$to}. Subject: {$subject}", $logFile); return true; }
    else { $errorInfo = error_get_last(); $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error.'); log_message($errorMsg, LOG_FILE_ERROR); error_log($errorMsg); return false; }
}
/**
 * Retrieves a form value safely for HTML output, using global state.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; $value = $form_submissions[$formId][$fieldName] ?? $default;
    if (is_array($value)) { log_message("Attempted get non-scalar value for form '{$formId}', field '{$fieldName}'", LOG_FILE_ERROR); return ''; }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
/**
 * Generates form status HTML (success/error) with styling.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    $baseClasses = 'form-message'; // Use CSS class
    $typeClass = $isSuccess ? 'form-message-success' : 'form-message-error';
    $iconClass = $isSuccess ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
    $title = $isSuccess ? 'Success!' : 'Error!';
    return "<div data-form-message-id=\"{$formId}\" class=\"{$baseClasses} {$typeClass}\" role=\"alert\">
                <strong class=\"form-message-title\"><i class=\"{$iconClass}\"></i> {$title}</strong>
                <span class=\"form-message-text\">" . htmlspecialchars($message['text']) . "</span>
            </div>";
}
/**
 * Generates HTML for a field error message.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) {
        return '<p class="form-error-message" id="' . $errorId . '">
                    <i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($form_errors[$formId][$fieldName]) .
               '</p>';
    }
    return '';
}
/**
 * Returns CSS class for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
    global $form_errors; return isset($form_errors[$formId][$fieldName]) ? 'form-input-error' : '';
}
/**
 * Gets ARIA describedby attribute value if error exists.
 */
function get_aria_describedby(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) { $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error'); return ' aria-describedby="' . $errorId . '" aria-invalid="true"'; }
    return ' aria-invalid="false"';
}
/**
 * Establishes a PDO database connection.
 */
function get_db_connection(): ?PDO {
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_CHARSET')) { log_message("Database configuration constants are missing.", LOG_FILE_ERROR); return null; }
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
    try { $pdo = new PDO($dsn, DB_USER, DB_PASS, $options); return $pdo; }
    catch (\PDOException $e) { log_message("Database Connection Error: " . $e->getMessage() . " (Code: " . $e->getCode() . ")", LOG_FILE_ERROR); error_log("Database Connection Error: " . $e->getMessage()); return null; }
}
// --- END Helper Functions ---


// --- Form Processing & Variable Setup (Keep EXACTLY the same as in the previous version) ---
// ... (Paste the Initialize Page Variables, Form State Variables, Form Processing Logic, GET Request logic, Prepare Form Data blocks here) ...
// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "PAHAL NGO Jalandhar | Empowering Communities";
$page_description = "'PAHAL' is a volunteer-driven youth NGO in Jalandhar, Punjab, focusing on community development through health, education, environment, and skill initiatives.";
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health, education, environment, skills, non-profit";

// --- Initialize Form State Variables ---
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token();

// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = $_POST['form_id'] ?? null;
    $pdo = null; // Initialize PDO variable

    // Basic Security Checks (Honeypot & CSRF)
    if (!empty($_POST[HONEYPOT_FIELD_NAME]) || !validate_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        log_message("[SPAM/CSRF DETECTED] Form Failed. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        if ($submitted_form_id) { $_SESSION['form_messages'][$submitted_form_id] = ['type' => 'error', 'text' => 'Security validation failed. Please refresh the page and try again.']; }
        else { $_SESSION['general_error'] = 'A security error occurred. Please try again.'; }
        unset($_SESSION[CSRF_TOKEN_NAME]); $csrf_token = generate_csrf_token(); // Regenerate
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . ($submitted_form_id ? "#" . $submitted_form_id : '')); exit;
    }

    $logContext = ''; $logFile = LOG_FILE_ERROR;

    // --- Process Contact Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form'; $form_errors[$form_id] = [];
        $logContext = "[Contact Form]"; $logFile = LOG_FILE_CONTACT;
        $name = sanitize_string($_POST['name'] ?? null); $email = sanitize_email($_POST['email'] ?? null); $message = sanitize_string($_POST['message'] ?? null);
        $form_submissions[$form_id] = ['name' => $name, 'email' => $email, 'message' => $message];
        $rules = [ 'name' => 'required|alpha_space|minLength:2|maxLength:100', 'email' => 'required|email|maxLength:255', 'message' => 'required|minLength:10|maxLength:2000', ];
        $validation_errors = validate_data($form_submissions[$form_id], $rules); $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_CONTACT; $subject = "Contact Form Submission from " . $name;
            $body = "New contact form submission:\n\nName: {$name}\nEmail: {$email}\nMessage:\n{$message}\n\nIP Address: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T');
            if (send_email($to, $subject, $body, $email, $name, $logContext, $logFile)) {
                $pdo = get_db_connection();
                if ($pdo) {
                    try {
                        $sql = "INSERT INTO contact_submissions (name, email, message, submitted_at, ip_address) VALUES (:name, :email, :message, NOW(), :ip)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':name' => $name, ':email' => $email, ':message' => $message, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
                        log_message("{$logContext} Data saved to database. Name: {$name}", $logFile);
                        $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message has been sent successfully."]; $form_submissions[$form_id] = [];
                    } catch (\PDOException $e) {
                        log_message("{$logContext} Database Insertion Error: " . $e->getMessage(), LOG_FILE_ERROR);
                        $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message was sent, but there was an issue saving it to our records. We will still get back to you."]; $form_submissions[$form_id] = [];
                    }
                } else {
                    log_message("{$logContext} Database connection failed, could not save submission.", LOG_FILE_ERROR);
                    $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message was sent, but couldn't be saved to our database due to a connection issue."]; $form_submissions[$form_id] = [];
                }
            } else { $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$name}, there was an error sending your message. Please try again later or contact us directly."]; }
        } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the " . ($errorCount > 1 ? $errorCount . " errors" : "error") . " below."]; log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR); }
        $_SESSION['scroll_to'] = '#contact';
    }
    // --- Process Volunteer Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form'; $form_errors[$form_id] = [];
        $logContext = "[Volunteer Form]"; $logFile = LOG_FILE_VOLUNTEER;
        $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? null); $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? null); $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? null); $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? null); $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? null); $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? null);
        $form_submissions[$form_id] = [ 'volunteer_name' => $volunteer_name, 'volunteer_email' => $volunteer_email, 'volunteer_phone' => $volunteer_phone, 'volunteer_area' => $volunteer_area, 'volunteer_availability' => $volunteer_availability, 'volunteer_message' => $volunteer_message ];
        $rules = [ 'volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100', 'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255', 'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20', 'volunteer_area' => 'required|in:Health Camps,Blood Donation Drives,Education Support,Environmental Projects,Skill Development,Event Management,General Support', 'volunteer_availability' => 'required|minLength:5|maxLength:150', 'volunteer_message' => 'maxLength:1000', ];
        $validation_errors = validate_data($form_submissions[$form_id], $rules); $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_VOLUNTEER; $subject = "Volunteer Application from " . $volunteer_name;
            $body = "New volunteer application:\n\nName: {$volunteer_name}\nEmail: " . ($volunteer_email ?: '(Not Provided)') . "\nPhone: " . ($volunteer_phone ?: '(Not Provided)') . "\nArea of Interest: {$volunteer_area}\nAvailability: {$volunteer_availability}\n" . (!empty($volunteer_message) ? "Message:\n{$volunteer_message}\n" : "") . "\nIP Address: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T');
            if (send_email($to, $subject, $body, $volunteer_email, $volunteer_name, $logContext, $logFile)) {
                $pdo = get_db_connection();
                if ($pdo) {
                    try {
                        $sql = "INSERT INTO volunteer_submissions (name, email, phone, area_of_interest, availability, message, submitted_at, ip_address) VALUES (:name, :email, :phone, :area_of_interest, :availability, :message, NOW(), :ip)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':name' => $volunteer_name, ':email' => $volunteer_email ?: null, ':phone' => $volunteer_phone ?: null, ':area_of_interest' => $volunteer_area, ':availability' => $volunteer_availability, ':message' => $volunteer_message ?: null, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
                        log_message("{$logContext} Data saved to database. Name: {$volunteer_name}", $logFile);
                        $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your volunteer application has been received. We'll be in touch soon."]; $form_submissions[$form_id] = [];
                    } catch (\PDOException $e) { log_message("{$logContext} Database Insertion Error: " . $e->getMessage(), LOG_FILE_ERROR); $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your application was sent, but there was an issue saving it to our records. We will still get back to you."]; $form_submissions[$form_id] = []; }
                } else { log_message("{$logContext} Database connection failed, could not save submission.", LOG_FILE_ERROR); $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your application was sent, but couldn't be saved to our database due to a connection issue."]; $form_submissions[$form_id] = []; }
            } else { $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$volunteer_name}, there was an error submitting your application. Please try again later."]; }
        } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the " . ($errorCount > 1 ? $errorCount . " errors" : "error") . " below."]; log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR); }
        $_SESSION['scroll_to'] = '#volunteer';
    }
     else { log_message("[WARNING] Received POST request with unknown form_id: " . ($submitted_form_id ?? 'NULL'), LOG_FILE_ERROR); header("Location: " . htmlspecialchars($_SERVER['PHP_SELF'])); exit; }

    // --- Post-Processing & Redirect ---
    unset($_SESSION[CSRF_TOKEN_NAME]); $csrf_token = generate_csrf_token();
    $_SESSION['form_messages'] = $form_messages; $_SESSION['form_errors'] = $form_errors;
    if (!empty($form_errors[$submitted_form_id ?? ''])) { $_SESSION['form_submissions'] = $form_submissions; }
    else { unset($_SESSION['form_submissions'][$submitted_form_id]); if (empty($_SESSION['form_submissions'])) { unset($_SESSION['form_submissions']); } }
    $scrollTarget = $_SESSION['scroll_to'] ?? ''; unset($_SESSION['scroll_to']);
    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget); exit;
} else {
    // --- GET Request ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML ---
$contact_form_name_value = get_form_value('contact_form', 'name'); $contact_form_email_value = get_form_value('contact_form', 'email'); $contact_form_message_value = get_form_value('contact_form', 'message');
$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name'); $volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email'); $volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone'); $volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area'); $volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability'); $volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');

// --- Focus Areas Data ---
$focus_areas = [ ['title' => 'Health & Wellness', 'icon' => 'fas fa-heartbeat', 'description' => 'Organizing blood donation camps, health check-ups, and awareness programs.', 'link' => 'blood-donation.php'], ['title' => 'Education & Awareness', 'icon' => 'fas fa-book-open', 'description' => 'Supporting underprivileged students and conducting educational workshops.', 'link' => '#focus'], ['title' => 'Environment Protection', 'icon' => 'fas fa-leaf', 'description' => 'Leading initiatives like tree plantation and e-waste collection programs.', 'link' => 'e-waste.php'], ['title' => 'Skill Development', 'icon' => 'fas fa-comments', 'description' => 'Enhancing communication skills, personality, and leadership qualities among youth.', 'link' => '#focus'], ];
// --- Objectives Data ---
$objectives = [ "Foster holistic personality development among youth.", "Inculcate a spirit of voluntary work and community service.", "Promote health awareness and facilitate healthcare access.", "Contribute towards environmental sustainability.", "Bridge the gap between potential blood donors and recipients.", "Empower individuals through education and skill enhancement.", ];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f172a"> <!-- Slate 900 -->

    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/pahal_ngo_cover.jpg"> <!-- CHANGE -->
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/pahal_ngo_cover.jpg"> <!-- CHANGE -->

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/icon.svg">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    <!-- Google Fonts (Poppins) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple CSS -->
    <style>
        /* --- CSS Variables --- */
        :root {
            --font-sans: 'Poppins', sans-serif;
            /* Light Theme */
            --color-primary: #059669; /* Emerald 600 */
            --color-primary-hover: #047857; /* Emerald 700 */
            --color-secondary: #4f46e5; /* Indigo 600 */
            --color-secondary-hover: #4338ca; /* Indigo 700 */
            --color-accent: #e11d48; /* Rose 600 */
            --color-accent-hover: #be123c; /* Rose 700 */
            --color-success: #16a34a;
            --color-error: #dc2626; /* Red 600 */
            --color-bg: #f8fafc; /* Slate 50 */
            --color-surface: #ffffff;
            --color-surface-alt: #f1f5f9; /* Slate 100 */
            --color-text: #1e293b; /* Slate 800 */
            --color-text-muted: #64748b; /* Slate 500 */
            --color-text-heading: #0f172a; /* Slate 900 */
            --color-border: #e2e8f0; /* Slate 200 */
            --color-white: #ffffff;
            --color-black: #000000;
            --header-height: 70px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --focus-ring-color: rgba(79, 70, 229, 0.5); /* Secondary color focus */
            color-scheme: light;
        }

        html.dark {
            /* Dark Theme */
            --color-primary: #34d399; /* Emerald 400 */
            --color-primary-hover: #6ee7b7; /* Emerald 300 */
            --color-secondary: #818cf8; /* Indigo 400 */
            --color-secondary-hover: #a78bfa; /* Violet 400 */
            --color-accent: #f87171; /* Red 400 */
            --color-accent-hover: #fb7185; /* Rose 400 */
            --color-success: #4ade80;
            --color-error: #f87171; /* Red 400 */
            --color-bg: #0f172a; /* Slate 900 */
            --color-surface: #1e293b; /* Slate 800 */
            --color-surface-alt: #334155; /* Slate 700 */
            --color-text: #cbd5e1; /* Slate 300 */
            --color-text-muted: #94a3b8; /* Slate 400 */
            --color-text-heading: #f1f5f9; /* Slate 100 */
            --color-border: #475569; /* Slate 600 */
            --focus-ring-color: rgba(167, 139, 250, 0.6); /* Violet 400 focus */
             color-scheme: dark;
        }

        /* --- Base & Reset --- */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; font-size: 16px; }
        body {
            font-family: var(--font-sans);
            background-color: var(--color-bg);
            color: var(--color-text);
            line-height: 1.6;
            padding-top: var(--header-height); /* Offset for fixed header */
            transition: background-color 0.3s ease, color 0.3s ease;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* --- Typography --- */
        h1, h2, h3, h4, h5, h6 {
            color: var(--color-text-heading);
            font-weight: 600; /* Semibold */
            margin-bottom: 0.75em; /* Consistent bottom margin */
            line-height: 1.3;
        }
        h1 { font-size: 2.5rem; font-weight: 700; } /* 40px */
        h2 { font-size: 2rem; } /* 32px */
        h3 { font-size: 1.5rem; } /* 24px */
        h4 { font-size: 1.25rem; } /* 20px */
        p { margin-bottom: 1rem; max-width: 65ch; }
        a { color: var(--color-secondary); text-decoration: none; transition: color 0.2s ease; }
        a:hover { color: var(--color-secondary-hover); text-decoration: underline; }
        *:focus-visible {
             outline: 2px solid transparent;
             box-shadow: 0 0 0 3px var(--focus-ring-color);
             border-radius: 3px;
             outline-offset: 1px;
        }
        address { font-style: normal; }
        hr { border: none; height: 1px; background-color: var(--color-border); margin: 2rem 0; }
        /* Helper for visually hidden */
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0; }
        .hidden { display: none !important; }

        /* --- Layout --- */
        .container {
            width: 100%;
            max-width: 1200px; /* Max width */
            margin-left: auto;
            margin-right: auto;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        .section-padding { padding: 4rem 0; /* ~py-16 */ }
         @media (min-width: 768px) {
            .section-padding { padding: 5rem 0; /* ~py-20 */ }
            h1 { font-size: 3rem; } /* 48px */
            h2 { font-size: 2.25rem; } /* 36px */
            h3 { font-size: 1.75rem; } /* 28px */
        }
         @media (min-width: 1024px) {
            .section-padding { padding: 6rem 0; /* ~py-24 */ }
            h1 { font-size: 3.75rem; } /* 60px */
        }

        .text-center { text-align: center; }
        .section-title {
            text-align: center;
            color: var(--color-primary);
            margin-bottom: 3rem; /* Spacing below title */
        }
        .section-title.underline::after {
            content: '';
            display: block;
            width: 80px; /* Width of underline */
            height: 3px; /* Thickness */
            background: linear-gradient(to right, var(--color-secondary), var(--color-accent));
            margin: 0.75rem auto 0; /* Spacing above/below */
            border-radius: 2px;
            opacity: 0.8;
        }
        .grid { display: grid; gap: 1.5rem; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .justify-center { justify-content: center; }
        .gap-small { gap: 0.75rem; }
        .gap-medium { gap: 1.5rem; }
        .gap-large { gap: 2.5rem; }

        /* --- Header --- */
        #main-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--header-height);
            background-color: rgba(255, 255, 255, 0.9); /* Light mode bg */
            border-bottom: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            z-index: 100;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        html.dark #main-header {
             background-color: rgba(15, 23, 42, 0.9); /* Dark mode bg (slate-900) */
             border-bottom-color: var(--color-border);
        }
        #main-header.scrolled {
             background-color: rgba(255, 255, 255, 0.95);
             box-shadow: var(--shadow-md);
        }
        html.dark #main-header.scrolled {
             background-color: rgba(15, 23, 42, 0.95);
        }
        #main-header .container { display: flex; align-items: center; justify-content: space-between; height: 100%; }
        .logo a { display: flex; align-items: center; gap: 0.5rem; font-size: 1.5rem; font-weight: 700; color: var(--color-primary); }
        .logo img { height: 30px; width: 30px; }

        /* --- Navigation --- */
        #navbar ul { list-style: none; display: flex; align-items: center; gap: 0.25rem; }
        #navbar ul li a.nav-link {
            display: block;
            padding: 0.5rem 0.75rem; /* Padding for links */
            color: var(--color-text-muted);
            font-weight: 500; /* Medium weight */
            font-size: 0.95rem;
            border-radius: 4px;
            position: relative;
             transition: color 0.2s ease, background-color 0.2s ease;
        }
        #navbar ul li a.nav-link:hover { color: var(--color-primary); background-color: rgba(0,0,0,0.03); }
        html.dark #navbar ul li a.nav-link:hover { background-color: rgba(255,255,255,0.05); }
        #navbar ul li a.nav-link.active { color: var(--color-primary); font-weight: 600; }
        /* Simple underline for active/hover */
        #navbar ul li a.nav-link::after {
            content: '';
            position: absolute;
            bottom: 2px; /* Position underline */
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background-color: var(--color-primary);
            transition: width 0.3s ease;
        }
        #navbar ul li a.nav-link:hover::after,
        #navbar ul li a.nav-link.active::after { width: 60%; } /* Underline width */

        /* Dark Mode Toggle Button */
        #dark-mode-toggle {
             background: none; border: none; cursor: pointer;
             color: var(--color-text-muted); font-size: 1.1rem; padding: 0.5rem;
             border-radius: 50%; transition: color 0.2s, background-color 0.2s;
             margin-left: 0.5rem; line-height: 0; /* Align icon better */
        }
        #dark-mode-toggle:hover { color: var(--color-primary); background-color: rgba(0,0,0,0.05); }
        html.dark #dark-mode-toggle:hover { background-color: rgba(255,255,255,0.1); }

        /* --- Mobile Navigation --- */
        #menu-toggle-btn { display: none; background: none; border: none; cursor: pointer; padding: 0.5rem; }
        #menu-toggle-btn span {
            display: block; width: 24px; height: 2px; background-color: var(--color-text-muted);
            margin: 5px 0; border-radius: 1px; transition: transform 0.3s ease, opacity 0.3s ease;
        }
        #menu-toggle-btn.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        #menu-toggle-btn.open span:nth-child(2) { opacity: 0; }
        #menu-toggle-btn.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        @media (max-width: 1023px) { /* Breakpoint for mobile nav */
             #menu-toggle-btn { display: block; }
             #navbar {
                 display: none; /* Hidden by default */
                 position: absolute;
                 top: var(--header-height);
                 left: 0;
                 width: 100%;
                 background-color: var(--color-surface);
                 border-top: 1px solid var(--color-border);
                 box-shadow: var(--shadow-lg);
                 padding: 1rem 0;
                 max-height: calc(100vh - var(--header-height));
                 overflow-y: auto;
             }
             html.dark #navbar { background-color: var(--color-surface); border-top-color: var(--color-border); }
             #navbar.open { display: block; }
             #navbar ul { flex-direction: column; align-items: stretch; gap: 0; }
             #navbar ul li { width: 100%; }
             #navbar ul li a.nav-link { display: block; text-align: center; padding: 0.75rem 1rem; border-radius: 0; border-bottom: 1px solid var(--color-border); }
             #navbar ul li:last-child a.nav-link { border-bottom: none; }
             #navbar ul li a.nav-link::after { display: none; } /* Hide underline on mobile */
             /* Center dark mode toggle on mobile */
             #navbar ul li:has(#dark-mode-toggle) { margin-top: 1rem; border-top: 1px solid var(--color-border); padding-top: 1rem; text-align: center; }
             #dark-mode-toggle { margin: 0 auto; }
        }

        /* --- Hero Section --- */
        #hero {
            background: linear-gradient(135deg, #059669, #4f46e5, #0ea5e9); /* Emerald, Indigo, Sky */
            color: var(--color-white);
            min-height: calc(90vh - var(--header-height));
            display: flex;
            align-items: center;
            padding: 3rem 0; /* Reduced padding */
            position: relative;
            overflow: hidden;
        }
        html.dark #hero {
             background: linear-gradient(135deg, #065f46, #3730a3, #0369a1); /* Darker shades */
        }
        #hero::before { /* Subtle overlay */
             content: ''; position: absolute; inset: 0; background-color: rgba(0, 0, 0, 0.15);
        }
        .hero-content { position: relative; z-index: 1; display: grid; gap: 2rem; align-items: center; }
        .hero-text h1 { color: var(--color-white); line-height: 1.2; text-shadow: 1px 1px 3px rgba(0,0,0,0.2); }
        .hero-text p { font-size: 1.1rem; opacity: 0.9; max-width: 55ch; margin-bottom: 2rem; }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center; }
        .hero-logo { display: flex; justify-content: center; }
        .hero-logo img {
            width: 180px; height: 180px; border-radius: 50%; background-color: rgba(255, 255, 255, 0.1);
            padding: 0.5rem; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            object-fit: contain;
        }
         .hero-scroll-indicator { display: none; /* Keep hidden for simplicity */ }
        @media (min-width: 1024px) {
             #hero { padding: 4rem 0; }
             .hero-content { grid-template-columns: 1fr 1fr; gap: 3rem; }
             .hero-text { text-align: left; }
             .hero-text p { font-size: 1.2rem; }
             .hero-actions { justify-content: flex-start; }
             .hero-logo { justify-content: flex-end; }
             .hero-logo img { width: 240px; height: 240px; }
             /* Optional: Show scroll indicator on desktop */
             /* .hero-scroll-indicator { display: block; position: absolute; bottom: 2rem; left: 50%; transform: translateX(-50%); z-index: 1; } */
             /* .hero-scroll-indicator a { color: rgba(255,255,255,0.6); font-size: 2rem; } */
             /* .hero-scroll-indicator a:hover { color: var(--color-white); } */
        }

        /* --- Buttons --- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.75rem 1.5rem; /* Button padding */
            font-size: 1rem; font-weight: 500;
            border-radius: 6px; /* Rounded corners */
            border: 1px solid transparent;
            cursor: pointer;
            text-align: center;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease, transform 0.1s ease;
            white-space: nowrap; /* Prevent wrapping */
            box-shadow: var(--shadow-sm);
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn:active { transform: translateY(0); }
        .btn i { margin-right: 0.5rem; font-size: 0.9em; }
        .btn.btn-primary { background-color: var(--color-primary); color: var(--color-white); }
        .btn.btn-primary:hover { background-color: var(--color-primary-hover); }
        .btn.btn-secondary { background-color: var(--color-secondary); color: var(--color-white); }
        .btn.btn-secondary:hover { background-color: var(--color-secondary-hover); }
        .btn.btn-accent { background-color: var(--color-accent); color: var(--color-white); }
        .btn.btn-accent:hover { background-color: var(--color-accent-hover); }
        .btn.btn-outline {
            background-color: transparent;
            border: 2px solid var(--color-primary);
            color: var(--color-primary);
        }
        .btn.btn-outline:hover { background-color: rgba(5, 150, 105, 0.1); } /* Primary with alpha */
        .btn.btn-outline.secondary { border-color: var(--color-secondary); color: var(--color-secondary); }
        .btn.btn-outline.secondary:hover { background-color: rgba(79, 70, 229, 0.1); } /* Secondary with alpha */
        /* Inverted outline for dark backgrounds */
        .btn.btn-outline.inverted { border-color: var(--color-white); color: var(--color-white); opacity: 0.9; }
        .btn.btn-outline.inverted:hover { background-color: rgba(255, 255, 255, 0.1); opacity: 1; }
        /* Disabled state */
        .btn:disabled, button:disabled { opacity: 0.6; cursor: not-allowed; box-shadow: none; transform: none; }
        .btn .spinner { width: 1em; height: 1em; border-width: 2px; display: inline-block; animation: spin 1s linear infinite; border-radius: 50%; border-top-color: transparent; border-right-color: transparent; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* --- Cards (Focus Area) --- */
        .focus-grid { display: grid; gap: 1.5rem; }
        .focus-item {
            background-color: var(--color-surface);
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-md);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border-top: 4px solid var(--color-secondary); /* Top border accent */
        }
        .focus-item:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
        .focus-item .icon { font-size: 2.5rem; color: var(--color-secondary); margin-bottom: 1rem; }
        .focus-item h3 { font-size: 1.25rem; color: var(--color-text-heading); margin-bottom: 0.5rem; }
        .focus-item p { font-size: 0.9rem; color: var(--color-text-muted); flex-grow: 1; margin-bottom: 1rem; }
        .focus-item .read-more-link { font-size: 0.9rem; font-weight: 500; color: var(--color-primary); margin-top: auto; }
        .focus-item .read-more-link:hover { text-decoration: underline; }
        .focus-item .read-more-link::after { content: ' →'; } /* Simple arrow */
        @media (min-width: 640px) { .focus-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .focus-grid { grid-template-columns: repeat(4, 1fr); } }

        /* --- Objectives Section --- */
        #objectives { background-color: var(--color-surface-alt); }
        .objectives-grid { display: grid; gap: 1rem; max-width: 900px; margin: 0 auto; }
        .objective-item {
            background-color: var(--color-surface);
            padding: 1rem 1.25rem;
            border-radius: 6px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: flex-start; /* Align icon top */
            gap: 0.75rem;
            border-left: 3px solid var(--color-primary); /* Left border accent */
        }
        .objective-item i { color: var(--color-primary); margin-top: 0.2em; font-size: 1rem; flex-shrink: 0; width: 20px; text-align: center; }
        .objective-item p { margin-bottom: 0; font-size: 0.95rem; }
        @media (min-width: 768px) { .objectives-grid { grid-template-columns: repeat(2, 1fr); gap: 1.25rem; } }

        /* --- Volunteer/Donate Sections --- */
        #volunteer, #donate { color: var(--color-white); position: relative; }
        #volunteer { background-color: var(--color-accent); } /* Accent bg */
        #donate { background-color: var(--color-primary); } /* Primary bg */
        html.dark #volunteer { background-color: var(--color-accent-hover); }
        html.dark #donate { background-color: var(--color-primary-hover); }
        #volunteer::before, #donate::before { content: ''; position: absolute; inset: 0; background-color: rgba(0, 0, 0, 0.1); } /* Consistent overlay */
        #volunteer .container, #donate .container { position: relative; z-index: 1; }
        #volunteer .section-title, #donate .section-title { color: var(--color-white); }
        #volunteer .section-title.underline::after,
        #donate .section-title.underline::after { background: var(--color-white); opacity: 0.7; }
        #volunteer p, #donate p { color: rgba(255, 255, 255, 0.9); text-align: center; max-width: 70ch; margin-left: auto; margin-right: auto; }
        #donate .container > div { text-align: center; } /* Center donate button */
        #donate p:last-of-type { font-size: 0.8rem; opacity: 0.7; margin-top: 0.5rem; } /* Note style */

        /* --- Forms --- */
        .form-panel {
            background-color: rgba(0, 0, 0, 0.15); /* Darker panel on colored bg */
            padding: 1.5rem;
            border-radius: 8px;
            max-width: 700px;
            margin: 2rem auto 0; /* Spacing above panel */
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.1);
        }
        html.dark .form-panel { background-color: rgba(0, 0, 0, 0.25); }
        .form-panel h3 { color: var(--color-white); text-align: center; font-size: 1.4rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9); /* Label color on dark bg */
        }
        .form-label.required::after { content: '*'; color: var(--color-accent); margin-left: 0.25rem; }
        html.dark .form-label.required::after { color: var(--color-error); } /* Use dark mode error color */
        .form-input, .form-select, .form-textarea {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem; /* Input padding */
            font-size: 1rem;
            line-height: 1.5;
            color: var(--color-white); /* Text color */
            background-color: rgba(255, 255, 255, 0.08); /* Input background */
            background-clip: padding-box;
            border: 1px solid rgba(255, 255, 255, 0.2); /* Input border */
            appearance: none; /* Remove default styling */
            border-radius: 6px; /* Input border radius */
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out, background-color 0.15s ease-in-out;
        }
        .form-input::placeholder, .form-textarea::placeholder { color: rgba(255, 255, 255, 0.5); opacity: 1; }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            color: var(--color-white);
            background-color: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.5);
            outline: 0;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2); /* Focus ring */
        }
        .form-select {
             /* Add arrow for select */
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23cccccc' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
             background-repeat: no-repeat;
             background-position: right 1rem center;
             background-size: 16px 12px;
             padding-right: 3rem; /* Space for arrow */
        }
        .form-textarea { min-height: 100px; resize: vertical; }
        .form-hint { font-size: 0.8rem; color: rgba(255, 255, 255, 0.7); margin-top: 0.25rem; }
        .form-input-error, .form-select.form-input-error, .form-textarea.form-input-error {
             border-color: var(--color-error) !important; /* Error border */
             box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.4) !important; /* Error focus ring (red-400) */
        }
        .form-error-message {
             color: var(--color-error); /* Light red for dark bg */
             font-size: 0.8rem; font-weight: 500; margin-top: 0.3rem; display: flex; align-items: center; gap: 0.25rem;
        }
         html.light #volunteer .form-error-message { color: #fecaca; } /* Very light red */
        .form-error-message i { font-size: 0.9em; }
        /* Form grid layout */
        .form-grid { display: grid; gap: 1.25rem; }
        @media (min-width: 768px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        .form-button-container { text-align: center; margin-top: 1.5rem; }

        /* --- Contact Section --- */
        #contact { background-color: var(--color-bg); }
        .contact-grid { display: grid; gap: 2.5rem; }
        .contact-info, .contact-form-container {
             background-color: var(--color-surface);
             padding: 2rem;
             border-radius: 8px;
             box-shadow: var(--shadow-lg);
             border: 1px solid var(--color-border);
        }
        .contact-info h3, .contact-form-container h3 {
            color: var(--color-secondary);
            font-size: 1.4rem;
            margin-bottom: 1.5rem;
        }
        .contact-info-item { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
        .contact-info-item i {
            color: var(--color-primary); font-size: 1.1rem; margin-top: 0.15em;
            flex-shrink: 0; width: 20px; text-align: center;
        }
        .contact-info-item div h4 { font-size: 0.9rem; font-weight: 600; color: var(--color-text-heading); margin-bottom: 0.1rem; }
        .contact-info-item div p, .contact-info-item div address { font-size: 0.9rem; color: var(--color-text-muted); margin-bottom: 0; line-height: 1.5; }
        .contact-info-item div a { color: var(--color-secondary); }
        .contact-info-item div a:hover { text-decoration: underline; }
        .registration-info {
             background-color: var(--color-surface-alt);
             padding: 0.75rem 1rem;
             border-radius: 4px;
             border: 1px solid var(--color-border);
             font-size: 0.75rem;
             color: var(--color-text-muted);
             margin-top: 2rem;
             box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
        }
        /* Style form inside contact section */
        #contact .form-label { color: var(--color-text-muted); } /* Standard label color */
        #contact .form-input, #contact .form-select, #contact .form-textarea {
            color: var(--color-text);
            background-color: var(--color-surface-alt); /* Light input background */
            border-color: var(--color-border);
        }
         html.dark #contact .form-input, html.dark #contact .form-select, html.dark #contact .form-textarea {
            background-color: var(--color-surface-alt); /* Dark surface alt */
            border-color: var(--color-border);
            color: var(--color-text);
        }
         #contact .form-input::placeholder, #contact .form-textarea::placeholder { color: var(--color-text-muted); opacity: 0.7; }
         #contact .form-input:focus, #contact .form-select:focus, #contact .form-textarea:focus {
             background-color: var(--color-white);
             border-color: var(--color-primary); /* Focus border */
             box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.3); /* Focus ring - primary */
         }
         html.dark #contact .form-input:focus, html.dark #contact .form-select:focus, html.dark #contact .form-textarea:focus {
            background-color: var(--color-surface);
            border-color: var(--color-primary); /* Focus border */
            box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.4); /* Focus ring - primary dark */
         }
         #contact .form-error-message { color: var(--color-error); } /* Standard error color */
         #contact .form-input-error {
             border-color: var(--color-error) !important;
             box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.3) !important; /* Red 600 focus */
         }
         html.dark #contact .form-input-error {
             border-color: var(--color-error) !important;
             box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.4) !important; /* Red 400 focus */
         }
        @media (min-width: 1024px) { .contact-grid { grid-template-columns: 1fr 1fr; } }


        /* --- Footer --- */
        footer {
            background-color: #111827; /* Gray 900 */
            color: #9ca3af; /* Gray 400 */
            padding: 3rem 0 1.5rem;
            margin-top: 0; /* Ensure no gap above footer */
            border-top: 4px solid var(--color-primary);
        }
        html.dark footer { background-color: var(--color-black); }
        .footer-grid { display: grid; gap: 2rem; margin-bottom: 2.5rem; }
        .footer-col h4.footer-heading {
            font-size: 1.1rem; font-weight: 600; color: var(--color-white); margin-bottom: 1rem;
            position: relative; padding-bottom: 0.5rem;
        }
        .footer-col h4.footer-heading::after {
            content: ''; position: absolute; bottom: 0; left: 0;
            width: 30px; height: 2px; background-color: var(--color-primary); border-radius: 1px;
        }
        .footer-col p, .footer-col address { font-size: 0.875rem; line-height: 1.6; margin-bottom: 0.5rem; }
        .footer-col ul { list-style: none; padding: 0; }
        .footer-col li { margin-bottom: 0.3rem; }
        .footer-col a.footer-link { color: #9ca3af; display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.875rem; }
        .footer-col a.footer-link:hover { color: var(--color-white); text-decoration: underline; }
        .footer-col a.footer-link i { opacity: 0.7; font-size: 0.9em; width: 16px; text-align: center; }
        .footer-col address i { color: var(--color-primary); }
        .footer-social-icons { display: flex; gap: 1rem; margin-top: 1rem; }
        .footer-social-icons a { color: #9ca3af; font-size: 1.2rem; transition: color 0.2s ease, transform 0.2s ease; }
        .footer-social-icons a:hover { color: var(--color-white); transform: scale(1.1); }
        .footer-bottom {
            text-align: center; font-size: 0.8rem; color: #6b7280; /* Gray 500 */
            padding-top: 1.5rem; border-top: 1px solid #374151; /* Gray 700 */
        }
        html.dark .footer-bottom { border-top-color: #4b5563; } /* Gray 600 */
        .footer-bottom a { color: #9ca3af; }
        .footer-bottom a:hover { color: var(--color-white); }
        @media (min-width: 768px) { .footer-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .footer-grid { grid-template-columns: repeat(4, 1fr); } }

        /* --- Modal --- */
        #bank-details-modal {
            position: fixed; inset: 0; z-index: 200;
            background-color: rgba(0, 0, 0, 0.6);
            display: none; /* Hidden by default */
            align-items: center; justify-content: center;
            padding: 1rem;
             backdrop-filter: blur(4px);
             opacity: 0; transition: opacity 0.3s ease;
        }
        #bank-details-modal.visible { display: flex; opacity: 1; }
        .modal-box {
            background-color: var(--color-surface);
            color: var(--color-text); /* Ensure text color contrast */
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 500px; /* Modal max width */
            position: relative;
             transform: scale(0.95);
             opacity: 0;
             transition: transform 0.3s ease, opacity 0.3s ease;
        }
        #bank-details-modal.visible .modal-box { transform: scale(1); opacity: 1; }
        .modal-box h3 { color: var(--color-primary); font-size: 1.4rem; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--color-border); }
        .modal-box p { font-size: 0.95rem; margin-bottom: 1rem; color: var(--color-text-muted); }
        .modal-content-box {
            background-color: var(--color-surface-alt);
            padding: 1rem; border-radius: 6px; border: 1px solid var(--color-border);
            margin: 1rem 0; font-size: 0.9rem;
        }
        .modal-content-box p { color: var(--color-text); margin-bottom: 0.5rem; line-height: 1.4; }
        .modal-content-box p strong { font-weight: 600; color: var(--color-text-heading); }
        .modal-footer-note { font-size: 0.8rem; text-align: center; color: var(--color-text-muted); margin-top: 1.5rem; font-style: italic; }
        .close-button {
            position: absolute; top: 0.75rem; right: 0.75rem;
            background: none; border: none; cursor: pointer;
            font-size: 1.5rem; color: var(--color-text-muted); line-height: 1;
            padding: 0.25rem; border-radius: 50%;
            transition: color 0.2s ease, background-color 0.2s ease;
        }
        .close-button:hover { color: var(--color-accent); background-color: rgba(0,0,0,0.05); }
        html.dark .close-button:hover { background-color: rgba(255,255,255,0.1); }

        /* --- Back to Top Button --- */
        #back-to-top {
            position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 50;
            background-color: var(--color-primary); color: var(--color-white);
            width: 40px; height: 40px; border-radius: 50%;
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; box-shadow: var(--shadow-lg);
            opacity: 0; visibility: hidden;
            transform: translateY(10px);
            transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease, background-color 0.2s ease;
        }
        #back-to-top.visible { opacity: 1; visibility: visible; transform: translateY(0); }
        #back-to-top:hover { background-color: var(--color-primary-hover); }

        /* --- Form Status Messages --- */
        .form-message {
             padding: 1rem 1.5rem; margin-bottom: 1.5rem; border-radius: 6px;
             border: 1px solid transparent; display: block;
             font-size: 0.95rem; line-height: 1.5;
             opacity: 0; transform: translateY(10px);
             animation: formMessageIn 0.5s 0.1s ease-out forwards;
        }
        @keyframes formMessageIn {
             to { opacity: 1; transform: translateY(0); }
        }
        .form-message-success {
             background-color: #d1fae5; border-color: #6ee7b7; color: #065f46; /* Green */
        }
        html.dark .form-message-success {
             background-color: rgba(16, 185, 129, 0.2); border-color: var(--color-success); color: #a7f3d0;
        }
        .form-message-error {
             background-color: #fee2e2; border-color: #fca5a5; color: #991b1b; /* Red */
        }
         html.dark .form-message-error {
            background-color: rgba(239, 68, 68, 0.2); border-color: var(--color-error); color: #fecaca;
        }
        .form-message-title { display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 1.05em; }
        .form-message-title i { margin-right: 0.5rem; font-size: 1.1em; vertical-align: middle; }
        .form-message-text { display: block; }

        /* --- Animations on Scroll --- */
        .animate-on-scroll { opacity: 0; transition: opacity 0.7s ease-out, transform 0.7s ease-out; }
        .animate-on-scroll.fade-in-up { transform: translateY(20px); }
        .animate-on-scroll.fade-in-left { transform: translateX(-30px); }
        .animate-on-scroll.fade-in-right { transform: translateX(30px); }
        .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }

        /* Honeypot */
        .honeypot-field { position: absolute !important; left: -9999px !important; width: 1px !important; height: 1px !important; overflow: hidden !important; opacity: 0 !important; }

    </style>

    <!-- Schema.org JSON-LD -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org", "@type": "NGO", "name": "PAHAL NGO", "alternateName": "PAHAL Jalandhar",
      "url": "https://your-pahal-domain.com/", <!-- CHANGE -->
      "logo": "https://your-pahal-domain.com/icon.svg", <!-- CHANGE -->
      "description": "<?= htmlspecialchars($page_description) ?>",
      "address": { "@type": "PostalAddress", "streetAddress": "36 New Vivekanand Park, Maqsudan", "addressLocality": "Jalandhar", "addressRegion": "Punjab", "postalCode": "144008", "addressCountry": "IN" },
      "contactPoint": [ { "@type": "ContactPoint", "telephone": "+91-6239366376", "contactType": "General Inquiry", "name": "Mr Bipan Suman" }, { "@type": "ContactPoint", "email": "<?= RECIPIENT_EMAIL_CONTACT ?>", "contactType": "General Inquiry" } ],
      "potentialAction": [ { "@type": "DonateAction", "name": "Donate to PAHAL", "target": "https://your-pahal-domain.com/#donate" }, { "@type": "RegisterAction", "name": "Volunteer with PAHAL", "target": "https://your-pahal-domain.com/#volunteer" } ]
    }
    </script>
</head>
<body> <!-- No dark class needed here, applied on <html> -->

<!-- Header -->
<header id="main-header">
   <div class="container">
        <div class="logo">
           <a href="#hero">
             <img src="icon.webp" alt="PAHAL Icon"> <!-- Adjust path if needed -->
             <span>PAHAL</span>
            </a>
       </div>

       <!-- Mobile Menu Button -->
       <button id="menu-toggle-btn" aria-label="Toggle Menu" aria-expanded="false">
           <span></span><span></span><span></span>
       </button>

       <!-- Navigation -->
       <nav id="navbar" aria-label="Main Navigation">
            <ul>
                <li><a href="#hero" class="nav-link active">Home</a></li>
                <li><a href="#focus" class="nav-link">Focus Areas</a></li>
                <li><a href="#objectives" class="nav-link">Objectives</a></li>
                <li><a href="#volunteer" class="nav-link">Volunteer</a></li>
                <li><a href="#donate" class="nav-link">Donate</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                <li><a href="blood-donation.php" class="nav-link">Blood Donation</a></li>
                <li><a href="e-waste.php" class="nav-link">E-Waste</a></li>
                <li>
                    <button id="dark-mode-toggle" aria-label="Toggle Dark Mode">
                        <i class="fas fa-moon" id="dark-mode-icon"></i>
                    </button>
                </li>
            </ul>
       </nav>
   </div>
</header>

<main>

    <!-- Hero Section -->
    <section id="hero">
        <div class="container hero-content">
            <div class="hero-text animate-on-scroll fade-in-left">
                <h1>PAHAL: Igniting Change, Empowering Youth</h1>
                <p>A volunteer-driven NGO in Jalandhar, dedicated to holistic development through impactful initiatives in health, education, environment, and skills.</p>
                <div class="hero-actions">
                    <a href="#volunteer" class="btn btn-secondary"><i class="fas fa-hands-helping"></i> Join Us</a>
                    <a href="#donate" class="btn btn-outline inverted"><i class="fas fa-donate"></i> Support Us</a>
                </div>
            </div>
            <div class="hero-logo animate-on-scroll fade-in-right delay-200">
                <img src="icon.webp" alt="PAHAL NGO Logo"> <!-- Adjust path if needed -->
            </div>
        </div>
        <!-- Scroll Indicator Removed for simplicity -->
    </section>

    <!-- Focus Areas Section -->
    <section id="focus" class="section-padding"> <!-- Removed background class, handled by body/section default -->
        <div class="container">
            <h2 class="section-title underline">Our Core Focus Areas</h2>
            <div class="focus-grid">
                <?php foreach ($focus_areas as $index => $area): ?>
                <div class="focus-item animate-on-scroll fade-in-up delay-<?= $index * 100 ?>">
                    <i class="<?= htmlspecialchars($area['icon']) ?> icon"></i>
                    <h3><?= htmlspecialchars($area['title']) ?></h3>
                    <p><?= htmlspecialchars($area['description']) ?></p>
                    <?php if (!empty($area['link']) && $area['link'] !== '#focus'): ?>
                        <a href="<?= htmlspecialchars($area['link']) ?>" class="read-more-link">Learn More</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Objectives Section -->
    <section id="objectives" class="section-padding">
        <div class="container">
            <h2 class="section-title underline">Our Objectives</h2>
            <div class="objectives-grid">
                <?php foreach ($objectives as $index => $objective): ?>
                <div class="objective-item animate-on-scroll fade-in-up delay-<?= $index * 50 ?>">
                    <i class="fas fa-check"></i>
                    <p><?= htmlspecialchars($objective) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Volunteer Section -->
    <section id="volunteer" class="section-padding">
        <div class="container">
            <h2 class="section-title underline">Become a Volunteer</h2>
            <p>Join our passionate team and make a difference. Fill out the form below!</p>

            <div class="form-panel animate-on-scroll fade-in-up">
                <h3>Volunteer Application</h3>
                <?= get_form_status_html('volunteer_form') ?>

                <form id="volunteer_form_element" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer" method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                    <input type="hidden" name="form_id" value="volunteer_form">
                    <div class="honeypot-field" aria-hidden="true">
                        <label for="website_url_volunteer" class="sr-only">Keep This Blank</label>
                        <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="volunteer_name" class="form-label required">Name</label>
                            <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Full Name" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>>
                            <?= get_field_error_html('volunteer_form', 'volunteer_name') ?>
                        </div>
                         <div class="form-group">
                            <label for="volunteer_area" class="form-label required">Area of Interest</label>
                            <select id="volunteer_area" name="volunteer_area" required class="form-select <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>>
                                <option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : '' ?>>-- Select --</option>
                                <option value="Health Camps" <?= ($volunteer_form_area_value === 'Health Camps') ? 'selected' : '' ?>>Health Camps</option>
                                <option value="Blood Donation Drives" <?= ($volunteer_form_area_value === 'Blood Donation Drives') ? 'selected' : '' ?>>Blood Donation Drives</option>
                                <option value="Education Support" <?= ($volunteer_form_area_value === 'Education Support') ? 'selected' : '' ?>>Education Support</option>
                                <option value="Environmental Projects" <?= ($volunteer_form_area_value === 'Environmental Projects') ? 'selected' : '' ?>>Environmental Projects</option>
                                <option value="Skill Development" <?= ($volunteer_form_area_value === 'Skill Development') ? 'selected' : '' ?>>Skill Development</option>
                                <option value="Event Management" <?= ($volunteer_form_area_value === 'Event Management') ? 'selected' : '' ?>>Event Management</option>
                                <option value="General Support" <?= ($volunteer_form_area_value === 'General Support') ? 'selected' : '' ?>>General Support</option>
                            </select>
                            <?= get_field_error_html('volunteer_form', 'volunteer_area') ?>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="volunteer_email" class="form-label">Email</label>
                            <input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="you@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>>
                            <p class="form-hint">Required if phone not provided.</p>
                            <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                        </div>
                        <div class="form-group">
                            <label for="volunteer_phone" class="form-label">Phone</label>
                            <input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="e.g., 9876543210" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>>
                            <p class="form-hint">Required if email not provided.</p>
                            <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="volunteer_availability" class="form-label required">Availability</label>
                        <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>>
                        <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                    </div>

                    <div class="form-group">
                        <label for="volunteer_message" class="form-label">Message <span style="font-size: 0.8em; opacity: 0.8;">(Optional)</span></label>
                        <textarea id="volunteer_message" name="volunteer_message" rows="3" class="form-textarea <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Anything else you'd like to share?" <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea>
                        <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                    </div>

                    <div class="form-button-container">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i><span>Submit Application</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- Donate Section -->
    <section id="donate" class="section-padding">
        <div class="container">
             <div class="animate-on-scroll fade-in-up">
                <h2 class="section-title underline">Support Our Cause</h2>
                <p>Your contribution empowers us to continue our work. Every donation makes a significant impact.</p>
                <button id="donate-button" class="btn btn-accent"><i class="fas fa-hand-holding-usd"></i> Donate Now</button>
                <p>Bank details for direct transfer will be shown.</p>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="section-padding">
        <div class="container">
            <h2 class="section-title underline">Get In Touch</h2>
            <div class="contact-grid">
                <!-- Contact Info -->
                <div class="contact-info animate-on-scroll fade-in-left">
                    <h3>Contact Information</h3>
                    <p>We'd love to hear from you! Reach out with questions or collaboration ideas.</p>
                    <div>
                        <div class="contact-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <h4>Address:</h4>
                                <address>
                                    36 New Vivekanand Park, Maqsudan,<br>
                                    Jalandhar, Punjab - 144008, India
                                </address>
                            </div>
                        </div>
                        <div class="contact-info-item">
                            <i class="fas fa-phone-alt"></i>
                            <div>
                                <h4>Phone:</h4>
                                <p><a href="tel:+916239366376">+91 6239366376</a></p>
                            </div>
                        </div>
                        <div class="contact-info-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <h4>Email:</h4>
                                <p><a href="mailto:<?= RECIPIENT_EMAIL_CONTACT ?>?subject=Inquiry%20via%20Website"><?= RECIPIENT_EMAIL_CONTACT ?></a></p>
                            </div>
                        </div>
                    </div>
                    <div class="registration-info">
                        Registered under Societies Registration Act (XXI of 1860). Reg No: DIC/JAL/SOCIETY/2004-2005/111
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="contact-form-container animate-on-scroll fade-in-right delay-150">
                    <h3 class="text-center">Send Us a Message</h3>
                    <?= get_form_status_html('contact_form') ?>

                    <form id="contact_form_element" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                         <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_contact" class="sr-only">Keep This Blank</label>
                            <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="name" class="form-label required">Name</label>
                            <input type="text" id="name" name="name" required value="<?= $contact_form_name_value ?>" class="form-input <?= get_field_error_class('contact_form', 'name') ?>" placeholder="Your Full Name" <?= get_aria_describedby('contact_form', 'name') ?>>
                            <?= get_field_error_html('contact_form', 'name') ?>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label required">Email</label>
                            <input type="email" id="email" name="email" required value="<?= $contact_form_email_value ?>" class="form-input <?= get_field_error_class('contact_form', 'email') ?>" placeholder="you@example.com" <?= get_aria_describedby('contact_form', 'email') ?>>
                            <?= get_field_error_html('contact_form', 'email') ?>
                        </div>

                        <div class="form-group">
                            <label for="message" class="form-label required">Message</label>
                            <textarea id="message" name="message" rows="4" required class="form-textarea <?= get_field_error_class('contact_form', 'message') ?>" placeholder="Your message..." <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea>
                            <?= get_field_error_html('contact_form', 'message') ?>
                        </div>

                        <div class="form-button-container">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-paper-plane"></i><span>Send Message</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- Footer -->
<footer>
    <div class="container">
        <div class="footer-grid">
            <!-- Column 1: About -->
            <div class="footer-col">
                <h4 class="footer-heading">About PAHAL</h4>
                <p>A volunteer-driven youth NGO in Jalandhar, committed to community development and positive social change.</p>
                <!-- Social Icons Placeholder -->
                 <!-- <div class="footer-social-icons">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                </div> -->
            </div>
            <!-- Column 2: Quick Links -->
            <div class="footer-col">
                <h4 class="footer-heading">Quick Links</h4>
                <ul>
                    <li><a href="#hero" class="footer-link"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="#focus" class="footer-link"><i class="fas fa-bullseye"></i> Focus Areas</a></li>
                    <li><a href="#volunteer" class="footer-link"><i class="fas fa-hands-helping"></i> Volunteer</a></li>
                    <li><a href="#donate" class="footer-link"><i class="fas fa-donate"></i> Donate</a></li>
                    <li><a href="#contact" class="footer-link"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </div>
            <!-- Column 3: Programs -->
            <div class="footer-col">
                <h4 class="footer-heading">Our Programs</h4>
                 <ul>
                    <li><a href="blood-donation.php" class="footer-link"><i class="fas fa-tint"></i> Blood Donation</a></li>
                    <li><a href="e-waste.php" class="footer-link"><i class="fas fa-recycle"></i> E-Waste Recycling</a></li>
                    <li><a href="#focus" class="footer-link"><i class="fas fa-graduation-cap"></i> Education & Skills</a></li>
                    <li><a href="#focus" class="footer-link"><i class="fas fa-leaf"></i> Environment</a></li>
                </ul>
            </div>
            <!-- Column 4: Contact Info -->
            <div class="footer-col">
                <h4 class="footer-heading">Contact Info</h4>
                <address>
                    <p><i class="fas fa-map-marker-alt"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab 144008</p>
                    <p><i class="fas fa-phone-alt"></i> <a href="tel:+916239366376" class="footer-link">+91 6239366376</a></p>
                    <p><i class="fas fa-envelope"></i> <a href="mailto:<?= RECIPIENT_EMAIL_CONTACT ?>" class="footer-link"><?= RECIPIENT_EMAIL_CONTACT ?></a></p>
                </address>
            </div>
        </div>
        <div class="footer-bottom">
            © <?= $current_year ?> PAHAL NGO. All Rights Reserved.
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="back-to-top" aria-label="Back to Top" title="Back to Top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Donate Modal -->
<div id="bank-details-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-box">
        <button class="close-button" aria-label="Close Modal"><i class="fas fa-times"></i></button>
        <h3 id="modal-title">Bank Details for Donation</h3>
        <p>You can donate directly using the details below. Thank you!</p>
        <div class="modal-content-box">
            <p><strong>Account Name:</strong> PAHAL</p>
            <p><strong>Account Number:</strong> 14640100001433</p>
            <p><strong>Bank Name:</strong> Bank of Baroda</p>
            <p><strong>Branch:</strong> Maqsudan, Jalandhar</p>
            <p><strong>IFSC Code:</strong> BARB0MAQSUDA <span style="font-size: 0.8em; opacity: 0.8;">(5th char is Zero)</span></p>
        </div>
        <p class="modal-footer-note">Please mention "Donation" in the transaction description if possible.</p>
    </div>
</div>

<!-- Main JavaScript (Mostly unchanged, uses IDs) -->
<script>
 document.addEventListener('DOMContentLoaded', () => {
    // console.log("PAHAL Simple CSS JS Loaded");

    const htmlElement = document.documentElement;
    const header = document.getElementById('main-header');
    const menuToggleBtn = document.getElementById('menu-toggle-btn');
    const navbar = document.getElementById('navbar');
    const navLinks = navbar ? navbar.querySelectorAll('.nav-link[href^="#"]') : [];
    const sections = document.querySelectorAll('main section[id]'); // Target sections within main
    const backToTopButton = document.getElementById('back-to-top');
    const donateButton = document.getElementById('donate-button');
    const modal = document.getElementById('bank-details-modal');
    const closeModalButton = modal ? modal.querySelector('.close-button') : null;
    const darkModeToggle = document.getElementById('dark-mode-toggle');
    const darkModeIcon = document.getElementById('dark-mode-icon');
    const animatedElements = document.querySelectorAll('.animate-on-scroll');

    // --- Dark Mode ---
    const applyTheme = (theme) => {
        if (theme === 'dark') {
            htmlElement.classList.add('dark');
            if (darkModeIcon) darkModeIcon.classList.replace('fa-moon', 'fa-sun');
            localStorage.setItem('theme', 'dark');
        } else {
            htmlElement.classList.remove('dark');
            if (darkModeIcon) darkModeIcon.classList.replace('fa-sun', 'fa-moon');
            localStorage.setItem('theme', 'light');
        }
    };
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(savedTheme ? savedTheme : (prefersDark ? 'dark' : 'light'));

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', () => {
            applyTheme(htmlElement.classList.contains('dark') ? 'light' : 'dark');
        });
    }
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
        if (!localStorage.getItem('theme')) { applyTheme(event.matches ? 'dark' : 'light'); }
    });

    // --- Header Scroll Effect ---
    if (header) {
        const scrollThreshold = 50;
        const updateHeader = () => {
            header.classList.toggle('scrolled', window.scrollY > scrollThreshold);
        };
        window.addEventListener('scroll', updateHeader, { passive: true });
        updateHeader();
    }

    // --- Mobile Menu Toggle ---
    if (menuToggleBtn && navbar) {
        menuToggleBtn.addEventListener('click', () => {
            const isOpen = menuToggleBtn.getAttribute('aria-expanded') === 'true';
            menuToggleBtn.setAttribute('aria-expanded', !isOpen);
            menuToggleBtn.classList.toggle('open');
            navbar.classList.toggle('open'); // You'll need CSS for .navbar.open
            // document.body.style.overflow = navbar.classList.contains('open') ? 'hidden' : '';
        });
        navbar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (navbar.classList.contains('open')) { menuToggleBtn.click(); }
            });
        });
    }

    // --- Active Nav Link Highlighting ---
     function changeActiveLink() {
        if (!navLinks.length || !sections.length) return;
        let currentSectionId = 'hero'; // Default to hero
        const headerHeight = header?.offsetHeight || 70;
        const scrollPosition = window.scrollY + headerHeight + 50;

        sections.forEach(section => {
            if (scrollPosition >= section.offsetTop) { currentSectionId = section.id; }
        });

        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${currentSectionId}`) {
                link.classList.add('active');
            }
        });
        // Ensure 'Home' is active if nothing else matches
        if (!navbar.querySelector('.nav-link.active')) {
            const homeLink = navbar.querySelector('.nav-link[href="#hero"]');
            if(homeLink) homeLink.classList.add('active');
        }
    }
    if (navLinks.length > 0 && sections.length > 0) {
        window.addEventListener('scroll', changeActiveLink, { passive: true });
        changeActiveLink();
    }

    // --- Form Message Animation Trigger (CSS handles animation) ---
    // const formMessages = document.querySelectorAll('[data-form-message-id]'); // Already handled by CSS animation delay

    // --- Back to Top Button ---
    if (backToTopButton) {
        const scrollThreshold = 300;
        const updateButtonVisibility = () => {
            backToTopButton.classList.toggle('visible', window.scrollY > scrollThreshold);
        };
        window.addEventListener('scroll', updateButtonVisibility, { passive: true });
        updateButtonVisibility();
        backToTopButton.addEventListener('click', (e) => {
            e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // --- Donate Modal ---
    if (donateButton && modal && closeModalButton) {
        let previouslyFocusedElement = null;
        const openModal = () => {
            previouslyFocusedElement = document.activeElement;
            modal.classList.add('visible'); // Use 'visible' class
            if (closeModalButton) closeModalButton.focus();
        };
        const closeModal = () => {
            modal.classList.remove('visible');
            if (previouslyFocusedElement) previouslyFocusedElement.focus();
            else if (donateButton) donateButton.focus();
        };
        donateButton.addEventListener('click', openModal);
        closeModalButton.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('visible')) closeModal(); });
    }

    // --- Animation on Scroll ---
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible'); // CSS handles the animation
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        animatedElements.forEach(el => observer.observe(el));
    } else {
        animatedElements.forEach(el => el.classList.add('is-visible')); // Fallback
    }

     // --- Handle Form Submission Indicator ---
     const handleFormSubmit = (formElement) => {
         if (!formElement) return;
         formElement.addEventListener('submit', (e) => {
             if (typeof formElement.checkValidity === 'function' && !formElement.checkValidity()) { return; }
             const submitButton = formElement.querySelector('button[type="submit"]');
             if (submitButton) {
                 submitButton.disabled = true;
                 const buttonTextSpan = submitButton.querySelector('span'); // Target the span containing text
                 const originalIcon = submitButton.querySelector('i');
                 const spinner = submitButton.querySelector('.spinner');

                 if (!spinner) { // Add spinner dynamically if needed
                    const newSpinner = document.createElement('span');
                    newSpinner.className = 'spinner';
                    newSpinner.style.marginRight = '0.5em'; // Add spacing
                    submitButton.prepend(newSpinner);
                 }
                 if(originalIcon) originalIcon.style.display = 'none';
                 if (buttonTextSpan) buttonTextSpan.textContent = 'Submitting...';
                 // If no span, might need more complex logic to find the text node
             }
         });
     };
     handleFormSubmit(document.getElementById('volunteer_form_element'));
     handleFormSubmit(document.getElementById('contact_form_element'));

 });
</script>

</body>
</html>
