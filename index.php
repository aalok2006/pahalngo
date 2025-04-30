<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 3.5 (Advanced UI Animations & Visuals - Styling based on E-Waste page)
// Features: Animated Gradients, Enhanced Hovers, Micro-interactions
// Backend: PHP mail(), CSRF, Honeypot, Logging
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration (Keep Existing) ---
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com"); // CHANGE ME
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Website');             // CHANGE ME
// Using the same CSRF token name as the e-waste page for consistency across the site
define('CSRF_TOKEN_NAME', 'csrf_token'); // Matching e-waste page
define('HONEYPOT_FIELD_NAME', 'website_url'); // Keep unique for this form
define('ENABLE_LOGGING', true);
$baseDir = __DIR__;
define('LOG_FILE_CONTACT', $baseDir . '/logs/contact_submissions.log');
define('LOG_FILE_VOLUNTEER', $baseDir . '/logs/volunteer_submissions.log');
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log'); // Shared error log
// --- END CONFIG ---

// --- Helper Functions ---
// *** ENSURE ALL REQUIRED HELPER FUNCTIONS ARE DEFINED HERE ***
// (log_message, generate_csrf_token, validate_csrf_token, sanitize_string,
//  sanitize_email, validate_data, send_email, get_form_value,
//  get_form_status_html, get_field_error_html, get_field_error_class, get_aria_describedby)
// **** Copied/verified from previous fixed version ****

/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) { error_log("Failed to create log directory: " . $logDir); error_log("Original Log Message ($logFile): " . $message); return; }
        // Attempt to create a .htaccess file to deny direct access to logs
        if (is_dir($logDir) && !file_exists($logDir . '/.htaccess')) { @file_put_contents($logDir . '/.htaccess', 'Deny from all'); }
    }
    $timestamp = date('Y-m-d H:i:s'); $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    // Use file_put_contents with FILE_APPEND and LOCK_EX for safer writing
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        $error = error_get_last();
        error_log("Failed to write log: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown file_put_contents error'));
        error_log("Original Log Message: " . $message); // Log the original message as well
    }
}


/**
 * Generates or retrieves a CSRF token.
 * Using the global CSRF_TOKEN_NAME.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        try { $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32)); }
        catch (Exception $e) {
            // Fallback for systems that don't have random_bytes (very rare on modern PHP)
            log_message("random_bytes failed, using md5 fallback for CSRF token.", LOG_FILE_ERROR);
            $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true));
        }
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token.
 * Using the global CSRF_TOKEN_NAME.
 */
function validate_csrf_token(?string $submittedToken): bool {
    // Use hash_equals for timing attack safety
    return !empty($submittedToken) && !empty($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
}

/**
 * Sanitize input string.
 */
function sanitize_string(string $input): string { return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

/**
 * Sanitize email address.
 */
function sanitize_email(string $email): string { $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL); return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : ''; }

/**
 * Validates input data based on rules.
 */
function validate_data(array $data, array $rules): array {
     $errors = [];
     foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null; // Use null coalescing operator
        $ruleList = explode('|', $ruleString);
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field)); // Format field name for messages

        foreach ($ruleList as $rule) {
            $params = [];
            if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }

            $isValid = true;
            $errorMessage = '';

            switch ($rule) {
                case 'required':
                    // Check if value is null, empty string, or an empty array (for things like checkboxes)
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} is required.";
                    }
                    break;
                case 'email':
                    // Only validate if value is not empty
                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid email.";
                    }
                    break;
                case 'minLength':
                    $min = (int)($params[0] ?? 0);
                    if ($value !== null && is_string($value) && mb_strlen($value, 'UTF-8') < $min) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be at least {$min} characters.";
                     }
                     break;
                 case 'maxLength':
                    $max = (int)($params[0] ?? PHP_INT_MAX);
                     if ($value !== null && is_string($value) && mb_strlen($value, 'UTF-8') > $max) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must not exceed {$max} characters.";
                     }
                     break;
                 case 'alpha_space':
                     // Only validate if value is not empty, allows Unicode letters
                     if (!empty($value) && !preg_match('/^[\p{L}\s]+$/u', $value)) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces.";
                     }
                     break;
                 case 'phone':
                    // Basic phone format validation, adjustable regex
                     if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                         $isValid = false;
                         $errorMessage = "Invalid phone format.";
                     }
                     break;
                 case 'in':
                     // Checks if the value is one of the allowed params
                     if (!empty($value) && !in_array($value, $params)) {
                         $isValid = false;
                         $errorMessage = "Invalid selection for {$fieldNameFormatted}.";
                     }
                     break;
                 case 'required_without':
                     // Checks if this field is required if the other field is empty
                     $otherField = $params[0] ?? null;
                     if ($otherField && empty($value) && empty($data[$otherField] ?? null)) {
                         $isValid = false;
                         $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_',' ',$otherField)). " is required.";
                     }
                     break;
                 case 'recaptcha': // Placeholder if you add reCAPTCHA later
                    // reCAPTCHA v3 validation logic would go here
                    // For now, it just checks if the field exists if required.
                    if (in_array('required', $ruleList) && empty($value)) {
                         $isValid = false; $errorMessage = "reCAPTCHA verification is required.";
                    }
                    // Real validation would call Google API:
                    // $recaptcha_secret = 'YOUR_SECRET_KEY';
                    // $recaptcha_response = $value; // Assumes $value is the g-recaptcha-response
                    // $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
                    // $recaptcha_data = ['secret' => $recaptcha_secret, 'response' => $recaptcha_response, 'remoteip' => $_SERVER['REMOTE_ADDR'] ];
                    // $options = [ 'http' => [ 'method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($recaptcha_data) ] ];
                    // $context  = stream_context_create($options);
                    // $verify = @file_get_contents($recaptcha_url, false, $context);
                    // if ($verify === FALSE) { // Handle API call failure
                    //    $isValid = false; $errorMessage = "reCAPTCHA verification failed (API error).";
                    //    log_message("reCAPTCHA API call failed for IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'), LOG_FILE_ERROR);
                    // } else {
                    //     $captcha_success = json_decode($verify);
                    //     if ($captcha_success->success == false || $captcha_success->score <= 0.5) { // Or your preferred threshold
                    //         $isValid = false; $errorMessage = "reCAPTCHA verification failed. Please try again.";
                    //         log_message("reCAPTCHA score low ({$captcha_success->score}) for IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . " | Errors: " . json_encode($captcha_success->{"error-codes"} ?? 'none'), LOG_FILE_ERROR);
                    //     }
                    // }
                    break;
                // Add other validation rules as needed
                default:
                    log_message("Unknown validation rule '{$rule}' for field '{$field}'.", LOG_FILE_ERROR);
                    break;
            }

            // If validation failed for this rule and no error is already set for this field, set the error and break from rules for this field
            if (!$isValid && !isset($errors[$field])) {
                $errors[$field] = $errorMessage;
                break;
            }
         }
     }
     return $errors;
}


/**
 * Sends an email using the standard PHP mail() function.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext, string $successLogFile = LOG_FILE_CONTACT, string $errorLogFile = LOG_FILE_ERROR): bool {
    $senderName = SENDER_NAME_DEFAULT; $senderEmail = SENDER_EMAIL_DEFAULT;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid recipient email format: {$to}", $errorLogFile); return false; }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid sender email format in config: {$senderEmail}", $errorLogFile); return false; }

    // Ensure Reply-To is a valid email, fallback to sender if not
    $validReplyTo = (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) ? $replyToEmail : $senderEmail;
    $replyToName = !empty($replyToName) ? $replyToName : $senderName;

    $headers = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    $headers .= "Reply-To: =?UTF-8?B?".base64_encode($replyToName)."?= <{$validReplyTo}>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?=";
    $wrapped_body = wordwrap($body, 70, "\r\n");

    // Attempt to send the email, using the -f flag to set the return-path
    if (@mail($to, $encodedSubject, $wrapped_body, $headers, "-f{$senderEmail}")) {
        log_message("{$logContext} Email submitted via mail() to {$to}. Subject: {$subject}", $successLogFile);
        return true;
    } else {
        $errorInfo = error_get_last();
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs.');
        log_message($errorMsg, $errorLogFile);
        error_log($errorMsg); // Log error server-side using PHP's built-in error_log
        return false;
    }
}


/**
 * Retrieves a form value safely for HTML output, using global state.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; $value = $form_submissions[$formId][$fieldName] ?? $default;
    // Handle cases where the value might be an array (e.g., multi-select, checkboxes - though not used here)
    if (is_array($value)) {
         // Log this as it might indicate an issue or unexpected input
        log_message("Attempted to get non-scalar value for {$formId}[{$fieldName}] using get_form_value.", LOG_FILE_ERROR);
        return ''; // Return empty string or handle arrays differently if needed
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error) with enhanced styling & animation.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    // Updated classes to match e-waste page color scheme but keep enhanced styling
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg transition-all duration-500 transform opacity-0 translate-y-2 scale-95'; // Start smaller for animation
    $typeClasses = $isSuccess
        ? 'bg-green-100 border-green-500 text-green-900 dark:bg-green-900/40 dark:border-green-600 dark:text-green-100' // E-waste success green shades
        : 'bg-red-100 border-red-500 text-red-900 dark:bg-red-900/40 dark:border-red-600 dark:text-red-100'; // E-waste error red shades
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-500 dark:text-green-400' : 'fas fa-exclamation-triangle text-red-500 dark:text-red-400';
    $title = $isSuccess ? 'Success!' : 'Error:';
    // data-form-message-id triggers JS animation
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\"><strong class=\"font-bold flex items-center text-base\"><i class=\"{$iconClass} mr-2 text-xl animate-pulse\"></i>{$title}</strong> <span class=\"block sm:inline mt-1 ml-8\">" . htmlspecialchars($message['text']) . "</span></div>";
}

/**
 * Generates HTML for a field error message with accessibility link.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) {
        $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
        // Updated class to match e-waste page/new theme accent color
        return '<p class="form-error-message text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1 animate-fade-in" id="' . $errorId . '">'
               . '<i class="fas fa-exclamation-circle text-xs"></i>' // Using exclamation circle as in advanced UI
               . htmlspecialchars($form_errors[$formId][$fieldName])
               . '</p>';
    }
    return '';
}

/**
 * Returns CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors;
     // Ensure 'form-input' base class is included, then add error class if needed
     $base = 'form-input';
     $error = 'form-input-error';
     return isset($form_errors[$formId][$fieldName]) ? ($base . ' ' . $error) : $base;
}

/**
 * Gets ARIA describedby attribute value if error exists.
 */
function get_aria_describedby(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) {
        $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
        return ' aria-describedby="' . $errorId . '"';
    }
    return '';
}
// --- END Helper Functions ---


// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "PAHAL NGO Jalandhar | Empowering Communities, Inspiring Change";
$page_description = "'PAHAL' is a leading volunteer-driven youth NGO in Jalandhar, Punjab, fostering holistic development through impactful initiatives in health, education, environment, and communication skills.";
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health camps, blood donation, education, environment, e-waste, communication skills, personality development, non-profit";

