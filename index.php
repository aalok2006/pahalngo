<?php
require __DIR__ . '/../vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Example usage
$mail = new PHPMailer(true);
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Enhanced Version: v2.0
// Features: PHPMailer, CSRF Protection, Honeypot, Logging, Expanded Content
// ========================================================================
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    die('Autoload file missing!');
}

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Dependency Check ---
// Ensure PHPMailer is loaded (via Composer autoload)


$mail = new PHPMailer(true);

try {
    $mail->setFrom('aalokkumar1902@gmail.com');
    $mail->addAddress('aalokkumar1902@gmail.com');
    $mail->Subject = 'Hello';
    $mail->Body    = 'This is a test mail.';
    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>


// --- Configuration ---
// ------------------------------------------------------------------------
// --- Email Settings ---
// CHANGE THIS to the email address where you want to receive CONTACT messages
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com");
// CHANGE THIS to the email address where you want to receive VOLUNTEER messages
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com");
// PHPMailer SMTP Configuration (RECOMMENDED for deliverability)
// Set USE_SMTP to true to use SMTP, false to use PHP mail() (less reliable)
define('USE_SMTP', true); // <<< CHANGE TO true FOR PRODUCTION
define('SMTP_HOST', 'smtp.example.com');        // Your SMTP server (e.g., smtp.gmail.com, smtp.mailgun.org)
define('SMTP_PORT', 587);                        // SMTP Port (587 for TLS, 465 for SSL)
define('SMTP_USERNAME', 'your_smtp_user@example.com'); // Your SMTP username
define('SMTP_PASSWORD', 'your_smtp_password');   // Your SMTP password
define('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS); // Or PHPMailer::ENCRYPTION_SMTPS
define('SMTP_FROM_EMAIL', 'noreply@your-pahal-domain.com'); // Email address mails will appear FROM
define('SMTP_FROM_NAME', 'PAHAL NGO Website');            // Name mails will appear FROM

// --- Security Settings ---
define('CSRF_TOKEN_NAME', 'csrf_token'); // Name for the CSRF token field
define('HONEYPOT_FIELD_NAME', 'website_url'); // Name for the honeypot field

// --- Logging ---
define('ENABLE_LOGGING', true); // Set to true to log submissions/errors
define('LOG_FILE_CONTACT', __DIR__ . '/logs/contact_submissions.log'); // Path to contact log file
define('LOG_FILE_VOLUNTEER', __DIR__ . '/logs/volunteer_submissions.log'); // Path to volunteer log file
define('LOG_FILE_ERROR', __DIR__ . '/logs/form_errors.log');           // Path to error log file
// --- END CONFIG ---
// ------------------------------------------------------------------------


// --- Function Definitions ---

/**
 * Logs a message to a specified file.
 * Creates the log directory if it doesn't exist.
 *
 * @param string $message The message to log.
 * @param string $logFile The full path to the log file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;

    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            // Cannot create directory, log to PHP error log as fallback
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ($logFile): " . $message);
            return;
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;

    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        // Cannot write to file, log to PHP error log
        $error = error_get_last();
        error_log("Failed to write to log file: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown'));
        error_log("Original Log Message: " . $message);
    }
}

/**
 * Generates or retrieves a CSRF token.
 *
 * @return string The CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token.
 *
 * @param string|null $submittedToken The token from the form submission.
 * @return bool True if valid, false otherwise.
 */
function validate_csrf_token(?string $submittedToken): bool {
    if (empty($submittedToken) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
}

/**
 * Sanitize input string (more robust).
 *
 * @param string $input The raw input string.
 * @return string Sanitized string.
 */
function sanitize_string(string $input): string {
    $input = trim($input);
    // Strip tags to prevent XSS, optionally allow specific basic tags if needed
    $input = strip_tags($input);
    // Convert special characters to HTML entities
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $input;
}

/**
 * Sanitize email address.
 *
 * @param string $email Raw email input.
 * @return string Sanitized email or empty string if invalid structure.
 */
function sanitize_email(string $email): string {
    $email = trim($email);
    // Basic filter first
    $sanitized_email = filter_var($email, FILTER_SANITIZE_EMAIL);
    // Validate the sanitized result
    if (filter_var($sanitized_email, FILTER_VALIDATE_EMAIL)) {
        return $sanitized_email;
    }
    return ''; // Return empty if invalid format
}

/**
 * Validates input data based on rules.
 *
 * @param array $data Associative array of field_name => value.
 * @param array $rules Associative array of field_name => rules_string (e.g., 'required|email|maxLength:255').
 * @return array Array of errors [field_name => error_message].
 */
function validate_data(array $data, array $rules): array {
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        $ruleList = explode('|', $ruleString);

        foreach ($ruleList as $rule) {
            $params = [];
            if (strpos($rule, ':') !== false) {
                list($rule, $paramString) = explode(':', $rule, 2);
                $params = explode(',', $paramString);
            }

            $isValid = true;
            $errorMessage = '';

            switch ($rule) {
                case 'required':
                    if ($value === null || $value === '') {
                        $isValid = false;
                        $errorMessage = ucfirst(str_replace('_', ' ', $field)) . " is required.";
                    }
                    break;
                case 'email':
                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid email address.";
                    }
                    break;
                case 'minLength':
                    if (!empty($value) && mb_strlen($value, 'UTF-8') < (int)$params[0]) {
                        $isValid = false;
                        $errorMessage = ucfirst(str_replace('_', ' ', $field)) . " must be at least {$params[0]} characters long.";
                    }
                    break;
                case 'maxLength':
                    if (!empty($value) && mb_strlen($value, 'UTF-8') > (int)$params[0]) {
                        $isValid = false;
                        $errorMessage = ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$params[0]} characters.";
                    }
                    break;
                case 'alpha_space': // Allow letters and spaces
                    if (!empty($value) && !preg_match('/^[A-Za-z\s]+$/', $value)) {
                        $isValid = false;
                        $errorMessage = ucfirst(str_replace('_', ' ', $field)) . " must only contain letters and spaces.";
                    }
                    break;
                 case 'phone': // Basic North American phone number structure (adjust regex as needed)
                    // Allows formats like 123-456-7890, (123) 456-7890, 123 456 7890, 123.456.7890, 1234567890 etc. and optional + extension
                    if (!empty($value) && !preg_match('/^(\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid phone number.";
                    }
                    break;
                 case 'contains':
                    if (!empty($value) && strpos($value, $params[0]) === false) {
                         $isValid = false;
                         $errorMessage = ucfirst(str_replace('_', ' ', $field)) . " must contain '{$params[0]}'.";
                    }
                    break;
                 // Add more rules as needed: numeric, url, etc.
            }

            if (!$isValid && empty($errors[$field])) { // Only add the first error for a field
                $errors[$field] = $errorMessage;
            }
        }
    }
    return $errors;
}


/**
 * Send email using PHPMailer (or fallback to mail())
 *
 * @param string $to Recipient email address.
 * @param string $subject Email subject.
 * @param string $body Email body (plain text).
 * @param string $replyToEmail Email for Reply-To header.
 * @param string $replyToName Name for Reply-To header.
 * @param string $logContext Prefix for logging messages (e.g., "[Contact Form]").
 * @return bool True on success, false on failure.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    if (USE_SMTP) {
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8'; // Ensure UTF-8 encoding

            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            if (!empty($replyToEmail)) {
                 $mail->addReplyTo($replyToEmail, $replyToName);
            }

            // Content
            $mail->isHTML(false); // Send as plain text
            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
            log_message("{$logContext} Email sent successfully via SMTP to {$to}. Subject: {$subject}", LOG_FILE_CONTACT); // Use a general log for sending status maybe?
            return true;
        } catch (PHPMailerException $e) {
            $errorMsg = "{$logContext} SMTP Mailer Error: {$mail->ErrorInfo}";
            log_message($errorMsg, LOG_FILE_ERROR);
            error_log($errorMsg); // Also log to general PHP error log
            return false;
        } catch (Exception $e) { // Catch broader exceptions
             $errorMsg = "{$logContext} General Error during SMTP setup: {$e->getMessage()}";
            log_message($errorMsg, LOG_FILE_ERROR);
            error_log($errorMsg);
            return false;
        }
    } else {
        // Fallback to built-in mail() - LESS RELIABLE
        $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        if (!empty($replyToEmail)) {
             $headers .= "Reply-To: " . $replyToName . " <" . $replyToEmail . ">\r\n";
        }
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; // Specify content type and charset

        // Attempt to send the email
        if (@mail($to, $subject, $body, $headers)) {
            log_message("{$logContext} Email sent successfully via mail() to {$to}. Subject: {$subject}", LOG_FILE_CONTACT); // Use general log
            return true;
        } else {
            $errorInfo = error_get_last(); // Get the last error if mail() failed
            $errorMsg = "{$logContext} Native mail() Error: " . ($errorInfo['message'] ?? 'Unknown mail() error occurred.');
            log_message($errorMsg, LOG_FILE_ERROR);
            error_log($errorMsg); // Log error server-side
            return false;
        }
    }
}

// --- Initialize Variables ---
// General page variables
$current_year = date('Y');
$page_title = "PAHAL NGO - An Endeavour for a Better Tomorrow";
$page_description = "'PAHAL' is a voluntary youth organization in Jalandhar working for Health, Education, Environment, and Communication skills development.";
$page_keywords = "PAHAL, NGO, Jalandhar, social work, youth organization, volunteer, health, education, environment, communication, blood donation, e-waste";

// Form state variables
$form_submissions = []; // To hold data for different forms processed on this page
$form_messages = []; // Associative array: form_id => ['type' => 'success|error', 'text' => 'Message']
$form_errors = [];   // Associative array: form_id => [field_name => 'Error text']

// Generate initial CSRF token
$csrf_token = generate_csrf_token();

// --- Form Processing Logic ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Identify which form was submitted
    $submitted_form_id = $_POST['form_id'] ?? null;

    // Basic Spam Checks
    // 1. Honeypot field: If filled, likely a bot
    if (!empty($_POST[HONEYPOT_FIELD_NAME])) {
        log_message("[SPAM DETECTED] Honeypot field filled. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        // Silently ignore or show a generic error
        // For simplicity here, we just exit without processing further for this request
        // In production, you might redirect or show a less informative message.
        http_response_code(400); // Bad Request
        die("Invalid request.");
    }

    // 2. CSRF Token Validation
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    if (!validate_csrf_token($submitted_token)) {
        log_message("[CSRF FAILURE] Invalid or missing CSRF token. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        // Invalidate the session token
        unset($_SESSION[CSRF_TOKEN_NAME]);
        http_response_code(403); // Forbidden
        die("Security validation failed. Please refresh the page and try again.");
    }
     // Regenerate CSRF token after successful validation (prevents token reuse) - Moved regeneration AFTER processing
     // unset($_SESSION[CSRF_TOKEN_NAME]); // Unset the old one

    // --- Process CONTACT Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        $form_errors[$form_id] = [];

        // Sanitize Inputs
        $name = sanitize_string($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? ''); // Returns '' if invalid format
        $message = sanitize_string($_POST['message'] ?? ''); // Allow more length here maybe?

        $form_submissions[$form_id] = ['name' => $name, 'email' => $email, 'message' => $message]; // Store sanitized data

        // Define Validation Rules
        $rules = [
            'name' => 'required|alpha_space|minLength:2|maxLength:100',
            'email' => 'required|email|maxLength:255',
            'message' => 'required|minLength:10|maxLength:5000',
        ];

        // Validate Data
        $validation_errors = validate_data($form_submissions[$form_id], $rules);
        $form_errors[$form_id] = $validation_errors;

        // If No Validation Errors, Proceed to Send Email
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_CONTACT;
            $subject = "PAHAL Website Contact: " . $name; // Use the sanitized name

            $body = "You have received a new message from the PAHAL website contact form.\n\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Sender Information:\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Name:    " . $name . "\n";
            $body .= "Email:   " . $email . "\n";
            $body .= "IP Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
            $body .= "Time:    " . date('Y-m-d H:i:s') . "\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Message:\n";
            $body .= "-------------------------------------------------\n";
            $body .= $message . "\n";
            $body .= "-------------------------------------------------\n";

            $logContext = "[Contact Form]";
            if (send_email($to, $subject, $body, $email, $name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message has been sent successfully. We'll get back to you soon."];
                // Log successful submission
                log_message("{$logContext} Submission successful. From: {$name} <{$email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_CONTACT);
                // Clear form fields ONLY on success
                $form_submissions[$form_id] = ['name' => '', 'email' => '', 'message' => ''];
            } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$name}, there was an error sending your message. Please try again later or use the phone number provided."];
                // Log error - Specific error logged within send_email()
                 log_message("{$logContext} Submission FAILED after validation. From: {$name} <{$email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else {
            // Validation Errors Occurred
            $errorCount = count($validation_errors);
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix the {$errorCount} error(s) indicated below."];
            log_message("[Contact Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        }
        // Scroll target for redirect
        $_SESSION['scroll_to'] = '#contact';

    } // End processing contact form


    // --- Process VOLUNTEER Sign-up Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form';
        $form_errors[$form_id] = [];

        // Sanitize Inputs
        $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? '');
        $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? '');
        $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? ''); // Basic string sanitize, validation rule checks format
        $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? '');
        $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? '');
        $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? '');

        $form_submissions[$form_id] = [
            'volunteer_name' => $volunteer_name,
            'volunteer_email' => $volunteer_email,
            'volunteer_phone' => $volunteer_phone,
            'volunteer_area' => $volunteer_area,
            'volunteer_availability' => $volunteer_availability,
            'volunteer_message' => $volunteer_message
        ];

        // Define Validation Rules
        $rules = [
            'volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'volunteer_email' => 'required|email|maxLength:255',
            'volunteer_phone' => 'required|phone|maxLength:20', // Added phone rule
            'volunteer_area' => 'required|maxLength:100', // e.g., Health, Education, Environment
            'volunteer_availability' => 'required|maxLength:200', // e.g., Weekends, Evenings
            'volunteer_message' => 'maxLength:2000', // Optional message
        ];

        // Validate Data
        $validation_errors = validate_data($form_submissions[$form_id], $rules);
        $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_VOLUNTEER; // Separate email for volunteer coordination
            $subject = "PAHAL Website: New Volunteer Sign-up - " . $volunteer_name;

            $body = "A new volunteer has expressed interest through the PAHAL website.\n\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Volunteer Information:\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Name:          " . $volunteer_name . "\n";
            $body .= "Email:         " . $volunteer_email . "\n";
            $body .= "Phone:         " . $volunteer_phone . "\n";
            $body .= "Area Interest: " . $volunteer_area . "\n";
            $body .= "Availability:  " . $volunteer_availability . "\n";
            $body .= "IP Address:    " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
            $body .= "Timestamp:     " . date('Y-m-d H:i:s') . "\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Optional Message:\n";
            $body .= "-------------------------------------------------\n";
            $body .= (!empty($volunteer_message) ? $volunteer_message : "(No message provided)") . "\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Next Steps: Please follow up with the volunteer.\n";
            $body .= "-------------------------------------------------\n";


            $logContext = "[Volunteer Form]";
             if (send_email($to, $subject, $body, $volunteer_email, $volunteer_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you for your interest, {$volunteer_name}! We've received your information and will contact you soon about volunteering opportunities."];
                // Log successful submission
                log_message("{$logContext} Submission successful. From: {$volunteer_name} <{$volunteer_email}>. Area: {$volunteer_area}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_VOLUNTEER);
                // Clear form fields ONLY on success
                 $form_submissions[$form_id] = []; // Clear all for this form
            } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$volunteer_name}, there was an error submitting your volunteer interest. Please try again later or contact us directly."];
                 log_message("{$logContext} Submission FAILED after validation. From: {$volunteer_name} <{$volunteer_email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else {
            // Validation Errors Occurred
            $errorCount = count($validation_errors);
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} highlighted error(s) below to sign up."];
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        }
         // Scroll target for redirect
        $_SESSION['scroll_to'] = '#volunteer-section';

    } // End processing volunteer form

    // --- Add processing for other forms (e.g., newsletter) if needed ---

    // --- Post-Processing ---
    // Regenerate CSRF token for the next request
    unset($_SESSION[CSRF_TOKEN_NAME]);
    $csrf_token = generate_csrf_token(); // Generate a new one for the response page

     // Redirect to self to prevent form re-submission on refresh (Post/Redirect/Get pattern)
     // We store messages and errors in the session to display them after redirect
    $_SESSION['form_messages'] = $form_messages;
    $_SESSION['form_errors'] = $form_errors;
    $_SESSION['form_submissions'] = $form_submissions; // Keep submitted data on error

    // Preserve query string if any, add scroll target
    $queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
    $scrollTo = $_SESSION['scroll_to'] ?? '';
    // Use session based scroll_to to survive redirect
    // $targetUrl = htmlspecialchars($_SERVER['PHP_SELF']) . $queryString . $scrollTo;

    // We need to get the redirect target from session _before_ unsetting it
    $scrollTarget = $_SESSION['scroll_to'] ?? '';
    unset($_SESSION['scroll_to']); // Clean up scroll target


    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $queryString . $scrollTarget);
    exit;

} else {
    // --- Not a POST request, retrieve messages/errors from session (after redirect) ---
    if (isset($_SESSION['form_messages'])) {
        $form_messages = $_SESSION['form_messages'];
        unset($_SESSION['form_messages']); // Clear after displaying
    }
    if (isset($_SESSION['form_errors'])) {
        $form_errors = $_SESSION['form_errors'];
        unset($_SESSION['form_errors']); // Clear after displaying
    }
    if (isset($_SESSION['form_submissions'])) {
        $form_submissions = $_SESSION['form_submissions'];
        unset($_SESSION['form_submissions']); // Clear after displaying
    }
     // Clear the used CSRF token if it's still there from a previous failed request without redirect somehow? (belt and braces)
    // unset($_SESSION[CSRF_TOKEN_NAME]);
    // Ensure a token exists for the form render
    $csrf_token = generate_csrf_token();

}

// ------------------------------------------------------------------------
// --- Prepare Data for HTML ---
// Get form field values (use submitted values from session if available, else empty)
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions;
    return htmlspecialchars($form_submissions[$formId][$fieldName] ?? $default, ENT_QUOTES, 'UTF-8');
}

// Generate form status message HTML
function get_form_status_html(string $formId): string {
    global $form_messages;
    if (empty($form_messages[$formId])) {
        return '';
    }

    $message = $form_messages[$formId];
    $typeClass = ($message['type'] === 'success')
        ? 'bg-green-100 border border-green-400 text-green-700'
        : 'bg-red-100 border border-red-400 text-red-700';
    $icon = ($message['type'] === 'success') ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>';

    return "<div class=\"{$typeClass} px-4 py-3 rounded relative mb-4 form-message text-sm shadow-md\" role=\"alert\">{$icon}{$message['text']}</div>";
}

// Generate error message for a specific field
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) {
        return '<p class="text-red-600 text-xs italic mt-1" role="alert"><i class="fas fa-times-circle mr-1"></i>' . htmlspecialchars($form_errors[$formId][$fieldName]) . '</p>';
    }
    return '';
}

// Function to add error class to input if needed
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors;
     return isset($form_errors[$formId][$fieldName]) ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-primary-dark focus:ring-primary-dark';
}


// Prepare specific form field values for the template
// Contact Form
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message'); // Note: textarea needs content, not value attribute

// Volunteer Form
$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area');
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');


// --- Dummy Data for New Sections (Replace with dynamic data source if using DB) ---
$news_items = [
    ['date' => '2024-10-15', 'title' => 'Successful Blood Donation Camp Held', 'excerpt' => 'Over 50 units collected in our quarterly blood drive. Thank you donors!', 'link' => 'news-details.php?id=1', 'image' => 'https://via.placeholder.com/400x250.png/2E7D32/FFFFFF?text=Blood+Camp+Success'],
    ['date' => '2024-09-20', 'title' => 'E-Waste Awareness Campaign Launched', 'excerpt' => 'Partnering with local schools to educate students on responsible e-waste disposal.', 'link' => 'e-waste.php', 'image' => 'https://via.placeholder.com/400x250.png/FFA000/000000?text=E-Waste+Campaign'],
    ['date' => '2024-08-05', 'title' => 'New Communication Skills Workshop Series', 'excerpt' => 'Helping youth enhance their public speaking and interview skills.', 'link' => '#', 'image' => 'https://via.placeholder.com/400x250.png/1976D2/FFFFFF?text=Workshop+Started'],
];

$gallery_images = [
    ['src' => 'https://via.placeholder.com/600x400.png/008000/FFFFFF?text=PAHAL+Activity+1', 'alt' => 'PAHAL Community health checkup camp'],
    ['src' => 'https://via.placeholder.com/600x400.png/DC143C/FFFFFF?text=PAHAL+Activity+2', 'alt' => 'Volunteers participating in a tree plantation drive'],
    ['src' => 'https://via.placeholder.com/600x400.png/FFD700/000000?text=PAHAL+Activity+3', 'alt' => 'Educational workshop for students'],
    ['src' => 'https://via.placeholder.com/600x400.png/4682B4/FFFFFF?text=PAHAL+Activity+4', 'alt' => 'Blood donation camp participants'],
    ['src' => 'https://via.placeholder.com/600x400.png/32CD32/FFFFFF?text=PAHAL+Activity+5', 'alt' => 'Environment cleaning initiative'],
    ['src' => 'https://via.placeholder.com/600x400.png/8A2BE2/FFFFFF?text=PAHAL+Activity+6', 'alt' => 'Team members planning an event'],
];


?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://your-pahal-domain.com/"> <!-- CHANGE to your URL -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:image" content="https://your-pahal-domain.com/icon.webp"> <!-- CHANGE to your logo/image URL -->
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE to your URL -->
    <meta property="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="twitter:image" content="https://your-pahal-domain.com/icon.webp"> <!-- CHANGE to your logo/image URL -->

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon"> <!-- Create and place favicon.ico -->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png"> <!-- Optional: PNG favicons -->
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png"> <!-- For Apple devices -->
    <link rel="manifest" href="/site.webmanifest"> <!-- For PWAs -->


    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" /> <!-- Updated integrity hash -->

    <!-- Simple Lightbox CSS (Optional, for Gallery) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.10.3/dist/simple-lightbox.min.css">

    <script>
        // Tailwind Config (Keep as is or extend further)
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#008000', // Green
                        'primary-dark': '#006400', // Darker Green
                        accent: '#DC143C', // Crimson Red
                        'accent-dark': '#a5102f',
                        lightbg: '#f8f9fa', // Very light gray
                        darktext: '#333333', // Darker text for readability
                        mediumtext: '#555555', // Medium gray text
                        lighttext: '#777777', // Light gray text
                    },
                    fontFamily: {
                        sans: ['Open Sans', 'sans-serif'],
                        heading: ['Lato', 'sans-serif'],
                    },
                    container: {
                      center: true,
                      padding: '1rem',
                      screens: {
                        sm: '640px',
                        md: '768px',
                        lg: '1024px',
                        xl: '1140px',
                        '2xl': '1280px', // Slightly wider max width for larger screens
                      },
                    },
                    animation: { // Adding animations
                        'fade-in-up': 'fadeInUp 0.6s ease-out forwards',
                        'slide-in-left': 'slideInLeft 0.6s ease-out forwards',
                         'pulse-slow': 'pulse 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: { // Defining keyframes
                        fadeInUp: {
                            '0%': { opacity: 0, transform: 'translateY(20px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' },
                        },
                        slideInLeft: {
                            '0%': { opacity: 0, transform: 'translateX(-30px)' },
                            '100%': { opacity: 1, transform: 'translateX(0)' },
                        },
                    }
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Base & Utility Styles */
        body {
            @apply font-sans text-darktext leading-relaxed antialiased; /* Anti-aliasing for smoother fonts */
        }
        h1, h2, h3, h4, h5, h6 {
             @apply font-heading text-primary font-bold leading-tight mb-4 tracking-tight; /* Tighter tracking */
        }
        h2 { @apply text-3xl md:text-4xl; }
        h3 { @apply text-2xl md:text-3xl text-primary-dark; } /* Darker heading for sections */
        h4 { @apply text-xl font-semibold text-gray-800; }
        p { @apply mb-4 text-base text-mediumtext; } /* Medium text color for paragraphs */
        a { @apply transition duration-300 ease-in-out; }
        .container { @apply px-4 sm:px-6 lg:px-8; } /* Consistent padding */

        /* Components */
        .section-title {
            @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark;
        }
        .section-title::after {
            content: '';
            @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-accent rounded-full; /* Longer accent line */
        }
        .btn {
            @apply inline-block bg-accent text-white py-3 px-7 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-accent-dark hover:-translate-y-0.5 shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent text-base cursor-pointer;
        }
        .btn-secondary {
             @apply inline-block bg-primary text-white py-3 px-7 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-primary-dark hover:-translate-y-0.5 shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary;
             /* Using primary color for secondary actions */
        }
        .btn-outline {
             @apply inline-block bg-transparent border-2 border-accent text-accent py-2.5 px-6 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-accent hover:text-white focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-accent;
        }
        .card {
             @apply bg-white p-6 rounded-lg shadow-md overflow-hidden hover:shadow-xl transition-shadow duration-300;
        }

        /* Header Styles */
        #main-header {
            @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200; /* Added border */
            min-height: 70px;
        }
        #main-header.scrolled { /* Style for when scrolled */
             @apply shadow-md bg-white;
        }
        #navbar ul li a {
            @apply text-primary font-semibold py-1 relative transition duration-300 ease-in-out text-lg block lg:inline-block lg:py-0; /* Slightly larger nav links */
        }
        #navbar ul li a::after {
            content: '';
            @apply absolute bottom-[-5px] left-0 w-0 h-0.5 bg-accent transition-all duration-300 ease-in-out rounded-full;
        }
        #navbar ul li a:hover::after,
        #navbar ul li a.active::after {
            @apply w-full;
        }
        #navbar ul li a:hover,
        #navbar ul li a.active {
            @apply text-accent;
        }
        .menu-toggle span {
             @apply block w-7 h-0.5 bg-primary mb-1.5 rounded-sm transition-all duration-300 ease-in-out; /* Thicker lines */
        }
        .menu-toggle.active span:nth-child(1) { @apply transform rotate-45 translate-y-[8px]; } /* Adjusted translation */
        .menu-toggle.active span:nth-child(2) { @apply opacity-0; }
        .menu-toggle.active span:nth-child(3) { @apply transform -rotate-45 translate-y-[-8px]; } /* Adjusted translation */

        /* Hero Section Specifics */
        #hero {
             background: linear-gradient(rgba(0, 100, 0, 0.75), rgba(0, 64, 0, 0.85)), url('https://via.placeholder.com/1920x1080.png/CCCCCC/FFFFFF?text=PAHAL+Hero+Background+Image') no-repeat center center/cover;
             @apply text-white min-h-screen flex items-center pt-24 pb-16 relative overflow-hidden; /* min-h-screen and overflow */
        }
        #hero::before { /* Optional overlay pattern */
            content: '';
           /* background: url('path/to/overlay-pattern.svg'); */
           /* background-repeat: repeat; */
            @apply absolute inset-0 opacity-5 z-0;
        }
        .hero-text h2 {
             @apply text-4xl lg:text-6xl font-black text-white mb-6 drop-shadow-lg;
        }
        .hero-text p {
             @apply text-lg lg:text-xl mb-10 max-w-3xl mx-auto text-gray-100 drop-shadow;
        }
        .hero-logo img {
             @apply drop-shadow-xl animate-pulse-slow; /* Added subtle pulse */
        }

        /* Focus Area Card Styles */
        .focus-item {
             @apply border-t-4 border-primary-dark bg-white p-6 md:p-8 rounded-lg shadow-lg text-center transition-transform duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2 relative;
             @apply flex flex-col; /* Use flex to push content */
        }
        .focus-item .icon {
             @apply text-5xl text-accent mb-5 inline-block transition-transform duration-300 group-hover:scale-110;
        }
         .focus-item h3 {
            @apply text-xl text-primary-dark mb-3 transition-colors duration-300;
         }
         .focus-item p {
             @apply text-sm text-gray-600 leading-relaxed flex-grow; /* Make paragraph take available space */
         }
         .focus-item .read-more-link {
            @apply block text-sm font-semibold text-accent mt-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300;
            @apply hover:underline;
         }
         /* Style for linked focus items */
         a.focus-item { @apply no-underline; }
         a.focus-item:hover h3 { @apply text-accent-dark; }

        /* Contact Form Specifics */
        #contact-form label {
            @apply block mb-2 text-sm font-medium text-primary-dark font-semibold; /* Darker labels */
        }
        #contact-form input[type="text"],
        #contact-form input[type="email"],
        #contact-form textarea {
             @apply bg-gray-50 border text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-primary/50 block w-full p-3 transition duration-300 ease-in-out;
             @apply placeholder-gray-400; /* Lighter placeholder text */
        }
         #contact-form textarea { @apply resize-vertical min-h-[120px]; } /* Vertical resize */

        /* Form status/error message base class */
        .form-message { @apply text-sm font-medium; }

        /* News Section Styles */
        #news-section .news-card {
            @apply bg-white rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl;
        }
        #news-section .news-card img {
             @apply w-full h-48 object-cover transition-transform duration-300 group-hover:scale-105;
        }
         #news-section .news-card h4 {
             @apply text-lg text-primary font-semibold mb-2 px-4 pt-4;
         }
         #news-section .news-card .date {
             @apply text-xs text-gray-500 px-4 block mb-2;
         }
        #news-section .news-card p {
             @apply text-sm text-gray-600 px-4 pb-4 leading-relaxed;
        }
         #news-section .news-card a.read-more {
            @apply block bg-lightbg text-center py-2 px-4 text-primary font-semibold text-sm hover:bg-gray-200;
         }

        /* Gallery Section Styles */
        .gallery-item img {
             @apply rounded-lg shadow-md transition-all duration-300 ease-in-out hover:shadow-xl hover:scale-105 cursor-pointer;
             @apply border-2 border-transparent hover:border-accent;
        }

        /* Volunteer/Donate Sections */
        #volunteer-section, #donate-section {
             @apply bg-primary text-white;
        }
        #volunteer-section .section-title, #donate-section .section-title {
             @apply !text-white after:!bg-white;
        }
         #volunteer-form label { @apply text-gray-100; }
         #volunteer-form input, #volunteer-form select, #volunteer-form textarea {
             @apply bg-white/90 border border-gray-300 !text-gray-900; /* Input fields on dark bg */
         }
         #donate-section p { @apply text-gray-100 max-w-3xl mx-auto text-lg leading-relaxed; }

        /* Footer Styles */
        footer {
            @apply bg-primary-dark text-gray-300 pt-16 pb-8 mt-16 border-t-4 border-accent; /* Darker footer bg */
        }
         footer h4 { @apply text-lg font-semibold text-white mb-4 relative pb-2; }
         footer h4::after { content:''; @apply absolute bottom-0 left-0 w-10 h-0.5 bg-accent rounded; }
         footer ul li a { @apply text-gray-300 hover:text-white hover:underline text-sm; }
         footer address { @apply text-sm text-gray-300 leading-relaxed; }
         footer address i { @apply text-accent mr-2; } /* Icon color */
         .footer-bottom { @apply border-t border-gray-600 pt-6 mt-8 text-center text-sm text-gray-400; }


         /* Animation Utility */
         .animate-on-scroll {
             opacity: 0; /* Initially hidden */
             transition: opacity 0.6s ease-out, transform 0.6s ease-out;
         }
         .animate-on-scroll.is-visible {
             opacity: 1;
             transform: none; /* Reset transform or apply final transform */
         }
         .fade-in-up { transform: translateY(20px); }
         .fade-in-up.is-visible { transform: translateY(0); }
         .fade-in-left { transform: translateX(-30px); }
         .fade-in-left.is-visible { transform: translateX(0); }

        /* Accessibility Improvements */
        *:focus-visible {
          @apply outline-none ring-2 ring-offset-2 ring-accent; /* More visible focus rings */
        }
        /* Hide honeypot visually but keep accessible to screen readers (standard technique) */
        .honeypot-field {
            position: absolute;
            left: -5000px;
            top: auto;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

    </style>

    <!-- Google Analytics (or other tracker) - REPLACE UA-XXXXX-Y -->
    <!--
    <script async src="https://www.googletagmanager.com/gtag/js?id=UA-XXXXX-Y"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'UA-XXXXX-Y');
    </script>
    -->

