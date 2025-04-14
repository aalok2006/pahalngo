<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 2.1 (PHPMailer Removed, using standard mail())
// Features: CSRF Protection, Honeypot, Logging, Expanded Content
// WARNING: Relies on PHP's mail() function which can have deliverability issues.
//          Ensure your server is correctly configured to send mail.
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration ---
// ------------------------------------------------------------------------
// --- Email Settings ---
// CHANGE THIS to the email address where you want to receive CONTACT messages
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com"); // CHANGE ME
// CHANGE THIS to the email address where you want to receive VOLUNTEER messages
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com"); // CHANGE ME

// --- Email Sending Defaults (for mail() function) ---
// CHANGE THIS potentially to an email address associated with your domain for better deliverability
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME (email mails appear FROM)
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Website');             // CHANGE ME (name mails appear FROM)

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
        // Attempt to create log directory (suppress errors, check result)
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) { // Check !is_dir again in case of race condition
            // Cannot create directory, log to PHP error log as fallback
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ($logFile): " . $message);
            return;
        }
        // Add a .htaccess file to deny direct access if possible
        if (is_dir($logDir) && !file_exists($logDir . '/.htaccess')) {
           @file_put_contents($logDir . '/.htaccess', 'Deny from all');
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;

    // Attempt to write (suppress errors, check result)
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        // Cannot write to file, log to PHP error log
        $error = error_get_last();
        error_log("Failed to write to log file: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown file write error'));
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
        try {
           $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            // Fallback if random_bytes fails (highly unlikely)
           $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true));
           log_message("Error generating CSRF token with random_bytes: " . $e->getMessage() . ". Using fallback.", LOG_FILE_ERROR);
        }
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
    // Use hash_equals for timing attack safe comparison
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
    // Strip tags to prevent XSS, optionally allow specific basic tags if needed later
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
 * Note: This is a basic implementation. A library like Valitron or Respect/Validation is recommended for complex cases.
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
            $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field)); // For messages

            switch ($rule) {
                case 'required':
                    // Check for empty string, null, or empty array (for multi-select/checkbox groups if added later)
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} is required.";
                    }
                    break;
                case 'email':
                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid email address.";
                    }
                    break;
                case 'minLength':
                     $length = mb_strlen((string)$value, 'UTF-8'); // Ensure value is string for strlen
                     if ($value !== null && $value !== '' && $length < (int)$params[0]) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters long.";
                     }
                     break;
                case 'maxLength':
                    $length = mb_strlen((string)$value, 'UTF-8');
                    if ($value !== null && $length > (int)$params[0]) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters.";
                    }
                    break;
                case 'alpha_space': // Allow letters and spaces ONLY
                    if (!empty($value) && !preg_match('/^[A-Za-z\s]+$/u', $value)) { // Added 'u' modifier for UTF-8
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces.";
                    }
                    break;
                 case 'phone': // Basic North American phone number structure + optional international prefix
                     // This regex is basic - consider libraries for comprehensive validation
                    if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid phone number format.";
                    }
                    break;
                case 'in': // Check if value is in a predefined list
                     if (!empty($value) && !in_array($value, $params)) {
                         $isValid = false;
                         $errorMessage = "Invalid selection for {$fieldNameFormatted}.";
                     }
                     break;
                 case 'required_without': // Requires one field OR another
                      // Usage: 'field_a' => 'required_without:field_b|...'
                      $otherFieldName = $params[0] ?? null;
                      if ($otherFieldName && empty($value) && empty($data[$otherFieldName])) {
                           $otherFieldNameFormatted = ucfirst(str_replace('_', ' ', $otherFieldName));
                           $isValid = false;
                           $errorMessage = "Either {$fieldNameFormatted} or {$otherFieldNameFormatted} is required.";
                      }
                      break;

                 // --- Add more rules as needed: ---
                 // 'numeric', 'integer', 'url', 'date:format', 'same:other_field', 'boolean', etc.

                 default:
                     // Optionally log or throw an error for unknown validation rules
                     log_message("Unknown validation rule '{$rule}' used for field '{$field}'.", LOG_FILE_ERROR);
                     break;
            }

            if (!$isValid && !isset($errors[$field])) { // Only record the first error per field
                $errors[$field] = $errorMessage;
                break; // Stop processing other rules for this field once one fails
            }
        }
    }
    return $errors;
}


/**
 * Sends an email using the standard PHP mail() function.
 * WARNING: mail() is often unreliable due to server configuration and spam filters.
 * Consider dedicated email services (SendGrid, Mailgun) with their SDKs or SMTP via a robust library for production.
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
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT;

    // Prepare headers
    $headers = "From: {$senderName} <{$senderEmail}>\r\n";
    if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) { // Validate reply-to email
         $replyToFormatted = $replyToName ? "{$replyToName} <{$replyToEmail}>" : $replyToEmail;
         $headers .= "Reply-To: {$replyToFormatted}\r\n";
    }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; // Specify content type and charset
    $headers .= "Content-Transfer-Encoding: 8bit\r\n"; // Recommended for UTF-8 plain text


    // Word wrap the body to prevent overly long lines (often marked as spam)
    // Use 70 chars max per line according to RFC 2822 recommendations
    $wrapped_body = wordwrap($body, 70, "\r\n");

    // Attempt to send the email using built-in mail()
    // Use @ to suppress default PHP errors/warnings; we handle logging below
    if (@mail($to, $subject, $wrapped_body, $headers)) {
        log_message("{$logContext} Email submitted successfully via mail() to {$to}. Subject: {$subject}", LOG_FILE_CONTACT); // Adjust log file maybe
        return true;
    } else {
        // mail() failed, log the error
        $errorInfo = error_get_last(); // Get the last error information
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error occurred. Check server mail logs.');
        log_message($errorMsg, LOG_FILE_ERROR);
        error_log($errorMsg); // Also log to the main PHP error log for visibility
        return false;
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
        http_response_code(400); // Bad Request
        die("Invalid request."); // Stop script execution
    }

    // 2. CSRF Token Validation
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    if (!validate_csrf_token($submitted_token)) {
        log_message("[CSRF FAILURE] Invalid or missing CSRF token. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        // Invalidate the session token
        unset($_SESSION[CSRF_TOKEN_NAME]);
        http_response_code(403); // Forbidden
        die("Security validation failed. Please refresh the page and try again."); // Stop script execution
    }
    // Valid CSRF token. Will regenerate after processing.

    // --- Process CONTACT Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        $form_errors[$form_id] = [];

        // Sanitize Inputs
        $name = sanitize_string($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? ''); // Returns '' if invalid format
        $message = sanitize_string($_POST['message'] ?? ''); // Sanitize message

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

            // Construct email body
            $body = "You have received a new message from the PAHAL website contact form.\n\n";
            $body .= "=================================================\n";
            $body .= " SENDER INFORMATION\n";
            $body .= "=================================================\n";
            $body .= " Name:    " . $name . "\n";
            $body .= " Email:   " . $email . "\n";
            $body .= " IP Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n"; // Collect IP for tracing
            $body .= " Time:    " . date('Y-m-d H:i:s T') . "\n"; // Add timezone
            $body .= "=================================================\n";
            $body .= " MESSAGE\n";
            $body .= "=================================================\n\n";
            $body .= $message . "\n\n";
            $body .= "=================================================\n";
            $body .= "-- End of Message --\n";

            $logContext = "[Contact Form]";
            // Attempt to send email using the wrapper function (now using mail())
            if (send_email($to, $subject, $body, $email, $name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message has been sent successfully. We'll get back to you soon."];
                // Log successful submission
                log_message("{$logContext} Submission successful. From: {$name} <{$email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_CONTACT);
                // Clear form fields ONLY on success by not re-populating $form_submissions on redirect
                $form_submissions[$form_id] = []; // Clear for redirect
            } else {
                // Error occurred during sending
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$name}, there was an internal error sending your message. Please try again later or use the phone number provided. If the problem persists, please contact support."];
                // Specific error is already logged within send_email()
                 log_message("{$logContext} Submission FAILED after validation (mail() returned false). From: {$name} <{$email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else {
            // Validation Errors Occurred
            $errorCount = count($validation_errors);
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix the {$errorCount} error(s) indicated below and resubmit."];
            // Log validation failure
            log_message("[Contact Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
             // Do NOT clear $form_submissions here, keep data for re-populating fields
        }
        // Set target for scrolling after redirect
        $_SESSION['scroll_to'] = '#contact';

    } // End processing contact form


    // --- Process VOLUNTEER Sign-up Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form';
        $form_errors[$form_id] = [];

        // Sanitize Inputs
        $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? '');
        $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? '');
        $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? ''); // Basic sanitize, validate rule handles format
        $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? '');
        $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? '');
        $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? ''); // Keep optional message safe

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
            'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255', // Require email OR phone
            'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20',
            'volunteer_area' => 'required|maxLength:100', // e.g., Health, Education, Environment
            'volunteer_availability' => 'required|maxLength:200', // e.g., Weekends, Evenings
            'volunteer_message' => 'maxLength:2000', // Optional message, limit length
        ];

        // Validate Data (ensure required_without and phone rules are implemented in validate_data)
        $validation_errors = validate_data($form_submissions[$form_id], $rules);
        $form_errors[$form_id] = $validation_errors;

        // If No Validation Errors, Proceed to Send Email
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_VOLUNTEER; // Separate email for volunteer coordination
            $subject = "PAHAL Website: New Volunteer Sign-up - " . $volunteer_name;

            // Construct email body
            $body = "A new volunteer has expressed interest through the PAHAL website.\n\n";
            $body .= "=================================================\n";
            $body .= " VOLUNTEER INFORMATION\n";
            $body .= "=================================================\n";
            $body .= " Name:            " . $volunteer_name . "\n";
            $body .= " Email:           " . (!empty($volunteer_email) ? $volunteer_email : "(Not Provided)") . "\n";
            $body .= " Phone:           " . (!empty($volunteer_phone) ? $volunteer_phone : "(Not Provided)") . "\n";
            $body .= " Area Interest:   " . $volunteer_area . "\n";
            $body .= " Availability:    " . $volunteer_availability . "\n";
            $body .= " IP Address:      " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
            $body .= " Timestamp:       " . date('Y-m-d H:i:s T') . "\n";
            $body .= "=================================================\n";
            $body .= " OPTIONAL MESSAGE\n";
            $body .= "=================================================\n\n";
            $body .= (!empty($volunteer_message) ? $volunteer_message : "(No message provided)") . "\n\n";
            $body .= "=================================================\n";
            $body .= " ACTION REQUIRED: Please follow up with the volunteer.\n";
            $body .= "=================================================\n";

            $logContext = "[Volunteer Form]";
            // Attempt to send email
             if (send_email($to, $subject, $body, $volunteer_email, $volunteer_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you for your interest, {$volunteer_name}! We've received your information and will contact you soon about volunteering opportunities."];
                // Log successful submission
                log_message("{$logContext} Submission successful. From: {$volunteer_name}, Email: {$volunteer_email}, Phone: {$volunteer_phone}, Area: {$volunteer_area}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_VOLUNTEER);
                // Clear form fields on success
                 $form_submissions[$form_id] = []; // Clear for redirect
            } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$volunteer_name}, there was an internal error submitting your volunteer interest. Please try again later or contact us directly."];
                 log_message("{$logContext} Submission FAILED after validation (mail() returned false). From: {$volunteer_name}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else {
            // Validation Errors Occurred
            $errorCount = count($validation_errors);
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} highlighted error(s) below to sign up."];
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
             // Keep submission data for re-populating form
        }
         // Set target for scrolling after redirect
        $_SESSION['scroll_to'] = '#volunteer-section';

    } // End processing volunteer form

    // --- Add processing for other forms (e.g., newsletter) here ---
    /*
    elseif ($submitted_form_id === 'newsletter_signup') {
        // ... handle newsletter form ...
        $_SESSION['scroll_to'] = '#newsletter-section'; // Example scroll target
    }
    */

    // --- Post-Processing (After handling specific form) ---
    // Regenerate CSRF token for the next request (prevents reuse)
    unset($_SESSION[CSRF_TOKEN_NAME]);
    $csrf_token = generate_csrf_token(); // Generate a new one before redirecting

    // --- Redirect to Prevent Resubmission (Post/Redirect/Get Pattern) ---
    // Store messages, errors, and potentially submissions (only on error) in session
    $_SESSION['form_messages'] = $form_messages;
    $_SESSION['form_errors'] = $form_errors;
    // Keep submitted data *only* if there were errors, otherwise clear it (handled within each form block now)
    if (!empty($form_errors[$submitted_form_id])) {
       $_SESSION['form_submissions'] = $form_submissions; // Preserve data for repopulation on error
    } else {
        unset($_SESSION['form_submissions']); // Don't need to keep data on success
    }


    // Preserve query string if any, and get scroll target from session
    $queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
    $scrollTarget = $_SESSION['scroll_to'] ?? ''; // Retrieve target set within form block
    unset($_SESSION['scroll_to']); // Clean up scroll target from session

    // Construct the redirect URL (using the current script's path)
    $redirectUrl = htmlspecialchars($_SERVER['PHP_SELF']) . $queryString . $scrollTarget;

    // Perform the redirect
    header("Location: " . $redirectUrl);
    exit; // IMPORTANT: Terminate script execution after header redirect

} else {
    // --- Not a POST request ---
    // Retrieve messages/errors/submissions from session (if redirected from POST)
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
        unset($_SESSION['form_submissions']); // Clear after displaying (used for repopulation)
    }

    // Ensure a CSRF token exists for the initial form load
    $csrf_token = generate_csrf_token();

}