// --- Initialize Form State Variables ---
// Initialize keys for expected forms to avoid warnings
$form_submissions['contact_form'] = []; $form_submissions['volunteer_form'] = [];
$form_messages['contact_form'] = []; $form_messages['volunteer_form'] = [];
$form_errors['contact_form'] = []; $form_errors['volunteer_form'] = [];

$csrf_token = generate_csrf_token(); // Generate token for the page


// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = $_POST['form_id'] ?? null;

    // Validate CSRF token for *any* submitted form
    if (!validate_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        log_message("[SECURITY] CSRF validation failed for form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        http_response_code(403);
        die("Security validation failed. Please refresh the page and try again.");
    }

    // --- Process Contact Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        // Honeypot check (applied after CSRF for efficiency)
        if (!empty($_POST[HONEYPOT_FIELD_NAME])) {
            log_message("[SPAM] Honeypot triggered on contact form. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            // Silently succeed or return a non-error to avoid signaling bot detection
             $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you! Your message has been received (spam filter triggered)."]; // Or a generic success message
            // Clear submission data
            $form_submissions[$form_id] = [];
        } else {
            // Sanitize Contact Form Data
            $contact_name = sanitize_string($_POST['name'] ?? '');
            $contact_email = sanitize_email($_POST['email'] ?? '');
            $contact_message = sanitize_string($_POST['message'] ?? '');

            $form_submissions[$form_id] = [
                'name' => $contact_name,
                'email' => $contact_email,
                'message' => $contact_message,
            ];

            // Validation Rules for Contact Form
            $rules = [
                'name' => 'required|alpha_space|minLength:2|maxLength:100',
                'email' => 'required|email|maxLength:255',
                'message' => 'required|minLength:10|maxLength:2000',
                 // Add reCAPTCHA rule here if implemented
                 // 'g-recaptcha-response' => 'required|recaptcha',
            ];

            $validation_errors = validate_data($form_submissions[$form_id], $rules);
            $form_errors[$form_id] = $validation_errors;

            if (empty($validation_errors)) {
                // Proceed with sending email if validation passes
                $to = RECIPIENT_EMAIL_CONTACT;
                $subject = "Website Contact Message from " . $contact_name;
                $body = "Name: " . $contact_name . "\n";
                $body .= "Email: " . $contact_email . "\n\n";
                $body .= "Message:\n" . $contact_message . "\n\n";
                $body .= "---\nIP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
                $body .= "Time: " . date('Y-m-d H:i:s T') . "\n";

                if (send_email($to, $subject, $body, $contact_email, $contact_name, "[Contact Form]", LOG_FILE_CONTACT, LOG_FILE_ERROR)) {
                    $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$contact_name}! Your message has been sent successfully. We will get back to you shortly."];
                    $form_submissions[$form_id] = []; // Clear form data on success
                } else {
                    $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, there was an issue sending your message. Please try again or contact us directly."];
                    // Error is logged within send_email
                }
            } else {
                // Validation errors
                 $errorCount = count($validation_errors);
                 $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) below to send your message."];
                 log_message("[Contact Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
                 // Submission data is kept for repopulating form
            }
        }
        $_SESSION['scroll_to'] = '#contact'; // Set scroll target

    } // --- End Contact Form Processing ---

    // --- Process Volunteer Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form';
        // Honeypot check
        if (!empty($_POST[HONEYPOT_FIELD_NAME])) {
            log_message("[SPAM] Honeypot triggered on volunteer form. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
             $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you! Your interest has been received (spam filter triggered)."]; // Or a generic success message
             $form_submissions[$form_id] = [];
        } else {
            // Sanitize Volunteer Form Data
            $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? '');
            $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? '');
            $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? '');
            $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? '');
            $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? '');
            $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? '');

            $form_submissions[$form_id] = [
                'volunteer_name' => $volunteer_name,
                'volunteer_email' => $volunteer_email,
                'volunteer_phone' => $volunteer_phone,
                'volunteer_area' => $volunteer_area,
                'volunteer_availability' => $volunteer_availability,
                'volunteer_message' => $volunteer_message,
            ];

            // Validation Rules for Volunteer Form
            $rules = [
                'volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100',
                 // require email OR phone
                'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255',
                'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20',
                'volunteer_area' => 'required|in:Health,Education,Environment,Communication,Events,Other', // Adjust options
                'volunteer_availability' => 'required|maxLength:255',
                'volunteer_message' => 'maxLength:1000', // Optional field
                // Add reCAPTCHA rule here if implemented
                // 'g-recaptcha-response' => 'required|recaptcha',
            ];

            $validation_errors = validate_data($form_submissions[$form_id], $rules);
            $form_errors[$form_id] = $validation_errors;

            if (empty($validation_errors)) {
                // Proceed with sending email if validation passes
                $to = RECIPIENT_EMAIL_VOLUNTEER;
                $subject = "New Volunteer Interest: " . $volunteer_name;
                $body = "A new volunteer interest form has been submitted.\n\n";
                $body .= "Name: " . $volunteer_name . "\n";
                $body .= "Email: " . (!empty($volunteer_email) ? $volunteer_email : "Not Provided") . "\n";
                $body .= "Phone: " . (!empty($volunteer_phone) ? $volunteer_phone : "Not Provided") . "\n";
                $body .= "Area of Interest: " . $volunteer_area . "\n";
                $body .= "Availability: " . $volunteer_availability . "\n";
                if (!empty($volunteer_message)) {
                    $body .= "Message:\n" . $volunteer_message . "\n";
                }
                $body .= "\n---\nIP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
                $body .= "Time: " . date('Y-m-d H:i:s T') . "\n";

                if (send_email($to, $subject, $body, $volunteer_email, $volunteer_name, "[Volunteer Form]", LOG_FILE_VOLUNTEER, LOG_FILE_ERROR)) {
                    $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your volunteer interest has been submitted. We will be in touch shortly."];
                    $form_submissions[$form_id] = []; // Clear form data on success
                } else {
                    $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, there was an issue submitting your volunteer interest. Please try again or contact us directly."];
                    // Error is logged within send_email
                }
            } else {
                // Validation errors
                 $errorCount = count($validation_errors);
                 $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) below to submit your interest."];
                 log_message("[Volunteer Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
                 // Submission data is kept for repopulating form
            }
        }
        $_SESSION['scroll_to'] = '#volunteer-section'; // Set scroll target

    } // --- End Volunteer Form Processing ---


     // --- Post-Processing & Redirect ---
     // Store form state in session for redirect
     $_SESSION['form_messages'] = $form_messages;
     $_SESSION['form_errors'] = $form_errors;

     // Keep submitted data only if errors occurred for the submitted form
     if (!empty($submitted_form_id) && !empty($form_errors[$submitted_form_id])) {
         $_SESSION['form_submissions'] = [$submitted_form_id => $form_submissions[$submitted_form_id]]; // Store only the data for the failed form
     } else {
        // If no errors, or no specific form_id submitted (shouldn't happen with form_id check), clear submission data
        unset($_SESSION['form_submissions']);
     }

     // Determine scroll target from session, then clear it
     $scrollTarget = $_SESSION['scroll_to'] ?? '';
     unset($_SESSION['scroll_to']);

     // Redirect to the same page, appending the scroll target fragment
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget);
     exit; // Terminate script after redirect

 } else {
     // --- GET Request: Retrieve session data ---
    // Retrieve state for all possible form IDs
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = ['contact_form' => [], 'volunteer_form' => []]; }
     if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = ['contact_form' => [], 'volunteer_form' => []]; }
     if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = ['contact_form' => [], 'volunteer_form' => []]; }

    $csrf_token = generate_csrf_token(); // Ensure token exists for GET request render

    // If there was a redirect with a scroll target, handle it with JS after the page loads
    // (Not implemented in PHP here, but usually done client-side)
 }

// --- Prepare Form Data for HTML Template ---
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message');

$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area');
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');