</head>
<body class="bg-white text-gray-700 font-sans leading-relaxed">

<!-- Header -->
<header id="main-header" class="py-2 md:py-0">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <!-- Logo -->
        <div class="logo flex-shrink-0">
             <!-- Consider using an SVG logo for better scaling -->
             <a href="#hero" class="text-3xl md:text-4xl font-black text-accent font-heading leading-none flex items-center">
                <img src="icon.webp" alt="PAHAL Logo Icon" class="h-8 w-8 mr-2 inline"> <!-- Logo Icon -->
                PAHAL
             </a>
             <p class="text-xs text-gray-500 italic ml-10 -mt-1 hidden sm:block">An Endeavour for a Better Tomorrow</p>
        </div>

        <!-- Mobile Menu Toggle -->
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus:ring-2 focus:ring-primary rounded">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- Navigation -->
        <nav id="navbar" aria-label="Main Navigation" class="w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-full overflow-hidden transition-all duration-500 ease-in-out lg:overflow-visible absolute lg:relative top-[65px] lg:top-0 left-0 bg-white lg:bg-transparent shadow-lg lg:shadow-none lg:border-none border-t border-gray-200">
             <ul class="flex flex-col lg:flex-row lg:items-center lg:space-x-6 xl:space-x-8 py-4 lg:py-0 px-4 lg:px-0">
                <li><a href="#hero" class="nav-link active">Home</a></li>
                <li><a href="#profile" class="nav-link">Profile</a></li>
                <li><a href="#objectives" class="nav-link">Objectives</a></li>
                <li><a href="#areas-focus" class="nav-link">Focus Areas</a></li>
                 <li><a href="#news-section" class="nav-link">News & Events</a></li>
                <li><a href="#volunteer-section" class="nav-link">Get Involved</a></li>
                <li><a href="#associates" class="nav-link">Associates</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                <!-- Add more top-level links here -->
            </ul>
        </nav>
    </div>