// ------------------------------------------------------------------------
// --- Prepare Data for HTML Template ---

/**
 * Retrieves a value for a form field, using session data if available (after error redirect), otherwise default.
 * Encodes output for HTML safety.
 *
 * @param string $formId The ID of the form.
 * @param string $fieldName The name of the field.
 * @param string $default Default value if not found.
 * @return string The safe HTML value attribute content.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; // Uses data potentially restored from session
    // Provide the submitted value OR the default
    $value = $form_submissions[$formId][$fieldName] ?? $default;
    // Ensure output is safe for HTML attributes
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates the HTML for displaying success or error messages for a specific form.
 *
 * @param string $formId The ID of the form.
 * @return string HTML block for the message, or empty string if no message.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; // Uses data potentially restored from session
    if (empty($form_messages[$formId])) {
        return '';
    }

    $message = $form_messages[$formId];
    $isSuccess = ($message['type'] === 'success');

    // Define base classes and specific type classes using Tailwind
    $baseClasses = 'px-4 py-3 rounded relative mb-6 form-message text-sm shadow-md border';
    $typeClasses = $isSuccess
        ? 'bg-green-100 border-green-400 text-green-800' // Adjusted text color for better contrast
        : 'bg-red-100 border-red-400 text-red-800';     // Adjusted text color
    $iconClass = $isSuccess
        ? 'fas fa-check-circle text-green-600'
        : 'fas fa-exclamation-triangle text-red-600';

    // Construct the HTML using the defined classes
    // Added role="alert" for accessibility
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\">"
           . "<strong class=\"font-bold\"><i class=\"{$iconClass} mr-2\"></i>" . ($isSuccess ? 'Success!' : 'Error:') . "</strong> "
           . "<span class=\"block sm:inline\">" . htmlspecialchars($message['text']) . "</span>"
           . "</div>";
}

/**
 * Generates an error message paragraph for a specific form field.
 *
 * @param string $formId The ID of the form.
 * @param string $fieldName The name of the field.
 * @return string HTML paragraph with error message, or empty string if no error.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; // Uses data potentially restored from session
    if (isset($form_errors[$formId][$fieldName])) {
        // Added role="alert" implicitly via context, consider adding aria-describedby to the input field linking to this error
        return '<p class="text-red-600 text-xs italic mt-1" id="' . $fieldName . '_error">'
               . '<i class="fas fa-times-circle mr-1"></i>'
               . htmlspecialchars($form_errors[$formId][$fieldName])
               . '</p>';
    }
    return '';
}

/**
 * Returns CSS classes for form field highlighting based on validation errors.
 *
 * @param string $formId The ID of the form.
 * @param string $fieldName The name of the field.
 * @return string String containing Tailwind classes for border/focus state.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors; // Uses data potentially restored from session
     // Return error classes if an error exists for this field, otherwise default focus/border classes
     return isset($form_errors[$formId][$fieldName])
         ? 'border-red-500 focus:border-red-500 focus:ring-red-500 ring-1 ring-red-500' // Added ring for more emphasis
         : 'border-gray-300 focus:border-primary-dark focus:ring-primary-dark focus:ring-1'; // Default style with ring on focus
}


// Prepare specific form field values for the template using the helper function
// Contact Form
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message'); // Note: textarea needs content between tags, not value attribute

// Volunteer Form
$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area');
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message'); // For textarea content


// --- Dummy Data for New Sections (Replace with dynamic data source if using DB/CMS) ---
$news_items = [
    ['id' => 1, 'date' => '2024-10-15', 'title' => 'Successful Blood Donation Camp Yields 50+ Units', 'excerpt' => 'A heartfelt thank you to all donors and volunteers who made our quarterly blood drive a resounding success. Your contribution saves lives!', 'link' => 'news-details.php?id=1', 'image' => 'https://via.placeholder.com/400x250.png/DC143C/FFFFFF?text=Blood+Camp+Heroics'],
    ['id' => 2, 'date' => '2024-09-20', 'title' => 'PAHAL Launches E-Waste Awareness Campaign in Local Schools', 'excerpt' => 'Educating the next generation on responsible electronic disposal. We partnered with schools to conduct interactive workshops.', 'link' => 'e-waste.php', 'image' => 'https://via.placeholder.com/400x250.png/2E7D32/FFFFFF?text=E-Waste+Education'],
    ['id' => 3, 'date' => '2024-08-05', 'title' => 'New Workshop Series Empowers Youth Communication Skills', 'excerpt' => 'Our latest initiative focuses on enhancing public speaking, interview techniques, and overall confidence among young adults.', 'link' => '#', 'image' => 'https://via.placeholder.com/400x250.png/1976D2/FFFFFF?text=Youth+Communication'],
    // Add more news items
];

$gallery_images = [
    ['src' => 'https://via.placeholder.com/600x400.png/008000/FFFFFF?text=PAHAL+Health+Camp', 'alt' => 'Community members receiving health checkups at a PAHAL camp'],
    ['src' => 'https://via.placeholder.com/600x400.png/32CD32/000000?text=PAHAL+Plantation+Drive', 'alt' => 'Volunteers enthusiastically planting saplings during an environment drive'],
    ['src' => 'https://via.placeholder.com/600x400.png/FFD700/000000?text=PAHAL+Student+Workshop', 'alt' => 'Students participating in an educational workshop organized by PAHAL'],
    ['src' => 'https://via.placeholder.com/600x400.png/DC143C/FFFFFF?text=PAHAL+Blood+Donors', 'alt' => 'Smiling blood donors contributing at a PAHAL donation camp'],
    ['src' => 'https://via.placeholder.com/600x400.png/2E7D32/FFFFFF?text=PAHAL+Cleanup+Event', 'alt' => 'PAHAL volunteers collecting litter during a community cleanup initiative'],
    ['src' => 'https://via.placeholder.com/600x400.png/8A2BE2/FFFFFF?text=PAHAL+Team+Meeting', 'alt' => 'The PAHAL core team collaborating during a planning meeting'],
    ['src' => 'https://via.placeholder.com/600x400.png/FFA500/000000?text=PAHAL+Skill+Training', 'alt' => 'Youth participating in a vocational skill training session'],
    ['src' => 'https://via.placeholder.com/600x400.png/4682B4/FFFFFF?text=PAHAL+Community+Outreach', 'alt' => 'PAHAL members engaging with the local community during an outreach program'],
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
    <meta name="robots" content="index, follow"> <!-- SEO hint -->

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://your-pahal-domain.com/"> <!-- CHANGE to your final URL -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:image" content="https://your-pahal-domain.com/og-image.jpg"> <!-- CHANGE to your preview image URL -->
    <meta property="og:image:width" content="1200"> <!-- Optional: Image dimensions -->
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PAHAL NGO"> <!-- Site name -->

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE to your final URL -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/twitter-image.jpg"> <!-- CHANGE to your Twitter preview image URL -->
    <!-- <meta name="twitter:site" content="@PahalNGOHandle"> --> <!-- Optional: Twitter handle -->


    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" sizes="any"> <!-- Favicon.ico -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg"> <!-- SVG Favicon (recommended) -->
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png"> <!-- For Apple devices -->
    <link rel="manifest" href="/site.webmanifest"> <!-- PWA Manifest -->
    <meta name="theme-color" content="#008000"> <!-- Theme color for browsers -->


    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" /> <!-- Ensure integrity hash is up-to-date -->

    <!-- Simple Lightbox CSS (for Gallery Popups) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.1/dist/simple-lightbox.min.css"> <!-- Use latest version -->

    <script>
        // Tailwind Config
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#008000', // Green
                        'primary-dark': '#006400', // Darker Green
                        accent: '#DC143C', // Crimson Red
                        'accent-dark': '#a5102f',
                        lightbg: '#f8f9fa', // Very light gray for section backgrounds
                        darktext: '#333333', // Main body text
                        mediumtext: '#555555', // Slightly lighter text
                        lighttext: '#777777', // Gray for secondary info
                        footerbg: '#004d00', // Very dark green for footer
                    },
                    fontFamily: {
                        sans: ['Open Sans', 'sans-serif'],
                        heading: ['Lato', 'sans-serif'],
                    },
                    container: {
                      center: true,
                      padding: '1rem', // Default padding
                      screens: {
                        sm: '640px',
                        md: '768px',
                        lg: '1024px',
                        xl: '1140px',
                        '2xl': '1280px', // Provide a bit more space on larger screens
                      },
                    },
                    fontSize: { // Add finer control over font sizes if needed
                      'xs': '.75rem', 'sm': '.875rem', 'base': '1rem', 'lg': '1.125rem',
                      'xl': '1.25rem', '2xl': '1.5rem', '3xl': '1.875rem', '4xl': '2.25rem',
                      '5xl': '3rem', '6xl': '3.75rem', '7xl': '4.5rem'
                    },
                    animation: { // Define custom animations
                        'fade-in-up': 'fadeInUp 0.6s ease-out forwards',
                        'slide-in-left': 'slideInLeft 0.6s ease-out forwards',
                        'slide-in-right': 'slideInRight 0.6s ease-out forwards',
                         'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite', // Slower pulse
                         'bounce-subtle': 'bounceSubtle 1.5s infinite',
                    },
                    keyframes: { // Define animation keyframes
                        fadeInUp: {
                            '0%': { opacity: 0, transform: 'translateY(30px)' }, // Start slightly lower
                            '100%': { opacity: 1, transform: 'translateY(0)' },
                        },
                        slideInLeft: {
                            '0%': { opacity: 0, transform: 'translateX(-40px)' }, // Start further left
                            '100%': { opacity: 1, transform: 'translateX(0)' },
                        },
                         slideInRight: {
                            '0%': { opacity: 0, transform: 'translateX(40px)' }, // Start further right
                            '100%': { opacity: 1, transform: 'translateX(0)' },
                        },
                        bounceSubtle: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-5px)' }
                        }
                    }
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Base & Global Styles */
        @layer base {
            html {
                 @apply scroll-smooth antialiased; /* Smooth scrolling & font smoothing */
             }
             body {
                 @apply font-sans text-darktext leading-relaxed text-base bg-white;
             }
             h1, h2, h3, h4, h5, h6 {
                  @apply font-heading text-primary font-bold leading-tight mb-4 tracking-tight;
             }
             h1 { @apply text-4xl lg:text-6xl font-black; } /* Bolder H1 */
             h2 { @apply text-3xl md:text-4xl; }
             h3 { @apply text-2xl md:text-3xl text-primary-dark; }
             h4 { @apply text-xl font-semibold text-gray-800; }
             p { @apply mb-4 text-base text-mediumtext max-w-prose; } /* Limit paragraph width for readability */
              a { @apply transition duration-300 ease-in-out; }
              a:not(.btn):not(.btn-secondary):not(.btn-outline) { /* Default link styling */
                  @apply text-primary hover:text-primary-dark hover:underline;
             }
            .container { @apply px-4 sm:px-6 lg:px-8; }

             /* Improve focus visibility */
             *:focus-visible {
               @apply outline-none ring-2 ring-offset-2 ring-accent/70 rounded;
            }
            /* Hide honeypot visually */
             .honeypot-field { @apply absolute left-[-9999px] w-px h-px overflow-hidden opacity-0; }
        }

         /* Custom Components */
        @layer components {
            .section-title {
                @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark;
            }
            .section-title::after {
                 content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-[3px] bg-accent rounded-full; /* Slightly thicker line */
            }
            .btn {
                @apply inline-flex items-center justify-center bg-accent text-white py-3 px-7 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-accent-dark transform hover:-translate-y-0.5 shadow-md hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-accent text-base cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed;
            }
            .btn i, .btn-secondary i, .btn-outline i { @apply mr-2 -ml-1 text-sm; } /* Standard icon spacing */
            .btn-secondary {
                 @apply inline-flex items-center justify-center bg-primary text-white py-3 px-7 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-primary-dark transform hover:-translate-y-0.5 shadow-md hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary disabled:opacity-50 disabled:cursor-not-allowed;
            }
             .btn-outline {
                 @apply inline-flex items-center justify-center bg-transparent border-2 border-accent text-accent py-2.5 px-6 rounded-md font-semibold font-sans transition duration-300 ease-in-out hover:bg-accent hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-accent disabled:opacity-50 disabled:cursor-not-allowed;
             }
             .card {
                 @apply bg-white p-6 rounded-lg shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-300 ease-in-out border border-gray-100; /* Subtle border */
             }
            .form-label {
                 @apply block mb-1.5 text-sm font-semibold text-primary-dark; /* Consistent label style */
            }
            .form-input { /* Base class for form inputs */
                 @apply bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-primary/50 focus:border-primary block w-full p-3 transition duration-300 ease-in-out placeholder-gray-400 disabled:bg-gray-200;
            }
            /* Apply base input style to specific types */
            input[type="text"].form-input,
            input[type="email"].form-input,
             input[type="tel"].form-input, /* Added tel */
            textarea.form-input,
             select.form-input {
                @apply form-input;
            }
             textarea.form-input { @apply resize-vertical min-h-[120px]; }
            select.form-input { @apply appearance-none pr-8 bg-no-repeat bg-right; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="%236B7280"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>'); } /* Add dropdown arrow */


            .form-error-message { @apply text-red-600 text-xs italic mt-1; } /* Class for error <p> tag */
            .form-input-error { @apply !border-red-500 !ring-red-500 !ring-1; } /* Class for input with error */


             .footer-link { /* Styling for footer links */
                 @apply text-gray-300 hover:text-white hover:underline text-sm transition-colors duration-200;
             }
             /* Apply link styling to children `a` tags in the footer nav list */
            footer ul li a { @apply footer-link; }
            footer address a { @apply footer-link hover:text-white; }


         }


        /* Specific Section Styles */
         @layer utilities {
             /* Header */
             #main-header { @apply fixed top-0 left-0 w-full bg-white/90 backdrop-blur-md z-50 shadow-sm transition-all duration-300 border-b border-gray-200; min-height: 70px; }
             #main-header.scrolled { @apply shadow-md bg-white/95; } /* Scrolled state */
             body { @apply pt-[70px]; } /* Offset body for fixed header */

              #navbar ul li a { @apply text-primary font-semibold py-1 relative transition duration-300 ease-in-out text-base md:text-lg block lg:inline-block lg:py-0; } /* Adjust size */
             #navbar ul li a::after { content: ''; @apply absolute bottom-[-5px] left-0 w-0 h-0.5 bg-accent transition-all duration-300 ease-in-out rounded-full; }
              #navbar ul li a:hover::after, #navbar ul li a.active::after { @apply w-full; }
             #navbar ul li a:hover, #navbar ul li a.active { @apply text-accent; }

              .menu-toggle span { @apply block w-7 h-0.5 bg-primary mb-1.5 rounded-sm transition-all duration-300 ease-in-out origin-center; } /* Added origin-center */
              .menu-toggle.active span:nth-child(1) { @apply rotate-45 translate-y-[8px]; }
              .menu-toggle.active span:nth-child(2) { @apply opacity-0 scale-x-0; } /* Fade and shrink middle */
              .menu-toggle.active span:nth-child(3) { @apply -rotate-45 translate-y-[-8px]; }


            /* Hero */
            #hero { background: linear-gradient(rgba(0, 100, 0, 0.78), rgba(0, 64, 0, 0.88)), url('https://via.placeholder.com/1920x1080.png/2E7D32/FFFFFF?text=Community+Engagement') no-repeat center center/cover; @apply text-white min-h-[calc(100vh-70px)] flex items-center py-16 relative overflow-hidden; } /* Adjust min-height */
             .hero-text h1 { @apply text-white mb-6 drop-shadow-lg leading-tight; }
             .hero-logo img { @apply drop-shadow-xl animate-pulse-slow; }
             .hero-scroll-indicator { @apply absolute bottom-10 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
             .hero-scroll-indicator a { @apply text-white/70 hover:text-white text-3xl animate-bounce-subtle; }


            /* Focus Areas */
             .focus-item { @apply border-t-4 border-primary-dark bg-white p-6 md:p-8 rounded-lg shadow-lg text-center transition-all duration-300 ease-in-out hover:shadow-2xl hover:-translate-y-2 relative flex flex-col; } /* Increased shadow on hover */
             .focus-item .icon { @apply text-5xl text-accent mb-5 inline-block transition-transform duration-300 group-hover:scale-110; }
             .focus-item h3 { @apply text-xl text-primary-dark mb-3 transition-colors duration-300 group-hover:text-accent-dark; }
             .focus-item p { @apply text-sm text-gray-600 leading-relaxed flex-grow mb-4; }
             .focus-item .read-more-link { @apply block text-sm font-semibold text-accent mt-auto opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:underline pt-2; } /* Use mt-auto to push down */
              a.focus-item { @apply no-underline; }


             /* Contact Form */
            #contact-form textarea { @apply resize-y; } /* Allow vertical resize only */


            /* News Section */
            #news-section .news-card { @apply bg-white rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col; }
            #news-section .news-card img { @apply w-full h-48 object-cover transition-transform duration-300 group-hover:scale-105; }
             #news-section .news-card .news-content { @apply p-5 flex flex-col flex-grow; } /* Flex content */
             #news-section .news-card .date { @apply text-xs text-gray-500 block mb-1; }
             #news-section .news-card h4 { @apply text-lg text-primary font-semibold group-hover:text-accent mb-2 leading-snug; }
            #news-section .news-card p { @apply text-sm text-gray-600 leading-relaxed mb-4 flex-grow; } /* Flex grow excerpt */
             #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-gray-100; } /* Push button down */


            /* Volunteer/Donate */
             #volunteer-section, #donate-section { @apply bg-gradient-to-br from-primary to-primary-dark text-white; } /* Gradient BG */
            #volunteer-section .section-title, #donate-section .section-title { @apply !text-white after:!bg-accent; } /* Adjust accent */
            #volunteer-form label { @apply !text-gray-100; }
             #volunteer-form .form-input { @apply !bg-white/80 !border-gray-400 !text-gray-900 focus:!bg-white; } /* Slightly transparent inputs */
             #donate-section p { @apply text-gray-100 max-w-3xl mx-auto text-lg leading-relaxed; }


            /* Footer */
             footer { @apply bg-footerbg text-gray-300 pt-16 pb-8 border-t-4 border-accent; }
             footer h4::after { @apply bg-accent; } /* Ensure footer heading lines use accent */
             .footer-bottom { @apply border-t border-primary pt-6 mt-8 text-center text-sm text-gray-500; } /* Darker border */

            /* Animation Staging */
             .animate-on-scroll { opacity: 0; transition: opacity 0.7s ease-out, transform 0.7s ease-out; }
             .animate-on-scroll.fade-in-up { transform: translateY(30px); }
            .animate-on-scroll.fade-in-left { transform: translateX(-40px); }
            .animate-on-scroll.fade-in-right { transform: translateX(40px); }

             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }

         }

    </style>

    <!-- Add Schema.org structured data for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "NGO",
      "name": "PAHAL NGO",
      "alternateName": "PAHAL",
      "url": "https://your-pahal-domain.com/", // CHANGE to your final URL
      "logo": "https://your-pahal-domain.com/icon.webp", // CHANGE to your logo URL
      "description": "PAHAL is a voluntary youth organization in Jalandhar dedicated to holistic personality development, community service, and fostering positive change in health, education, environment, and communication.",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "36 New Vivekanand Park, Maqsudan",
        "addressLocality": "Jalandhar",
        "addressRegion": "Punjab",
        "postalCode": "144008",
        "addressCountry": "IN"
      },
      "contactPoint": [
        { "@type": "ContactPoint", "telephone": "+91-181-267-2784", "contactType": "Office Phone" },
        { "@type": "ContactPoint", "telephone": "+91-98556-14230", "contactType": "Mobile Phone", "areaServed": "IN" },
        { "@type": "ContactPoint", "email": "engage@pahal-ngo.org", "contactType": "General Inquiry" }
      ],
       "sameAs": [ // Add social media links
         "https://www.facebook.com/PahalNgoJalandhar/",
         "https://www.instagram.com/pahalasadi/",
         "https://twitter.com/PahalNGO1",
         "https://www.linkedin.com/company/pahal-ngo/"
       ]
    }
    </script>