// --- Dummy Data (Keep Existing) ---
// Replace with real data source later
$news_items = [
    ['date' => '2023-10-27', 'title' => 'Successful Blood Donation Camp', 'excerpt' => 'PAHAL organized a high-impact blood donation camp...', 'link' => '#', 'image' => 'https://via.placeholder.com/400x300.png/059669/f9fafb?text=News+Image+1'],
    ['date' => '2023-10-20', 'title' => 'E-Waste Collection Drive Announced', 'excerpt' => 'Join us on [Date] for our next e-waste collection...', 'link' => 'e-waste.php', 'image' => 'https://via.placeholder.com/400x300.png/e11d48/f9fafb?text=News+Image+2'],
    ['date' => '2023-10-15', 'title' => 'Personality Development Workshop', 'excerpt' => 'Free workshop held for local youth focusing on communication...', 'link' => '#', 'image' => 'https://via.placeholder.com/400x300.png/6366f1/f9fafb?text=News+Image+3'],
];
$gallery_images = [
    ['src' => 'https://via.placeholder.com/800x600.png/059669/ffffff?text=Gallery+1', 'alt' => 'Gallery Image 1'],
    ['src' => 'https://via.placeholder.com/800x600.png/e11d48/ffffff?text=Gallery+2', 'alt' => 'Gallery Image 2'],
    ['src' => 'https://via.placeholder.com/800x600.png/6366f1/ffffff?text=Gallery+3', 'alt' => 'Gallery Image 3'],
    ['src' => 'https://via.placeholder.com/800x600.png/f59e0b/ffffff?text=Gallery+4', 'alt' => 'Gallery Image 4'],
     ['src' => 'https://via.placeholder.com/800x600.png/0ea5e9/ffffff?text=Gallery+5', 'alt' => 'Gallery Image 5'],
     ['src' => 'https://via.placeholder.com/800x600.png/16a34a/ffffff?text=Gallery+6', 'alt' => 'Gallery Image 6'],
];
$associates = [
    ['name' => 'Partner 1', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+1'],
    ['name' => 'Partner 2', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+2'],
    ['name' => 'Partner 3', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+3'],
    ['name' => 'Partner 4', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+4'],
    ['name' => 'Partner 5', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+5'],
    ['name' => 'Partner 6', 'img' => 'https://via.placeholder.com/150x80/ccc/333?text=Associate+6'],
];

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth"> <!-- Added dark class toggle via JS -->
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
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-og-enhanced.jpg"> <!-- CHANGE -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/images/pahal-twitter-enhanced.jpg"> <!-- CHANGE -->

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    <!-- Tailwind CSS CDN with Forms Plugin -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Poppins & Fira Code) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet"> <!-- Using fonts from e-waste page -->

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple Lightbox CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.css">

    <script>
        // Tailwind Config (Updated with e-waste palette and animations)
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        // Using fonts from e-waste page
                        sans: ['Open Sans', 'sans-serif'],
                        heading: ['Lato', 'sans-serif'],
                         // Removed 'mono' if not needed
                    },
                    colors: { // Define semantic names linked to CSS Variables
                        'theme-primary': 'var(--color-primary)', 'theme-primary-hover': 'var(--color-primary-hover)', 'theme-primary-focus': 'var(--color-primary-focus)',
                        'theme-secondary': 'var(--color-secondary)', 'theme-secondary-hover': 'var(--color-secondary-hover)',
                        'theme-accent': 'var(--color-accent)', 'theme-accent-hover': 'var(--color-accent-hover)',
                        'theme-success': 'var(--color-success)', 'theme-warning': 'var(--color-warning)', 'theme-info': 'var(--color-info)',
                        'theme-bg': 'var(--color-bg)', 'theme-surface': 'var(--color-surface)', 'theme-surface-alt': 'var(--color-surface-alt)',
                        'theme-text': 'var(--color-text)', 'theme-text-muted': 'var(--color-text-muted)', 'theme-text-heading': 'var(--color-text-heading)',
                        'theme-border': 'var(--color-border)', 'theme-border-light': 'var(--color-border-light)',
                        'theme-glow': 'var(--color-glow)',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out forwards', 'fade-in-down': 'fadeInDown 0.7s ease-out forwards', 'fade-in-up': 'fadeInUp 0.7s ease-out forwards',
                        'slide-in-left': 'slideInLeft 0.7s ease-out forwards', 'slide-in-right': 'slideInRight 0.7s ease-out forwards',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite', 'form-message-in': 'formMessageIn 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards', // Smoother ease
                        'bounce-subtle': 'bounceSubtle 2s infinite ease-in-out',
                        'gradient-bg': 'gradientBg 15s ease infinite', // Animated gradient (kept)
                        'glow-pulse': 'glowPulse 2.5s infinite alternate ease-in-out', // Glow animation (kept)
                         'icon-bounce': 'iconBounce 0.6s ease-out', // For button icons (kept)
                        // Added animations from e-waste page if they are different/needed
                        'fade-in-scale': 'fadeInScale 0.6s ease-out forwards', // from e-waste
                         'slide-up': 'slideUp 0.5s ease-out forwards', // from e-waste
                         'pulse-glow': 'pulseGlow 2s ease-in-out infinite', // from e-waste
                    },
                    keyframes: {
                         // Kept keyframes from second index for advanced animations
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInDown: { '0%': { opacity: '0', transform: 'translateY(-25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideInLeft: { '0%': { opacity: '0', transform: 'translateX(-40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        slideInRight: { '0%': { opacity: '0', transform: 'translateX(40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        formMessageIn: { '0%': { opacity: '0', transform: 'translateY(15px) scale(0.95)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
                        bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                        gradientBg: { '0%': { backgroundPosition: '0% 50%' }, '50%': { backgroundPosition: '100% 50%' }, '100%': { backgroundPosition: '0% 50%' } },
                        glowPulse: { '0%': { boxShadow: '0 0 5px 0px var(--color-glow)' }, '100%': { boxShadow: '0 0 20px 5px var(--color-glow)' } },
                        iconBounce: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-3px)' } },
                         // Added keyframes from e-waste page if different/needed
                         fadeInScale: { '0%': { opacity: 0, transform: 'scale(0.95)' }, '100%': { opacity: 1, transform: 'scale(1)' } }, // from e-waste
                         slideUp: { '0%': { opacity: 0, transform: 'translateY(20px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } }, // from e-waste
                         pulseGlow: { '0%, 100%': { opacity: 1, boxShadow: '0 0 0 0 rgba(255, 160, 0, 0.7)' }, '50%': { opacity: 0.8, boxShadow: '0 0 10px 5px rgba(255, 160, 0, 0)' } }, // from e-waste
                    },
                    boxShadow: { // Keep existing + add focus shadow variable
                        'card': '0 5px 15px rgba(0, 0, 0, 0.07)', 'card-dark': '0 8px 25px rgba(0, 0, 0, 0.3)',
                        'input-focus': '0 0 0 3px var(--color-primary-focus)',
                    },
                    container: { // Keep previous container settings
                      center: true, padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' }, screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1280px' }, // Use second index container sizes
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Theme Variables - Updated based on E-Waste Page Palette */
        :root { /* Light Theme - Based on E-Waste colors */
          --color-primary: #2E7D32; /* Green 800 */ --color-primary-hover: #1B5E20; /* Green 900 */ --color-primary-focus: rgba(46, 125, 50, 0.4); /* Green 800 alpha */
          --color-secondary: #6B7280; /* Neutral Medium (as a distinct theme color) */ --color-secondary-hover: #4B5563; /* Neutral Medium Darker */
          --color-accent: #FFA000; /* Amber 500 */ --color-accent-hover: #FF8F00; /* Amber 600 */
          --color-success: #16a34a; /* Green 600 */ --color-warning: #f59e0b; /* Amber 500 */ --color-info: #3B82F6; /* Blue 500 (from e-waste info box) */
          --color-bg: #F9FAFB; /* Secondary (e-waste background) */
          --color-surface: #ffffff; --color-surface-alt: #F3F4F6; /* Neutral light (from e-waste background) */
          --color-text: #374151; /* Neutral dark (from e-waste text) */ --color-text-muted: #6B7280; /* Neutral medium (from e-waste text) */ --color-text-heading: #1B5E20; /* Primary dark (from e-waste headings) */
          --color-border: #D1D5DB; /* Gray 300 */ --color-border-light: #E5E7EB; /* Gray 200 */
          --color-glow: rgba(255, 160, 0, 0.4); /* Accent glow color */
          --scrollbar-thumb: #9CA3AF; /* Gray 400 */ --scrollbar-track: #F3F4F6; /* Gray 100 */
          color-scheme: light;
        }

        html.dark { /* Dark Theme - Adjusted based on E-Waste palette */
           --color-primary: #6EE7B7; /* Emerald 300 */ --color-primary-hover: #34d399; /* Emerald 600 */ --color-primary-focus: rgba(110, 231, 183, 0.4);
           --color-secondary: #94A3B8; /* Slate 400 */ --color-secondary-hover: #CBD5E1; /* Slate 300 */
           --color-accent: #FCD34D; /* Amber 300 */ --color-accent-hover: #FBBF24; /* Amber 400 */
           --color-success: #86EFAC; /* Green 300 */ --color-warning: #FDE68A; /* Yellow 200 */ --color-info: #60A5FA; /* Blue 400 */
           --color-bg: #1F2937; /* Gray 800 */
           --color-surface: #374151; /* Gray 700 */ --color-surface-alt: #4B5563; /* Gray 600 */
           --color-text: #D1D5DB; /* Gray 300 */ --color-text-muted: #9CA3AF; /* Gray 400 */ --color-text-heading: #F3F4F6; /* Gray 100 */
           --color-border: #6B7280; /* Gray 500 */ --color-border-light: #4B5563; /* Gray 600 */
           --color-glow: rgba(252, 211, 77, 0.4); /* Accent glow color (dark) */
           --scrollbar-thumb: #6B7280; /* Gray 500 */ --scrollbar-track: #374151; /* Gray 700 */
           color-scheme: dark;
        }


        /* Custom Scrollbar (Kept) */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 5px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 5px; border: 2px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 75%, white); }


        @layer base {
            html { @apply scroll-smooth antialiased; }
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300 overflow-x-hidden pt-[70px]; } /* Added pt-[70px] here */
            h1, h2, h3, h4, h5, h6 { @apply font-heading font-bold text-theme-text-heading tracking-tight leading-tight; } /* Using Lato font from e-waste */
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-black; } /* Black weight for H1 as in e-waste */
            h2 { @apply text-3xl md:text-4xl font-bold mb-4; }
            h3 { @apply text-2xl md:text-3xl font-bold text-theme-primary mb-4 mt-5; } /* Primary color for H3 */
            h4 { @apply text-xl font-semibold text-theme-secondary mb-2; } /* Secondary color for H4 */
            p { @apply mb-5 leading-relaxed text-base max-w-prose text-theme-text-muted; } /* Muted text color for paragraphs */
            a { @apply text-theme-primary hover:text-theme-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary/50 rounded-sm; transition-colors duration-200; } /* Primary color for links */
            a:not(.btn):not(.nav-link):not(.footer-link) { /* More specific default link */
                 @apply underline decoration-theme-primary/50 hover:decoration-theme-primary decoration-1 underline-offset-2;
            }
            hr { @apply border-theme-border/40 my-12; }
            blockquote { @apply border-l-4 border-theme-accent bg-theme-surface-alt p-5 my-6 italic text-theme-text-muted shadow-inner rounded-r-md;} /* Accent border for blockquote */
            blockquote cite { @apply block not-italic mt-2 text-sm text-theme-text-muted/80;}
            address { @apply not-italic;}
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-offset-theme-surface ring-theme-accent/70 rounded-md; } /* Accent ring for focus-visible */
            .honeypot-field { @apply !absolute !-left-[9999px] !w-px !h-px !overflow-hidden !opacity-0; }

             /* Checkmark & Cross lists from e-waste */
             ul.checkmark-list { @apply list-none space-y-2 mb-6 pl-0; }
             ul.checkmark-list li { @apply flex items-start; }
             ul.checkmark-list li::before { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-green-500 mr-3 mt-1 text-sm flex-shrink-0; }
             ul.cross-list { @apply list-none space-y-2 mb-6 pl-0; }
             ul.cross-list li { @apply flex items-start; }
             ul.cross-list li::before { content: '\f00d'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-danger mr-3 mt-1 text-sm flex-shrink-0; }
             /* Table styles from e-waste */
            table { @apply w-full border-collapse text-left text-sm text-theme-text-muted; }
            thead { @apply bg-theme-primary/10; }
            th { @apply border border-theme-primary/20 px-4 py-2 font-semibold text-theme-primary; }
            td { @apply border border-theme-border px-4 py-2; }
            tbody tr:nth-child(odd) { @apply bg-white dark:bg-theme-surface; } /* Adjusted for dark mode */
            tbody tr:nth-child(even) { @apply bg-theme-surface-alt dark:bg-theme-surface-alt; } /* Adjusted for dark mode */
            tbody tr:hover { @apply bg-theme-primary/5; }
        }

        @layer components {
            .section-padding { @apply py-16 md:py-20 lg:py-24 px-4; }
            .section-title { @apply text-center mb-12 md:mb-16 text-theme-primary-hover; } /* Dark primary for titles */
            .section-title-underline::after { content: ''; @apply block w-24 h-1 bg-gradient-to-r from-theme-primary to-theme-accent mx-auto mt-4 rounded-full opacity-80; } /* Primary to Accent underline */

            /* Enhanced Buttons (Updated colors based on e-waste palette mapping) */
            .btn { @apply relative inline-flex items-center justify-center px-7 py-3 border border-transparent text-base font-medium rounded-full shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-theme-surface overflow-hidden transition-all duration-300 ease-out transform hover:-translate-y-1 hover:shadow-lg disabled:opacity-60 disabled:cursor-not-allowed group; } /* Rounded full as in e-waste */
            .btn::before { /* Subtle gradient overlay (kept) */ content: ''; @apply absolute inset-0 opacity-20 group-hover:opacity-30 transition-opacity duration-300; background: linear-gradient(rgba(255,255,255,0.5), rgba(255,255,255,0)); }
            .btn i { @apply mr-2 text-sm transition-transform duration-300 group-hover:scale-110; } /* Icon animation (kept) */
            .btn-primary { @apply text-white bg-theme-primary hover:bg-theme-primary-hover focus-visible:ring-theme-primary; } /* Maps to e-waste primary (green) */
            .btn-accent { @apply text-white bg-theme-accent hover:bg-theme-accent-hover focus-visible:ring-theme-accent; } /* Maps to e-waste accent (amber) */
            .btn-secondary { @apply text-theme-text-heading bg-theme-surface hover:bg-theme-surface-alt focus-visible:ring-theme-primary; } /* A light button option */
            .btn-outline { @apply bg-transparent border-2 border-theme-primary text-theme-primary hover:bg-theme-primary hover:text-white focus-visible:ring-theme-primary; } /* Maps to e-waste outline */
            .btn-outline.secondary { @apply !text-theme-secondary !border-theme-secondary hover:!bg-theme-secondary hover:!text-white focus-visible:ring-theme-secondary; } /* Outline secondary */
            .btn-icon { @apply p-2.5 rounded-full focus-visible:ring-offset-0; } /* Slightly larger icon button */

            /* Enhanced Cards (Kept styling, updated colors) */
            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/60 overflow-hidden relative transition-all duration-300; }
            .card-hover { @apply hover:shadow-xl dark:hover:shadow-2xl hover:border-theme-primary/50 hover:scale-[1.03] z-10; }
            .card::after { content: ''; @apply absolute inset-0 rounded-xl opacity-0 transition-opacity duration-300 pointer-events-none; box-shadow: 0 0 25px -5px var(--color-glow); }
            .card-hover:hover::after { @apply opacity-70; }

            /* Panel with Glassmorphism (Kept styling, updated colors) */
            .panel { @apply bg-theme-surface/75 dark:bg-theme-surface/70 backdrop-blur-xl border border-theme-border/40 rounded-2xl shadow-lg p-6 md:p-8; }

            /* Enhanced Forms (Kept styling, updated colors) */
            .form-label { @apply block mb-1.5 text-sm font-medium text-theme-text-muted; }
            .form-label.required::after { content: '*'; @apply text-theme-accent ml-0.5; }
            .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-bg dark:bg-theme-surface/60 border-theme-border placeholder-theme-text-muted/70 text-theme-text shadow-sm transition duration-200 ease-in-out focus:border-theme-primary focus:ring-2 focus:ring-theme-primary/50 focus:outline-none disabled:opacity-60; }
             /* Autofill styles (Kept) */
            input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus, textarea:-webkit-autofill, textarea:-webkit-autofill:hover, textarea:-webkit-autofill:focus, select:-webkit-autofill, select:-webkit-autofill:hover, select:-webkit-autofill:focus { -webkit-text-fill-color: var(--color-text); -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; transition: background-color 5000s ease-in-out 0s; }
            html.dark input:-webkit-autofill, html.dark input:-webkit-autofill:hover, html.dark input:-webkit-autofill:focus, html.dark textarea:-webkit-autofill, html.dark textarea:-webkit-autofill:hover, html.dark textarea:-webkit-autofill:focus, html.dark select:-webkit-autofill, html.dark select:-webkit-autofill:hover, html.dark select:-webkit-autofill:focus { -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; }
            select.form-input { /* Keep custom arrow if any, or rely on @tailwindcss/forms */ }
            textarea.form-input { @apply min-h-[120px] resize-y; } /* Kept resize-y from e-waste */
            .form-input-error { @apply !border-theme-accent ring-2 ring-theme-accent/50 focus:!border-theme-accent focus:!ring-theme-accent/50; } /* Accent color for error border/ring */
            .form-error-message { @apply text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1; } /* Error message style */
            .form-section { @apply card border-l-4 border-theme-primary mt-8; } /* Default border */
             #volunteer-section .form-section { @apply !border-theme-accent; } /* Volunteer form accent border */
             #contact .form-section { @apply !border-theme-secondary; } /* Contact form secondary border */


             /* Spinner (Kept) */
             .spinner { @apply inline-block animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-current; }

             /* Footer Styles (Kept styling, updated colors) */
             .footer-heading { @apply text-lg font-semibold text-white mb-5 relative pb-2; }
             .footer-heading::after { @apply content-[''] absolute bottom-0 left-0 w-10 h-0.5 bg-theme-primary rounded-full; } /* Primary underline */
             .footer-link { @apply text-gray-400 hover:text-white hover:underline text-sm transition-colors duration-200; }
             footer ul.footer-links li { @apply mb-1.5; }
             footer ul.footer-links li a { @apply footer-link inline-flex items-center gap-1.5; }
             footer ul.footer-links i { @apply opacity-70; }
             footer address p { @apply mb-3 flex items-start gap-3; }
             footer address i { @apply text-theme-primary mt-1 w-4 text-center flex-shrink-0; } /* Primary color for icons */
             .footer-social-icon { @apply text-xl transition duration-300 text-gray-400 hover:scale-110; }
             .footer-bottom { @apply border-t border-gray-700/50 pt-8 mt-12 text-center text-sm text-gray-500; }

            /* --- SECTION STYLES --- */
             #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/85 dark:bg-theme-bg/80 backdrop-blur-xl z-50 shadow-sm transition-all duration-300 border-b border-theme-border/30; min-height: 70px; }
             #main-header.scrolled { @apply shadow-lg bg-theme-surface/95 dark:bg-theme-bg/90 border-theme-border/50; }

              /* Navigation (Kept styling, updated colors) */
              #navbar ul li a { @apply text-theme-text-muted hover:text-theme-primary dark:hover:text-theme-primary font-medium py-2 relative transition duration-300 ease-in-out text-sm lg:text-base block lg:inline-block lg:py-0 px-3 lg:px-2 xl:px-3; }
              #navbar ul li a::after { content: ''; @apply absolute bottom-[-5px] left-0 w-0 h-[3px] bg-gradient-to-r from-theme-primary to-theme-accent opacity-0 transition-all duration-300 ease-out rounded-full group-hover:opacity-100 group-hover:w-full; } /* Primary to accent gradient underline */
              #navbar ul li a.active { @apply text-theme-primary font-semibold; }
              #navbar ul li a.active::after { @apply w-full opacity-100; }

              /* Mobile menu toggle (Kept) */
              .menu-toggle { @apply text-theme-text-muted hover:text-theme-primary transition-colors duration-200; }
              .menu-toggle span { @apply block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out; }
              .menu-toggle span:nth-child(1) { @apply mb-1.5; }
              .menu-toggle span:nth-child(3) { @apply mt-1.5; }
              .menu-toggle.open span:nth-child(1) { @apply transform rotate-45 translate-y-[6px]; }
              .menu-toggle.open span:nth-child(2) { @apply opacity-0; }
              .menu-toggle.open span:nth-child(3) { @apply transform -rotate-45 -translate-y-[6px]; }


              /* Mobile Navbar container (Kept) */
              #navbar { @apply w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-theme-surface dark:bg-theme-surface lg:bg-transparent shadow-xl lg:shadow-none lg:border-none border-t border-theme-border transition-all duration-500 ease-in-out; }
              #navbar.open { @apply block; max-height: calc(100vh - 70px); }

             /* Hero Section (Kept styling, updated colors/gradient) */
             #hero {
                 @apply animated-gradient-primary text-white min-h-[calc(100vh-70px)] flex items-center py-20 relative overflow-hidden;
             }
             #hero::before { /* Subtle overlay */
                 content: ''; @apply absolute inset-0 bg-black/20;
             }
             .hero-text h1 { @apply !text-white mb-6 drop-shadow-xl leading-tight font-black; } /* Black weight for H1 */
             .hero-logo img { @apply drop-shadow-2xl animate-glow-pulse bg-white/10; animation-duration: 4s; }
             .hero-scroll-indicator { @apply absolute bottom-8 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
             .hero-scroll-indicator a { @apply text-white/60 hover:text-white text-4xl animate-bounce-subtle; }

             /* Focus Areas (Kept styling, updated colors) */
             .focus-item { @apply card card-hover border-t-4 border-theme-primary bg-theme-surface p-6 md:p-8 text-center flex flex-col items-center; } /* Primary border color */
             .focus-item .icon { @apply text-5xl text-theme-primary mb-6 inline-block transition-transform duration-300 group-hover:scale-110 group-hover:animate-icon-bounce; } /* Primary icon color */
             .focus-item h3 { @apply text-xl text-theme-text-heading mb-3 transition-colors duration-300 group-hover:text-theme-primary; } /* Primary color on hover */
             .focus-item p { @apply text-sm text-theme-text-muted leading-relaxed flex-grow mb-4 text-center; }
             .focus-item .read-more-link { @apply relative block text-sm font-semibold text-theme-accent mt-auto opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:underline pt-2; } /* Accent color for read more */
             .focus-item .read-more-link::after { content: '\f061'; font-family: 'Font Awesome 6 Free'; @apply font-black text-xs ml-1.5 opacity-0 group-hover:opacity-100 translate-x-[-5px] group-hover:translate-x-0 transition-all duration-300 inline-block;}

             /* Objectives Section (Kept styling, updated colors/gradient) */
             #objectives { @apply bg-gradient-to-b from-theme-bg to-theme-surface-alt dark:from-theme-bg dark:to-theme-surface/30; }
             .objective-item { @apply bg-theme-surface/80 dark:bg-theme-surface/90 backdrop-blur-sm p-5 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:border-theme-accent border-l-4 border-transparent flex items-start space-x-4; } /* Accent border on hover */
             .objective-item i { @apply text-theme-primary group-hover:text-theme-accent transition-all duration-300 flex-shrink-0 w-6 text-center text-xl group-hover:rotate-[15deg]; } /* Primary icon, accent on hover */

             /* News Section (Kept styling, updated colors) */
             #news-section { @apply bg-theme-surface-alt dark:bg-theme-surface/50; }
             #news-section .news-card { @apply card card-hover flex flex-col; }
             #news-section .news-card img { @apply rounded-t-xl; }
             #news-section .news-card .news-content { @apply p-5 flex flex-col flex-grow; }
             #news-section .news-card .date { @apply block text-xs text-theme-text-muted mb-2; }
             #news-section .news-card h4 { @apply text-lg font-semibold text-theme-text-heading mb-2 leading-snug flex-grow; }
             #news-section .news-card h4 a { @apply text-inherit hover:text-theme-primary; } /* Primary color on hover */
             #news-section .news-card p { @apply text-sm text-theme-text-muted mb-4 leading-relaxed; }
             #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-theme-border-light; }

             /* Volunteer/Donate Sections (Kept styling, updated colors/gradients) */
              #volunteer-section { @apply animated-gradient-accent text-white relative; } /* Use accent gradient */
              #volunteer-section::before { content:''; @apply absolute inset-0 bg-black/25;}
              #donate-section { @apply animated-gradient-primary text-white relative; } /* Use primary gradient */
              #donate-section::before { content:''; @apply absolute inset-0 bg-black/25;}
              #volunteer-section .section-title, #donate-section .section-title { @apply !text-white relative z-10; }
              #volunteer-section .section-title::after { @apply !bg-theme-primary/70 relative z-10; } /* Primary underline for volunteer title */
              #donate-section .section-title::after { @apply !bg-theme-accent/70 relative z-10; } /* Accent underline for donate title */
              #volunteer-section form, #donate-section > div > div { @apply relative z-10; }
              #volunteer-section .panel { @apply !bg-black/30 dark:!bg-black/40 !border-white/20; }
              #volunteer-section .form-label { @apply !text-gray-100; }
              /* Define inverted input style (Kept) */
              .form-input-inverted { @apply !bg-white/10 !border-gray-400/40 !text-white placeholder:!text-gray-300/60 focus:!bg-white/20 focus:!border-white focus:!ring-white/50; }
              /* Apply inverted style where needed (Kept) */
              #volunteer-section .form-input { @apply form-input-inverted; }
               /* Override error state for inverted (Kept) */
              #volunteer-section .form-input-error { @apply !border-red-400 !ring-red-400/60 focus:!border-red-400 focus:!ring-red-400/60; }


             /* Gallery (Kept styling) */
             .gallery-item img { @apply transition-all duration-400 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110; }

             /* Associates (Kept styling, updated colors) */
             #associates { @apply bg-theme-surface-alt dark:bg-theme-surface/50; }
             .associate-logo img { @apply filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100; }
             .associate-logo p { @apply text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors; } /* Primary color on hover */

             /* Contact Section (Kept styling, updated colors/gradient) */
             #contact { @apply bg-gradient-to-b from-theme-surface-alt to-theme-bg dark:from-theme-surface/50 dark:to-theme-bg;}
             #contact .panel { @apply !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50; } /* Solid surface for form */
             .contact-info-item { @apply flex items-start gap-4; }
             .contact-info-item i { @apply text-theme-primary text-lg mt-1 w-5 text-center flex-shrink-0; } /* Primary color for icons */
             #contact .registration-info { @apply bg-theme-surface-alt dark:bg-theme-surface/80 p-4 rounded-md border border-theme-border text-xs text-theme-text-muted mt-8 shadow-inner;} /* Alt surface bg */

             /* Footer (Kept styling, updated colors) */
             footer { @apply bg-primary-dark dark:bg-black text-gray-300 pt-12 pb-8 mt-12 border-t-4 border-theme-accent dark:border-theme-accent; } /* Dark primary/black bg, Accent border */
             footer .footer-heading { @apply text-lg font-semibold text-white mb-5 relative pb-2; }
             footer .footer-heading::after { @apply content-[''] absolute bottom-0 left-0 w-10 h-0.5 bg-theme-primary rounded-full; } /* Primary underline */
             footer .footer-link { @apply text-gray-400 hover:text-white hover:underline text-sm transition-colors duration-200; }
             footer ul.footer-links li { @apply mb-1.5; }
             footer ul.footer-links li a { @apply footer-link inline-flex items-center gap-1.5; }
             footer ul.footer-links i { @apply opacity-70; }
             footer address p { @apply mb-3 flex items-start gap-3; }
             footer address i { @apply text-theme-primary mt-1 w-4 text-center flex-shrink-0; } /* Primary color for icons */
             .footer-social-icon { @apply text-xl transition duration-300 text-gray-400 hover:scale-110; }
             .footer-bottom { @apply border-t border-gray-700/50 pt-8 mt-12 text-center text-sm text-gray-500; }

            /* Back to Top (Kept styling, updated colors) */
             #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-theme-accent text-white shadow-lg hover:bg-theme-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-accent opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95; } /* Accent color */
             #back-to-top.visible { @apply opacity-100 visible; }

             /* Modal Styles (Kept styling, updated colors) */
             .modal-container { @apply fixed inset-0 bg-black/70 dark:bg-black/80 z-[100] hidden items-center justify-center p-4 backdrop-blur-md transition-opacity duration-300 ease-out; }
             .modal-container.flex { @apply flex; opacity: 1; }
             .modal-container.hidden { @apply hidden; opacity: 0; }

             .modal-box { @apply bg-theme-surface rounded-lg shadow-xl p-6 md:p-8 w-full max-w-lg text-left relative transform transition-all duration-300 scale-95 opacity-0; }
             .modal-container.flex .modal-box { @apply scale-100 opacity-100; }

             #bank-details-modal h3 { @apply !text-theme-primary !mt-0 mb-5 border-b border-theme-border pb-3; } /* Primary title */
             .modal-content-box { @apply bg-theme-surface-alt dark:bg-theme-surface/50 p-4 rounded-md border border-theme-border/50 space-y-2 my-5 text-sm text-theme-text-muted; } /* Muted text */
             .modal-content-box p strong { @apply font-medium text-theme-text-heading; } /* Heading text */
             .modal-footer-note { @apply text-xs text-theme-text-muted text-center mt-6 italic; }
             .close-button { @apply absolute top-4 right-4 text-theme-text-muted hover:text-theme-accent p-1 rounded-full transition-colors focus-visible:ring-theme-accent; } /* Accent color on hover */
        }

        @layer utilities {
            /* Animation Delays (Kept) */
             .delay-50 { animation-delay: 0.05s; } .delay-100 { animation-delay: 0.1s; } .delay-150 { animation-delay: 0.15s; } .delay-200 { animation-delay: 0.2s; } .delay-300 { animation-delay: 0.3s; } .delay-400 { animation-delay: 0.4s; } .delay-500 { animation-delay: 0.5s; } .delay-700 { animation-delay: 0.7s; }

             /* Animation on Scroll Classes (Kept) */
             .animate-on-scroll { opacity: 0; transition: opacity 0.8s cubic-bezier(0.165, 0.84, 0.44, 1), transform 0.8s cubic-bezier(0.165, 0.84, 0.44, 1); }
             .animate-on-scroll.fade-in-up { transform: translateY(40px); }
             .animate-on-scroll.fade-in-left { transform: translateX(-50px); }
             .animate-on-scroll.fade-in-right { transform: translateX(50px); }
             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }

             /* Animated Gradient Background Utility (Updated colors) */
             .animated-gradient-primary {
                background: linear-gradient(-45deg, var(--color-primary), var(--color-secondary), var(--color-info), var(--color-primary)); /* Primary, Secondary, Info */
                background-size: 400% 400%;
                animation: gradientBg 18s ease infinite;
             }
             .animated-gradient-accent { /* Used for Volunteer section */
                 background: linear-gradient(-45deg, var(--color-accent), var(--color-warning), var(--color-primary), var(--color-accent)); /* Accent, Warning, Primary */
                 background-size: 400% 400%;
                 animation: gradientBg 20s ease infinite;
             }

            /* Form Error Class (Already using semantic color) */
            /* .form-input-error { @apply !border-theme-accent ring-2 ring-theme-accent/50 focus:!border-theme-accent focus:!ring-theme-accent/50; } */


             /* Added e-waste animations to utilities */
             .animate-delay-100 { animation-delay: 0.1s; }
             .animate-delay-200 { animation-delay: 0.2s; }
             .animate-delay-300 { animation-delay: 0.3s; }
        }
    </style>
    <!-- Schema.org JSON-LD (Keep - Update details) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org", "@type": "NGO", "name": "PAHAL NGO",
      "url": "https://your-pahal-domain.com/", "logo": "https://your-pahal-domain.com/icon.webp",
      "description": "PAHAL is a voluntary youth organization in Jalandhar dedicated to holistic personality development, community service, and fostering positive change in health, education, environment, and communication.",
      "address": {"@type": "PostalAddress", "streetAddress": "36 New Vivekanand Park, Maqsudan", "addressLocality": "Jalandhar", "addressRegion": "Punjab", "postalCode": "144008", "addressCountry": "IN" },
      "contactPoint": [
           { "@type": "ContactPoint", "telephone": "+91-181-2672784", "contactType": "general" },
           { "@type": "ContactPoint", "telephone": "+91-9855614230", "contactType": "general" },
           { "@type": "ContactPoint", "email": "engage@pahal-ngo.org", "contactType": "customer service" }
      ],
      "sameAs": [
           "https://www.instagram.com/yourpahal", /* CHANGE */
           "https://www.facebook.com/yourpahal", /* CHANGE */
           "https://twitter.com/yourpahal", /* CHANGE */
           "https://www.linkedin.com/company/yourpahal" /* CHANGE */
       ]
    }
    </script>
