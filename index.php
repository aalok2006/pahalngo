<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 3.6 (Styling Aligned with E-Waste/Blood Pages) - FIX for ?? operator
// Features: Consistent Tailwind UI, Animated Gradients, Enhanced Hovers, Micro-interactions
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
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url');
define('ENABLE_LOGGING', true);
$baseDir = __DIR__;
define('LOG_FILE_CONTACT', $baseDir . '/logs/contact_submissions.log');
define('LOG_FILE_VOLUNTEER', $baseDir . '/logs/volunteer_submissions.log');
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
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
        if (is_dir($logDir) && !file_exists($logDir . '/.htaccess')) { @file_put_contents($logDir . '/.htaccess', 'Deny from all'); }
    }
    $timestamp = date('Y-m-d H:i:s'); $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) { $error = error_get_last(); error_log("Failed to write log: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown')); error_log("Original Log: " . $message); }
}

/**
 * Generates or retrieves a CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) { try { $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32)); } catch (Exception $e) { $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true)); } }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token.
 */
function validate_csrf_token(?string $submittedToken): bool {
    if (empty($submittedToken) || !isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) { return false; }
    // Use hash_equals for timing attack resistance
    $result = hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
    // Always unset the session token after it's used/compared
    unset($_SESSION[CSRF_TOKEN_NAME]);
    return $result;
}

/**
 * Sanitize input string.
 */
function sanitize_string(?string $input): string {
    if ($input === null) return '';
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        $value = $data[$field] ?? null; $ruleList = explode('|', $ruleString); $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));

        if (is_string($value) && $ruleString !== 'required') { // Don't trim required check yet
            $value = trim($value);
            if ($value === '') $value = null; // Treat empty string after trim as null for non-required checks
        }

        foreach ($ruleList as $rule) {
            $params = []; if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }
            $isValid = true; $errorMessage = '';
            switch ($rule) {
                case 'required': if ($value === null || $value === '' || (is_array($value) && empty($value))) { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please enter a valid email."; } break;
                case 'minLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters."; } break;
                case 'maxLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters."; } break;
                case 'alpha_space': if (!empty($value) && !preg_match('/^[\p{L}\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) { $isValid = false; $errorMessage = "Invalid phone format."; } break;
                case 'in': if ($value !== null && is_array($params) && !in_array($value, $params)) { $isValid = false; $errorMessage = "Invalid selection for {$fieldNameFormatted}."; } break;
                 case 'required_without':
                     $otherField = $params[0] ?? null;
                      if ($otherField && ($value === null || trim($value) === '') && empty(trim($data[$otherField] ?? ''))) {
                         $isValid = false;
                         $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_', ' ', $otherField)) . " is required.";
                     }
                     break;
                default: log_message("Unknown validation rule '{$rule}' for field '{$field}'.", LOG_FILE_ERROR); break;
            }
            if (!$isValid && !isset($errors[$field])) { $errors[$field] = $errorMessage; break; }
         }
     }
     return $errors;
}

/**
 * Sends an email using the standard PHP mail() function.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT; $senderEmail = SENDER_EMAIL_DEFAULT;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid recipient: {$to}", LOG_FILE_ERROR); return false; }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid sender in config: {$senderEmail}", LOG_FILE_ERROR); return false; }
    $headers = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    $replyToValidEmail = sanitize_email($replyToEmail); // Sanitize reply-to
    $replyToHeader = "";
    if (!empty($replyToValidEmail)) { $replyToNameClean = sanitize_string($replyToName); $replyToFormatted = $replyToNameClean ? "=?UTF-8?B?".base64_encode($replyToNameClean)."?= <{$replyToValidEmail}>" : $replyToValidEmail; $headers .= "Reply-To: {$replyToFormatted}\r\n"; }
    else { $headers .= "Reply-To: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n"; } // Fallback reply-to
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n"; $headers .= "MIME-Version: 1.0\r\n"; $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?="; $wrapped_body = wordwrap($body, 70, "\r\n");
    if (@mail($to, $encodedSubject, $wrapped_body, $headers, "-f{$senderEmail}")) { log_message("{$logContext} Email submitted via mail() to {$to}. Subject: {$subject}", LOG_FILE_CONTACT); return true; }
    else { $errorInfo = error_get_last(); $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error.'); log_message($errorMsg, LOG_FILE_ERROR); error_log($errorMsg); return false; }
}

/**
 * Retrieves a form value safely for HTML output, using global state.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; $value = $form_submissions[$formId][$fieldName] ?? $default;
    if (is_array($value)) { log_message("Attempted get non-scalar value for {$formId}[{$fieldName}]", LOG_FILE_ERROR); return ''; }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error) with enhanced styling & animation.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    // Adjusted classes for Main page's specific theme-color variables
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg opacity-0 transform translate-y-2 scale-95'; // Start smaller for animation
    $typeClasses = $isSuccess ? 'bg-theme-success/10 border-theme-success text-theme-success dark:bg-theme-success/30' : 'bg-theme-accent/10 border-theme-accent text-theme-accent dark:bg-theme-accent/30';
    $iconClass = $isSuccess ? 'fas fa-check-circle text-theme-success' : 'fas fa-exclamation-triangle text-theme-accent';
    $title = $isSuccess ? 'Success!' : 'Error:';
    // data-form-message-id triggers JS animation
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\"><strong class=\"font-bold flex items-center text-base\"><i class=\"{$iconClass} mr-2 text-xl animate-pulse\"></i>{$title}</strong> <span class=\"block sm:inline mt-1 ml-8\">" . htmlspecialchars($message['text']) . "</span></div>";
}


/**
 * Generates HTML for a field error message with accessibility link.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) {
        // Use classes matching Main page's theme colors
        return '<p class="text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1 animate-fade-in"><i class="fas fa-exclamation-circle text-xs"></i>' . htmlspecialchars($form_errors[$formId][$fieldName]) . '</p>';
    } return '';
}

/**
 * Returns CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors;
     // Use base form-input class + error class if error exists
     $base = 'form-input';
     $error = 'form-input-error'; // This class is defined in @layer utilities
     return isset($form_errors[$formId][$fieldName]) ? ($base . ' ' . $error) : $base;
}


/**
 * Gets ARIA describedby attribute value if error exists.
 */
function get_aria_describedby(string $formId, string $fieldName): string {
    global $form_errors; if (isset($form_errors[$formId][$fieldName])) { return ' aria-describedby="' . htmlspecialchars($formId . '_' . $fieldName . '_error') . '"'; } return '';
}
// --- END Helper Functions ---


// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "PAHAL NGO Jalandhar | Empowering Communities, Inspiring Change";
$page_description = "'PAHAL' is a leading volunteer-driven youth NGO in Jalandhar, Punjab, fostering holistic development through impactful initiatives in health, education, environment, and communication skills.";
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health camps, blood donation, education, environment, e-waste, communication skills, personality development, non-profit";

// --- Initialize Form State Variables ---
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token();

// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = sanitize_string($_POST['form_id'] ?? '');
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_value = sanitize_string($_POST[HONEYPOT_FIELD_NAME] ?? ''); // Sanitize honeypot
    $logContext = "[Main Page POST]"; // Default context

     // Security Checks
    if (!empty($honeypot_value)) {
        log_message("{$logContext} Honeypot triggered. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR'] ?? 'Not available'}", LOG_FILE_ERROR); // Fix ?? here too
         // Act like success to spambots
        $_SESSION['form_messages'][$submitted_form_id] = ['type' => 'success', 'text' => 'Thank you for your submission!'];
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id), true, 303);
        exit;
     }
     // validate_csrf_token now unsets the token upon any comparison attempt
     if (!validate_csrf_token($submitted_token)) {
        log_message("{$logContext} Invalid CSRF token. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR'] ?? 'Not available'}", LOG_FILE_ERROR); // Fix ?? here too
        // Invalidate the form submission attempt
        $displayFormId = !empty($submitted_form_id) ? $submitted_form_id : 'general_error'; // Fallback ID
        $_SESSION['form_messages'][$displayFormId] = ['type' => 'error', 'text' => 'Security validation failed. Please refresh the page and try submitting the form again.'];
         // Keep submitted data if errors occurred, except for sensitive fields
         if (isset($_POST)) {
             $temp_data = $_POST;
             unset($temp_data[CSRF_TOKEN_NAME]); // Don't keep the invalid token
             unset($temp_data[HONEYPOT_FIELD_NAME]); // Don't keep honeypot value
             // Sanitize again before storing in session
             $sanitized_temp_data = [];
             foreach($temp_data as $key => $value) {
                 if (is_string($value)) { // Only sanitize strings
                      // Simple check if it looks like an email or phone to use appropriate sanitizer
                     if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                         $sanitized_temp_data[$key] = sanitize_email($value);
                     } else {
                         $sanitized_temp_data[$key] = sanitize_string($value);
                     }
                 } else {
                     $sanitized_temp_data[$key] = $value; // Keep non-strings as is (like checkboxes)
                 }
             }
            $_SESSION['form_submissions'][$displayFormId] = $sanitized_temp_data;
         }

        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($displayFormId), true, 303); // Use 303
        exit;
     }
     // CSRF token was valid and has been unset by validate_csrf_token().
     // A new token will be generated on the subsequent GET request.


    // --- Process Contact Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        $logContext = "[Contact Form]";
        // Sanitize
        $contact_name = sanitize_string($_POST['name'] ?? '');
        $contact_email = sanitize_email($_POST['email'] ?? '');
        $contact_message = sanitize_string($_POST['message'] ?? '');

        // Store submitted data before validation for repopulation on error
        $submitted_data = ['name' => $contact_name, 'email' => $contact_email, 'message' => $contact_message];

        // Validate
        $rules = [
            'name' => 'required|alpha_space|minLength:2|maxLength:100',
            'email' => 'required|email|maxLength:255',
            'message' => 'required|minLength:10|maxLength:2000',
        ];
        $validation_errors = validate_data($submitted_data, $rules);
        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_CONTACT;
            $subject = "Website Contact Form: " . $contact_name;
            $body = "Message from website contact form:\n\n"
                  . "Name: {$contact_name}\n"
                  . "Email: {$contact_email}\n"
                  . "Message:\n{$contact_message}\n"
                  . "\n-------------------------------------------------\n"
                  // FIX START: Replace ?? with isset() and ternary operator
                  . "Submitted By IP: " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Not available') . "\n"
                  // FIX END
                  . "Timestamp: " . date('Y-m-d H:i:s T') . "\n"
                  . "-------------------------------------------------\n";

            if (send_email($to, $subject, $body, $contact_email, $contact_name, $logContext)) {
                $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$contact_name}! Your message has been sent."];
                log_message("{$logContext} Success. Name: {$contact_name}, Email: {$contact_email}.", LOG_FILE_CONTACT);
                 // Clear submitted data on success
                unset($submitted_data);
            } else {
                $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$contact_name}, there was an error sending your message. Please try again later or contact us directly."];
                // Error logged within send_email()
            }
        } else {
            // Validation errors occurred
            $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below to send your message."];
            log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR'] ?? 'Not available'}", LOG_FILE_ERROR); // Fix ?? here too
             // Submitted data is kept automatically by the POST handling logic below
        }
        $_SESSION['scroll_to'] = '#contact'; // Set scroll target
    }

    // --- Process Volunteer Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
         $form_id = 'volunteer_form';
         $logContext = "[Volunteer Form]";
        // Sanitize
        $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? '');
        $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? '');
        $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? '');
        $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? '');
        $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? '');
        $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? '');

        // Store submitted data before validation for repopulation on error
        $submitted_data = [
            'volunteer_name' => $volunteer_name, 'volunteer_email' => $volunteer_email, 'volunteer_phone' => $volunteer_phone,
            'volunteer_area' => $volunteer_area, 'volunteer_availability' => $volunteer_availability,
            'volunteer_message' => $volunteer_message
        ];

        // Validate
        $rules = [
            'volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100',
             // Require email OR phone
            'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255',
            'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20',
             // Example options for volunteer_area - expand as needed
            'volunteer_area' => 'required|in:Health,Education,Environment,Communication Skills,Other',
            'volunteer_availability' => 'required|maxLength:150',
            'volunteer_message' => 'maxLength:2000',
        ];
        $validation_errors = validate_data($submitted_data, $rules);
        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_VOLUNTEER;
             $subject = "New Volunteer Interest: " . $volunteer_name;
             $body = "Volunteer interest submitted via website:\n\n"
                   . "Name: {$volunteer_name}\n"
                   . "Email: " . (!empty($volunteer_email) ? $volunteer_email : "(Not Provided)") . "\n"
                   . "Phone: " . (!empty($volunteer_phone) ? $volunteer_phone : "(Not Provided)") . "\n"
                   . "Area of Interest: {$volunteer_area}\n"
                   . "Availability: {$volunteer_availability}\n"
                   . "Message:\n" . (!empty($volunteer_message) ? $volunteer_message : "(None)") . "\n"
                   . "\n-------------------------------------------------\n"
                   // FIX START: Replace ?? with isset() and ternary operator
                   . "Submitted By IP: " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Not available') . "\n"
                   // FIX END
                   . "Timestamp: " . date('Y-m-d H:i:s T') . "\n"
                   . "-------------------------------------------------\n";

             // Use volunteer email as reply-to if provided, otherwise use default sender
             $replyToEmail = !empty($volunteer_email) ? $volunteer_email : SENDER_EMAIL_DEFAULT;

             if (send_email($to, $subject, $body, $replyToEmail, $volunteer_name, $logContext)) {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your volunteer interest has been registered. We will be in touch soon."];
                 log_message("{$logContext} Success. Name: {$volunteer_name}, Area: {$volunteer_area}.", LOG_FILE_VOLUNTEER);
                  // Clear submitted data on success
                 unset($submitted_data);
             } else {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$volunteer_name}, there was an error registering your interest. Please try again later or contact us directly."];
                 // Error logged within send_email()
             }
         } else {
             // Validation errors occurred
             $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below to register your interest."];
              // FIX START: Replace ?? with isset() and ternary operator
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Not available'), LOG_FILE_ERROR);
             // FIX END
             // Submitted data is kept automatically by the POST handling logic below
        }
         $_SESSION['scroll_to'] = '#volunteer-section'; // Set scroll target
    }

    // --- Post-Processing & Redirect ---
    // Store form results in session (messages and errors)
     $_SESSION['form_messages'] = $form_messages;
     $_SESSION['form_errors'] = $form_errors;

     // Store submitted data only if errors occurred for the form that was just processed
     if (isset($form_errors[$submitted_form_id]) && !empty($form_errors[$submitted_form_id])) {
         $_SESSION['form_submissions'][$submitted_form_id] = $submitted_data ?? []; // Store submitted data if available (?? okay here as it's PHP >= 7 context, but replaced for consistency)
          $_SESSION['form_submissions'][$submitted_form_id] = isset($submitted_data) ? $submitted_data : []; // More compatible version
     } else {
         // If no errors for the submitted form, clear any old submissions for that form
         if (isset($_SESSION['form_submissions'][$submitted_form_id])) {
              unset($_SESSION['form_submissions'][$submitted_form_id]);
         }
     }

     // Get scroll target and clear it from session
     $scrollTarget = $_SESSION['scroll_to'] ?? ''; // ?? okay here
     unset($_SESSION['scroll_to']);

     // Redirect using PRG pattern (HTTP 303 See Other is best practice for POST redirects)
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303);
     exit; // Terminate script after redirect

} else {
    // --- GET Request: Retrieve session data after potential redirect ---
    // Retrieve form results stored in session by the POST handler
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = []; }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = []; }
    // Retrieve submitted data only if redirection happened due to errors
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = []; }
    // Ensure a CSRF token is available for the form(s) to be displayed
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML Template (using helper function) ---
// These variables are used to pre-fill form fields, typically after a validation error
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message');
$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area'); // Should be 'Health', 'Education', etc.
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');


// --- Dummy Data (Keep Existing) ---
$news_items = [
    ['title' => 'Successful Health Camp Organized in Maqsudan', 'excerpt' => 'PAHAL NGO conducted a free health check-up camp offering consultations, basic tests, and awareness sessions...', 'date' => '2023-10-25', 'image' => 'https://via.placeholder.com/400x250/16a34a/d1fae5?text=Health+Camp', 'link' => '#news-health-camp'],
    ['title' => 'E-Waste Collection Drive Receives Overwhelming Response', 'excerpt' => 'Our recent e-waste collection initiative saw significant community participation, ensuring responsible disposal...', 'date' => '2023-11-10', 'image' => 'https://via.placeholder.com/400x250/059669/d1fae5?text=E-Waste+Drive', 'link' => 'e-waste.php#news'],
    ['title' => 'Workshop on Communication Skills for College Students', 'excerpt' => 'PAHAL\'s communication experts led an engaging workshop focusing on public speaking and interpersonal skills...', 'date' => '2023-11-05', 'image' => 'https://via.placeholder.com/400x250/6366f1/e0e7ff?text=Communication+Workshop', 'link' => '#news-communication'],
    ['title' => 'Blood Donation Camp Saves Multiple Lives', 'excerpt' => 'Thanks to generous donors, our latest blood donation camp successfully collected units vital for local hospitals...', 'date' => '2023-09-20', 'image' => 'https://via.placeholder.com/400x250/e11d48/fee2e2?text=Blood+Camp', 'link' => 'blood-donation.php#news'],
];