</header>

<main>
    <!-- Hero Section -->
    <section id="hero" class="relative">
        <div class="container mx-auto relative z-10 flex flex-col lg:flex-row items-center justify-between gap-10 text-center">
             <div class="hero-text flex-1 lg:pl-5 order-2 lg:order-none flex flex-col items-center justify-center text-center animate-on-scroll fade-in-up">
              <h1 class="text-4xl lg:text-6xl font-black text-white mb-6 drop-shadow-lg font-heading">
                 Empowering Communities, Inspiring Change
              </h1>
              <p class="text-lg lg:text-xl mb-10 max-w-3xl mx-auto text-gray-100 drop-shadow-sm">
                PAHAL is dedicated to fostering holistic growth and creating a sustainable, equitable future through community-driven action in health, education, environment, and communication.
              </p>
              <div class="space-x-4">
                <a href="#profile" class="btn btn-secondary"><i class="fas fa-info-circle mr-2"></i>Learn More</a>
                 <a href="#volunteer-section" class="btn"><i class="fas fa-hands-helping mr-2"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-none flex-shrink-0 w-[150px] lg:w-auto animate-on-scroll fade-in-left" style="animation-delay: 0.2s;">
                <img src="icon.webp" alt="PAHAL NGO Logo - Large" class="mx-auto w-32 h-32 md:w-48 md:h-48 lg:w-56 lg:h-56">
            </div>
        </div>
         <!-- Scroll down indicator -->
        <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10 hidden md:block">
             <a href="#profile" aria-label="Scroll down to Profile" class="text-white/70 hover:text-white text-3xl animate-bounce">
                 <i class="fas fa-chevron-down"></i>
             </a>
        </div>
    </section>

    <!-- Profile Section -->
    <section id="profile" class="py-16 md:py-24 bg-lightbg animate-on-scroll">
        <div class="container mx-auto">
             <h2 class="section-title">Our Profile & Aim</h2>
             <div class="grid md:grid-cols-2 gap-12 items-center">
                 <div class="profile-text md:order-1 animate-on-scroll fade-in-left">
                    <h3 class="text-2xl mb-4">Who We Are</h3>
                    <p class="mb-6 text-gray-600 text-lg">'PAHAL', meaning 'Initiative', is a vibrant, volunteer-driven youth organization founded by a diverse group of passionate individuals: Educationists, Doctors, Legal Experts, Technocrats, Dynamic Entrepreneurs, and dedicated Students. We are united by a shared vision: to bring about positive, tangible change within our society.</p>
                    <blockquote class="border-l-4 border-accent bg-white p-4 my-6 shadow-sm">
                       <p class="italic font-semibold text-primary text-lg text-center">"PAHAL is an endeavour for a Better Tomorrow"</p>
                    </blockquote>
                    <h3 class="text-2xl mb-4 mt-6">Our Aim</h3>
                    <p class="text-gray-600 text-lg">Our fundamental goal is to foster <span class="font-semibold text-primary-dark">Holistic Personality Development</span>. We achieve this by inspiring individuals from all walks of life to actively engage in <span class="font-semibold text-primary-dark">service to humanity</span>. At PAHAL, we strive to awaken the social conscience, providing practical avenues for creative and constructive engagement with communities locally and globally.</p>
                 </div>
                 <div class="profile-image md:order-2 animate-on-scroll fade-in-up" style="animation-delay: 0.1s;">
                     <!-- --- IMAGE SUGGESTION ---
                          Replace with a dynamic group photo or a compelling image of PAHAL's work.
                          Placeholder: https://via.placeholder.com/600x450.png/1B5E20/FFFFFF?text=PAHAL+In+Action
                     -->
                     <img src="https://via.placeholder.com/600x450.png/1B5E20/FFFFFF?text=PAHAL+In+Action" alt="PAHAL NGO members working together" class="rounded-lg shadow-xl mx-auto w-full object-cover">
                 </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section -->
    <section id="objectives" class="py-16 md:py-24 animate-on-scroll">
        <div class="container mx-auto">
             <h2 class="section-title">Our Core Objectives</h2>
            <ul class="max-w-5xl mx-auto grid md:grid-cols-2 gap-6">
                 <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-users fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To foster genuine collaboration <strong class="text-primary-dark">with & among the people</strong>, ensuring community needs are central.</span>
                 </li>
                 <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-hands-helping fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To engage in <strong class="text-primary-dark">creative & constructive social action</strong>, promoting the inherent dignity of all forms of labour.</span>
                 </li>
                 <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-lightbulb fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To deepen self-awareness and community understanding through direct engagement with <strong class="text-primary-dark">social realities</strong>.</span>
                 </li>
                 <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-graduation-cap fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To translate academic knowledge into practical solutions for <strong class="text-primary-dark">mitigating societal challenges</strong>.</span>
                 </li>
                 <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-cogs fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To develop and implement skills essential for effective <strong class="text-primary-dark">humanity development programs</strong>.</span>
                 </li>
                  <li class="objective-item group bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-lg hover:border-accent hover:scale-[1.02] flex items-start">
                     <i class="fas fa-chart-line fa-fw text-primary group-hover:text-accent text-xl mr-4 mt-1 w-6 text-center flex-shrink-0 transition-colors"></i>
                     <span class="text-lg">To promote <strong class="text-primary-dark">sustainable practices</strong> and environmental consciousness in all activities.</span>
                 </li>
            </ul>
        </div>
    </section>

    <!-- Areas of Focus Section -->
    <section id="areas-focus" class="py-16 md:py-24 bg-lightbg animate-on-scroll">
        <div class="container mx-auto">
            <h2 class="section-title">Our Areas of Focus</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">

                 <!-- Health Card -->
                 <a href="blood-donation.php" title="Learn about PAHAL's Health & Blood Donation initiatives"
                    class="focus-item group animate-on-scroll fade-in-up">
                     <span class="icon"><i class="fas fa-heartbeat"></i></span>
                     <h3>Health & Wellness</h3>
                     <p>Promoting community health is paramount. We actively organize health awareness campaigns, observe key health days, and run life-saving blood donation drives. Your health, our priority.</p>
                      <span class="read-more-link">Explore Health Programs <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>

                  <!-- Education Card -->
                   <div class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.1s;">
                     <span class="icon"><i class="fas fa-book-open-reader"></i></span>
                     <h3>Education & Skilling</h3>
                     <p>Addressing the educational landscape requires dedication. PAHAL focuses on tackling unemployment by enhancing Ethical foundations, essential Life Skills, and practical Professional Education for youth.</p>
                      <!-- <a href="#education-programs" class="read-more-link">See Education Initiatives <i class="fas fa-arrow-right ml-1"></i></a> -->
                      <span class="read-more-link opacity-50 cursor-not-allowed">More Info Coming Soon</span> <!-- Placeholder if no specific page -->
                   </div>

                  <!-- Environment Card -->
                 <a href="e-waste.php" title="Learn about PAHAL's Environmental efforts & E-waste program"
                    class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.2s;">
                      <span class="icon"><i class="fas fa-leaf"></i></span>
                      <h3>Environment Sustainability</h3>
                      <p>We are committed stewards of our planet. Our initiatives include increasing green cover through tree plantation and running effective waste management programs, including dedicated e-waste collection & recycling.</p>
                      <span class="read-more-link">Discover E-Waste Program <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>

                  <!-- Communication Card -->
                 <div class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.3s;">
                     <span class="icon"><i class="fas fa-comments"></i></span>
                     <h3>Communication Skills</h3>
                     <p>Effective communication is vital for personal and professional success. Through ongoing workshops and interactive programs, we empower youth to master verbal, non-verbal, and presentation skills.</p>
                     <span class="read-more-link opacity-50 cursor-not-allowed">Details Pending</span>
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section -->
    <section id="volunteer-section" class="py-16 md:py-24 text-white animate-on-scroll">
        <div class="container mx-auto">
             <h2 class="section-title">Become a Part of PAHAL</h2>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left">
                    <h3 class="text-3xl font-bold mb-4 text-white">Join Our Mission</h3>
                    <p class="text-gray-100 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed">PAHAL thrives on the energy and dedication of volunteers. Whether you're an individual, student, institution, or organization, your contribution matters. We welcome everyone who shares our passion for community upliftment.</p>
                    <p class="text-gray-100 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed">By joining us, you not only contribute to society but also gain valuable experience, develop new skills, and connect with like-minded individuals. Fill out the form or contact us directly!</p>
                     <div class="mt-6 space-x-4 text-center lg:text-left">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary">Contact Us Directly</a>
                        <!-- Optional: Link to a separate detailed volunteer page -->
                         <!-- <a href="/volunteer.php" class="btn">More Volunteer Info</a> -->
                     </div>
                 </div>

                 <!-- Volunteer Sign-up Form -->
                 <div class="lg:col-span-1 bg-primary-dark p-6 sm:p-8 md:p-10 rounded-lg shadow-2xl border-t-4 border-accent animate-on-scroll fade-in-up">
                     <h3 class="text-2xl mb-6 text-white font-semibold">Express Your Interest</h3>

                     <!-- Volunteer Form Status Message -->
                     <?= get_form_status_html('volunteer_form') ?>

                    <form id="volunteer-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5">
                        <!-- CSRF Token -->
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <!-- Form ID -->
                         <input type="hidden" name="form_id" value="volunteer_form">
                         <!-- Honeypot -->
                         <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_volunteer">Please leave this field blank</label>
                            <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>

                         <div>
                            <label for="volunteer_name" class="block mb-2 text-sm font-medium">Full Name:</label>
                            <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>"
                                   class="transition duration-300 ease-in-out <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" aria-required="true" aria-describedby="volunteer_name_error">
                             <?= get_field_error_html('volunteer_form', 'volunteer_name') ?>
                        </div>
                        <div class="grid md:grid-cols-2 gap-4">
                             <div>
                                <label for="volunteer_email" class="block mb-2 text-sm font-medium">Email Address:</label>
                                <input type="email" id="volunteer_email" name="volunteer_email" required value="<?= $volunteer_form_email_value ?>"
                                       class="transition duration-300 ease-in-out <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" aria-required="true" aria-describedby="volunteer_email_error">
                                <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                            </div>
                            <div>
                                <label for="volunteer_phone" class="block mb-2 text-sm font-medium">Phone Number:</label>
                                <input type="tel" id="volunteer_phone" name="volunteer_phone" required value="<?= $volunteer_form_phone_value ?>"
                                       class="transition duration-300 ease-in-out <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" aria-required="true" aria-describedby="volunteer_phone_error">
                                <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                            </div>
                         </div>
                        <div>
                            <label for="volunteer_area" class="block mb-2 text-sm font-medium">Area of Interest (e.g., Health, Education, Events):</label>
                            <input type="text" id="volunteer_area" name="volunteer_area" required value="<?= $volunteer_form_area_value ?>" list="area-options"
                                   class="transition duration-300 ease-in-out <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" aria-describedby="volunteer_area_error">
                             <datalist id="area-options">
                                <option value="Health Programs">
                                <option value="Education Initiatives">
                                <option value="Environmental Projects">
                                <option value="Communication Workshops">
                                <option value="Event Management">
                                <option value="Blood Donation Camps">
                                <option value="E-Waste Collection">
                                <option value="General Support">
                            </datalist>
                             <?= get_field_error_html('volunteer_form', 'volunteer_area') ?>
                        </div>
                         <div>
                            <label for="volunteer_availability" class="block mb-2 text-sm font-medium">Your Availability (e.g., Weekends, Specific days/times):</label>
                            <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>"
                                   class="transition duration-300 ease-in-out <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" aria-required="true" aria-describedby="volunteer_availability_error">
                            <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                         </div>
                        <div>
                            <label for="volunteer_message" class="block mb-2 text-sm font-medium">Message (Optional - Tell us more about your interest):</label>
                            <textarea id="volunteer_message" name="volunteer_message" rows="4"
                                      class="transition duration-300 ease-in-out resize-vertical <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" aria-describedby="volunteer_message_error"><?= $volunteer_form_message_value ?></textarea>
                              <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                         </div>
                        <button type="submit" class="btn btn-secondary !bg-accent hover:!bg-accent-dark w-full sm:w-auto"><i class="fas fa-paper-plane mr-2"></i>Sign Up to Volunteer</button>
                    </form>
                 </div>

            </div>
        </div>
    </section>


     <!-- News & Events Section -->
    <section id="news-section" class="py-16 md:py-24 bg-lightbg animate-on-scroll">
        <div class="container mx-auto">
            <h2 class="section-title">Latest News & Upcoming Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach ($news_items as $index => $item): ?>
                <div class="news-card group animate-on-scroll fade-in-up" style="animation-delay: <?= $index * 0.1 ?>s;">
                    <a href="<?= htmlspecialchars($item['link']) ?>" class="block group" title="Read more about <?= htmlspecialchars($item['title']) ?>">
                         <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                     </a>
                     <div class="p-5">
                         <span class="date block text-xs text-gray-500 mb-1"><i class="fas fa-calendar-alt mr-1"></i><?= htmlspecialchars($item['date']) ?></span>
                         <h4 class="text-lg font-semibold text-primary group-hover:text-accent mb-2 leading-snug">
                             <a href="<?= htmlspecialchars($item['link']) ?>" class="hover:underline"><?= htmlspecialchars($item['title']) ?></a>
                         </h4>
                         <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($item['excerpt']) ?></p>
                         <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-outline btn-sm !py-1.5 !px-4 text-sm">Read More <i class="fas fa-arrow-right ml-1 text-xs"></i></a>
                     </div>
                </div>
                <?php endforeach; ?>
            </div>
             <div class="text-center mt-12">
                <a href="/news-archive.php" class="btn btn-secondary">View All News & Events</a> <!-- Link to a dedicated archive page -->
            </div>
        </div>
    </section>

    <!-- Gallery Section (Simple) -->
    <section id="gallery-section" class="py-16 md:py-24 animate-on-scroll">
        <div class="container mx-auto">
            <h2 class="section-title">Glimpses of Our Work</h2>
            <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach ($gallery_images as $index => $image): ?>
                <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block animate-on-scroll fade-in-up" style="animation-delay: <?= $index * 0.05 ?>s;">
                    <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="aspect-video object-cover w-full h-full rounded-lg shadow-md transition-all duration-300 hover:shadow-xl hover:scale-105">
                </a>
                <?php endforeach; ?>
            </div>
             <p class="text-center mt-8 text-gray-600 italic">Click on images to enlarge.</p>
        </div>
    </section>


    <!-- Associates Section -->
    <section id="associates" class="py-16 md:py-24 bg-lightbg animate-on-scroll">
        <div class="container mx-auto">
            <h2 class="section-title">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-gray-600 mb-12">We are proud to collaborate with a diverse range of organizations who share our commitment to community development. Their support is invaluable.</p>
             <div class="flex flex-wrap justify-center items-center gap-x-12 gap-y-8">
                 <!-- Logos - Consider using SVG or consistent PNGs -->
                <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0s">
                    <img src="naco.webp" alt="NACO Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                    <p class="text-sm font-semibold text-gray-500">NACO</p>
                </div>
                <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.05s">
                    <img src="microsoft.webp" alt="Microsoft Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                    <p class="text-sm font-semibold text-gray-500">Microsoft</p>
                </div>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.1s">
                     <img src="Karo_Logo-01.webp" alt="Karo Sambhav Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                     <p class="text-sm font-semibold text-gray-500">Karo Sambhav</p>
                 </div>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.15s">
                    <img src="psacs.webp" alt="PSACS Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                    <p class="text-sm font-semibold text-gray-500">PSACS</p>
                 </div>
                <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.2s">
                    <img src="nabard.webp" alt="NABARD Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                    <p class="text-sm font-semibold text-gray-500">NABARD</p>
                </div>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.25s">
                     <img src="punjab-gov.png" alt="Govt of Punjab Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                     <p class="text-sm font-semibold text-gray-500">Govt. Punjab</p>
                 </div>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.3s">
                     <img src="ramsan.png" alt="Ramsan Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                     <p class="text-sm font-semibold text-gray-500">Ramsan</p>
                 </div>
                  <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 opacity-80 hover:opacity-100 animate-on-scroll fade-in-up" style="animation-delay: 0.35s">
                     <img src="image.png" alt="Apollo Tyres Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 grayscale group-hover:grayscale-0 transition duration-300">
                     <p class="text-sm font-semibold text-gray-500">Apollo Tyres</p>
                 </div>
                 <!-- Add more logos - consider a slider/carousel if many logos -->
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <section id="donate-section" class="py-16 md:py-24 bg-primary text-white text-center animate-on-scroll">
        <div class="container mx-auto">
            <h2 class="section-title !text-white after:!bg-white"><i class="fas fa-donate mr-2"></i>Support Our Cause</h2>
            <p class="text-gray-100 max-w-3xl mx-auto mb-8 text-lg leading-relaxed">Your contribution, big or small, empowers us to continue our vital work in the community. Donations help fund our programs in health, education, environment, and more.</p>
            <p class="text-gray-100 max-w-3xl mx-auto mb-10 text-lg leading-relaxed">All donations are tax-exempted under Section 80G of the Income Tax Act.</p>
            <!-- NOTE: This is a simple CTA. For actual online donations, integrate a payment gateway -->
            <div class="space-y-4 sm:space-y-0 sm:space-x-6">
                <a href="#contact" class="btn btn-secondary !bg-white !text-primary hover:!bg-gray-100">Contact for Donation Details</a>
                 <!-- Example: Link to a dedicated donation page if you build one -->
                 <!-- <a href="/donate.php" class="btn">Donate Online Now</a> -->
                 <!-- Example: Button triggering a modal with bank details -->
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary" onclick="alert('Bank Details:\nAccount Name: PAHAL NGO\nAccount No: [Your Account Number]\nBank: [Your Bank Name]\nBranch: [Your Branch]\nIFSC Code: [Your IFSC Code]\nPlease mention \'Donation\' in the transfer description.\n\nAlternatively, contact us for other methods.')">
                    View Bank Transfer Details
                </button>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-16 md:py-24 animate-on-scroll">
        <div class="container mx-auto">
             <h2 class="section-title">Get In Touch With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-gray-600 mb-12">Have questions, suggestions, or want to collaborate? We'd love to hear from you! Reach out via the form below, email, phone, or visit our office.</p>
             <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                 <!-- Contact Details & Map -->
                 <div class="lg:col-span-2 animate-on-scroll fade-in-left">
                     <h3 class="text-2xl mb-6 font-semibold">Contact Information</h3>
                     <div class="space-y-5 text-gray-700 text-base">
                         <p class="flex items-start">
                             <i class="fas fa-map-marker-alt fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center flex-shrink-0"></i>
                             <span class="font-medium">Address:</span>&nbsp;
                             <span>36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008 (India)</span>
                         </p>
                         <p class="flex items-start">
                             <i class="fas fa-phone-alt fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center flex-shrink-0"></i>
                             <span class="font-medium">Office:</span>&nbsp;
                             <a href="tel:+911812672784" class="hover:text-accent hover:underline">+91 181-267-2784</a>
                         </p>
                         <p class="flex items-start">
                             <i class="fas fa-mobile-alt fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center flex-shrink-0"></i>
                             <span class="font-medium">Mobile:</span>&nbsp;
                             <a href="tel:+919855614230" class="hover:text-accent hover:underline">+91 98556-14230</a>
                         </p>
                         <p class="flex items-start">
                             <i class="fas fa-envelope fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center flex-shrink-0"></i>
                              <span class="font-medium">Email:</span>&nbsp;
                             <a href="mailto:engage@pahal-ngo.org" class="hover:text-accent hover:underline break-all">engage@pahal-ngo.org</a>
                         </p>
                     </div>

                     <div class="mt-10">
                         <h4 class="text-lg font-semibold text-primary mb-4">Connect With Us Online</h4>
                         <div class="flex space-x-5">
                             <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" title="PAHAL on Instagram" class="text-gray-500 text-3xl transition duration-300 hover:text-[#E1306C] hover:scale-110"><i class="fab fa-instagram-square"></i></a>
                             <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" title="PAHAL on Facebook" class="text-gray-500 text-3xl transition duration-300 hover:text-[#1877F2] hover:scale-110"><i class="fab fa-facebook-square"></i></a>
                             <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" title="PAHAL on Twitter" class="text-gray-500 text-3xl transition duration-300 hover:text-[#1DA1F2] hover:scale-110"><i class="fab fa-twitter-square"></i></a>
                             <!-- Add LinkedIn, YouTube etc. if available -->
                             <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" title="PAHAL on LinkedIn" class="text-gray-500 text-3xl transition duration-300 hover:text-[#0A66C2] hover:scale-110"><i class="fab fa-linkedin"></i></a>
                         </div>
                     </div>

                      <!-- Embedded Map (Replace with your actual coordinates/place ID) -->
                    <div class="mt-10 border-t pt-8">
                        <h4 class="text-lg font-semibold text-primary mb-4">Our Location</h4>
                        <!-- Adjust width, height, zoom level (15 is city level, higher is closer) -->
                        <iframe
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3407.758638397537!2d75.5988858150772!3d31.33949238143149!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b4422dab0c5%3A0xe88f5c48cfc1a3d3!2sPahal%20NGO!5e0!3m2!1sen!2sin!4v1678886655444!5m2!1sen!2sin"
                            width="100%"
                            height="250"
                            style="border:0;"
                            allowfullscreen=""
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Google Map location for PAHAL NGO Jalandhar"
                            class="rounded-md shadow-lg">
                         </iframe>
                     </div>

                     <div class="registration-info mt-10 pt-6 border-t border-gray-200 text-xs text-gray-500 bg-gray-50 p-4 rounded">
                         <h4 class="text-sm font-semibold text-primary mb-2">Registration & Compliance:</h4>
                         <p class="mb-1"><i class="fas fa-certificate mr-1 text-primary-dark"></i>Registered under Societies Registration Act XXI, 1860 (Reg. No.: 737)</p>
                         <p class="mb-1"><i class="fas fa-certificate mr-1 text-primary-dark"></i>Registered under Section 12-A of Income Tax Act, 1961</p>
                         <p class="mb-1"><i class="fas fa-donate mr-1 text-primary-dark"></i>Donations Exempted under Section 80G of Income Tax Act, 1961 (Vide No. CIT/JL-I/Trust/93/2011-12/2582)</p>
                          <!-- Add FCRA details if applicable -->
                     </div>
                 </div>

                <!-- Contact Form -->
                <div class="lg:col-span-3 bg-gray-50 p-6 sm:p-8 md:p-10 rounded-lg shadow-xl border-t-4 border-primary animate-on-scroll fade-in-up">
                    <h3 class="text-2xl mb-6 font-semibold">Send Us a Quick Message</h3>

                    <!-- PHP Status Message Area for Contact Form -->
                     <?= get_form_status_html('contact_form') ?>

                    <form id="contact-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <!-- CSRF Token -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <!-- Form ID -->
                        <input type="hidden" name="form_id" value="contact_form">
                        <!-- Honeypot -->
                         <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_contact">Please do not fill this out</label>
                            <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>

                        <div>
                            <label for="contact_name" class="block mb-2 text-sm font-medium text-primary">Your Name:</label>
                            <input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>"
                                   class="transition duration-300 ease-in-out <?= get_field_error_class('contact_form', 'name') ?>" aria-required="true" aria-describedby="contact_name_error" placeholder="e.g., John Doe">
                            <?= get_field_error_html('contact_form', 'name') ?>
                         </div>
                        <div>
                            <label for="contact_email" class="block mb-2 text-sm font-medium text-primary">Your Email:</label>
                            <input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>"
                                   class="transition duration-300 ease-in-out <?= get_field_error_class('contact_form', 'email') ?>" aria-required="true" aria-describedby="contact_email_error" placeholder="e.g., john.doe@email.com">
                            <?= get_field_error_html('contact_form', 'email') ?>
                         </div>
                        <div>
                            <label for="contact_message" class="block mb-2 text-sm font-medium text-primary">Your Message:</label>
                            <textarea id="contact_message" name="message" rows="6" required
                                      class="transition duration-300 ease-in-out resize-vertical <?= get_field_error_class('contact_form', 'message') ?>" aria-required="true" aria-describedby="contact_message_error" placeholder="Please type your inquiry or feedback here..."><?= $contact_form_message_value ?></textarea>
                            <?= get_field_error_html('contact_form', 'message') ?>
                         </div>
                        <button type="submit" class="btn w-full sm:w-auto flex items-center justify-center">
                             <i class="fas fa-paper-plane mr-2"></i>Send Message
                             <!-- Add a simple spinner for loading state (hidden initially) -->
                             <svg class="animate-spin -mr-1 ml-3 h-5 w-5 text-white hidden" id="contact-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                             </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- Footer -->
<footer class="bg-primary-dark text-gray-300 pt-16 pb-8">
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
            <!-- Footer About -->
            <div class="animate-on-scroll fade-in-up">
                <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-12 after:h-0.5 after:bg-accent">About PAHAL</h4>
                <img src="icon.webp" alt="PAHAL Footer Icon" class="w-16 h-16 float-left mr-3 rounded-full bg-white p-1 shadow-md mb-2">
                <p class="text-sm mb-3 leading-relaxed text-gray-400">A registered non-profit youth organization based in Jalandhar, Punjab, driving positive social change since [Year Founded - e.g., 2005]. We focus on impactful initiatives in health, education, environment, and communication.</p>
                <p class="text-xs text-gray-500">Reg No: 737 | 80G & 12A Certified</p>
                 <div class="mt-4 flex space-x-4">
                     <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" title="Instagram" class="text-2xl transition duration-300 text-gray-400 hover:text-[#E1306C]"><i class="fab fa-instagram"></i></a>
                     <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" title="Facebook" class="text-2xl transition duration-300 text-gray-400 hover:text-[#1877F2]"><i class="fab fa-facebook-f"></i></a>
                     <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" title="Twitter" class="text-2xl transition duration-300 text-gray-400 hover:text-[#1DA1F2]"><i class="fab fa-twitter"></i></a>
                     <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" title="LinkedIn" class="text-2xl transition duration-300 text-gray-400 hover:text-[#0A66C2]"><i class="fab fa-linkedin"></i></a>
                 </div>
            </div>

             <!-- Footer Quick Links -->
             <div class="animate-on-scroll fade-in-up" style="animation-delay: 0.1s;">
                 <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-12 after:h-0.5 after:bg-accent">Quick Links</h4>
                 <ul class="space-y-2 text-sm columns-2">
                    <li><a href="#profile" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Profile</a></li>
                    <li><a href="#objectives" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Objectives</a></li>
                    <li><a href="#areas-focus" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Focus Areas</a></li>
                     <li><a href="#news-section" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>News & Events</a></li>
                    <li><a href="#volunteer-section" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Get Involved</a></li>
                    <li><a href="#donate-section" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Donate</a></li>
                    <li><a href="#associates" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Associates</a></li>
                    <li><a href="#gallery-section" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Gallery</a></li>
                    <li><a href="#contact" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Contact Us</a></li>
                    <!-- Program Specific Links -->
                    <li><a href="blood-donation.php" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Blood Donation</a></li>
                    <li><a href="e-waste.php" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>E-Waste Program</a></li>
                    <!-- Policy Links -->
                    <!-- <li><a href="/privacy-policy.php" class="footer-link"><i class="fas fa-angle-right mr-1 text-xs"></i>Privacy Policy</a></li> -->
                 </ul>
             </div>

             <!-- Footer Contact Info -->
             <div class="animate-on-scroll fade-in-up" style="animation-delay: 0.2s;">
                 <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-12 after:h-0.5 after:bg-accent">Contact Info</h4>
                 <address class="not-italic space-y-3 text-sm text-gray-300">
                     <p class="flex items-start"><i class="fas fa-map-marker-alt fa-fw mr-3 mt-1 text-accent flex-shrink-0"></i><span>36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008, India</span></p>
                     <p class="flex items-center"><i class="fas fa-phone-alt fa-fw mr-3 text-accent"></i> <a href="tel:+911812672784" class="footer-link hover:text-white">Office: +91 181-267-2784</a></p>
                     <p class="flex items-center"><i class="fas fa-mobile-alt fa-fw mr-3 text-accent"></i> <a href="tel:+919855614230" class="footer-link hover:text-white">Mobile: +91 98556-14230</a></p>
                     <p class="flex items-start"><i class="fas fa-envelope fa-fw mr-3 mt-1 text-accent"></i> <a href="mailto:engage@pahal-ngo.org" class="footer-link hover:text-white break-all">engage@pahal-ngo.org</a></p>
                      <p class="flex items-center"><i class="fas fa-globe fa-fw mr-3 text-accent"></i> <a href="http://www.pahal-ngo.org" target="_blank" rel="noopener noreferrer" class="footer-link hover:text-white">www.pahal-ngo.org</a></p>
                 </address>
             </div>

            <!-- Simple Newsletter Signup (Example - Requires Backend Logic) -->
             <div class="animate-on-scroll fade-in-up" style="animation-delay: 0.3s;">
                <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-12 after:h-0.5 after:bg-accent">Stay Updated</h4>
                <p class="text-sm text-gray-400 mb-4">Subscribe to our newsletter for updates on our projects and events.</p>
                <form action="#newsletter-signup" method="POST" class="flex">
                     <!-- Add hidden fields for CSRF, form_id if implementing -->
                     <label for="newsletter-email" class="sr-only">Email for Newsletter</label>
                     <input type="email" id="newsletter-email" name="newsletter_email" required placeholder="Enter your email"
                           class="bg-gray-700 text-gray-200 placeholder-gray-500 px-4 py-2 rounded-l-md border border-gray-600 focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent text-sm flex-grow">
                     <button type="submit" class="bg-accent text-white px-4 py-2 rounded-r-md hover:bg-accent-dark focus:outline-none focus:ring-2 focus:ring-accent text-sm font-semibold">
                        <i class="fas fa-paper-plane"></i> <span class="sr-only">Subscribe</span>
                    </button>
                </form>
                <!-- Placeholder for newsletter success/error message -->
                 <div id="newsletter-message" class="text-xs mt-2"></div>
             </div>

        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom border-t border-gray-700 pt-6 mt-8 text-center text-sm text-gray-500">
            <p>© <?= $current_year ?> PAHAL (Regd.). All Rights Reserved. | An Endeavour for a Better Tomorrow.</p>
             <!-- Optional: Link to Privacy Policy / Terms -->
             <p class="mt-2 text-xs">
                <!-- <a href="/privacy-policy.php" class="hover:text-white hover:underline">Privacy Policy</a> |
                <a href="/terms.php" class="hover:text-white hover:underline">Terms of Use</a> | -->
                 Website by [Your Name/Company, or remove]
             </p>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="back-to-top" title="Back to Top"
        class="fixed bottom-5 right-5 z-50 p-3 rounded-full bg-accent text-white shadow-lg hover:bg-accent-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent opacity-0 invisible transition-all duration-300">
   <i class="fas fa-arrow-up"></i>
</button>

<!-- Simple Lightbox JS (Optional, for Gallery) -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.10.3/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const navbar = document.getElementById('navbar');
        const navLinks = document.querySelectorAll('#navbar a.nav-link'); // Select only main nav links
        const header = document.getElementById('main-header');
        const backToTopButton = document.getElementById('back-to-top');
        let headerHeight = header ? header.offsetHeight : 70;
        const sections = document.querySelectorAll('main section[id]');

        // --- Header & Body Padding ---
        function updateLayout() {
            if (!header) return;
            headerHeight = header.offsetHeight;
             // Adjust body padding ONLY IF header is truly fixed and overlaps content
             // document.body.style.paddingTop = `${headerHeight}px`; // Re-enable if layout requires it

             // Sticky Header Style on Scroll
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                 header.classList.remove('scrolled');
            }

            // Back to Top Button Visibility
             if (window.scrollY > 300) {
                backToTopButton.classList.remove('opacity-0', 'invisible');
                backToTopButton.classList.add('opacity-100', 'visible');
             } else {
                 backToTopButton.classList.remove('opacity-100', 'visible');
                 backToTopButton.classList.add('opacity-0', 'invisible');
             }
        }

         // Initial calculations
        updateLayout();
        window.addEventListener('resize', updateLayout);
        window.addEventListener('scroll', updateLayout, { passive: true }); // Use passive listener for scroll performance

        // --- Mobile Menu Toggle ---
        if (menuToggle && navbar) {
            menuToggle.addEventListener('click', () => {
                const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
                menuToggle.setAttribute('aria-expanded', !isExpanded);
                menuToggle.classList.toggle('active');
                navbar.classList.toggle('hidden'); // Toggle visibility
                // Optional: Add/remove class for max-height transition if needed
                 // Example: navbar.classList.toggle('max-h-screen') if using max-height for transition
                 document.body.classList.toggle('overflow-hidden', !isExpanded); // Prevent scrolling when menu open
            });
        }

        // --- Active Link Highlighting ---
        function setActiveLink() {
            let currentSectionId = '';
            const scrollPosition = window.pageYOffset;
             // Add a larger offset to trigger active state sooner when scrolling up
             const offset = headerHeight + 100;

            sections.forEach(section => {
                 const sectionTop = section.offsetTop - offset;
                 const sectionHeight = section.offsetHeight;
                const sectionId = '#' + section.getAttribute('id');

                if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                    currentSectionId = sectionId;
                }
            });

            // Special case for the top of the page (Hero section)
             if (currentSectionId === '' && scrollPosition < (document.getElementById('profile')?.offsetTop - offset || 500)) {
                currentSectionId = '#hero';
            }

            navLinks.forEach(link => {
                link.classList.remove('active');
                 const linkHref = link.getAttribute('href');
                 // Match links ending with the section ID to handle potential base URLs
                 if (linkHref && linkHref.endsWith(currentSectionId)) {
                     link.classList.add('active');
                }
            });
        }

        // --- Smooth Scrolling ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');
                if (targetId.length <= 1) return; // Ignore href="#" or empty

                 const targetElement = document.querySelector(targetId);

                if (targetElement) {
                     e.preventDefault(); // Only prevent default for internal links that exist

                     // Close mobile menu if open
                    if (navbar.classList.contains('hidden') === false && window.innerWidth < 1024) {
                         menuToggle.click(); // Simulate click to close
                     }

                    // Calculate position considering fixed header
                    const elementPosition = targetElement.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerHeight - 10; // Add small buffer

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });

                     // Optionally update URL hash without jump (if needed)
                     // history.pushState(null, null, targetId); // Be careful with browser history impact

                     // Set active class immediately after click
                     navLinks.forEach(lnk => lnk.classList.remove('active'));
                    // Find the corresponding nav link and activate it
                     const correspondingNavLink = document.querySelector(`#navbar a[href$="${targetId}"]`);
                    if (correspondingNavLink) {
                         correspondingNavLink.classList.add('active');
                     }

                }
                 // Else: Let the browser handle the click (e.g., links to other pages)
            });
        });

        // --- Back to Top Button Action ---
        if (backToTopButton) {
             backToTopButton.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // --- Contact Form Spinner ---
        const contactForm = document.getElementById('contact-form');
         if (contactForm) {
             contactForm.addEventListener('submit', () => {
                const submitButton = contactForm.querySelector('button[type="submit"]');
                const spinner = document.getElementById('contact-spinner');
                if (submitButton && spinner) {
                     submitButton.disabled = true; // Prevent double-submit
                     spinner.classList.remove('hidden'); // Show spinner
                }
            });
        }
        // Add similar logic for volunteer form if desired

        // --- Simple Lightbox Initialization ---
         if (typeof SimpleLightbox !== 'undefined') {
             new SimpleLightbox('.gallery a', {
                 /* options */
                captionsData: 'alt',
                 captionDelay: 250,
             });
         }


        // --- Intersection Observer for Animations ---
         const animatedElements = document.querySelectorAll('.animate-on-scroll');

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, observerInstance) => {
                 entries.forEach(entry => {
                     if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        // Optional: Unobserve after animation to save resources
                         // observerInstance.unobserve(entry.target);
                     } else {
                         // Optional: Remove class to re-animate on scroll up (can be distracting)
                         // entry.target.classList.remove('is-visible');
                     }
                 });
             }, {
                 root: null, // relative to the viewport
                 threshold: 0.1 // Trigger when 10% of the element is visible
             });

            animatedElements.forEach(el => observer.observe(el));
        } else {
             // Fallback for older browsers: Just show the elements
            animatedElements.forEach(el => el.classList.add('is-visible'));
         }


        // Initial setup calls on load
        setActiveLink();
        window.addEventListener('scroll', setActiveLink, { passive: true }); // Update active link on scroll
        window.addEventListener('load', () => {
            updateLayout(); // Ensure layout is correct after everything loads
            setActiveLink(); // Recalculate active link after load potentially shifts elements
        });

    }); // End DOMContentLoaded
</script>

</body>
</html>