</head>
<body class="bg-theme-bg text-theme-text font-sans">

<!-- Header -->
<header id="main-header" class="py-2 md:py-0">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <div class="logo flex-shrink-0 py-2">
             <a href="#hero" aria-label="PAHAL NGO Home" class="text-3xl md:text-4xl font-black text-theme-accent dark:text-red-400 font-heading leading-none flex items-center transition-opacity hover:opacity-80">
                <img src="icon.webp" alt="" class="h-9 w-9 mr-2 inline object-contain animate-pulse-slow" aria-hidden="true"> <!-- Subtle pulse on logo -->
                PAHAL
             </a>
             <p class="text-xs text-theme-text-muted italic ml-11 -mt-1.5 hidden sm:block">An Endeavour for a Better Tomorrow</p>
        </div>
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-theme-primary rounded">
            <span class="sr-only">Open menu</span> <span></span> <span></span> <span></span>
        </button>
        <nav id="navbar" aria-label="Main Navigation" class="navbar-container"> <!-- Use class for JS targeting -->
            <ul class="flex flex-col lg:flex-row lg:items-center lg:space-x-5 xl:space-x-6 py-4 lg:py-0 px-4 lg:px-0">
                <li><a href="#hero" class="nav-link active group">Home</a></li>
                <li><a href="#profile" class="nav-link group">Profile</a></li>
                <li><a href="#objectives" class="nav-link group">Objectives</a></li>
                <li><a href="#areas-focus" class="nav-link group">Focus Areas</a></li>
                <li><a href="#news-section" class="nav-link group">News</a></li>
                <li><a href="#volunteer-section" class="nav-link group">Get Involved</a></li>
                <li><a href="blood-donation.php" class="nav-link group">Blood Drive</a></li>
                <li><a href="e-waste.php" class="nav-link group">E-Waste</a></li>
                <li><a href="#contact" class="nav-link group">Contact</a></li>
                <li> <!-- Theme Toggle Button -->
                     <button id="theme-toggle" type="button" title="Toggle theme" class="btn-icon text-theme-text-muted hover:text-theme-primary hover:bg-theme-primary/10 dark:hover:bg-theme-primary/20 transition-colors duration-200 ml-2 p-2.5">
                         <i class="fas fa-moon text-lg block dark:hidden" id="theme-toggle-dark-icon"></i>
                         <i class="fas fa-sun text-lg hidden dark:block" id="theme-toggle-light-icon"></i>
                     </button>
                </li>
            </ul>
        </nav>
    </div>