$gallery_images = [
    ['src' => 'https://via.placeholder.com/800x500/059669/ffffff?text=Gallery+Image+1', 'alt' => 'PAHAL Activity 1'],
    ['src' => 'https://via.placeholder.com/800x500/6366f1/ffffff?text=Gallery+Image+2', 'alt' => 'PAHAL Activity 2'],
    ['src' => 'https://via.placeholder.com/800x500/e11d48/ffffff?text=Gallery+Image+3', 'alt' => 'PAHAL Activity 3'],
    ['src' => 'https://via.placeholder.com/800x500/16a34a/ffffff?text=Gallery+Image+4', 'alt' => 'PAHAL Activity 4'],
    ['src' => 'https://via.placeholder.com/800x500/0ea5e9/ffffff?text=Gallery+Image+5', 'alt' => 'PAHAL Activity 5'],
    ['src' => 'https://via.placeholder.com/800x500/f59e0b/ffffff?text=Gallery+Image+6', 'alt' => 'PAHAL Activity 6'],
    ['src' => 'https://via.placeholder.com/800x500/34d399/ffffff?text=Gallery+Image+7', 'alt' => 'PAHAL Activity 7'],
    ['src' => 'https://via.placeholder.com/800x500/a78bfa/ffffff?text=Gallery+Image+8', 'alt' => 'PAHAL Activity 8'],
];

$associates = [
    ['img' => 'https://via.placeholder.com/150x80/ffffff/1f2937?text=Partner+1', 'name' => 'Local Hospital'],
    ['img' => 'https://via.placeholder.com/150x80/ffffff/1f2937?text=Partner+2', 'name' => 'Community Center'],
    ['img' => 'https://via.placeholder.com/150x80/ffffff/1f2937?text=Partner+3', 'name' => 'Educational Institute'],
    ['img' => 'https://via.placeholder.com/150x80/ffffff/1f2937?text=Partner+4', 'name' => 'Corporate Sponsor'],
    ['img' => 'https://via.placeholder.com/150x80/ffffff/1f2937?text=Partner+5', 'name' => 'Govt. Department'],
];