</head>
<body class="bg-white text-gray-700 font-sans leading-relaxed">

<!-- Header -->
<header id="main-header" class="py-2 md:py-0">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <!-- Logo -->
        <div class="logo flex-shrink-0">
             <a href="#hero" aria-label="PAHAL NGO Home" class="text-3xl md:text-4xl font-black text-accent font-heading leading-none flex items-center">
                <img src="icon.webp" alt="" class="h-9 w-9 mr-2 inline" aria-hidden="true"> <!-- Hidden decorative icon -->
                PAHAL
             </a>
             <p class="text-xs text-gray-500 italic ml-11 -mt-1.5 hidden sm:block">An Endeavour for a Better Tomorrow</p>
        </div>

        <!-- Mobile Menu Toggle -->
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded">
            <span class="sr-only">Open main menu</span>
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- Navigation -->
        <nav id="navbar" aria-label="Main Navigation" class="w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-0 left-0 bg-white lg:bg-transparent shadow-lg lg:shadow-none lg:border-none border-t border-gray-200 transition-all duration-500 ease-in-out">
            <ul class="flex flex-col lg:flex-row lg:items-center lg:space-x-6 xl:space-x-8 py-4 lg:py-0 px-4 lg:px-0">
                <li><a href="#hero" class="nav-link active">Home</a></li>
                <li><a href="#profile" class="nav-link">Profile</a></li>
                <li><a href="#objectives" class="nav-link">Objectives</a></li>
                <li><a href="#areas-focus" class="nav-link">Focus Areas</a></li>
                <li><a href="#news-section" class="nav-link">News & Events</a></li>
                <li><a href="#volunteer-section" class="nav-link">Get Involved</a></li>
                 <!-- Dropdown Example (Requires JS) -->
                 <!--
                 <li class="relative group">
                    <button aria-haspopup="true" aria-expanded="false" class="nav-link flex items-center">
                       <span>Resources</span> <i class="fas fa-chevron-down text-xs ml-1.5"></i>
                    </button>
                    <ul class="absolute left-0 mt-1 w-48 bg-white shadow-lg rounded-md py-1 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-20">
                       <li><a href="gallery.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-primary hover:text-white">Gallery</a></li>
                       <li><a href="reports.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-primary hover:text-white">Reports</a></li>
                       <li><a href="faqs.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-primary hover:text-white">FAQs</a></li>
                    </ul>
                 </li>
                 -->
                 <li><a href="#associates" class="nav-link">Associates</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
            </ul>
        </nav>
    </div>