</header>

<main>
    <!-- Hero Section -->
    <section id="hero" class="relative">
        <!-- Background overlay removed, using animated gradient directly -->
        <div class="container mx-auto relative z-10 flex flex-col-reverse lg:flex-row items-center justify-between gap-10 text-center lg:text-left">
             <div class="hero-text flex-1 order-2 lg:order-1 flex flex-col items-center lg:items-start justify-center text-center lg:text-left animate-on-scroll fade-in-left">
              <h1 class="font-heading !text-white"> <!-- Ensure white text -->
                 Empowering Communities,<br> Inspiring Change
              </h1>
              <p class="text-lg lg:text-xl my-6 max-w-xl mx-auto lg:mx-0 text-gray-100 drop-shadow-md">
                Join PAHAL, a youth-driven NGO in Jalandhar, committed to holistic development and tangible social impact through dedicated action in health, education, environment, and communication.
              </p>
              <div class="mt-8 flex flex-wrap justify-center lg:justify-start gap-4">
                <a href="#profile" class="btn btn-secondary text-base md:text-lg shadow-lg !bg-white !text-theme-secondary hover:!bg-gray-100"><i class="fas fa-info-circle"></i>Discover More</a>
                 <a href="#volunteer-section" class="btn btn-primary text-base md:text-lg shadow-lg"><i class="fas fa-hands-helping"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto animate-on-scroll fade-in-right delay-200">
                 <img src="icon.webp" alt="PAHAL NGO Large Logo Icon" class="mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-60 lg:h-60 rounded-full shadow-2xl bg-white/25 p-3 backdrop-blur-sm"> <!-- Increased padding/bg alpha -->
            </div>
        </div>
        <div class="hero-scroll-indicator">
             <a href="#profile" aria-label="Scroll down"><i class="fas fa-chevron-down"></i></a>
        </div>
    </section>

    <!-- Profile Section -->
    <section id="profile" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto animate-on-scroll fade-in-up">
             <h2 class="section-title section-title-underline">Our Profile & Vision</h2>
             <div class="grid md:grid-cols-5 gap-12 items-center mt-16">
                 <div class="md:col-span-3 profile-text">
                    <h3 class="text-2xl mb-4 !text-theme-text-heading">Who We Are</h3>
                    <p class="mb-6 text-theme-text-muted text-lg">'PAHAL' (Initiative) stands as a testament to collective action... driven by a singular vision: to catalyze perceptible, positive transformation within our social fabric.</p>
                    <blockquote><p>"PAHAL is an endeavour for a Better Tomorrow"</p></blockquote>
                    <h3 class="text-2xl mb-4 mt-10 !text-theme-text-heading">Our Core Vision</h3>
                    <p class="text-theme-text-muted text-lg">We aim to cultivate <strong class="text-theme-primary font-medium">Holistic Personality Development</strong>... thereby building a more compassionate and equitable world.</p>
                 </div>
                 <div class="md:col-span-2 profile-image animate-on-scroll fade-in-right delay-200">
                    <img src="https://via.placeholder.com/500x600.png/2E7D32/F9FAFB?text=PAHAL+Vision" alt="PAHAL NGO team vision" class="rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px] border-4 border-white dark:border-theme-surface"> <!-- Updated placeholder image colors -->
                </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section -->
     <section id="objectives" class="section-padding bg-gradient-to-b from-theme-bg to-theme-surface-alt dark:from-theme-bg dark:to-theme-surface/30">
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Our Guiding Objectives</h2>
             <div class="max-w-6xl mx-auto grid md:grid-cols-2 lg:grid-cols-3 gap-6 mt-16">
                 <div class="objective-item group animate-on-scroll fade-in-up"><i class="fas fa-users"></i><p>To collaborate genuinely <strong>with and among the people</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-100"><i class="fas fa-people-carry"></i><p>To engage in <strong>creative & constructive social action</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-200"><i class="fas fa-lightbulb"></i><p>To enhance knowledge of <strong>self & community realities</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-300"><i class="fas fa-seedling"></i><p>To apply scholarship for <strong>mitigating social problems</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-400"><i class="fas fa-tools"></i><p>To gain and apply skills in <strong>humanity development</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-500"><i class="fas fa-recycle"></i><p>To promote <strong>sustainable practices</strong> & awareness.</p></div>
            </div>
        </div>
    </section>

    <!-- Areas of Focus Section -->
    <section id="areas-focus" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Key Focus Areas</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-16">
                 <!-- Health -->
                 <a href="blood-donation.php" title="Health Initiatives" class="focus-item group animate-on-scroll fade-in-up card-hover">
                     <span class="icon"><i class="fas fa-heart-pulse"></i></span> <h3>Health & Wellness</h3>
                     <p>Prioritizing community well-being via awareness campaigns, blood drives, and promoting healthy lifestyles.</p>
                     <span class="read-more-link">Blood Donation Program</span>
                 </a>
                 <!-- Education -->
                 <div class="focus-item group animate-on-scroll fade-in-up delay-100 card-hover">
                     <span class="icon"><i class="fas fa-user-graduate"></i></span> <h3>Education & Skilling</h3>
                     <p>Empowering youth by fostering ethical foundations, essential life skills, and professional readiness.</p>
                     <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span>
                  </div>
                 <!-- Environment -->
                 <a href="e-waste.php" title="E-waste Recycling" class="focus-item group animate-on-scroll fade-in-up delay-200 card-hover">
                      <span class="icon"><i class="fas fa-leaf"></i></span> <h3>Environment</h3>
                      <p>Championing stewardship through plantation drives, waste management, and e-waste recycling.</p>
                      <span class="read-more-link">E-Waste Program</span>
                 </a>
                 <!-- Communication -->
                 <div class="focus-item group animate-on-scroll fade-in-up delay-300 card-hover">
                     <span class="icon"><i class="fas fa-comments"></i></span> <h3>Communication Skills</h3>
                     <p>Enhancing verbal, non-verbal, and presentation abilities in youth via interactive programs.</p>
                      <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span>
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section -->
     <section id="volunteer-section" class="section-padding animated-gradient-accent text-white relative">
        <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div> <!-- Darkening Overlay -->
        <div class="container mx-auto relative z-10">
             <h2 class="section-title !text-white section-title-underline after:!bg-theme-primary">Join the PAHAL Movement</h2> <!-- Primary underline for Volunteer title -->
            <div class="grid lg:grid-cols-2 gap-12 items-center mt-16">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left">
                    <h3 class="text-3xl lg:text-4xl font-bold mb-4 text-white leading-snug drop-shadow-md">Make a Difference, Volunteer With Us</h3>
                    <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed drop-shadow-sm">PAHAL welcomes passionate individuals... Your time, skills, and dedication are invaluable assets.</p>
                    <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed drop-shadow-sm">Volunteering offers a rewarding experience... Express your interest below!</p>
                     <div class="mt-10 flex flex-wrap justify-center lg:justify-start gap-4">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-theme-primary"><i class="fas fa-phone-alt"></i>Contact Directly</a>
                         <!-- <a href="volunteer-opportunities.php" class="btn !bg-white !text-theme-secondary hover:!bg-gray-100"><i class="fas fa-list-alt"></i>View Opportunities</a> -->
                     </div>
                 </div>
                 <!-- Volunteer Sign-up Form -->
                 <div class="panel !bg-black/30 dark:!bg-black/40 !border-white/20 animate-on-scroll fade-in-right delay-100">
                     <h3 class="text-2xl mb-6 text-white font-semibold text-center">Register Your Volunteer Interest</h3>
                     <?= get_form_status_html('volunteer_form') ?>
                    <form id="volunteer-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5" novalidate> <!-- Add novalidate for custom validation -->
                        <!-- Hidden Fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="volunteer_form">
                        <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_volunteer">Keep Blank</label>
                            <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>
                        <!-- Form Fields (using updated classes) -->
                        <div>
                            <label for="volunteer_name" class="form-label !text-gray-200 required">Full Name</label>
                            <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>>
                            <?= get_field_error_html('volunteer_form', 'volunteer_name') ?>
                        </div>
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label for="volunteer_email" class="form-label !text-gray-200">Email</label>
                                <input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>>
                                <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                            </div>
                            <div>
                                <label for="volunteer_phone" class="form-label !text-gray-200">Phone</label>
                                <input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>>
                                <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                            </div>
                        </div>
                        <p class="text-xs text-gray-300 -mt-3" id="volunteer_contact_note">Provide Email or Phone.</p>
                        <div>
                            <label for="volunteer_area" class="form-label !text-gray-200 required">Area of Interest</label>
                            <select id="volunteer_area" name="volunteer_area" required class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>>
                                <option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : ''?>>-- Select --</option>
                                <option value="Health" <?= $volunteer_form_area_value == 'Health' ? 'selected' : ''?>>Health</option>
                                <option value="Education" <?= $volunteer_form_area_value == 'Education' ? 'selected' : ''?>>Education</option>
                                <option value="Environment" <?= $volunteer_form_area_value == 'Environment' ? 'selected' : ''?>>Environment</option>
                                <option value="Communication" <?= $volunteer_form_area_value == 'Communication' ? 'selected' : ''?>>Communication</option>
                                <option value="Events" <?= $volunteer_form_area_value == 'Events' ? 'selected' : ''?>>Events</option>
                                <option value="Other" <?= $volunteer_form_area_value == 'Other' ? 'selected' : ''?>>Other</option>
                            </select>
                            <?= get_field_error_html('volunteer_form', 'volunteer_area') ?>
                        </div>
                        <div>
                            <label for="volunteer_availability" class="form-label !text-gray-200 required">Availability</label>
                            <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>>
                            <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                        </div>
                        <div>
                            <label for="volunteer_message" class="form-label !text-gray-200">Message (Optional)</label>
                            <textarea id="volunteer_message" name="volunteer_message" rows="3" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Your motivation or skills..." <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea>
                            <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                        </div>
                        <button type="submit" class="btn btn-accent w-full sm:w-auto"><span class="button-text flex items-center"><i class="fas fa-paper-plane"></i>Submit Interest</span><span class="spinner hidden ml-2"></span></button>
                    </form>
                 </div>
            </div>
        </div>
    </section>


    <!-- News & Events Section -->
    <section id="news-section" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Latest News & Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-16">
                <?php if (!empty($news_items)): ?>
                    <?php foreach ($news_items as $index => $item): ?>
                    <div class="news-card group animate-on-scroll fade-in-up delay-<?= ($index * 100) ?> card-hover">
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="block aspect-[16/10] overflow-hidden rounded-t-xl" title="Read more">
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                        </a>
                        <div class="news-content">
                             <span class="date"><i class="far fa-calendar-alt mr-1 opacity-70"></i><?= date('M j, Y', strtotime($item['date'])) ?></span>
                             <h4 class="my-2"><a href="<?= htmlspecialchars($item['link']) ?>" class="group-hover:!text-theme-primary"><?= htmlspecialchars($item['title']) ?></a></h4> <!-- Primary color on hover -->
                             <p><?= htmlspecialchars($item['excerpt']) ?></p>
                              <div class="read-more-action">
                                  <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-outline secondary !text-sm !py-1 !px-3">Read More <i class="fas fa-arrow-right text-xs ml-1 group-hover:translate-x-1 transition-transform"></i></a>
                              </div>
                         </div>
                    </div>
                    <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-theme-text-muted md:col-span-2 lg:col-span-3">No recent news.</p>
                 <?php endif; ?>
            </div>
            <div class="text-center mt-12"><a href="/news-archive.php" class="btn btn-primary"><i class="far fa-newspaper"></i>View News Archive</a></div> <!-- Primary button -->
        </div>
    </section>

    <!-- Gallery Section -->
    <section id="gallery-section" class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Glimpses of Our Work</h2>
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4 mt-16">
                    <?php foreach ($gallery_images as $index => $image): ?>
                    <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block aspect-video rounded-lg overflow-hidden shadow-md group animate-on-scroll fade-in-up delay-<?= ($index * 50) ?> transition-all duration-300 hover:shadow-xl hover:scale-105">
                         <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110">
                     </a>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-8 text-theme-text-muted italic">Click images to view larger.</p>
            <?php else: ?>
                 <p class="text-center text-theme-text-muted">Gallery coming soon.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Associates Section -->
    <section id="associates" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-theme-text-muted mb-16">Collaboration amplifies impact. We value the support of these esteemed organizations.</p>
             <div class="flex flex-wrap justify-center items-center gap-x-10 md:gap-x-16 gap-y-10"> <!-- Increased gap -->
                <?php foreach ($associates as $index => $associate): ?>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll fade-in-up delay-<?= ($index * 50) ?>">
                    <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-3 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100">
                    <p class="text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p> <!-- Primary color on hover -->
                 </div>
                 <?php endforeach; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <section id="donate-section" class="section-padding text-center relative overflow-hidden animated-gradient-primary">
         <div class="absolute inset-0 bg-black/35 mix-blend-multiply z-0"></div>
         <div class="container mx-auto relative z-10">
             <i class="fas fa-donate text-4xl text-white bg-theme-accent p-4 rounded-full shadow-lg mb-6 inline-block animate-bounce-subtle"></i> <!-- Accent icon bg -->
             <h2 class="section-title !text-white section-title-underline after:!bg-theme-accent/70"><span class="drop-shadow-md">Support Our Initiatives</span></h2> <!-- Accent underline -->
            <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto mb-8 text-lg leading-relaxed drop-shadow">Your contribution fuels our mission in health, education, and environment within Jalandhar.</p>
            <p class="text-gray-200 dark:text-gray-300 bg-black/25 dark:bg-black/50 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-10 backdrop-blur-sm border border-white/20">Donations Tax Exempt under Sec 80G.</p>
            <div class="space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center items-center gap-4">
                 <a href="#contact" class="btn btn-secondary !bg-white !text-theme-primary hover:!bg-gray-100 shadow-xl"><i class="fas fa-info-circle"></i> Donation Inquiries</a> <!-- Light button, primary text -->
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-theme-primary shadow-xl" data-modal-target="bank-details-modal"><i class="fas fa-university"></i>View Bank Details</button>
            </div>
        </div>
     </section>

    <!-- Contact Section -->
     <section id="contact" class="section-padding bg-gradient-to-b from-theme-surface-alt to-theme-bg dark:from-theme-surface/50 dark:to-theme-bg">
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Connect With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-theme-text-muted mb-16">Questions, suggestions, partnerships, or just want to learn more? We're here to connect.</p>
             <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                 <!-- Contact Details -->
                 <div class="lg:col-span-2 animate-on-scroll fade-in-left">
                     <h3 class="text-2xl mb-6 font-semibold !text-theme-text-heading">Contact Information</h3>
                     <address class="space-y-6 text-theme-text-muted text-base mb-10">
                        <div class="contact-info-item"><i class="fas fa-map-marker-alt"></i><div><span>Our Office:</span> 36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008</div></div>
                        <div class="contact-info-item"><i class="fas fa-phone-alt"></i><div><span>Phone Lines:</span> <a href="tel:+911812672784">181-267-2784</a><br><a href="tel:+919855614230">98556-14230</a></div></div>
                        <div class="contact-info-item"><i class="fas fa-envelope"></i><div><span>Email Us:</span> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></div></div>
                     </address>
                    <div class="mb-10 pt-8 border-t border-theme-border/50"><h4 class="text-lg font-semibold text-theme-secondary mb-4">Follow Our Journey</h4><div class="flex space-x-5 social-icons"><!-- Social Icons --></div></div>
                     <div class="mb-10 pt-8 border-t border-theme-border/50"><h4 class="text-lg font-semibold text-theme-secondary mb-4">Visit Us</h4><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3406.124022090013!2d75.5963185752068!3d31.339546756899223!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b7f02a86379%3A0x4c61457c43d15b97!2s36%2C%20New%20Vivekanand%20Park%2C%20Maqsudan%2C%20Jalandhar%2C%20Punjab%20144008!5e0!3m2!1sen!2sin!4v1700223266482!5m2!1sen!2sin" width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" class="rounded-lg shadow-md border border-theme-border/50"></iframe></div>
                     <div class="registration-info"><h4 class="text-sm font-semibold text-theme-primary dark:text-theme-primary mb-2">Registration</h4><!-- Reg Details --></div>
                 </div>
                <!-- Contact Form -->
                <div class="lg:col-span-3 panel !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50 animate-on-scroll fade-in-right delay-100">
                    <h3 class="text-2xl mb-8 font-semibold !text-theme-text-heading text-center lg:text-left">Send Us a Message</h3>
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6" novalidate> <!-- Add novalidate -->
                        <!-- Hidden fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                        <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_contact">Leave this field blank</label>
                            <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>
                        <!-- Form Fields -->
                        <div>
                            <label for="contact_name" class="form-label required">Name</label>
                            <input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="<?= get_field_error_class('contact_form', 'name') ?>" placeholder="e.g., Jane Doe" aria-required="true" <?= get_aria_describedby('contact_form', 'name') ?>>
                            <?= get_field_error_html('contact_form', 'name') ?>
                        </div>
                        <div>
                            <label for="contact_email" class="form-label required">Email</label>
                            <input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="<?= get_field_error_class('contact_form', 'email') ?>" placeholder="e.g., jane.doe@example.com" aria-required="true" <?= get_aria_describedby('contact_form', 'email') ?>>
                            <?= get_field_error_html('contact_form', 'email') ?>
                        </div>
                        <div>
                            <label for="contact_message" class="form-label required">Message</label>
                            <textarea id="contact_message" name="message" rows="5" required class="<?= get_field_error_class('contact_form', 'message') ?>" placeholder="Your thoughts..." aria-required="true" <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea>
                            <?= get_field_error_html('contact_form', 'message') ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-full sm:w-auto" id="contact-submit-button"><span class="button-text flex items-center"><i class="fas fa-paper-plane"></i>Send Message</span><span class="spinner hidden ml-2"></span></button>
                    </form>
                 </div>
            </div>
        </div>
    </section>

    <!-- Donation Modal -->
     <div id="bank-details-modal" class="modal-container" aria-labelledby="modal-title" role="dialog" aria-modal="true">
      <div class="modal-box">
         <button type="button" class="close-button" aria-label="Close modal" data-modal-close="bank-details-modal"><i class="fas fa-times fa-lg"></i></button>
         <h3 id="modal-title">Bank Transfer Details</h3>
        <p>Use the following details for direct bank transfers. Mention "Donation" in the description.</p>
         <div class="modal-content-box">
            <p><strong>Account Name:</strong> PAHAL (Regd.)</p>
            <p><strong>Account Number:</strong> [YOUR_BANK_ACCOUNT_NUMBER]</p> <!-- REPLACE -->
             <p><strong>Bank Name:</strong> [YOUR_BANK_NAME]</p> <!-- REPLACE -->
             <p><strong>Branch:</strong> [YOUR_BANK_BRANCH]</p> <!-- REPLACE -->
             <p><strong>IFSC Code:</strong> [YOUR_IFSC_CODE]</p> <!-- REPLACE -->
        </div>
        <p class="modal-footer-note">For queries or receipts, contact us. Thank you!</p>
      </div>
    </div>