// Define theme colors using PHP variables for use in Tailwind config
$primary_color = '#059669'; $primary_hover = '#047857'; $primary_focus = 'rgba(5, 150, 105, 0.3)'; // Emerald
$secondary_color = '#6366f1'; $secondary_hover = '#4f46e5'; // Indigo
$accent_color = '#e11d48'; $accent_hover = '#be123c'; // Rose
$success_color = '#16a34a'; // Green
$warning_color = '#f59e0b'; // Amber
$info_color = '#0ea5e9'; // Sky
$bg_light = '#f8fafc'; $bg_dark = '#0b1120'; // Slate
$surface_light = '#ffffff'; $surface_alt_light = '#f1f5f9'; // White, Slate 100
$surface_dark = '#1e293b'; $surface_alt_dark = '#334155'; // Slate 800, 700
$text_light = '#1e293b'; $text_muted_light = '#64748b'; $text_heading_light = '#0f172a'; // Slate 800, 500, 900
$text_dark = '#cbd5e1'; $text_muted_dark = '#94a3b8'; $text_heading_dark = '#f1f5f9'; // Slate 300, 400, 100
$border_light = '#e2e8f0'; $border_light_light = '#f1f5f9'; // Slate 200, 100
$border_dark = '#475569'; $border_light_dark = '#334155'; // Slate 600, 700
$glow_color_light = 'rgba(5, 150, 105, 0.4)'; // Primary glow light
$glow_color_dark = 'rgba(52, 211, 153, 0.5)'; // Primary glow dark (using emerald 400)

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth"> <!-- The 'dark' class will be added/removed by JS -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b1120"> <!-- Using darker slate bg -->

    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/"/> <!-- CHANGE THIS URL -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-og-enhanced.jpg"/> <!-- CHANGE THIS IMAGE URL -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"/> <!-- CHANGE THIS URL -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/images/pahal-twitter-enhanced.jpg"/> <!-- CHANGE THIS IMAGE URL -->

    <!-- Favicon -->
    <link rel="icon" href="icon.webp" sizes="any"> <!-- Use the actual icon path -->
    <link rel="icon" type="image/svg+xml" href="icon.svg"> <!-- If you have an SVG icon -->
    <link rel="apple-touch-icon" href="apple-touch-icon.png"> <!-- Add apple-touch-icon -->
    <link rel="manifest" href="/site.webmanifest"> <!-- Add webmanifest -->


    <!-- Tailwind CSS CDN with Forms Plugin -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Poppins & Fira Code) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Fira+Code:wght@400&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple Lightbox CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.css">

    <script>
        // Tailwind Config - Uses PHP variables for colors
        tailwind.config = {
            darkMode: 'class', // Enable dark mode based on 'dark' class
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                        heading: ['Poppins', 'sans-serif'],
                        mono: ['Fira Code', 'monospace'],
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
                        'fade-in': 'fadeIn 0.6s ease-out forwards',
                        'fade-in-down': 'fadeInDown 0.7s ease-out forwards',
                        'fade-in-up': 'fadeInUp 0.7s ease-out forwards',
                        'slide-in-left': 'slideInLeft 0.7s ease-out forwards',
                        'slide-in-right': 'slideInRight 0.7s ease-out forwards',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                         // Added form message animation
                         'form-message-in': 'formMessageIn 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards', // Smoother ease
                        'bounce-subtle': 'bounceSubtle 2s infinite ease-in-out',
                         // Added gradient animation
                         'gradient-bg': 'gradientBg 15s ease infinite',
                         // Added glow animation
                         'glow-pulse': 'glowPulse 2.5s infinite alternate ease-in-out',
                         // Added icon bounce animation
                         'icon-bounce': 'iconBounce 0.6s ease-out', // For button icons
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInDown: { '0%': { opacity: '0', transform: 'translateY(-25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }, // Increased distance
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideInLeft: { '0%': { opacity: '0', transform: 'translateX(-40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        slideInRight: { '0%': { opacity: '0', transform: 'translateX(40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                         // Keyframes for form message animation
                        formMessageIn: { '0%': { opacity: '0', transform: 'translateY(15px) scale(0.95)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
                        bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                         // Keyframes for animated gradient
                        gradientBg: {
                           '0%': { backgroundPosition: '0% 50%' },
                           '50%': { backgroundPosition: '100% 50%' },
                           '100%': { backgroundPosition: '0% 50%' },
                        },
                        // Keyframes for glow effect
                         glowPulse: {
                            '0%': { boxShadow: '0 0 5px 0px var(--color-glow)' },
                            '100%': { boxShadow: '0 0 20px 5px var(--color-glow)' },
                        },
                        // Keyframes for icon bounce on hover
                        iconBounce: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-3px)' },
                        }
                    },
                    boxShadow: { // Keep existing + add focus shadow variable
                        'card': '0 5px 15px rgba(0, 0, 0, 0.07)', // Light theme card shadow
                        'card-dark': '0 8px 25px rgba(0, 0, 0, 0.3)', // Dark theme card shadow
                        'input-focus': '0 0 0 3px var(--color-primary-focus)', // Focus ring shadow using CSS variable
                    },
                    container: { // Keep previous container settings
                      center: true,
                      padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' },
                      screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1280px' },
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Theme Variables - More Vibrant Palette (Matching E-Waste/Blood Structure) */
        :root { /* Light Theme */
          --color-primary: <?= $primary_color ?>;
          --color-primary-hover: <?= $primary_hover ?>;
          --color-primary-focus: <?= $primary_focus ?>;
          --color-secondary: <?= $secondary_color ?>;
          --color-secondary-hover: <?= $secondary_hover ?>;
          --color-accent: <?= $accent_color ?>;
          --color-accent-hover: <?= $accent_hover ?>;
          --color-success: <?= $success_color ?>;
          --color-warning: <?= $warning_color ?>;
          --color-info: <?= $info_color ?>;
          --color-bg: <?= $bg_light ?>;
          --color-surface: <?= $surface_light ?>;
          --color-surface-alt: <?= $surface_alt_light ?>;
          --color-text: <?= $text_light ?>;
          --color-text-muted: <?= $text_muted_light ?>;
          --color-text-heading: <?= $text_heading_light ?>;
          --color-border: <?= $border_light ?>;
          --color-border-light: <?= $border_light_light ?>;
          --color-glow: <?= $glow_color_light ?>;
          --scrollbar-thumb: #cbd5e1; /* Slate 300 */
          --scrollbar-track: #f1f5f9; /* Slate 100 */
          color-scheme: light; /* Hint browser for scrollbar, form controls etc. */
        }

        html.dark {
          --color-primary: #34d399; /* Emerald 400 */
          --color-primary-hover: #6ee7b7; /* Emerald 300 */
          --color-primary-focus: rgba(52, 211, 153, 0.4);
          --color-secondary: #a78bfa; /* Violet 400 */
          --color-secondary-hover: #c4b5fd; /* Violet 300 */
          --color-accent: #f87171; /* Red 400 */
          --color-accent-hover: #fb7185; /* Rose 400 */
          --color-success: #4ade80; /* Green 400 */
          --color-warning: #facc15; /* Yellow 400 */
          --color-info: #38bdf8; /* Sky 400 */
          --color-bg: <?= $bg_dark ?>; /* Darker Slate */
          --color-surface: <?= $surface_dark ?>; /* Slate 800 */
          --color-surface-alt: <?= $surface_alt_dark ?>; /* Slate 700 */
          --color-text: <?= $text_dark ?>; /* Slate 300 */
          --color-text-muted: <?= $text_muted_dark ?>; /* Slate 400 */
          --color-text-heading: <?= $text_heading_dark ?>; /* Slate 100 */
          --color-border: <?= $border_dark ?>; /* Slate 600 */
          --color-border-light: <?= $border_light_dark ?>; /* Slate 700 */
          --color-glow: <?= $glow_color_dark ?>;
          --scrollbar-thumb: #475569; /* Slate 600 */
          --scrollbar-track: #1e293b; /* Slate 800 */
          color-scheme: dark; /* Hint browser */
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 5px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 5px; border: 2px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 75%, white); } /* Subtle hover */


        @layer base {
            html { @apply scroll-smooth antialiased; }
            /* Add body padding top for fixed header AFTER header height is calculated in JS or set fixed */
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300 overflow-x-hidden; padding-top: 70px; } /* Set padding top for fixed header */
            h1, h2, h3, h4, h5, h6 { @apply font-heading font-semibold text-theme-text-heading tracking-tight leading-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-extrabold; } /* Bolder H1 */
            h2 { @apply text-3xl md:text-4xl font-bold mb-4; }
            h3 { @apply text-xl md:text-2xl font-bold text-theme-primary mb-4 mt-5; }
            h4 { @apply text-lg font-semibold text-theme-secondary mb-2; }
            p { @apply mb-5 leading-relaxed text-base; } /* Removed max-w-prose default */
            a { @apply text-theme-secondary hover:text-theme-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-secondary/50 rounded-sm; transition-colors duration-200; }
            /* Style non-button/nav links */
            a:not(.btn):not(.btn-secondary):not(.btn-outline):not(.nav-link):not(.footer-link) {
                 @apply underline decoration-theme-secondary/50 hover:decoration-theme-primary decoration-1 underline-offset-2;
            }
            hr { @apply border-theme-border/40 my-12; } /* Lighter border */
            blockquote { @apply border-l-4 border-theme-secondary bg-theme-surface-alt p-5 my-6 italic text-theme-text-muted shadow-inner rounded-r-md;}
            blockquote cite { @apply block not-italic mt-2 text-sm text-theme-text-muted/80;}
            address { @apply not-italic;}
             /* Global focus style */
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-offset-theme-surface ring-theme-primary/70 rounded-md; }
             /* Honeypot field hidden visually */
            .honeypot-field { @apply !absolute !-left-[9999px] !w-px !h-px !overflow-hidden !opacity-0; }
        }

        @layer components {
            /* Standard Section Padding */
            .section-padding { @apply py-16 md:py-20 lg:py-24 px-4; }

            /* Section Title Styling */
            .section-title { @apply text-center mb-12 md:mb-16 text-theme-primary; }
            .section-title-underline::after { content: ''; @apply block w-24 h-1 bg-gradient-to-r from-theme-secondary to-theme-accent mx-auto mt-4 rounded-full opacity-80; } /* Gradient underline */

            /* Button Styles */
            .btn { @apply relative inline-flex items-center justify-center px-7 py-3 border border-transparent text-base font-medium rounded-lg shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-theme-surface overflow-hidden transition-all duration-300 ease-out transform hover:-translate-y-1 hover:shadow-lg disabled:opacity-60 disabled:cursor-not-allowed group; }
            .btn::before { /* Subtle gradient overlay */
                content: ''; @apply absolute inset-0 opacity-20 group-hover:opacity-30 transition-opacity duration-300;
                background: linear-gradient(rgba(255,255,255,0.5), rgba(255,255,255,0));
            }
            .btn i { @apply mr-2 text-sm transition-transform duration-300 group-hover:scale-110; } /* Icon animation */
            .btn-primary { @apply text-white bg-theme-primary hover:bg-theme-primary-hover focus-visible:ring-theme-primary; }
            .btn-secondary { @apply text-white bg-theme-secondary hover:bg-theme-secondary-hover focus-visible:ring-theme-secondary; }
            .btn-accent { @apply text-white bg-theme-accent hover:bg-theme-accent-hover focus-visible:ring-theme-accent; }
            .btn-outline { @apply text-theme-primary border-2 border-current bg-transparent hover:bg-theme-primary/10 focus-visible:ring-theme-primary; }
             .btn-outline.secondary { @apply !text-theme-secondary hover:!bg-theme-secondary/10 focus-visible:ring-theme-secondary; } /* Outline secondary */
             .btn-icon { @apply p-2.5 rounded-full focus-visible:ring-offset-0; } /* Slightly larger icon button */


            /* Card Styles */
            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/60 overflow-hidden relative transition-all duration-300; }
            .card-hover { @apply hover:shadow-xl dark:hover:shadow-2xl hover:border-theme-primary/50 hover:scale-[1.03] z-10; } /* Enhanced hover */
            .card::after { /* Subtle glow on hover preparation */
                content: ''; @apply absolute inset-0 rounded-xl opacity-0 transition-opacity duration-300 pointer-events-none;
                box-shadow: 0 0 25px -5px var(--color-glow);
            }
            .card-hover:hover::after { @apply opacity-70; }

            /* Panel with Glassmorphism */
            .panel { @apply bg-theme-surface/75 dark:bg-theme-surface/70 backdrop-blur-xl border border-theme-border/40 rounded-2xl shadow-lg p-6 md:p-8; }

            /* Form Styles */
            .form-label { @apply block mb-1.5 text-sm font-medium text-theme-text-muted; }
            .form-label.required::after { content: '*'; @apply text-theme-accent ml-0.5; }
            .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-bg dark:bg-theme-surface/60 border-theme-border placeholder-theme-text-muted/70 text-theme-text shadow-sm transition duration-200 ease-in-out focus:border-theme-primary focus:ring-2 focus:ring-theme-primary/50 focus:outline-none disabled:opacity-60; }
            /* Autofill styles */
            input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus, textarea:-webkit-autofill, textarea:-webkit-autofill:hover, textarea:-webkit-autofill:focus, select:-webkit-autofill, select:-webkit-autofill:hover, select:-webkit-autofill:focus { -webkit-text-fill-color: var(--color-text); -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; transition: background-color 5000s ease-in-out 0s; }
            html.dark input:-webkit-autofill, html.dark input:-webkit-autofill:hover, html.dark input:-webkit-autofill:focus, html.dark textarea:-webkit-autofill, html.dark textarea:-webkit-autofill:hover, html.dark textarea:-webkit-autofill:focus, html.dark select:-webkit-autofill, html.dark select:-webkit-autofill:hover, html.dark select:-webkit-autofill:focus { -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; }
            select.form-input { /* Rely on @tailwindcss/forms for default arrow */ }
            textarea.form-input { @apply min-h-[120px] resize-vertical; }
             /* Error input class */
            .form-input-error { @apply !border-theme-accent ring-2 ring-theme-accent/50 focus:!border-theme-accent focus:!ring-theme-accent/50; }
             /* Error message class */
            .form-error-message { @apply text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1; }
            /* Form section card styling with border */
            .form-section { @apply card border-l-4 border-theme-primary mt-8; } /* Default border */
             #volunteer-section .form-section { @apply !border-theme-accent; } /* Volunteer form border */
             #contact .form-section { @apply !border-theme-secondary; } /* Contact form border */


             /* Spinner */
             .spinner { @apply inline-block animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-current; } /* Slightly larger */

             /* Footer Styles */
             .footer-heading { @apply text-lg font-semibold text-white mb-5 relative pb-2; }
             .footer-heading::after { @apply content-[''] absolute bottom-0 left-0 w-10 h-0.5 bg-theme-primary rounded-full; } /* Footer heading underline */
             .footer-link { @apply text-gray-400 hover:text-white hover:underline text-sm transition-colors duration-200; }
             footer ul.footer-links li { @apply mb-1.5; } /* Space out footer links */
             footer ul.footer-links li a { @apply footer-link inline-flex items-center gap-1.5; }
             footer ul.footer-links i { @apply opacity-70; }
             footer address p { @apply mb-3 flex items-start gap-3; } /* Align address items */
             footer address i { @apply text-theme-primary mt-1 w-4 text-center flex-shrink-0; }
             .footer-social-icon { @apply text-xl transition duration-300 text-gray-400 hover:scale-110; } /* Social icons */
             .footer-bottom { @apply border-t border-gray-700/50 pt-8 mt-12 text-center text-sm text-gray-500; }

            /* --- SPECIFIC SECTION STYLES --- */
             /* Header */
             #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/85 dark:bg-theme-bg/80 backdrop-blur-xl z-50 shadow-sm transition-all duration-300 border-b border-theme-border/30; min-height: 70px; }
             #main-header.scrolled { @apply shadow-lg bg-theme-surface/95 dark:bg-theme-bg/90 border-theme-border/50; }
             /* body padding handled in @layer base */

              /* Navigation */
              #navbar ul li a { @apply text-theme-text-muted hover:text-theme-primary dark:hover:text-theme-primary font-medium py-2 relative transition duration-300 ease-in-out text-sm lg:text-base block lg:inline-block lg:py-0 px-3 lg:px-2 xl:px-3; } /* Adjusted padding */
              #navbar ul li a::after { content: ''; @apply absolute bottom-[-5px] left-0 w-0 h-[3px] bg-gradient-to-r from-theme-secondary to-theme-primary opacity-0 transition-all duration-300 ease-out rounded-full group-hover:opacity-100 group-hover:w-full; } /* Animated underline */
              #navbar ul li a.active { @apply text-theme-primary font-semibold; }
              #navbar ul li a.active::after { @apply w-full opacity-100; } /* Always underlined when active */

              /* Mobile menu toggle */
              .menu-toggle { @apply text-theme-text-muted hover:text-theme-primary transition-colors duration-200; }
              .menu-toggle span { @apply block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out; }
              .menu-toggle span:nth-child(1) { @apply mb-1.5; }
              .menu-toggle span:nth-child(3) { @apply mt-1.5; }
              .menu-toggle.open span:nth-child(1) { @apply transform rotate-45 translate-y-[6px]; } /* Adjusted translate */
              .menu-toggle.open span:nth-child(2) { @apply opacity-0; }
              .menu-toggle.open span:nth-child(3) { @apply transform -rotate-45 -translate-y-[6px]; } /* Adjusted translate */


              /* Mobile Navbar container */
               /* Use class for JS targeting */
              #navbar { @apply w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-theme-surface dark:bg-theme-surface lg:bg-transparent shadow-xl lg:shadow-none lg:border-none border-t border-theme-border transition-all duration-500 ease-in-out; }
              #navbar.open { @apply block; max-height: calc(100vh - 70px); /* JS could also set this */ } /* Ensure it opens */

             /* Hero Section */
             #hero {
                 /* Use the animated gradient utility */
                 @apply animated-gradient-primary text-white min-h-[calc(100vh-70px)] flex items-center py-20 relative overflow-hidden;
             }
             #hero::before { /* Subtle overlay */
                 content: ''; @apply absolute inset-0 bg-black/20;
             }
             .hero-text h1 { @apply !text-white mb-6 drop-shadow-xl leading-tight font-extrabold; }
             .hero-logo img { @apply drop-shadow-2xl animate-glow-pulse bg-white/10; animation-duration: 4s; } /* Slower glow pulse */
             .hero-scroll-indicator { @apply absolute bottom-8 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
             .hero-scroll-indicator a { @apply text-white/60 hover:text-white text-4xl animate-bounce-subtle; }

             /* Focus Areas */
             .focus-item { @apply card card-hover border-t-4 border-theme-secondary bg-theme-surface p-6 md:p-8 text-center flex flex-col items-center; } /* Changed border color */
             .focus-item .icon { @apply text-5xl text-theme-secondary mb-6 inline-block transition-transform duration-300 group-hover:scale-110 group-hover:animate-icon-bounce; } /* Bounce icon */
             .focus-item h3 { @apply text-xl text-theme-text-heading mb-3 transition-colors duration-300 group-hover:text-theme-secondary; }
             .focus-item p { @apply text-sm text-theme-text-muted leading-relaxed flex-grow mb-4 text-center; }
             .focus-item .read-more-link { @apply relative block text-sm font-semibold text-theme-primary mt-auto opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:underline pt-2; }
             .focus-item .read-more-link::after { content: '\f061'; font-family: 'Font Awesome 6 Free'; @apply font-black text-xs ml-1.5 opacity-0 group-hover:opacity-100 translate-x-[-5px] group-hover:translate-x-0 transition-all duration-300 inline-block;} /* Animated arrow */

             /* Objectives Section */
             #objectives { @apply bg-gradient-to-b from-theme-bg to-theme-surface-alt dark:from-theme-bg dark:to-theme-surface/30; } /* Subtle gradient background */
             .objective-item { @apply bg-theme-surface/80 dark:bg-theme-surface/90 backdrop-blur-sm p-5 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:border-theme-secondary border-l-4 border-transparent flex items-start space-x-4; }
             .objective-item i { @apply text-theme-secondary group-hover:text-theme-accent transition-all duration-300 flex-shrink-0 w-6 text-center text-xl group-hover:rotate-[15deg]; } /* Rotate icon */

             /* News Section */
             #news-section { @apply bg-theme-surface-alt dark:bg-theme-surface/50; }
             #news-section .news-card { @apply card card-hover flex flex-col; }
             #news-section .news-card img { @apply rounded-t-xl; } /* Ensure img corners match card */
             #news-section .news-card .news-content { @apply p-5 flex flex-col flex-grow; }
             #news-section .news-card .date { @apply block text-xs text-theme-text-muted mb-2; }
             #news-section .news-card h4 { @apply text-lg font-semibold text-theme-text-heading mb-2 leading-snug flex-grow; }
             #news-section .news-card h4 a { @apply text-inherit hover:text-inherit; } /* Use inherit for link color */
             #news-section .news-card p { @apply text-sm text-theme-text-muted mb-4 leading-relaxed; }
             #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-theme-border-light; }

             /* Volunteer/Donate Sections */
              #volunteer-section { @apply animated-gradient-accent text-white relative; } /* Use animated gradient */
              #volunteer-section::before { content:''; @apply absolute inset-0 bg-black/25;} /* Darken overlay */
              #donate-section { @apply animated-gradient-primary text-white relative; }
              #donate-section::before { content:''; @apply absolute inset-0 bg-black/25;}
              #volunteer-section .section-title, #donate-section .section-title { @apply !text-white relative z-10; }
              #volunteer-section .section-title::after, #donate-section .section-title::after { @apply !bg-white/70 relative z-10; }
              #volunteer-section form, #donate-section > div > div { @apply relative z-10; } /* Ensure form/content is above overlay */
              #volunteer-section .panel { @apply !bg-black/30 dark:!bg-black/40 !border-white/20; } /* Adjust panel for gradient bg */
              #volunteer-section .form-label { @apply !text-gray-100; }
              /* Define inverted input style */
              .form-input-inverted { @apply !bg-white/10 !border-gray-400/40 !text-white placeholder:!text-gray-300/60 focus:!bg-white/20 focus:!border-white focus:!ring-white/50; }
              /* Apply inverted style where needed */
              #volunteer-section .form-input { @apply form-input-inverted; }
               /* Override error state for inverted */
              #volunteer-section .form-input-error { @apply !border-red-400 !ring-red-400/60 focus:!border-red-400 focus:!ring-red-400/60; }


             /* Gallery */
             .gallery-item img { @apply transition-all duration-400 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110; } /* Add contrast */

             /* Associates */
             #associates { @apply bg-theme-surface-alt dark:bg-theme-surface/50; }
             .associate-logo img { @apply filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100; }
             .associate-logo p { @apply text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors; }

             /* Contact Section */
             #contact { @apply bg-gradient-to-b from-theme-surface-alt to-theme-bg dark:from-theme-surface/50 dark:to-theme-bg;}
             #contact .panel { @apply !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50; } /* Solid surface for form */
             .contact-info-item { @apply flex items-start gap-4; }
             .contact-info-item i { @apply text-theme-primary text-lg mt-1 w-5 text-center flex-shrink-0; }
             #contact .registration-info { @apply bg-theme-surface dark:bg-theme-surface/80 p-4 rounded-md border border-theme-border text-xs text-theme-text-muted mt-8 shadow-inner;}

             /* Footer */
             footer { @apply bg-slate-900 dark:bg-black text-gray-400 pt-16 pb-8 mt-0 border-t-4 border-theme-primary dark:border-theme-primary; }
             footer .footer-text { @apply text-sm text-gray-400 mb-2 leading-relaxed; }

             /* Back to Top */
             #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-theme-primary text-white shadow-lg hover:bg-theme-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95; }
             #back-to-top.visible { @apply opacity-100 visible; }

             /* Modal Styles */
             .modal-container { @apply fixed inset-0 bg-black/70 dark:bg-black/80 z-[100] hidden items-center justify-center p-4 backdrop-blur-md transition-opacity duration-300 ease-out; } /* Added transition */
             .modal-container.flex { @apply flex; opacity: 1; } /* Use flex to show */
             .modal-container.hidden { @apply hidden; opacity: 0; } /* Use hidden to hide */

             .modal-box { @apply bg-theme-surface rounded-lg shadow-xl p-6 md:p-8 w-full max-w-lg text-left relative transform transition-all duration-300 scale-95 opacity-0; } /* Wider modal */
             .modal-container.flex .modal-box { @apply scale-100 opacity-100; } /* Animate in */

             #bank-details-modal h3 { @apply !text-theme-primary !mt-0 mb-5 border-b border-theme-border pb-3; } /* Title style */
             .modal-content-box { @apply bg-theme-surface-alt dark:bg-theme-surface/50 p-4 rounded-md border border-theme-border/50 space-y-2 my-5 text-sm; }
             .modal-content-box p strong { @apply font-medium text-theme-text-heading; }
             .modal-footer-note { @apply text-xs text-theme-text-muted text-center mt-6 italic; }
             .close-button { @apply absolute top-4 right-4 text-theme-text-muted hover:text-theme-accent p-1 rounded-full transition-colors focus-visible:ring-theme-accent; }
        }

        @layer utilities {
            /* Animation Delays */
             .delay-50 { animation-delay: 0.05s; }
             .delay-100 { animation-delay: 0.1s; }
             .delay-150 { animation-delay: 0.15s; }
             .delay-200 { animation-delay: 0.2s; }
             .delay-300 { animation-delay: 0.3s; }
             .delay-400 { animation-delay: 0.4s; }
             .delay-500 { animation-delay: 0.5s; }
             .delay-700 { animation-delay: 0.7s; }

             /* Animation on Scroll Classes */
             .animate-on-scroll { opacity: 0; transition: opacity 0.8s cubic-bezier(0.165, 0.84, 0.44, 1), transform 0.8s cubic-bezier(0.165, 0.84, 0.44, 1); } /* Smoother ease */
             .animate-on-scroll.fade-in-up { transform: translateY(40px); } /* Increase distance */
             .animate-on-scroll.fade-in-left { transform: translateX(-50px); }
             .animate-on-scroll.fade-in-right { transform: translateX(50px); }
             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }

             /* Animated Gradient Background Utility */
             .animated-gradient-primary {
                background: linear-gradient(-45deg, var(--color-primary), var(--color-secondary), var(--color-info), var(--color-primary));
                background-size: 400% 400%;
                animation: gradientBg 18s ease infinite;
             }
             .animated-gradient-accent { /* Used for Volunteer section */
                 background: linear-gradient(-45deg, var(--color-accent), var(--color-warning), var(--color-secondary), var(--color-accent));
                 background-size: 400% 400%;
                 animation: gradientBg 20s ease infinite;
             }
             /* NOTE: Volunteer section uses the ACCENT gradient, not secondary. Renamed .animated-gradient-secondary to .animated-gradient-accent */
             /* If you need a separate secondary gradient, define it here */

        }
    </style>
    <!-- Schema.org JSON-LD -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "NGO",
      "name": "PAHAL NGO",
      "url": "https://your-pahal-domain.com/", /* CHANGE */
      "logo": "https://your-pahal-domain.com/icon.webp", /* CHANGE */
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
        {
          "@type": "ContactPoint",
          "telephone": "+911812672784",
          "contactType": "general inquiry"
        },
         {
          "@type": "ContactPoint",
          "telephone": "+919855614230",
          "contactType": "general inquiry"
        },
        {
          "@type": "ContactPoint",
          "email": "engage@pahal-ngo.org",
          "contactType": "customer service"
        }
      ],
      "sameAs": [
        "...", /* Add social media links here */
        "...",
        "..."
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
            <span class="sr-only">Open menu</span>
            <span></span>
            <span></span>
            <span></span>
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
                    <blockquote><p>"PAHAL is an endeavour for a Better Tomorrow"</p><cite>- PAHAL NGO</cite></blockquote>
                    <h3 class="text-2xl mb-4 mt-10 !text-theme-text-heading">Our Core Vision</h3>
                    <p class="text-theme-text-muted text-lg">We aim to cultivate <strong class="text-theme-primary font-medium">Holistic Personality Development</strong>... thereby building a more compassionate and equitable world.</p>
                 </div>
                 <div class="md:col-span-2 profile-image animate-on-scroll fade-in-right delay-200">
                    <img src="https://via.placeholder.com/500x600.png/059669/f9fafb?text=PAHAL+Vision" alt="PAHAL NGO team vision" class="rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px] border-4 border-white dark:border-theme-surface">
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
     <section id="volunteer-section" class="section-padding text-white relative animated-gradient-accent">
        <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div> <!-- Darkening Overlay -->
        <div class="container mx-auto relative z-10">
             <h2 class="section-title !text-white section-title-underline after:!bg-white/70">Join the PAHAL Movement</h2>
            <div class="grid lg:grid-cols-2 gap-12 items-center mt-16">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left">
                    <h3 class="text-3xl lg:text-4xl font-bold mb-4 text-white leading-snug drop-shadow-md">Make a Difference, Volunteer With Us</h3>
                    <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed drop-shadow-sm">PAHAL welcomes passionate individuals... Your time, skills, and dedication are invaluable assets.</p>
                    <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed drop-shadow-sm">Volunteering offers a rewarding experience... Express your interest below!</p>
                     <div class="mt-10 flex flex-wrap justify-center lg:justify-start gap-4">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-theme-secondary"><i class="fas fa-phone-alt"></i>Contact Directly</a>
                         <!-- <a href="volunteer-opportunities.php" class="btn !bg-white !text-theme-secondary hover:!bg-gray-100"><i class="fas fa-list-alt"></i>View Opportunities</a> -->
                     </div>
                 </div>
                 <!-- Volunteer Sign-up Form -->
                 <div class="panel !bg-black/30 dark:!bg-black/40 !border-white/20 animate-on-scroll fade-in-right delay-100">
                     <h3 class="text-2xl mb-6 text-white font-semibold text-center">Register Your Volunteer Interest</h3>
                     <?= get_form_status_html('volunteer_form') ?>
                    <form id="volunteer-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5">
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
                        <p class="text-xs text-gray-300 -mt-3" id="volunteer_contact_note">Provide Email or Phone (at least one is required).</p>
                         <div>
                            <label for="volunteer_area" class="form-label !text-gray-200 required">Area of Interest</label>
                            <select id="volunteer_area" name="volunteer_area" required class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>>
                                <option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : ''?>>-- Select --</option>
                                <option value="Health" <?= $volunteer_form_area_value == 'Health' ? 'selected' : ''?>>Health</option>
                                <option value="Education" <?= $volunteer_form_area_value == 'Education' ? 'selected' : ''?>>Education</option>
                                <option value="Environment" <?= $volunteer_form_area_value == 'Environment' ? 'selected' : ''?>>Environment</option>
                                <option value="Communication Skills" <?= $volunteer_form_area_value == 'Communication Skills' ? 'selected' : ''?>>Communication Skills</option>
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
                        <button type="submit" class="btn btn-accent w-full sm:w-auto">
                            <span class="spinner hidden mr-2"></span>
                            <span class="button-text flex items-center"><i class="fas fa-paper-plane"></i>Submit Interest</span>
                        </button>
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
                             <h4 class="my-2"><a href="<?= htmlspecialchars($item['link']) ?>" class="group-hover:!text-theme-secondary"><?= htmlspecialchars($item['title']) ?></a></h4>
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
            <div class="text-center mt-12"><a href="/news-archive.php" class="btn btn-secondary"><i class="far fa-newspaper"></i>View News Archive</a></div> <!-- CHANGE LINK IF NEEDED -->
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
                <?php if (!empty($associates)): ?>
                    <?php foreach ($associates as $index => $associate): ?>
                     <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll fade-in-up delay-<?= ($index * 50) ?>">
                        <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-3 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100">
                        <p class="text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p>
                     </div>
                     <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-theme-text-muted md:col-span-full">Partner logos coming soon.</p>
                 <?php endif; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <section id="donate-section" class="section-padding text-center relative overflow-hidden animated-gradient-primary">
         <div class="absolute inset-0 bg-black/35 mix-blend-multiply z-0"></div>
         <div class="container mx-auto relative z-10">
             <i class="fas fa-donate text-4xl text-white bg-theme-accent p-4 rounded-full shadow-lg mb-6 inline-block animate-bounce-subtle"></i>
             <h2 class="section-title !text-white section-title-underline after:!bg-white/70"><span class="drop-shadow-md">Support Our Initiatives</span></h2>
            <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto mb-8 text-lg leading-relaxed drop-shadow">Your contribution fuels our mission in health, education, and environment within Jalandhar.</p>
            <p class="text-gray-200 dark:text-gray-300 bg-black/25 dark:bg-black/50 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-10 backdrop-blur-sm border border-white/20">Donations Tax Exempt under Sec 80G.</p>
            <div class="space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center items-center gap-4">
                 <a href="#contact" class="btn btn-secondary !bg-white !text-theme-primary hover:!bg-gray-100 shadow-xl"><i class="fas fa-info-circle"></i> Donation Inquiries</a>
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
                     <!-- Social Icons Placeholder -->
                    <div class="mb-10 pt-8 border-t border-theme-border/50">
                        <h4 class="text-lg font-semibold text-theme-secondary mb-4">Follow Our Journey</h4>
                        <div class="flex justify-center md:justify-start space-x-5 social-icons">
                             <!-- Social Icons Here -->
                            <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram" class="footer-social-icon hover:text-[#E1306C]"><i class="fab fa-instagram"></i></a>
                            <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook" class="footer-social-icon hover:text-[#1877F2]"><i class="fab fa-facebook-f"></i></a>
                            <a href="..." target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter" class="footer-social-icon hover:text-[#1DA1F2]"><i class="fab fa-twitter"></i></a>
                            <a href="..." target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn" class="footer-social-icon hover:text-[#0A66C2]"><i class="fab fa-linkedin-in"></i></a>
                         </div>
                     </div>
                     <!-- Map Placeholder -->
                     <div class="mb-10 pt-8 border-t border-theme-border/50">
                         <h4 class="text-lg font-semibold text-theme-secondary mb-4">Visit Us</h4>
                         <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3406.124022090013!2d75.5963185752068!3d31.339546756899223!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b7f02a86379%3A0x4c61457c43d15b97!2s36%2C%20New%20Vivekanand%20Park%2C%20Maqsudan%2C%20Jalandhar%2C%20Punjab%20144008!5e0!3m2!1sen!2sin!4v1700223266482!5m2!1sen!2sin" width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" class="rounded-lg shadow-md border border-theme-border/50"></iframe>
                     </div>
                     <!-- Registration Info Placeholder -->
                     <div class="registration-info">
                         <h4 class="text-sm font-semibold text-theme-primary dark:text-theme-primary mb-2">Registration Details</h4>
                         <p>Registered under the Societies Registration Act, 1860. <br>Registration No: 737 of 2007-08. <br>Approved under Section 12A & 80G of the Income Tax Act, 1961.</p>
                     </div>
                 </div>
                <!-- Contact Form -->
                <div class="lg:col-span-3 panel !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50 animate-on-scroll fade-in-right delay-100">
                    <h3 class="text-2xl mb-8 font-semibold !text-theme-text-heading text-center lg:text-left">Send Us a Message</h3>
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <!-- Hidden fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                        <div class="honeypot-field" aria-hidden="true">
                             <label for="website_url_contact">Keep Blank</label>
                             <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                         </div>
                        <!-- Form Fields -->
                        <div>
                            <label for="contact_name" class="form-label required">Name</label>
                            <input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="form-input <?= get_field_error_class('contact_form', 'name') ?>" placeholder="e.g., Jane Doe" aria-required="true" <?= get_aria_describedby('contact_form', 'name') ?>>
                            <?= get_field_error_html('contact_form', 'name') ?>
                        </div>
                        <div>
                            <label for="contact_email" class="form-label required">Email</label>
                            <input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="form-input <?= get_field_error_class('contact_form', 'email') ?>" placeholder="e.g., jane.doe@example.com" aria-required="true" <?= get_aria_describedby('contact_form', 'email') ?>>
                            <?= get_field_error_html('contact_form', 'email') ?>
                        </div>
                        <div>
                            <label for="contact_message" class="form-label required">Message</label>
                            <textarea id="contact_message" name="message" rows="5" required class="form-input <?= get_field_error_class('contact_form', 'message') ?>" placeholder="Your thoughts..." aria-required="true" <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea>
                            <?= get_field_error_html('contact_form', 'message') ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-full sm:w-auto" id="contact-submit-button">
                            <span class="button-text flex items-center"><i class="fas fa-paper-plane"></i>Send Message</span>
                            <span class="spinner hidden ml-2"></span>
                        </button>
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
<footer class="bg-slate-900 dark:bg-black text-gray-400 pt-16 pb-8 mt-0 border-t-4 border-theme-primary dark:border-theme-primary">
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
                     <li><a href="/privacy-policy.php">Privacy</a></li> <!-- CHANGE LINK IF NEEDED -->
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
   <i class="fas fa-arrow-up text-lg"></i> <!-- Slightly larger icon -->
</button>

<!-- Simple Lightbox JS (Place before main script) -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        console.log("PAHAL Main Page JS Loaded");

        // --- Theme Toggle ---
        const themeToggleBtn = document.getElementById('theme-toggle');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const htmlElement = document.documentElement;

        const applyTheme = (theme) => {
            if (theme === 'dark') {
                htmlElement.classList.add('dark');
                darkIcon?.classList.add('hidden');
                lightIcon?.classList.remove('hidden');
                localStorage.setItem('theme', 'dark');
            } else {
                htmlElement.classList.remove('dark');
                darkIcon?.classList.remove('hidden');
                lightIcon?.classList.add('hidden');
                localStorage.setItem('theme', 'light');
            }
        };

        // Check saved theme or system preference
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
        applyTheme(initialTheme); // Apply theme on load

        // Toggle theme on button click
        themeToggleBtn?.addEventListener('click', () => {
            applyTheme(htmlElement.classList.contains('dark') ? 'light' : 'dark');
        });


        // --- Header & Layout Updates on Scroll/Resize ---
        const header = document.getElementById('main-header');
        const backToTopButton = document.getElementById('back-to-top');
        const sections = document.querySelectorAll('main section[id]'); // Get all main sections with an ID
        let headerHeight = header?.offsetHeight ?? 70; // Default height if header not found

        let scrollTimeout;
        const updateLayout = () => {
            // Update header shadow on scroll
            if (window.scrollY > 50) {
                header?.classList.add('scrolled');
            } else {
                header?.classList.remove('scrolled');
            }

            // Show/hide back to top button
            if (backToTopButton) {
                 if (window.scrollY > window.innerHeight / 2) { // Show after scrolling down half the viewport
                     backToTopButton.classList.add('visible');
                 } else {
                     backToTopButton.classList.remove('visible');
                 }
            }

             // Update header height variable (less critical with static 70px, but good practice)
             headerHeight = header?.offsetHeight ?? 70;

             // Update active navigation link based on scroll position
             setActiveLink(); // Call active link update here too
        };

        // Use passive scroll listener for performance
        window.addEventListener('scroll', () => {
             clearTimeout(scrollTimeout);
             scrollTimeout = setTimeout(updateLayout, 50); // Debounce scroll updates
        }, { passive: true });

        window.addEventListener('resize', updateLayout); // Update on resize

        // Initial layout update
        updateLayout();


        // --- Mobile Menu ---
        let isMobileMenuOpen = false;
        const navbar = document.getElementById('navbar');

        const toggleMobileMenu = (forceClose = false) => {
            if (navbar && menuToggle) {
                if (forceClose || isMobileMenuOpen) {
                    // Close menu
                    navbar.classList.remove('open');
                    menuToggle.classList.remove('open');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    isMobileMenuOpen = false;
                     // Re-enable scroll if it was disabled
                    document.body.style.overflowY = '';
                } else {
                    // Open menu
                    navbar.classList.add('open');
                    menuToggle.classList.add('open');
                    menuToggle.setAttribute('aria-expanded', 'true');
                    isMobileMenuOpen = true;
                     // Disable scroll when menu is open (optional, but prevents background scrolling)
                    document.body.style.overflowY = 'hidden';
                }
            }
        };

        // Toggle menu on button click
        menuToggle?.addEventListener('click', () => toggleMobileMenu());

        // Close menu when a nav link is clicked (in mobile view)
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                 // Only close if mobile menu is open
                 if(isMobileMenuOpen) {
                     toggleMobileMenu(true); // Force close
                 }
             });
        });

         // Close mobile menu on window resize above mobile breakpoint (optional but good UX)
         window.addEventListener('resize', () => {
             if (window.innerWidth >= 1024 && isMobileMenuOpen) { // 1024px is Tailwind's 'lg' breakpoint
                 toggleMobileMenu(true); // Force close if open and resize happens
             }
         });


        // --- Active Navigation Link Highlighting ---
         const setActiveLink = () => {
             let currentActive = '';
             sections.forEach(section => {
                 const sectionTop = section.offsetTop - headerHeight - 1; // Adjust for header height and a small margin
                 const sectionBottom = sectionTop + section.offsetHeight;
                 if (window.scrollY >= sectionTop && window.scrollY < sectionBottom) {
                     currentActive = '#' + section.id;
                 }
             });

             navLinks.forEach(link => {
                 link.classList.remove('active');
                 // Special handling for root URL if applicable, or 'Home' link for the #hero section
                 const linkHref = link.getAttribute('href');
                 if (linkHref === currentActive || (linkHref === '#hero' && currentActive === '')) {
                     link.classList.add('active');
                 }
                 // Handle links to other pages (e.g., blood-donation.php, e-waste.php) - these won't match #sections
                 // If you want these to be 'active' when you're on their respective pages,
                 // you'd need PHP logic to add the 'active' class on page load.
             });
         };

         let activeLinkTimeout;
         window.addEventListener('scroll', () => {
             clearTimeout(activeLinkTimeout);
             activeLinkTimeout = setTimeout(setActiveLink, 100); // Debounce
         }, { passive: true });

         // Set active link on initial load
         setActiveLink();


        // --- Smooth Scroll for Anchor Links ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            // Check if the anchor is a nav link and should be handled by the click listener above
            // Or handle all local anchor links here
             if (!anchor.classList.contains('nav-link')) { // Prevent double handling for nav links
                 anchor.addEventListener('click', function (e) {
                     e.preventDefault();
                     const targetId = this.getAttribute('href');
                     const targetElement = document.querySelector(targetId);

                     if (targetElement) {
                        // Calculate offset for fixed header
                        const elementPosition = targetElement.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - headerHeight - 20; // Add a small margin

                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });

                        // Update URL hash after scroll completes (optional)
                         // history.pushState(null, null, targetId); // Might interfere with back button
                     }
                 });
             }
        });

         // --- Handle scrolling to section after page load (e.g., after form submission redirect) ---
         // Check if there's a hash in the URL on page load
        const hash = window.location.hash;
        if (hash) {
            // Use a small delay to allow the page to render and header height to be calculated
            setTimeout(() => {
                try {
                     // Decode hash to handle special characters like spaces in IDs
                    const targetElement = document.querySelector(decodeURIComponent(hash));
                    if (targetElement) {
                        const elementPosition = targetElement.getBoundingClientRect().top;
                        // Adjust scroll position by header height and an extra margin
                        const offsetPosition = elementPosition + window.pageYOffset - headerHeight - 20; // Adjust by header height and add a small margin

                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
                         // Focus the target element for accessibility (optional)
                         targetElement.focus({ preventScroll: true });

                    } else {
                        console.warn("Target element for hash not found:", hash);
                    }
                } catch (e) {
                     console.error("Error scrolling to hash:", hash, e);
                }
            }, 100); // Delay execution slightly
        }


        // --- Back to Top Button Functionality ---
        // Visibility logic is in updateLayout() scroll handler
        backToTopButton?.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });


        // --- Form Submission Spinner & Message Animation ---
        // Select forms by the added '-tag' suffix to avoid conflicts
        const forms = document.querySelectorAll('form[id$="-form-tag"]');

         forms.forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             // Find the spinner and text span within the submit button
             const spinner = submitButton?.querySelector('.spinner');
             const buttonTextSpan = submitButton?.querySelector('.button-text');

             // Add submit event listener
             form.addEventListener('submit', (e) => {
                 // Basic HTML5 validity check before showing spinner
                 if (form.checkValidity()) {
                    // Show spinner, hide text, disable button
                    if (submitButton) submitButton.disabled = true;
                    if (buttonTextSpan) buttonTextSpan.classList.add('opacity-70'); // Or hidden/invisible
                    if (spinner) spinner.classList.remove('hidden'); // Or add appropriate display class
                 } else {
                     // If HTML5 validation fails client-side, the form won't submit,
                     // and the button remains enabled. No action needed here other than default browser validation UI.
                     console.log("Client-side validation failed, submission prevented.");
                 }
             });

             // --- Form Message Animation ---
             // The form status HTML is rendered by PHP with opacity: 0 and translate-y-2 scale-95
             // We need JS to add the animation classes *after* the element is in the DOM following a redirect
             const formId = form.id.replace('-tag', ''); // Get the original form ID
             const statusMessage = document.querySelector(`[data-form-message-id="${formId}"]`);

             if(statusMessage) {
                 // Add the class that triggers the animation
                 // Use a tiny timeout to ensure the browser registers the initial state before animating
                 setTimeout(() => {
                     statusMessage.style.opacity = '1';
                     statusMessage.style.transform = 'translateY(0) scale(1)';
                      // Or if using tailwind animation class: statusMessage.classList.add('animate-form-message-in');
                 }, 50); // Small delay for rendering
             }
         });


        // --- Gallery Lightbox Initialization ---
        try {
             // Check if SimpleLightbox library is available (loaded via CDN)
             if (typeof SimpleLightbox !== 'undefined') {
                const gallery = new SimpleLightbox('.gallery a', {
                    /* Options */
                    captionDelay: 250, // Show caption after 250ms
                    fadeSpeed: 200,    // Fade speed for lightbox overlay
                    animationSpeed: 200 // Animation speed for image transitions
                    // Add more options as needed: https://simplelightbox.com/docs/#options
                });

                // Optional: Add event listeners if needed, e.g., gallery.on('show.simplelightbox', function(){ ... });

             } else {
                 console.error("SimpleLightbox library (SimpleLightbox) not found.");
             }
        } catch(e) {
             console.error("Error initializing SimpleLightbox:", e);
        }


        // --- Animation on Scroll using Intersection Observer ---
        // Check if IntersectionObserver is supported by the browser
        if ('IntersectionObserver' in window) {
            const observerOptions = {
                root: null, // viewport
                rootMargin: '0px 0px -10% 0px', // Start animation when 10% from bottom of viewport
                threshold: 0.1 // Trigger when 10% of the element is visible
            };

            const intersectionCallback = (entries, observer) => {
                entries.forEach(entry => {
                    // If the element is visible in the viewport
                    if (entry.isIntersecting) {
                        // Add the 'is-visible' class to trigger the CSS animation
                        entry.target.classList.add('is-visible');
                        // Optionally stop observing once it's visible to save performance
                        observer.unobserve(entry.target);
                    }
                     // If you want animations to reset when they leave the viewport (e.g., for elements higher up on a long page)
                    // else {
                    //    entry.target.classList.remove('is-visible');
                    // }
                });
            };

            // Create the observer
            const observer = new IntersectionObserver(intersectionCallback, observerOptions);

            // Select all elements with the 'animate-on-scroll' class and observe them
            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                observer.observe(el);
            });

        } else {
            // Fallback for browsers that do not support Intersection Observer
            // Just show all elements immediately
            console.warn("IntersectionObserver not supported. Falling back to showing all animated elements.");
            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                el.classList.add('is-visible');
            });
        }


         // --- Modal Handling (Bank Details) ---
         const modalTriggers = document.querySelectorAll('[data-modal-target]');
         const modalClosers = document.querySelectorAll('[data-modal-close]');
         const modals = document.querySelectorAll('.modal-container'); // Select all modal containers

         // Function to open a modal
         const openModal = (modalId) => {
             const modal = document.getElementById(modalId);
             if (modal) {
                 modal.classList.add('flex'); // Use flex to display modal
                 modal.classList.remove('hidden'); // Hide utility class is removed
                 document.body.style.overflowY = 'hidden'; // Prevent scrolling background
                 // Optional: Focus the first interactive element inside the modal for accessibility
                 modal.querySelector('button, a, input, select, textarea')?.focus();
             }
         };

         // Function to close a modal
         const closeModal = (modal) => {
              if (modal) {
                modal.classList.add('hidden'); // Use hidden to hide modal
                modal.classList.remove('flex'); // Remove flex display
                document.body.style.overflowY = ''; // Restore background scrolling
                 // Optional: Return focus to the element that triggered the modal (requires storing it when modal opens)
             }
         };

         // Add event listeners to buttons that trigger modals
         modalTriggers.forEach(button => {
             button.addEventListener('click', () => {
                 const targetModalId = button.getAttribute('data-modal-target');
                 if (targetModalId) {
                     openModal(targetModalId);
                 }
             });
         });

         // Add event listeners to buttons inside modals that close them
         modalClosers.forEach(button => {
             button.addEventListener('click', () => {
                 // Find the parent modal container
                 const modal = button.closest('.modal-container');
                 if (modal) {
                     closeModal(modal);
                 }
             });
         });

         // Add event listener to close modal when clicking outside the modal-box
         modals.forEach(modal => {
             modal.addEventListener('click', (event) => {
                 // Check if the click target is the modal container itself, not its children
                 if (event.target === modal) {
                     closeModal(modal);
                 }
             });
         });

         // Add event listener to close modal on Escape key press
         document.addEventListener('keydown', (event) => {
             if (event.key === 'Escape') {
                 // Find any currently open modal and close it
                 document.querySelectorAll('.modal-container.flex').forEach(openModal => {
                     closeModal(openModal);
                 });
             }
         });


        console.log("PAHAL Main Page JS Initialized.");
    });
</script>

</body>
</html>