</header>

<main>
    <!-- Hero Section -->
    <section id="hero" class="relative">
        <div class="container mx-auto relative z-10 flex flex-col-reverse lg:flex-row items-center justify-between gap-10 text-center lg:text-left">
             <div class="hero-text flex-1 order-2 lg:order-1 flex flex-col items-center lg:items-start justify-center text-center lg:text-left animate-on-scroll fade-in-left">
              <h1 class="font-heading">
                 Empowering Communities,<br> Inspiring Change
              </h1>
              <p class="text-lg lg:text-xl my-6 max-w-xl mx-auto lg:mx-0 text-gray-100 drop-shadow-sm">
                Join PAHAL, a youth-driven NGO in Jalandhar, committed to holistic development and tangible social impact through dedicated action in health, education, environment, and communication.
              </p>
              <div class="mt-8 flex flex-wrap justify-center lg:justify-start gap-4">
                <a href="#profile" class="btn btn-secondary text-base md:text-lg"><i class="fas fa-info-circle"></i>Discover More</a>
                 <a href="#volunteer-section" class="btn text-base md:text-lg"><i class="fas fa-hands-helping"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto animate-on-scroll fade-in-right" style="animation-delay: 0.2s;">
                 <img src="icon.webp" alt="PAHAL NGO Large Logo Icon" class="mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-64 lg:h-64 rounded-full shadow-2xl bg-white/20 p-2 backdrop-blur-sm">
            </div>
        </div>
         <!-- Scroll down indicator -->
        <div class="hero-scroll-indicator">
             <a href="#profile" aria-label="Scroll down to learn more">
                 <i class="fas fa-chevron-down"></i>
             </a>
        </div>
    </section>

    <!-- Profile Section -->
    <section id="profile" class="section-padding bg-lightbg">
        <div class="container mx-auto animate-on-scroll fade-in-up">
             <h2 class="section-title">Our Profile & Vision</h2>
             <div class="grid md:grid-cols-5 gap-12 items-center">
                 <div class="md:col-span-3 profile-text">
                    <h3 class="text-2xl mb-4">Who We Are</h3>
                    <p class="mb-6 text-gray-700 text-lg">'PAHAL' (Initiative) stands as a testament to collective action. We are a dynamic, volunteer-led youth organization conceived by a confluence of inspired minds—Educationists, Doctors, Legal Professionals, Technologists, Entrepreneurs, and passionate Students—all driven by a singular vision: to catalyze perceptible, positive transformation within our social fabric.</p>
                     <blockquote class="border-l-4 border-accent bg-white p-5 my-8 shadow-sm rounded-r-lg relative">
                        <i class="fas fa-quote-left text-accent text-2xl absolute -top-3 -left-3 opacity-50"></i>
                        <p class="italic font-semibold text-primary text-xl text-center">"PAHAL is an endeavour for a Better Tomorrow"</p>
                     </blockquote>
                    <h3 class="text-2xl mb-4 mt-10">Our Core Vision</h3>
                     <p class="text-gray-700 text-lg">We aim to cultivate <strong class="text-primary-dark font-semibold">Holistic Personality Development</strong> by motivating active participation in <strong class="text-primary-dark font-semibold">humanitarian service</strong>. PAHAL endeavours to stimulate social consciousness, offering tangible platforms for individuals to engage <strong class="text-primary-dark font-semibold">creatively and constructively</strong> with global and local communities, thereby building a more compassionate and equitable world.</p>
                 </div>
                 <div class="md:col-span-2 profile-image">
                    <!-- Placeholder - Replace with a relevant, high-quality image -->
                    <img src="https://via.placeholder.com/500x600.png/006400/FFFFFF?text=PAHAL+Team+Spirit" alt="PAHAL NGO team engaging in a community activity" class="rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px]">
                </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section -->
    <section id="objectives" class="section-padding">
        <div class="container mx-auto">
             <h2 class="section-title">Our Guiding Objectives</h2>
             <div class="max-w-5xl mx-auto grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                 <!-- Objective Item Structure -->
                 <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up">
                     <i class="fas fa-users fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                     <p class="text-lg leading-snug">To collaborate genuinely <strong class="font-semibold text-primary-dark">with and among the people</strong>.</p>
                 </div>
                 <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up" style="animation-delay: 0.1s;">
                     <i class="fas fa-people-carry fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                     <p class="text-lg leading-snug">To engage in <strong class="font-semibold text-primary-dark">creative & constructive social action</strong>.</p>
                 </div>
                 <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up" style="animation-delay: 0.2s;">
                     <i class="fas fa-lightbulb fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                     <p class="text-lg leading-snug">To enhance knowledge of <strong class="font-semibold text-primary-dark">self & community realities</strong>.</p>
                 </div>
                 <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up" style="animation-delay: 0.3s;">
                     <i class="fas fa-seedling fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                      <p class="text-lg leading-snug">To apply scholarship for <strong class="font-semibold text-primary-dark">mitigating social problems</strong>.</p>
                 </div>
                 <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up" style="animation-delay: 0.4s;">
                     <i class="fas fa-tools fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                     <p class="text-lg leading-snug">To gain and apply skills in <strong class="font-semibold text-primary-dark">humanity development</strong>.</p>
                 </div>
                  <div class="objective-item group bg-gradient-to-br from-lightbg to-white p-6 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-xl hover:border-primary border-l-4 border-transparent flex items-start space-x-4 animate-on-scroll fade-in-up" style="animation-delay: 0.5s;">
                     <i class="fas fa-recycle fa-2x text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-8 text-center"></i>
                     <p class="text-lg leading-snug">To promote <strong class="font-semibold text-primary-dark">sustainable practices</strong> & awareness.</p>
                 </div>
            </div>
        </div>
    </section>

    <!-- Areas of Focus Section -->
    <section id="areas-focus" class="section-padding bg-lightbg">
        <div class="container mx-auto">
            <h2 class="section-title">Our Key Focus Areas</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">

                 <!-- Health Card - Link -->
                 <a href="blood-donation.php" title="Explore PAHAL's Health Initiatives and Blood Donation Program"
                    class="focus-item group animate-on-scroll fade-in-up">
                     <span class="icon"><i class="fas fa-heart-pulse"></i></span> <!-- Changed icon -->
                     <h3>Health & Wellness</h3>
                     <p>Prioritizing community well-being through health awareness campaigns, crucial blood donation drives, and promoting healthy lifestyles.</p>
                     <span class="read-more-link">Learn About Health Programs <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>

                  <!-- Education Card - Div -->
                  <div class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.1s;">
                     <span class="icon"><i class="fas fa-user-graduate"></i></span> <!-- Changed icon -->
                     <h3>Education & Skilling</h3>
                     <p>Empowering youth by fostering ethical foundations, essential life skills, and professional readiness to tackle unemployment challenges.</p>
                     <span class="read-more-link opacity-50 cursor-not-allowed" title="More information coming soon">Details Soon <i class="fas fa-clock ml-1"></i></span>
                  </div>

                  <!-- Environment Card - Link -->
                 <a href="e-waste.php" title="Learn about PAHAL's E-waste recycling and environmental sustainability efforts"
                    class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.2s;">
                      <span class="icon"><i class="fas fa-leaf"></i></span>
                      <h3>Environment</h3>
                      <p>Championing environmental stewardship through tree plantation drives, effective waste management solutions, and specialized e-waste recycling initiatives.</p>
                      <span class="read-more-link">Explore E-Waste Program <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>

                  <!-- Communication Card - Div -->
                 <div class="focus-item group animate-on-scroll fade-in-up" style="animation-delay: 0.3s;">
                     <span class="icon"><i class="fas fa-comments-dollar"></i></span> <!-- Changed icon - slightly abstract -->
                     <h3>Communication Skills</h3>
                     <p>Enhancing crucial verbal, non-verbal, and presentation abilities in youth through continuous, interactive programs for personal and professional growth.</p>
                      <span class="read-more-link opacity-50 cursor-not-allowed" title="More information coming soon">Details Soon <i class="fas fa-clock ml-1"></i></span>
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section -->
    <section id="volunteer-section" class="section-padding text-white"> <!-- Removed animate-on-scroll -->
        <div class="container mx-auto">
             <h2 class="section-title !text-white after:!bg-accent">Join the PAHAL Movement</h2>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left"> <!-- Added animation here -->
                    <h3 class="text-3xl lg:text-4xl font-bold mb-4 text-white leading-snug">Make a Difference, Volunteer With Us</h3>
                    <p class="text-gray-100 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed">PAHAL welcomes passionate individuals, students, and organizations eager to contribute to community betterment. Your time, skills, and dedication are invaluable assets in our mission.</p>
                    <p class="text-gray-100 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed">Volunteering offers a rewarding experience: develop skills, network with peers, and directly impact lives. Express your interest via the form or contact us!</p>
                     <div class="mt-10 flex flex-wrap justify-center lg:justify-start gap-4">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary"><i class="fas fa-phone-alt"></i>Contact Us Directly</a>
                        <!-- Optional: Link to a separate volunteer page -->
                         <a href="volunteer-opportunities.php" class="btn !bg-white !text-accent hover:!bg-gray-100"><i class="fas fa-list-alt"></i>View Opportunities</a>
                     </div>
                 </div>

                 <!-- Volunteer Sign-up Form -->
                 <div class="bg-primary-dark p-6 sm:p-8 md:p-10 rounded-lg shadow-2xl border-t-4 border-accent animate-on-scroll fade-in-right" style="animation-delay: 0.1s;"> <!-- Added animation here -->
                     <h3 class="text-2xl mb-6 text-white font-semibold">Register Your Interest to Volunteer</h3>

                     <!-- Volunteer Form Status Message -->
                     <?= get_form_status_html('volunteer_form') ?>

                    <form id="volunteer-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <input type="hidden" name="form_id" value="volunteer_form">
                         <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_volunteer">Do not fill this field</label>
                            <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>

                         <div>
                             <label for="volunteer_name" class="form-label !text-gray-100">Full Name:</label>
                             <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" aria-describedby="volunteer_name_error">
                             <?= get_field_error_html('volunteer_form', 'volunteer_name') ?>
                         </div>
                         <div class="grid md:grid-cols-2 gap-5">
                             <div>
                                 <label for="volunteer_email" class="form-label !text-gray-100">Email:</label>
                                 <input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" aria-describedby="volunteer_email_error volunteer_contact_note">
                                 <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                            </div>
                             <div>
                                 <label for="volunteer_phone" class="form-label !text-gray-100">Phone:</label>
                                 <input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone Number" aria-describedby="volunteer_phone_error volunteer_contact_note">
                                 <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                            </div>
                         </div>
                          <p class="text-xs text-gray-300 -mt-3" id="volunteer_contact_note">Please provide at least one contact method (Email or Phone).</p>
                         <div>
                            <label for="volunteer_area" class="form-label !text-gray-100">Primary Area of Interest:</label>
                            <select id="volunteer_area" name="volunteer_area" required class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" aria-describedby="volunteer_area_error">
                                <option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : ''?>>-- Select Area --</option>
                                <option value="Health Programs" <?= $volunteer_form_area_value == 'Health Programs' ? 'selected' : ''?>>Health Programs</option>
                                <option value="Education Initiatives" <?= $volunteer_form_area_value == 'Education Initiatives' ? 'selected' : ''?>>Education Initiatives</option>
                                <option value="Environmental Projects" <?= $volunteer_form_area_value == 'Environmental Projects' ? 'selected' : ''?>>Environmental Projects</option>
                                <option value="Communication Workshops" <?= $volunteer_form_area_value == 'Communication Workshops' ? 'selected' : ''?>>Communication Workshops</option>
                                <option value="Event Management/Support" <?= $volunteer_form_area_value == 'Event Management/Support' ? 'selected' : ''?>>Event Management/Support</option>
                                <option value="Blood Donation Coordination" <?= $volunteer_form_area_value == 'Blood Donation Coordination' ? 'selected' : ''?>>Blood Donation Coordination</option>
                                <option value="E-Waste Collection Assistance" <?= $volunteer_form_area_value == 'E-Waste Collection Assistance' ? 'selected' : ''?>>E-Waste Collection Assistance</option>
                                <option value="General Office/Admin Support" <?= $volunteer_form_area_value == 'General Office/Admin Support' ? 'selected' : ''?>>General Office/Admin Support</option>
                                <option value="Other" <?= $volunteer_form_area_value == 'Other' ? 'selected' : ''?>>Other (Specify in message)</option>
                            </select>
                            <?= get_field_error_html('volunteer_form', 'volunteer_area') ?>
                        </div>
                         <div>
                            <label for="volunteer_availability" class="form-label !text-gray-100">Your Availability:</label>
                            <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings after 6 PM, Flexible" aria-required="true" aria-describedby="volunteer_availability_error">
                             <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                        </div>
                        <div>
                            <label for="volunteer_message" class="form-label !text-gray-100">Why do you want to volunteer? (Optional)</label>
                            <textarea id="volunteer_message" name="volunteer_message" rows="3" class="form-input <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Share a bit about your motivation or specific skills..." aria-describedby="volunteer_message_error"><?= $volunteer_form_message_value // Textarea content ?></textarea>
                            <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                        </div>
                         <button type="submit" class="btn !bg-accent hover:!bg-accent-dark w-full sm:w-auto"><i class="fas fa-paper-plane"></i>Submit Volunteer Interest</button>
                    </form>
                 </div>
            </div>
        </div>
    </section>


    <!-- News & Events Section -->
    <section id="news-section" class="section-padding bg-lightbg">
        <div class="container mx-auto">
            <h2 class="section-title">Latest News & Upcoming Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php if (!empty($news_items)): ?>
                    <?php foreach ($news_items as $index => $item): ?>
                    <div class="news-card group animate-on-scroll fade-in-up" style="animation-delay: <?= $index * 0.1 ?>s;">
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="block aspect-[16/10] overflow-hidden group" title="Read more about <?= htmlspecialchars($item['title']) ?>"> <!-- Aspect ratio for consistency -->
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="Image for <?= htmlspecialchars($item['title']) ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                        </a>
                        <div class="news-content">
                             <span class="date text-gray-500"><i class="far fa-calendar-alt mr-1"></i><?= date('M j, Y', strtotime($item['date'])) // Format date ?></span>
                             <h4 class="my-2">
                                 <a href="<?= htmlspecialchars($item['link']) ?>" class="hover:underline text-primary group-hover:text-accent transition-colors"><?= htmlspecialchars($item['title']) ?></a>
                             </h4>
                             <p class="text-sm text-gray-600 leading-relaxed flex-grow"><?= htmlspecialchars($item['excerpt']) ?></p>
                              <div class="read-more-action">
                                  <a href="<?= htmlspecialchars($item['link']) ?>" class="btn-outline !py-1.5 !px-4 !text-sm !border-primary hover:!bg-primary hover:!text-white">Read More <i class="fas fa-arrow-right text-xs ml-1"></i></a>
                              </div>
                         </div>
                    </div>
                    <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-gray-500 md:col-span-2 lg:col-span-3">No recent news items to display. Please check back soon!</p>
                 <?php endif; ?>
            </div>
            <div class="text-center mt-12">
                <a href="/news-archive.php" class="btn btn-secondary"><i class="far fa-newspaper"></i>View News Archive</a> <!-- Link to a dedicated archive page -->
            </div>
        </div>
    </section>

    <!-- Gallery Section (Simple) -->
    <section id="gallery-section" class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title">Glimpses of Our Work</h2>
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4">
                    <?php foreach ($gallery_images as $index => $image): ?>
                    <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block aspect-video rounded-lg overflow-hidden shadow-md group animate-on-scroll fade-in-up" style="animation-delay: <?= $index * 0.05 ?>s;">
                         <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-110 group-hover:opacity-90">
                     </a>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-8 text-gray-600 italic">Click on images to view larger.</p>
            <?php else: ?>
                 <p class="text-center text-gray-500">Gallery images are currently being updated. Please check back later.</p>
            <?php endif; ?>
        </div>
    </section>


    <!-- Associates Section -->
    <section id="associates" class="section-padding bg-gradient-to-b from-lightbg to-white"> <!-- Subtle gradient -->
        <div class="container mx-auto">
            <h2 class="section-title">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-gray-600 mb-16">Collaboration is key to our success. We are grateful for the support and partnership of these esteemed organizations.</p>
             <div class="flex flex-wrap justify-center items-center gap-x-10 md:gap-x-16 gap-y-8">
                <?php $associates = [ // Group associates data
                    ['name' => 'NACO', 'img' => 'naco.webp'],
                    ['name' => 'Microsoft', 'img' => 'microsoft.webp'],
                    ['name' => 'Karo Sambhav', 'img' => 'Karo_Logo-01.webp'],
                    ['name' => 'PSACS', 'img' => 'psacs.webp'],
                    ['name' => 'NABARD', 'img' => 'nabard.webp'],
                    ['name' => 'Govt. Punjab', 'img' => 'punjab-gov.png'],
                    ['name' => 'Ramsan', 'img' => 'ramsan.png'],
                    ['name' => 'Apollo Tyres', 'img' => 'image.png'],
                ]; ?>
                <?php foreach ($associates as $index => $associate): ?>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll fade-in-up" style="animation-delay: <?= $index * 0.05 ?>s">
                    <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100">
                    <p class="text-xs font-semibold text-gray-500 group-hover:text-primary-dark transition-colors"><?= htmlspecialchars($associate['name']) ?></p>
                 </div>
                 <?php endforeach; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <section id="donate-section" class="section-padding text-center"> <!-- No animate-on-scroll needed for full bg section -->
        <div class="container mx-auto relative z-10"> <!-- Ensure content is above background effects -->
             <i class="fas fa-donate text-4xl text-white bg-accent p-4 rounded-full shadow-lg mb-6 inline-block"></i>
             <h2 class="section-title !text-white after:!bg-white"><span class="drop-shadow-md">Support Our Initiatives</span></h2>
            <p class="text-gray-100 max-w-3xl mx-auto mb-8 text-lg leading-relaxed drop-shadow">Your generous contribution fuels our mission, enabling vital programs in health, education, and environmental protection within the Jalandhar community.</p>
            <p class="text-gray-200 bg-black/20 inline-block px-4 py-1 rounded text-sm font-semibold mb-10 backdrop-blur-sm">Donations are Tax Exempt under Sec 80G of the Income Tax Act.</p>
            <div class="space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center gap-4">
                 <a href="#contact" class="btn btn-secondary !bg-white !text-primary hover:!bg-gray-100"><i class="fas fa-info-circle"></i> Donation Inquiries</a>
                 <!-- Modal trigger example (needs JS to function) -->
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary" data-modal-target="bank-details-modal">
                     <i class="fas fa-university"></i>View Bank Details
                </button>
                 <!-- Link to Online Platform (if exists) -->
                 <!-- <a href="https://your-payment-gateway-link.com" target="_blank" rel="noopener noreferrer" class="btn"><i class="fas fa-credit-card"></i>Donate Online Securely</a> -->
            </div>
        </div>
        <!-- Background effect -->
         <div class="absolute inset-0 bg-black/30 mix-blend-multiply"></div>
     </section>

    <!-- Contact Section -->
    <section id="contact" class="section-padding bg-white"> <!-- Removed outer animate-on-scroll -->
        <div class="container mx-auto">
             <h2 class="section-title">Connect With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-gray-600 mb-16">Whether you have questions, suggestions, partnership proposals, or just want to learn more, we encourage you to reach out. We're here to connect.</p>
             <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                 <!-- Contact Details & Map -->
                 <div class="lg:col-span-2 animate-on-scroll fade-in-left"> <!-- Animate inner column -->
                     <h3 class="text-2xl mb-6 font-semibold">Contact Information</h3>
                     <div class="space-y-5 text-gray-700 text-base mb-10">
                        <!-- Address -->
                         <div class="flex items-start">
                            <i class="fas fa-map-marker-alt fa-fw text-primary text-xl mr-4 mt-1 w-6 text-center flex-shrink-0"></i>
                            <div>
                                <span class="font-semibold text-gray-800 block">Our Office:</span>
                                <span class="text-gray-600">36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008, India</span>
                            </div>
                        </div>
                        <!-- Phones -->
                        <div class="flex items-start">
                            <i class="fas fa-phone-alt fa-fw text-primary text-xl mr-4 mt-1 w-6 text-center flex-shrink-0"></i>
                            <div>
                                 <span class="font-semibold text-gray-800 block">Phone Lines:</span>
                                 <a href="tel:+911812672784" class="hover:text-accent hover:underline text-gray-600 block">Office: +91 181-267-2784</a>
                                <a href="tel:+919855614230" class="hover:text-accent hover:underline text-gray-600 block">Mobile: +91 98556-14230</a>
                            </div>
                        </div>
                        <!-- Email -->
                        <div class="flex items-start">
                             <i class="fas fa-envelope fa-fw text-primary text-xl mr-4 mt-1 w-6 text-center flex-shrink-0"></i>
                             <div>
                                 <span class="font-semibold text-gray-800 block">Email Us:</span>
                                 <a href="mailto:engage@pahal-ngo.org" class="hover:text-accent hover:underline text-gray-600 break-all">engage@pahal-ngo.org</a>
                            </div>
                        </div>
                     </div>

                    <!-- Social Media -->
                    <div class="mb-10 border-t pt-8">
                         <h4 class="text-lg font-semibold text-primary mb-4">Follow Our Journey Online</h4>
                         <div class="flex space-x-5">
                             <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" aria-label="PAHAL on Instagram" title="PAHAL on Instagram" class="text-gray-500 text-3xl transition duration-300 hover:text-[#E1306C] hover:scale-110"><i class="fab fa-instagram-square"></i></a>
                             <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" aria-label="PAHAL on Facebook" title="PAHAL on Facebook" class="text-gray-500 text-3xl transition duration-300 hover:text-[#1877F2] hover:scale-110"><i class="fab fa-facebook-square"></i></a>
                             <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" aria-label="PAHAL on Twitter" title="PAHAL on Twitter" class="text-gray-500 text-3xl transition duration-300 hover:text-[#1DA1F2] hover:scale-110"><i class="fab fa-twitter-square"></i></a>
                             <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" aria-label="PAHAL on LinkedIn" title="PAHAL on LinkedIn" class="text-gray-500 text-3xl transition duration-300 hover:text-[#0A66C2] hover:scale-110"><i class="fab fa-linkedin"></i></a>
                             <!-- Add YouTube if available -->
                              <!-- <a href="https://youtube.com/yourchannel" target="_blank" rel="noopener noreferrer" title="PAHAL on YouTube" class="text-gray-500 text-3xl transition duration-300 hover:text-[#FF0000] hover:scale-110"><i class="fab fa-youtube-square"></i></a> -->
                         </div>
                    </div>

                     <!-- Embedded Map -->
                     <div class="mb-10 border-t pt-8">
                         <h4 class="text-lg font-semibold text-primary mb-4">Visit Us</h4>
                        <iframe
                             src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3407.758638397537!2d75.5988858150772!3d31.33949238143149!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b4422dab0c5%3A0xe88f5c48cfc1a3d3!2sPahal%20NGO!5e0!3m2!1sen!2sin!4v1678886655444!5m2!1sen!2sin"
                             width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy"
                             referrerpolicy="no-referrer-when-downgrade" title="PAHAL NGO Location Map"
                            class="rounded-md shadow-xl border border-gray-200">
                         </iframe>
                     </div>

                     <!-- Reg Info -->
                    <div class="registration-info bg-gray-50 p-4 rounded border border-gray-200">
                         <h4 class="text-sm font-semibold text-primary-dark mb-2">Registration & Compliance</h4>
                        <p class="text-xs text-gray-600 mb-1"><i class="fas fa-certificate mr-1 text-primary-dark"></i> Societies Registration Act XXI, 1860 (Reg. No.: 737)</p>
                        <p class="text-xs text-gray-600 mb-1"><i class="fas fa-certificate mr-1 text-primary-dark"></i> Section 12-A, Income Tax Act, 1961</p>
                         <p class="text-xs text-gray-600"><i class="fas fa-donate mr-1 text-primary-dark"></i> Section 80G Tax Exemption Certified</p>
                     </div>
                 </div>

                <!-- Contact Form -->
                <div class="lg:col-span-3 bg-gradient-to-br from-lightbg to-white p-6 sm:p-8 md:p-10 rounded-lg shadow-xl border-t-4 border-primary animate-on-scroll fade-in-right" style="animation-delay: 0.1s;"> <!-- Animate inner column -->
                    <h3 class="text-2xl mb-8 font-semibold">Send Us a Message Directly</h3>

                     <!-- PHP Status Message Area -->
                    <?= get_form_status_html('contact_form') ?>

                    <form id="contact-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                         <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_contact">Do not fill</label>
                            <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>

                        <div>
                            <label for="contact_name" class="form-label">Your Name:</label>
                            <input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="form-input <?= get_field_error_class('contact_form', 'name') ?>" aria-required="true" aria-describedby="contact_name_error" placeholder="e.g., Jane Doe">
                            <?= get_field_error_html('contact_form', 'name') ?>
                         </div>
                        <div>
                            <label for="contact_email" class="form-label">Your Email:</label>
                             <input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="form-input <?= get_field_error_class('contact_form', 'email') ?>" aria-required="true" aria-describedby="contact_email_error" placeholder="e.g., jane.doe@example.com">
                             <?= get_field_error_html('contact_form', 'email') ?>
                        </div>
                        <div>
                            <label for="contact_message" class="form-label">Your Message:</label>
                            <textarea id="contact_message" name="message" rows="5" required class="form-input <?= get_field_error_class('contact_form', 'message') ?>" aria-required="true" aria-describedby="contact_message_error" placeholder="Please share your thoughts, questions, or feedback here..."><?= $contact_form_message_value // Use textarea content, not value ?></textarea>
                             <?= get_field_error_html('contact_form', 'message') ?>
                        </div>
                         <button type="submit" class="btn w-full sm:w-auto" id="contact-submit-button">
                            <i class="fas fa-paper-plane"></i>
                             <span>Send Message</span>
                             <!-- Simple Spinner (hidden initially) -->
                              <svg class="animate-spin ml-3 h-5 w-5 text-white hidden" id="contact-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                              </svg>
                         </button>
                    </form>
                 </div>
            </div>
        </div>
    </section>

    <!-- Donation Modal Placeholder -->
    <div id="bank-details-modal" class="fixed inset-0 bg-black/50 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm" aria-labelledby="modal-title" role="dialog" aria-modal="true">
      <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-md text-left relative animate-on-scroll fade-in-up">
         <button type="button" class="absolute top-3 right-3 text-gray-400 hover:text-gray-600" aria-label="Close modal" data-modal-close="bank-details-modal">
           <i class="fas fa-times fa-lg"></i>
         </button>
         <h3 id="modal-title" class="text-xl font-semibold text-primary mb-4">Bank Transfer Details for Donation</h3>
        <p class="text-sm text-gray-600 mb-4">Please use the following details for direct bank transfers. Ensure you mention "Donation" in the transfer description.</p>
         <div class="space-y-2 text-sm bg-gray-50 p-4 rounded border">
            <p><strong>Account Name:</strong> PAHAL (Regd.)</p>
            <p><strong>Account Number:</strong> [YOUR_BANK_ACCOUNT_NUMBER]</p> <!-- REPLACE -->
             <p><strong>Bank Name:</strong> [YOUR_BANK_NAME]</p> <!-- REPLACE -->
             <p><strong>Branch:</strong> [YOUR_BANK_BRANCH]</p> <!-- REPLACE -->
             <p><strong>IFSC Code:</strong> [YOUR_IFSC_CODE]</p> <!-- REPLACE -->
        </div>
        <p class="text-xs text-gray-500 mt-4">For any queries regarding donations or receipts, please contact us directly. Thank you for your support!</p>
      </div>
    </div>

</main>

<!-- Footer -->
<footer class="bg-footerbg text-gray-300 pt-16 pb-8 mt-0"> <!-- Reduced margin top if next section is colored -->
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12 text-center md:text-left">
            <!-- Footer About -->
            <div>
                <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-12 after:h-0.5 after:bg-accent">About PAHAL</h4>
                <a href="#hero" class="inline-block mb-3">
                  <img src="icon.webp" alt="PAHAL Footer Icon" class="w-14 h-14 rounded-full bg-white p-1 shadow-md">
                </a>
                <p class="text-sm mb-3 leading-relaxed text-gray-400">Jalandhar-based non-profit youth organization fostering holistic growth & community service since [Year - e.g., 2005].</p>
                 <p class="text-xs text-gray-500">Reg No: 737 | 80G & 12A Certified</p>
                 <div class="mt-4 flex justify-center md:justify-start space-x-4">
                     <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram" class="text-xl transition duration-300 text-gray-400 hover:text-[#E1306C]"><i class="fab fa-instagram"></i></a>
                     <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook" class="text-xl transition duration-300 text-gray-400 hover:text-[#1877F2]"><i class="fab fa-facebook-f"></i></a>
                     <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter" class="text-xl transition duration-300 text-gray-400 hover:text-[#1DA1F2]"><i class="fab fa-twitter"></i></a>
                     <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn" class="text-xl transition duration-300 text-gray-400 hover:text-[#0A66C2]"><i class="fab fa-linkedin"></i></a>
                 </div>
            </div>

             <!-- Footer Quick Links -->
             <div>
                 <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-12 after:h-0.5 after:bg-accent">Explore</h4>
                 <ul class="space-y-2 text-sm columns-2 md:columns-1">
                     <li><a href="#profile"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Profile</a></li>
                     <li><a href="#objectives"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Objectives</a></li>
                     <li><a href="#areas-focus"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Focus Areas</a></li>
                     <li><a href="#news-section"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> News</a></li>
                     <li><a href="#gallery-section"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Gallery</a></li>
                     <li><a href="blood-donation.php"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Blood Donation</a></li>
                     <li><a href="e-waste.php"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> E-Waste</a></li>
                     <li><a href="#volunteer-section"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Volunteer</a></li>
                     <li><a href="#donate-section"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Donate</a></li>
                     <li><a href="#associates"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Associates</a></li>
                     <li><a href="#contact"><i class="fas fa-chevron-right text-xs mr-1.5 opacity-70"></i> Contact</a></li>
                 </ul>
             </div>

             <!-- Footer Contact -->
             <div>
                 <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-12 after:h-0.5 after:bg-accent">Reach Us</h4>
                 <address class="not-italic space-y-3 text-sm text-gray-300">
                     <p class="flex items-start"><i class="fas fa-map-marker-alt fa-fw mr-3 mt-1 text-accent flex-shrink-0"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008</p>
                     <p class="flex items-center"><i class="fas fa-phone-alt fa-fw mr-3 text-accent"></i> <a href="tel:+911812672784">181-267-2784 (Office)</a></p>
                     <p class="flex items-center"><i class="fas fa-mobile-alt fa-fw mr-3 text-accent"></i> <a href="tel:+919855614230">98556-14230 (Mobile)</a></p>
                     <p class="flex items-start"><i class="fas fa-envelope fa-fw mr-3 mt-1 text-accent"></i> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></p>
                 </address>
             </div>

             <!-- Footer Mini Map or Quote -->
             <div>
                  <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-12 after:h-0.5 after:bg-accent">Our Inspiration</h4>
                 <blockquote class="text-sm italic text-gray-400 border-l-2 border-accent pl-4">
                    "The best way to find yourself is to lose yourself in the service of others."
                     <cite class="block not-italic mt-1 text-xs text-gray-500">- Mahatma Gandhi</cite>
                </blockquote>
             </div>

        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <p>© <?= $current_year ?> PAHAL (Regd.), Jalandhar. All Rights Reserved. | An Endeavour for a Better Tomorrow.</p>
             <p class="mt-2 text-xs">
                 <!-- Add privacy policy link if available -->
                 <a href="/privacy-policy.php" class="footer-link opacity-70">Privacy Policy</a> |
                 <a href="/terms.php" class="footer-link opacity-70">Terms of Use</a>
                 <!-- Optional: <span class="mx-1">|</span> Website designed by [Your Name/Company] -->
             </p>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="back-to-top" aria-label="Back to Top" title="Back to Top" class="fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-accent text-white shadow-lg hover:bg-accent-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-accent opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95">
   <i class="fas fa-arrow-up"></i>
</button>


<!-- Simple Lightbox JS -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.1/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript -->
<script>
    // Wait for the DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', () => {
        // --- Elements ---
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const navbar = document.getElementById('navbar');
        const navLinks = document.querySelectorAll('#navbar a.nav-link[href^="#"]'); // Select only internal nav links
        const header = document.getElementById('main-header');
        const backToTopButton = document.getElementById('back-to-top');
        const sections = document.querySelectorAll('main section[id]'); // All sections with IDs in main
        let headerHeight = header ? header.offsetHeight : 70;

        // --- State ---
        let isMobileMenuOpen = false;

        // --- Header & Layout Updates ---
        function updateLayout() {
            if (!header) return;
            headerHeight = header.offsetHeight;
            document.body.style.paddingTop = `${headerHeight}px`; // Offset body for fixed header

            // Sticky Header Styling
            header.classList.toggle('scrolled', window.scrollY > 50);

            // Back to Top Button Visibility
            backToTopButton?.classList.toggle('opacity-0', window.scrollY <= 300);
            backToTopButton?.classList.toggle('invisible', window.scrollY <= 300);
        }

        // Initial layout calculation & listeners
        updateLayout();
        window.addEventListener('resize', updateLayout);
        window.addEventListener('scroll', updateLayout, { passive: true });

        // --- Mobile Menu ---
        function toggleMobileMenu(forceClose = false) {
            if (!menuToggle || !navbar) return;

            const shouldOpen = !isMobileMenuOpen && !forceClose;

            menuToggle.setAttribute('aria-expanded', shouldOpen);
            menuToggle.classList.toggle('active', shouldOpen);
            // navbar.classList.toggle('hidden', !shouldOpen);
             // Use max-height for transition effect
             if (shouldOpen) {
                navbar.classList.remove('hidden');
                 navbar.style.maxHeight = navbar.scrollHeight + "px"; // Expand to content height
             } else {
                navbar.style.maxHeight = "0";
                 // Hide after transition (important for accessibility and layout)
                navbar.addEventListener('transitionend', () => {
                   if (!isMobileMenuOpen) navbar.classList.add('hidden');
                }, { once: true });
            }

            document.body.classList.toggle('overflow-hidden', shouldOpen); // Prevent body scroll when open
             isMobileMenuOpen = shouldOpen;
         }

        if (menuToggle) {
             menuToggle.addEventListener('click', () => toggleMobileMenu());
        }

        // --- Active Link Highlighting ---
        function setActiveLink() {
            let currentSectionId = '';
            const scrollThreshold = headerHeight + 120; // Activate slightly earlier

             sections.forEach(section => {
                 const sectionTop = section.offsetTop - scrollThreshold;
                const sectionBottom = sectionTop + section.offsetHeight;
                if (window.pageYOffset >= sectionTop && window.pageYOffset < sectionBottom) {
                     currentSectionId = '#' + section.getAttribute('id');
                }
            });

             // Handle top of page case
            if (currentSectionId === '' && window.pageYOffset < (sections[0]?.offsetTop - scrollThreshold || 500)) {
                 currentSectionId = '#hero';
             }

             navLinks.forEach(link => {
                const isActive = link.getAttribute('href') === currentSectionId;
                link.classList.toggle('active', isActive);
                 link.setAttribute('aria-current', isActive ? 'page' : null); // Accessibility: mark current page link
             });
        }

         // Run initially and on scroll
         setActiveLink();
        window.addEventListener('scroll', setActiveLink, { passive: true });

        // --- Smooth Scrolling & Menu Close ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
             anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');
                if (targetId.length <= 1) return; // Ignore empty or '#' links

                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    e.preventDefault();
                    if (isMobileMenuOpen) {
                         toggleMobileMenu(true); // Force close menu
                     }

                     const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - headerHeight - 10; // Offset for header
                    window.scrollTo({ top: targetPosition, behavior: 'smooth' });

                     // Manually set active state immediately if it's a primary nav link
                     if (this.classList.contains('nav-link')) {
                        navLinks.forEach(lnk => lnk.classList.remove('active'));
                        this.classList.add('active');
                    }
                }
            });
        });

        // --- Back to Top ---
         if (backToTopButton) {
             backToTopButton.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
             });
        }

        // --- Form Submission Indicator ---
        const contactForm = document.getElementById('contact-form');
        const contactSubmitButton = document.getElementById('contact-submit-button');
        const contactSpinner = document.getElementById('contact-spinner');
         if (contactForm && contactSubmitButton && contactSpinner) {
            contactForm.addEventListener('submit', (e) => {
                 // Optional: Add client-side validation here before disabling
                 // if (!contactForm.checkValidity()) { return; }
                 contactSubmitButton.disabled = true;
                contactSubmitButton.querySelector('span').textContent = 'Sending...'; // Change text
                 contactSpinner.classList.remove('hidden');
             });
        }
         // Add similar logic for the Volunteer form if needed

        // --- Gallery Lightbox ---
         if (typeof SimpleLightbox !== 'undefined') {
             try {
                 new SimpleLightbox('.gallery a', {
                    captionsData: 'alt',
                    captionDelay: 250,
                    fadeSpeed: 200,
                     // Add more options if needed
                });
            } catch(e) {
                 console.error("SimpleLightbox initialization failed:", e);
             }
         }

        // --- Animation on Scroll ---
        const observerOptions = { root: null, rootMargin: '0px', threshold: 0.15 }; // Trigger when 15% visible

        const intersectionCallback = (entries, observer) => {
             entries.forEach(entry => {
                if (entry.isIntersecting) {
                     entry.target.classList.add('is-visible');
                     observer.unobserve(entry.target); // Animate only once
                 }
             });
        };

         if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(intersectionCallback, observerOptions);
            document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el));
         } else {
            // Fallback for older browsers: just show everything
             document.querySelectorAll('.animate-on-scroll').forEach(el => el.classList.add('is-visible'));
         }

        // --- Modal Handling (Basic Example) ---
         const modalTriggers = document.querySelectorAll('[data-modal-target]');
         const modalClosers = document.querySelectorAll('[data-modal-close]');

        modalTriggers.forEach(button => {
             button.addEventListener('click', () => {
                 const modalId = button.getAttribute('data-modal-target');
                const modal = document.getElementById(modalId);
                 if (modal) {
                     modal.classList.remove('hidden');
                    modal.classList.add('flex'); // Use flex for centering
                    modal.querySelector('[role="dialog"]')?.focus(); // Focus modal content for accessibility
                     document.body.style.overflow = 'hidden'; // Prevent body scroll
                 }
            });
         });

         modalClosers.forEach(button => {
             button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal-close');
                const modal = document.getElementById(modalId);
                 if (modal) {
                     modal.classList.add('hidden');
                     modal.classList.remove('flex');
                     document.body.style.overflow = ''; // Restore body scroll
                      // Optional: focus back the trigger button
                 }
             });
         });

         // Close modal on outside click
         document.querySelectorAll('[aria-modal="true"]').forEach(modal => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) { // Only if clicking the background overlay
                     modal.classList.add('hidden');
                     modal.classList.remove('flex');
                    document.body.style.overflow = '';
                }
            });
        });
         // Close modal with ESC key
        document.addEventListener('keydown', (event) => {
             if (event.key === 'Escape') {
                 document.querySelectorAll('[aria-modal="true"]:not(.hidden)').forEach(modal => {
                    modal.classList.add('hidden');
                     modal.classList.remove('flex');
                     document.body.style.overflow = '';
                });
             }
         });


    }); // End DOMContentLoaded
</script>

</body>
</html>