</main>

<!-- Footer -->
<footer class="bg-primary-dark dark:bg-black text-gray-300 pt-12 pb-8 mt-12 border-t-4 border-theme-accent dark:border-theme-accent">
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12 text-center md:text-left">
            <!-- Footer About -->
            <div>
                <h4 class="footer-heading">About PAHAL</h4>
                <a href="#hero" class="inline-block mb-3"><img src="icon.webp" alt="PAHAL Icon" class="w-14 h-14 rounded-full bg-white p-1 shadow-md mx-auto md:mx-0"></a>
                <p class="footer-text">Jalandhar NGO fostering holistic growth & community service.</p>
                <p class="text-xs text-gray-500">Reg No: 737 | 80G & 12A</p>
                 <div class="mt-4 flex justify-center md:justify-start space-x-4">
                     <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram" class="footer-social-icon hover:text-[#E1306C]"><i class="fab fa-instagram"></i></a>
                     <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook" class="footer-social-icon hover:text-[#1877F2]"><i class="fab fa-facebook-f"></i></a>
                     <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter" class="footer-social-icon hover:text-[#1DA1F2]"><i class="fab fa-twitter"></i></a>
                     <a href="..." target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn" class="footer-social-icon hover:text-[#0A66C2]"><i class="fab fa-linkedin-in"></i></a>
                 </div>
            </div>
             <!-- Footer Quick Links -->
             <div>
                 <h4 class="footer-heading">Explore</h4>
                 <ul class="footer-links space-y-1.5 text-sm columns-2 md:columns-1">
                     <li><a href="#profile"><i class="fas fa-chevron-right"></i>Profile</a></li>
                     <li><a href="#objectives"><i class="fas fa-chevron-right"></i>Objectives</a></li>
                     <li><a href="#areas-focus"><i class="fas fa-chevron-right"></i>Focus Areas</a></li>
                     <li><a href="#news-section"><i class="fas fa-chevron-right"></i>News</a></li>
                     <li><a href="blood-donation.php"><i class="fas fa-chevron-right"></i>Blood Drive</a></li>
                     <li><a href="e-waste.php"><i class="fas fa-chevron-right"></i>E-Waste</a></li>
                     <li><a href="#volunteer-section"><i class="fas fa-chevron-right"></i>Volunteer</a></li>
                     <li><a href="#donate-section"><i class="fas fa-chevron-right"></i>Donate</a></li>
                     <li><a href="#contact"><i class="fas fa-chevron-right"></i>Contact</a></li>
                     <li><a href="/privacy-policy.php"><i class="fas fa-chevron-right"></i>Privacy</a></li>
                 </ul>
             </div>
             <!-- Footer Contact -->
             <div>
                 <h4 class="footer-heading">Reach Us</h4>
                 <address>
                     <p><i class="fas fa-map-marker-alt"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008</p>
                     <p><i class="fas fa-phone-alt"></i> <a href="tel:+911812672784">181-267-2784</a></p>
                     <p><i class="fas fa-mobile-alt"></i> <a href="tel:+919855614230">98556-14230</a></p>
                     <p><i class="fas fa-envelope"></i> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></p>
                 </address>
             </div>
             <!-- Footer Inspiration -->
             <div>
                  <h4 class="footer-heading">Inspiration</h4>
                 <blockquote>"The best way to find yourself is to lose yourself in the service of others."<cite>- Mahatma Gandhi</cite></blockquote>
             </div>
        </div>
        <!-- Footer Bottom -->
        <div class="footer-bottom"><p>© <?= $current_year ?> PAHAL (Regd.), Jalandhar. All Rights Reserved.</p></div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="back-to-top" aria-label="Back to Top" title="Back to Top" class="back-to-top-button">
   <i class="fas fa-arrow-up text-lg"></i>
</button>

<!-- Simple Lightbox JS -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript (Keep existing logic) -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Elements
        const themeToggleBtn = document.getElementById('theme-toggle');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const htmlElement = document.documentElement;
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const navbar = document.getElementById('navbar');
        const navLinks = document.querySelectorAll('#navbar a.nav-link[href^="#"]');
        const header = document.getElementById('main-header');
        const backToTopButton = document.getElementById('back-to-top');
        const sections = document.querySelectorAll('main section[id]');
        let headerHeight = header?.offsetHeight ?? 70; // Default if header not found

        // --- Theme Toggle ---
        const applyTheme = (theme) => {
            if (theme === 'dark') {
                htmlElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                darkIcon?.classList.add('hidden');
                lightIcon?.classList.remove('hidden');
            } else {
                htmlElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                darkIcon?.classList.remove('hidden');
                lightIcon?.classList.add('hidden');
            }
        };
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        // Apply theme based on stored preference or system preference
        const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
        applyTheme(initialTheme);

        themeToggleBtn?.addEventListener('click', () => {
            applyTheme(htmlElement.classList.contains('dark') ? 'light' : 'dark');
        });

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
             if (!localStorage.getItem('theme')) { // Only react to system change if no explicit preference is set
                 applyTheme(e.matches ? 'dark' : 'light');
            }
        });


        // --- Header & Layout ---
        let scrollTimeout;
        const updateLayout = () => {
            headerHeight = header?.offsetHeight ?? 70; // Recalculate header height
            // Add scrolled class to header
            if (header && window.scrollY > 50) { // Adjust scroll threshold
                 header.classList.add('scrolled');
             } else if (header) {
                 header.classList.remove('scrolled');
             }

             // Show/hide back to top button
             if (backToTopButton) {
                 if (window.scrollY > (window.innerHeight * 0.75)) { // Show after scrolling down 75% of viewport height
                     backToTopButton.classList.add('visible');
                 } else {
                     backToTopButton.classList.remove('visible');
                 }
             }

             // Close mobile menu on scroll if open (optional)
             if (isMobileMenuOpen) {
                 // toggleMobileMenu(true); // Pass true to force close
             }
        };

        updateLayout(); // Initial call
        window.addEventListener('resize', updateLayout);
        window.addEventListener('scroll', () => {
             // Use a timeout for performance on scroll events
             clearTimeout(scrollTimeout);
             scrollTimeout = setTimeout(updateLayout, 50); // Adjust delay as needed
        }, { passive: true }); // Use passive listener for better scroll performance


        // --- Mobile Menu ---
        let isMobileMenuOpen = false; // State variable

        const toggleMobileMenu = (forceClose = false) => {
             if (menuToggle && navbar) {
                 isMobileMenuOpen = forceClose ? false : !isMobileMenuOpen;

                 menuToggle.setAttribute('aria-expanded', isMobileMenuOpen);
                 menuToggle.classList.toggle('open', isMobileMenuOpen);
                 navbar.classList.toggle('open', isMobileMenuOpen);
                //  htmlElement.classList.toggle('overflow-hidden', isMobileMenuOpen); // Prevent background scroll (optional)
             }
         };

        menuToggle?.addEventListener('click', () => toggleMobileMenu());

         // Close menu when clicking outside on mobile (optional, requires more complex click detection or overlay)
         // Close menu on resize if it goes above mobile breakpoint (handled by CSS default display)


        // --- Active Link Highlighting ---
        const setActiveLink = () => {
             const scrollPos = window.scrollY + headerHeight + 1; // Offset by header height
            sections.forEach(section => {
                 if (section.offsetTop <= scrollPos && section.offsetTop + section.offsetHeight > scrollPos) {
                     navLinks.forEach(link => {
                        link.classList.remove('active');
                         if (link.getAttribute('href') === `#${section.id}`) {
                            link.classList.add('active');
                        }
                    });
                 } else {
                     // Handle case where scroll is between sections or at the very top
                      if (window.scrollY < sections[0].offsetTop && sections[0].id === 'hero') {
                         navLinks.forEach(link => link.classList.remove('active'));
                         document.querySelector('a[href="#hero"]')?.classList.add('active');
                     }
                 }
             });
         };

         let activeLinkTimeout;
         window.addEventListener('scroll', () => {
             clearTimeout(activeLinkTimeout);
             activeLinkTimeout = setTimeout(setActiveLink, 100); // Adjust delay for performance
         }, { passive: true });

         // Initial call to set active link on page load
         setActiveLink();


        // --- Smooth Scroll & Menu Close ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href === '#' || href === '#hero') { // Handle # or #hero links
                     e.preventDefault(); // Prevent default hash behavior
                     window.scrollTo({ top: 0, behavior: 'smooth' });
                 } else { // Handle other section links
                    const targetId = href.substring(1);
                    const targetElement = document.getElementById(targetId);
                    if (targetElement) {
                        e.preventDefault(); // Prevent default hash behavior

                        // Calculate the scroll position taking the fixed header into account
                        const offsetTop = targetElement.getBoundingClientRect().top + window.scrollY - headerHeight;

                        window.scrollTo({
                            top: offsetTop,
                            behavior: 'smooth'
                        });

                        // Optional: Update URL hash after scroll finishes
                        // window.history.pushState(null, null, href);
                    }
                 }

                // Close mobile menu after clicking a link
                if (isMobileMenuOpen) {
                    toggleMobileMenu(true); // Force close the menu
                }
            });
        });

        // --- Back to Top ---
        backToTopButton?.addEventListener('click', () => {
             window.scrollTo({ top: 0, behavior: 'smooth' });
         });

        // --- Form Submission & Messages ---
        document.querySelectorAll('form[id$="-form-tag"]').forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             const buttonTextSpan = submitButton?.querySelector('.button-text'); // Use the span for text
             const spinner = submitButton?.querySelector('.spinner'); // Use the span for spinner
             form.addEventListener('submit', (e) => {
                 // Simple client-side check before disabling button
                 if (form.checkValidity()) {
                    if (submitButton) {
                        submitButton.disabled = true;
                        if (buttonTextSpan) buttonTextSpan.classList.add('opacity-0'); // Hide text gracefully
                        if (spinner) spinner.classList.remove('hidden'); // Show spinner
                     }
                 }
                 // Note: Server-side validation is still the primary security measure.
             });

             // Animate form status messages in after redirect
             const formId = form.id.replace('-tag', ''); // Get form ID from the element ID
             const statusMessage = document.querySelector(`[data-form-message-id="${formId}"]`);
             if(statusMessage) {
                 // Use setTimeout to allow the element to be rendered before animating
                 setTimeout(() => {
                     statusMessage.style.opacity = '1';
                     statusMessage.style.transform = 'translateY(0) scale(1)';
                 }, 50); // A small delay (e.g., 50ms) is usually sufficient
             }
        });


        // --- Gallery Lightbox ---
        // Initialize SimpleLightbox if the element exists and library is loaded
        try {
            if (typeof SimpleLightbox !== 'undefined') {
                new SimpleLightbox('.gallery a', {
                    captionDelay: 250,
                    fadeSpeed: 200,
                    animationSpeed: 200,
                    // Add more options as needed
                 });
            } else {
                console.warn("SimpleLightbox library not found. Gallery images will not open in a lightbox.");
            }
        } catch(e) {
            console.error("SimpleLightbox initialization failed:", e);
        }


        // --- Animation on Scroll (Intersection Observer) ---
        const observerOptions = {
            root: null, // viewport
            rootMargin: '0px 0px -15% 0px', // Trigger when 15% from bottom of viewport
            threshold: 0.05 // Trigger as soon as even a small part is visible
        };

        const intersectionCallback = (entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    // Optional: Stop observing once animated
                    // observer.unobserve(entry.target);
                } else {
                    // Optional: Remove class if element scrolls out of view (useful for elements that re-appear)
                     // entry.target.classList.remove('is-visible');
                }
            });
        };

        // Check if IntersectionObserver is supported before using it
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(intersectionCallback, observerOptions);
            // Observe all elements with the 'animate-on-scroll' class
            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                observer.observe(el);
            });
        } else {
            // Fallback for browsers that don't support IntersectionObserver
            console.warn("IntersectionObserver not supported. Applying all animations on load.");
            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                el.classList.add('is-visible'); // Simply apply the final state styles
            });
        }


         // --- Modal Handling ---
        const modalTriggers = document.querySelectorAll('[data-modal-target]');
        const modalClosers = document.querySelectorAll('[data-modal-close]');
        const modals = document.querySelectorAll('.modal-container');

        // Function to open a modal
        const openModal = (modalId) => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('hidden'); // Use 'hidden' for initial state
                modal.classList.add('flex');    // Use 'flex' to display and center
                // Add a short delay to allow 'display' change before transition
                setTimeout(() => {
                     // The opacity and transform transitions are handled by the CSS classes
                     // added/removed based on the presence of the 'flex' class.
                     // Or you could explicitly add a 'modal-active' class here
                     // modal.classList.add('modal-active');
                 }, 10); // Small delay
                htmlElement.classList.add('overflow-hidden'); // Prevent background scroll
            }
        };

        // Function to close a modal
        const closeModal = (modal) => {
            if (modal) {
                // Trigger CSS transition out
                 modal.classList.remove('flex');
                 // Optional: Add a class to trigger outro animation if needed
                 // modal.classList.add('modal-closing');

                // Wait for the transition to finish before hiding completely
                const transitionDuration = parseFloat(getComputedStyle(modal).transitionDuration) * 1000; // Get duration from CSS
                setTimeout(() => {
                    modal.classList.add('hidden'); // Hide completely after transition
                    // Remove overflow-hidden only if no other modals are open
                    if (!document.querySelectorAll('.modal-container.flex').length) {
                         htmlElement.classList.remove('overflow-hidden');
                    }
                     // Optional: Remove closing class
                     // modal.classList.remove('modal-closing');
                }, transitionDuration); // Match CSS transition duration
            }
        };

        // Event listeners for modal triggers
        modalTriggers.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal-target');
                if (modalId) {
                    openModal(modalId);
                }
            });
        });

        // Event listeners for modal closers
        modalClosers.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal-close');
                if (modalId) {
                    const modal = document.getElementById(modalId);
                    closeModal(modal);
                }
            });
        });

        // Close modal when clicking outside the modal-box
        modals.forEach(modal => {
            modal.addEventListener('click', (event) => {
                // Check if the click target is the modal container itself, not its children
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
        });

        // Close modal when pressing the Escape key
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                // Find any open modal and close it
                document.querySelectorAll('.modal-container.flex').forEach(openModal => {
                     closeModal(openModal);
                 });
            }
        });


        console.log("PAHAL Advanced UI Initialized.");
    });
</script>

</body>
</html>
