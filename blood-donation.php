<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Version: 3.0 (UI/UX Enhancement - FIXED HELPER FUNCTIONS)
// ... other initial comments ...
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration (Keep Existing) ---
// ... (Your configuration constants: RECIPIENT_EMAIL_*, SENDER_EMAIL_*, etc.) ...
define('RECIPIENT_EMAIL_DONOR_REG', "bloodbank@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_BLOOD_REQUEST', "bloodrequests@your-pahal-domain.com"); // CHANGE ME
define('SENDER_EMAIL_DEFAULT', 'noreply@your-pahal-domain.com'); // CHANGE ME
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Blood Program');        // CHANGE ME
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url_blood'); // Unique honeypot name
define('ENABLE_LOGGING', true);
$baseDir = __DIR__;
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
define('LOG_FILE_BLOOD_DONOR', $baseDir . '/logs/blood_donor_regs.log');
define('LOG_FILE_BLOOD_REQUEST', $baseDir . '/logs/blood_requests.log');
// --- END CONFIG ---


// --- Helper Functions ---
// **** ADD THE FULL DEFINITIONS HERE ****

/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    // Use @ to suppress errors if directory already exists or permissions fail initially
    if (!is_dir($logDir)) {
        // Attempt to create directory recursively
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) { // Check again if mkdir failed but dir exists (race condition)
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ($logFile): " . $message); // Log original message to PHP error log
            return; // Stop logging if directory cannot be created
        }
         // Attempt to create .htaccess file for security (best effort)
         if (is_dir($logDir) && !file_exists($logDir . '/.htaccess')) {
             @file_put_contents($logDir . '/.htaccess', 'Deny from all');
         }
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    // Use LOCK_EX for atomic append, suppress errors with @
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        $error = error_get_last();
        error_log("Failed to write log: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown file write error'));
        error_log("Original Log Message: " . $message); // Log original message to PHP error log
    }
}

/**
 * Generates or retrieves a CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        try {
            // Preferred method for cryptographically secure random bytes
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            // Fallback for environments where random_bytes might fail
            $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true));
             log_message("CSRF token generated using fallback method. Exception: " . $e->getMessage(), LOG_FILE_ERROR);
        }
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token using hash_equals for timing attack resistance.
 */
function validate_csrf_token(?string $submittedToken): bool {
    // Ensure both submitted token and session token exist and are non-empty strings
    if (empty($submittedToken) || !isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
}

/**
 * Sanitize input string.
 */
function sanitize_string(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize email address.
 */
function sanitize_email(string $email): string {
    $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    // Return the sanitized email only if it's a valid format, otherwise return empty string
    return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : '';
}

/**
 * Validates input data based on rules. (Basic implementation - Consider a validation library for complex apps)
 */
function validate_data(array $data, array $rules): array {
     $errors = [];
     foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null; // Use null coalescing operator
        $ruleList = explode('|', $ruleString);
        // Format field name for error messages (e.g., 'donor_name' -> 'Donor name')
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));

        foreach ($ruleList as $rule) {
            $params = [];
            // Check if the rule has parameters (e.g., minLength:5)
            if (strpos($rule, ':') !== false) {
                list($rule, $paramString) = explode(':', $rule, 2);
                $params = explode(',', $paramString);
            }

            $isValid = true; // Assume valid initially for each rule
            $errorMessage = ''; // Default error message

            switch ($rule) {
                case 'required':
                    // Check for null, empty string, or empty array (if applicable, though less common here)
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} is required.";
                    }
                    break;
                case 'email':
                    // Use filter_var for robust email validation
                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid email address for {$fieldNameFormatted}.";
                    }
                    break;
                 case 'minLength':
                     if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)$params[0]) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters long.";
                     }
                     break;
                 case 'maxLength':
                     if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)$params[0]) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters.";
                     }
                     break;
                 case 'alpha_space':
                     // Allows letters and spaces (supports Unicode letters with /u)
                     if (!empty($value) && !preg_match('/^[\p{L}\s]+$/u', $value)) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces.";
                     }
                     break;
                 case 'phone':
                      // Slightly more flexible regex allowing optional country code, spaces, dots, hyphens, parentheses
                     // Adjust based on expected formats for your region
                     if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                         $isValid = false;
                         $errorMessage = "Please enter a valid phone number format for {$fieldNameFormatted}.";
                     }
                     break;
                 case 'date':
                     $format = $params[0] ?? 'Y-m-d'; // Default to YYYY-MM-DD
                     if (!empty($value)) {
                         $d = DateTime::createFromFormat($format, $value);
                         // Check if date was created successfully AND if formatting it back gives the original string
                         if (!($d && $d->format($format) === $value)) {
                             $isValid = false;
                             $errorMessage = "{$fieldNameFormatted} must be a valid date in {$format} format.";
                         }
                     }
                     break;
                 case 'integer':
                     if (!empty($value) && filter_var($value, FILTER_VALIDATE_INT) === false) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be a whole number.";
                     }
                     break;
                 case 'min':
                     if (!empty($value) && is_numeric($value) && $value < (float)$params[0]) { // Use float for numeric comparison
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]}.";
                     }
                     break;
                 case 'max':
                     if (!empty($value) && is_numeric($value) && $value > (float)$params[0]) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be no more than {$params[0]}.";
                     }
                     break;
                 case 'in':
                     // Check if value exists in the provided list of parameters
                     if (!empty($value) && is_array($params) && !in_array($value, $params)) {
                         $isValid = false;
                         $errorMessage = "Invalid selection for {$fieldNameFormatted}.";
                     }
                     break;
                 case 'required_without': // Example: Ensure one of two fields is filled
                      $otherField = $params[0] ?? null;
                      if ($otherField && empty($value) && empty($data[$otherField] ?? null)) {
                          $isValid = false;
                          $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_', ' ', $otherField)) . " is required.";
                      }
                      break;
                 // Add other rules as needed (numeric, url, etc.)
            }

            // If a rule failed for this field, record the first error and stop checking other rules for this field
            if (!$isValid && !isset($errors[$field])) {
                $errors[$field] = $errorMessage;
                break; // Move to the next field
            }
         }
     }
     return $errors;
}

/**
 * Sends an email using the standard PHP mail() function.
 * WARNING: mail() deliverability is highly dependent on server configuration.
 * Consider using a library like PHPMailer or Symfony Mailer with SMTP for production.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT; // Ensure this is configured on your server

    // Basic input validation
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
         log_message("{$logContext} Invalid recipient email address: {$to}", LOG_FILE_ERROR);
         return false;
    }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
         log_message("{$logContext} Invalid sender email address in config: {$senderEmail}", LOG_FILE_ERROR);
         return false; // Don't attempt to send with invalid sender
    }

    // Construct Headers
    $headers = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n"; // Encode sender name for non-ASCII chars
    if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
         $replyToFormatted = $replyToName ? "=?UTF-8?B?".base64_encode($replyToName)."?= <{$replyToEmail}>" : $replyToEmail;
         $headers .= "Reply-To: {$replyToFormatted}\r\n";
    } else {
        // If no valid reply-to provided, maybe default to sender or omit
        $headers .= "Reply-To: {$senderName} <{$senderEmail}>\r\n"; // Default reply-to sender
    }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; // Specify UTF-8
    $headers .= "Content-Transfer-Encoding: 8bit\r\n"; // Suitable for UTF-8 plain text

    // Encode subject for non-ASCII characters
    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?=";

    // Word wrap the body
    $wrapped_body = wordwrap($body, 70, "\r\n");

    // Attempt to send mail, suppressing default PHP errors with @
    // Error handling relies on checking return value and error_get_last()
    if (@mail($to, $encodedSubject, $wrapped_body, $headers, "-f{$senderEmail}")) { // Using -f to set envelope sender (may require server config)
        log_message("{$logContext} Email submitted via mail() to {$to}. Subject: {$subject}", LOG_FILE_BLOOD_DONOR); // Log success
        return true;
    } else {
        // Log detailed error if mail() fails
        $errorInfo = error_get_last();
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs, ensure sendmail/postfix is working and configured.');
        log_message($errorMsg, LOG_FILE_ERROR);
        error_log($errorMsg); // Also log to PHP's main error log
        return false;
    }
}

/**
 * Retrieves a form value safely for HTML output, using global state.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; // Use the global array holding submitted values on error/redirect
    // Check if form data exists for the given form ID and field name
    $value = $form_submissions[$formId][$fieldName] ?? $default;
    // Ensure the value is scalar before outputting (prevents errors with arrays)
    if (is_array($value)) {
        // Decide how to handle arrays - maybe return empty or log error?
        // For simple forms, often indicates an issue. Let's return empty string.
        log_message("Attempted to get non-scalar form value for {$formId}[{$fieldName}]", LOG_FILE_ERROR);
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


// --- UPDATED UI Helper Functions ---

/**
 * Generates form status HTML (success/error) with enhanced styling & animation.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; // Use global form_messages array
    if (empty($form_messages[$formId])) return '';

    $message = $form_messages[$formId];
    $isSuccess = ($message['type'] === 'success');
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg transition-all duration-500 transform opacity-0 translate-y-2'; // Base + animation start
    $typeClasses = $isSuccess
        ? 'bg-green-100 border-green-500 text-green-900 dark:bg-green-900/30 dark:border-green-700 dark:text-green-200'
        : 'bg-red-100 border-red-500 text-red-900 dark:bg-red-900/30 dark:border-red-700 dark:text-red-200';
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-600 dark:text-green-400' : 'fas fa-exclamation-triangle text-red-600 dark:text-red-400';
    $title = $isSuccess ? 'Success!' : 'Error:';

    // Add 'show' class via JS after element exists to trigger animation
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\">"
         . "<strong class=\"font-bold flex items-center\"><i class=\"{$iconClass} mr-2 text-lg\"></i>{$title}</strong> "
         . "<span class=\"block sm:inline mt-1 ml-6\">" . htmlspecialchars($message['text']) . "</span>"
         . "</div>";
}

/**
 * Generates HTML for a field error message with accessibility link.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; // Use global form_errors array
    $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) {
        return '<p class="text-red-500 dark:text-red-400 text-xs italic mt-1 font-medium" id="' . $errorId . '">'
             . '<i class="fas fa-times-circle mr-1"></i>'
             . htmlspecialchars($form_errors[$formId][$fieldName])
             . '</p>';
    }
    return '';
}

/**
 * Returns CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors; // Use global form_errors array
     $base = 'form-input'; // Defined in @layer components
     $error = 'form-input-error'; // Defined in @layer components
     return isset($form_errors[$formId][$fieldName]) ? ($base . ' ' . $error) : $base;
}

/**
 * Gets ARIA describedby attribute value if error exists.
 */
function get_aria_describedby(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) {
        return ' aria-describedby="' . htmlspecialchars($formId . '_' . $fieldName . '_error') . '"';
    }
    return '';
}
// --- END Helper Functions ---

// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "Donate Blood & Request Assistance | PAHAL NGO Jalandhar";
$page_description = "Register as a blood donor, find donation camps, or request urgent blood assistance through PAHAL NGO in Jalandhar. Your donation saves lives.";
$page_keywords = "pahal ngo blood donation, jalandhar blood bank, donate blood jalandhar, blood request jalandhar, blood camp schedule, save life, volunteer donor";

// --- Initialize Form State Variables ---
$form_submissions = [];
$form_messages = [];
$form_errors = [];
// This line caused the error - moved helper function definitions *above* it.
$csrf_token = generate_csrf_token(); // Generate initial token for GET request

// --- Dummy Data & Logic (Same as before) ---
$upcoming_camps = [
    ['id' => 1, 'date' => new DateTime('2024-11-15'), 'time' => '10:00 AM - 3:00 PM', 'location' => 'PAHAL NGO Main Office, Maqsudan, Jalandhar', 'organizer' => 'PAHAL & Local Hospital Partners', 'notes' => 'Walk-ins welcome, pre-registration encouraged. Refreshments provided.' ],
    ['id' => 2, 'date' => new DateTime('2024-12-10'), 'time' => '9:00 AM - 1:00 PM', 'location' => 'Community Centre, Model Town, Jalandhar', 'organizer' => 'PAHAL Youth Wing', 'notes' => 'Special drive focusing on Thalassemia awareness.' ],
];
// Filter and sort camps... (same logic as before)
$upcoming_camps = array_filter($upcoming_camps, fn($camp) => $camp['date'] >= new DateTime('today'));
usort($upcoming_camps, fn($a, $b) => $a['date'] <=> $b['date']);

$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$blood_facts = ["One donation can save up to three lives.", "Blood cannot be manufactured – it only comes from generous donors.", "About 1 in 7 people entering a hospital need blood.", "The shelf life of donated blood is typically 42 days.", "Type O negative blood is the universal red cell donor type.", "Type AB positive plasma is the universal plasma donor type.", "Regular blood donation may help keep iron levels in check."];


// --- Form Processing Logic ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- ANTI-SPAM / CSRF CHECK ---
    $submitted_form_id = $_POST['form_id'] ?? null;
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME]);

    if ($honeypot_filled || !validate_csrf_token($submitted_token)) {
        $reason = $honeypot_filled ? "Honeypot filled" : "Invalid CSRF token";
        log_message("[SECURITY FAILED] Blood Page - {$reason}. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        http_response_code(403); // Forbidden
        // Display a generic error page or message
        die("Security validation failed. Please refresh the page using Ctrl+F5 or Cmd+R and try submitting again. Do not use browser back/forward buttons after submitting.");
    }

    // --- Process Donor Registration Form ---
    if ($submitted_form_id === 'donor_registration_form') {
        $form_id = 'donor_registration_form'; $form_errors[$form_id] = [];
        // Sanitize Data
        $donor_name = sanitize_string($_POST['donor_name'] ?? '');
        $donor_email = sanitize_email($_POST['donor_email'] ?? '');
        $donor_phone = sanitize_string($_POST['donor_phone'] ?? ''); // Keep as string for formats
        $donor_blood_group = sanitize_string($_POST['donor_blood_group'] ?? '');
        $donor_dob = sanitize_string($_POST['donor_dob'] ?? ''); // Keep as string for validation
        $donor_location = sanitize_string($_POST['donor_location'] ?? '');
        $donor_consent = isset($_POST['donor_consent']) && $_POST['donor_consent'] === 'yes';

        // Store sanitized data for potential re-population
        $form_submissions[$form_id] = [
            'donor_name' => $donor_name, 'donor_email' => $donor_email, 'donor_phone' => $donor_phone,
            'donor_blood_group' => $donor_blood_group, 'donor_dob' => $donor_dob,
            'donor_location' => $donor_location, 'donor_consent' => $donor_consent ? 'yes' : ''
        ];

        // Validation Rules
        $rules = [
            'donor_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'donor_email' => 'required|email|maxLength:255',
            'donor_phone' => 'required|phone|maxLength:20',
            'donor_blood_group' => 'required|in:'.implode(',', $blood_types),
            'donor_dob' => 'required|date:Y-m-d', // Validate specific format
            'donor_location' => 'required|maxLength:150',
            // 'donor_consent' validation handled separately below
        ];
        $validation_errors = validate_data($form_submissions[$form_id], $rules);

        // Custom Age Validation (after basic date format check)
        $age = null;
        if (empty($validation_errors['donor_dob']) && !empty($donor_dob)) { // Only check if date format is valid
            try {
                $birthDate = new DateTime($donor_dob);
                $today = new DateTime();
                 if ($birthDate > $today) { // Prevent future dates
                     $validation_errors['donor_dob'] = "Date of birth cannot be in the future.";
                 } else {
                    $age = $today->diff($birthDate)->y;
                    if ($age < 18 || $age > 65) {
                        $validation_errors['donor_dob'] = "Donors must typically be between 18 and 65 years old. Your age: {$age}.";
                    }
                 }
            } catch (Exception $e) {
                // This case should be rare if date format validation passed, but for safety:
                $validation_errors['donor_dob'] = "Invalid date format encountered during age calculation.";
                log_message("Error calculating age for DOB: {$donor_dob}. Exception: " . $e->getMessage(), LOG_FILE_ERROR);
            }
        }

        // Custom Consent Check
        if (!$donor_consent) {
            $validation_errors['donor_consent'] = "You must consent to be contacted for donation needs.";
        }

        // Assign errors to the global state
        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_DONOR_REG;
            $subject = "New Blood Donor Registration: " . $donor_name;
            // Construct Email Body
            $body = "A potential blood donor has registered via the PAHAL website.\n\n"
                  . "-------------------------------------------------\n"
                  . " Donor Details:\n"
                  . "-------------------------------------------------\n"
                  . " Name:        " . $donor_name . "\n"
                  . " DOB:         " . $donor_dob . ($age !== null ? " (Age Approx: {$age})" : "") . "\n"
                  . " Email:       " . $donor_email . "\n"
                  . " Phone:       " . $donor_phone . "\n"
                  . " Blood Group: " . $donor_blood_group . "\n"
                  . " Location:    " . $donor_location . "\n"
                  . " Consent Given: Yes\n"
                  . " IP Address:   " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n"
                  . " Timestamp:    " . date('Y-m-d H:i:s T') . "\n"
                  . "-------------------------------------------------\n"
                  . "ACTION: Please verify eligibility and add to the donor database/contact list.\n"
                  . "-------------------------------------------------\n";

            $logContext = "[Donor Reg Form]";
            // Attempt to send email
            if (send_email($to, $subject, $body, $donor_email, $donor_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$donor_name}! Your registration is received. We will contact you regarding donation opportunities and eligibility confirmation."];
                log_message("{$logContext} Success. Name: {$donor_name}, BG: {$donor_blood_group}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_DONOR);
                // Clear form submissions on success
                $form_submissions[$form_id] = [];
            } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$donor_name}, there was an internal error processing your registration. Please try again later or contact us directly if the problem persists."];
                 // Error already logged within send_email()
            }
        } else {
            // Validation failed
            $errorCount = count($validation_errors);
            $plural = ($errorCount === 1) ? 'error' : 'errors';
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} {$plural} indicated below to complete your registration."];
            log_message("[Donor Reg Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        }
        $_SESSION['scroll_to'] = '#donor-registration'; // Set scroll target
    }

    // --- Process Blood Request Form ---
    elseif ($submitted_form_id === 'blood_request_form') {
        $form_id = 'blood_request_form'; $form_errors[$form_id] = [];
        // Sanitize
        $request_patient_name = sanitize_string($_POST['request_patient_name'] ?? '');
        $request_blood_group = sanitize_string($_POST['request_blood_group'] ?? '');
        $request_units_raw = $_POST['request_units'] ?? null;
        $request_hospital = sanitize_string($_POST['request_hospital'] ?? '');
        $request_contact_person = sanitize_string($_POST['request_contact_person'] ?? '');
        $request_contact_phone = sanitize_string($_POST['request_contact_phone'] ?? '');
        $request_urgency = sanitize_string($_POST['request_urgency'] ?? '');
        $request_message = sanitize_string($_POST['request_message'] ?? '');

        // Store sanitized data
        $form_submissions[$form_id] = [
            'request_patient_name' => $request_patient_name, 'request_blood_group' => $request_blood_group,
            'request_units' => $request_units_raw, // Store raw for re-populating field
            'request_hospital' => $request_hospital, 'request_contact_person' => $request_contact_person,
            'request_contact_phone' => $request_contact_phone, 'request_urgency' => $request_urgency,
            'request_message' => $request_message
        ];

        // Validation Rules
        $rules = [
            'request_patient_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'request_blood_group' => 'required|in:'.implode(',', $blood_types),
            'request_units' => 'required|integer|min:1|max:20', // Validate as integer
            'request_hospital' => 'required|maxLength:200',
            'request_contact_person' => 'required|alpha_space|minLength:2|maxLength:100',
            'request_contact_phone' => 'required|phone|maxLength:20',
            'request_urgency' => 'required|maxLength:50', // Consider making this an 'in' rule if options are fixed
            'request_message' => 'maxLength:2000', // Allow longer messages
        ];
        $validation_errors = validate_data($form_submissions[$form_id], $rules);
        $form_errors[$form_id] = $validation_errors;

        // Re-validate units strictly as integer after basic validation
        $request_units = null;
        if(empty($validation_errors['request_units'])) {
            $request_units = filter_var($request_units_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            if($request_units === false) {
                 // This should ideally be caught by the 'integer' rule, but double check
                 $form_errors[$form_id]['request_units'] = "Units required must be a whole number between 1 and 20.";
                 $validation_errors['request_units'] = $form_errors[$form_id]['request_units']; // Ensure it's counted
            }
        }


         if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_BLOOD_REQUEST;
             $subject = "Blood Request ({$request_urgency}): {$request_blood_group} for {$request_patient_name}";
             // Construct Email Body
             $body = "A blood request was submitted via PAHAL website.\n\n"
                   . "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n"
                   . "          BLOOD REQUEST DETAILS - {$request_urgency}\n"
                   . "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n\n"
                   . " Patient Name:      " . $request_patient_name . "\n"
                   . " Blood Group Needed: " . $request_blood_group . "\n"
                   . " Units Required:    " . ($request_units ?: 'Invalid Input') . "\n" // Show validated value
                   . " Urgency:           " . $request_urgency . "\n"
                   . " Hospital Name/Addr:" . $request_hospital . "\n\n"
                   . "-------------------------------------------------\n"
                   . " Contact Information:\n"
                   . "-------------------------------------------------\n"
                   . " Contact Person:    " . $request_contact_person . "\n"
                   . " Contact Phone:     " . $request_contact_phone . "\n\n"
                   . "-------------------------------------------------\n"
                   . " Additional Info:\n"
                   . "-------------------------------------------------\n"
                   . (!empty($request_message) ? wordwrap($request_message, 70, "\n                   ") : "(None)") . "\n\n" // Word wrap message
                   . "-------------------------------------------------\n"
                   . " Details Submitted By:\n"
                   . "-------------------------------------------------\n"
                   . " IP Address:        " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n"
                   . " Timestamp:         " . date('Y-m-d H:i:s T') . "\n"
                   . "-------------------------------------------------\n"
                   . " ACTION: Verify request and assist if possible (contact donors/blood banks).\n"
                   . "-------------------------------------------------\n";

             $logContext = "[Blood Req Form]";
             if (send_email($to, $subject, $body, '', $request_contact_person, $logContext)) { // Use contact person name for Reply-To Name if needed
                 $form_messages[$form_id] = ['type' => 'success', 'text' => "Your blood request has been submitted. We understand the urgency and will try our best to assist. We may contact {$request_contact_person} shortly if potential matches are found."];
                 log_message("{$logContext} Success. Patient: {$request_patient_name}, BG: {$request_blood_group}, Units: {$request_units}. Contact: {$request_contact_person}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_REQUEST);
                 $form_submissions[$form_id] = []; // Clear form on success
             } else {
                 $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, there was an error submitting your blood request. Please double-check the details, try again, or call us directly for urgent needs."];
                 // Error logged in send_email()
             }
         } else {
             $errorCount = count($validation_errors);
             $plural = ($errorCount === 1) ? 'error' : 'errors';
             $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix the {$errorCount} {$plural} indicated below to submit your request."];
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
         }
         $_SESSION['scroll_to'] = '#request-blood'; // Set scroll target
    }

    // --- Post-Processing & Redirect ---
     unset($_SESSION[CSRF_TOKEN_NAME]); // Invalidate submitted token
     $csrf_token = generate_csrf_token(); // Regenerate token for the next request

     // Store results in session for display after redirect
     $_SESSION['form_messages'] = $form_messages;
     $_SESSION['form_errors'] = $form_errors;
     // Only store submissions if there were errors, otherwise clear them
     if (!empty($form_errors[$submitted_form_id ?? ''])) {
         $_SESSION['form_submissions'] = $form_submissions;
     } else {
          // Ensure submission data is cleared if successful
          if (isset($_SESSION['form_submissions'])) unset($_SESSION['form_submissions']);
     }

     // Get scroll target and clear it from session
     $scrollTarget = $_SESSION['scroll_to'] ?? '';
     unset($_SESSION['scroll_to']);

     // Redirect to prevent form resubmission on refresh (POST-Redirect-GET pattern)
     // Use HTTP 303 See Other for PRG pattern
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303);
     exit;

} else {
    // --- GET Request: Retrieve session data after potential redirect ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = []; }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = []; }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = []; }
    // Generate token if not already set (e.g., first visit)
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $csrf_token = generate_csrf_token();
    } else {
        $csrf_token = $_SESSION[CSRF_TOKEN_NAME];
    }
}

// --- Prepare Form Data for HTML ---
// Use the get_form_value function which handles checking the $form_submissions array
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
$donor_reg_email_value = get_form_value('donor_registration_form', 'donor_email');
$donor_reg_phone_value = get_form_value('donor_registration_form', 'donor_phone');
$donor_reg_blood_group_value = get_form_value('donor_registration_form', 'donor_blood_group');
$donor_reg_dob_value = get_form_value('donor_registration_form', 'donor_dob');
$donor_reg_location_value = get_form_value('donor_registration_form', 'donor_location');
// For checkbox, check if the submitted value was 'yes'
$donor_reg_consent_value = (get_form_value('donor_registration_form', 'donor_consent') === 'yes');

$blood_req_patient_name_value = get_form_value('blood_request_form', 'request_patient_name');
$blood_req_blood_group_value = get_form_value('blood_request_form', 'request_blood_group');
$blood_req_units_value = get_form_value('blood_request_form', 'request_units'); // This will be the raw value
$blood_req_hospital_value = get_form_value('blood_request_form', 'request_hospital');
$blood_req_contact_person_value = get_form_value('blood_request_form', 'request_contact_person');
$blood_req_contact_phone_value = get_form_value('blood_request_form', 'request_contact_phone');
$blood_req_urgency_value = get_form_value('blood_request_form', 'request_urgency');
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');

?>
<!DOCTYPE html>
<!-- Add class="dark" for default dark mode -->
<html lang="en" class="dark">
<head>
    <!-- Meta tags, title, CSS links (Tailwind, Fonts, FontAwesome), etc. -->
    <!-- ... (Keep the head section from your enhanced code) ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#111827"> <!-- Dark theme color -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/blood-donation.php"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-blood-og.jpg"/> <!-- CHANGE -->

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script> <!-- Add forms plugin -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Using Poppins and Fira Code -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Fira+Code:wght@400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="icon" href="/favicon.ico" type="image/x-icon"> <!-- Make sure favicon exists -->

    <!-- Tailwind Config & Custom CSS -->
    <!-- ... (Keep the <script> and <style> block from your enhanced code) ... -->
    <script>
        tailwind.config = {
          darkMode: 'class', // Enable class-based dark mode
          theme: {
            extend: {
              fontFamily: {
                sans: ['Poppins', 'sans-serif'],
                heading: ['Poppins', 'sans-serif'], // Use Poppins for headings too
                mono: ['Fira Code', 'monospace'],
              },
              colors: {
                 // Define theme colors accessible via CSS variables AND tailwind classes
                'theme-primary': 'var(--color-primary)',
                'theme-secondary': 'var(--color-secondary)',
                'theme-accent': 'var(--color-accent)', // Usually for errors/emphasis
                'theme-success': 'var(--color-success)',
                'theme-warning': 'var(--color-warning)',
                'theme-info': 'var(--color-info)',
                'theme-bg': 'var(--color-bg)',
                'theme-surface': 'var(--color-surface)',
                'theme-text': 'var(--color-text)',
                'theme-text-muted': 'var(--color-text-muted)',
                'theme-border': 'var(--color-border)',
              },
              animation: {
                'fade-in': 'fadeIn 0.6s ease-out forwards',
                'fade-in-down': 'fadeInDown 0.7s ease-out forwards',
                'fade-in-up': 'fadeInUp 0.7s ease-out forwards',
                'slide-in-bottom': 'slideInBottom 0.8s ease-out forwards',
                'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'form-message-in': 'formMessageIn 0.5s ease-out forwards',
              },
              keyframes: {
                 fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                 fadeInDown: { '0%': { opacity: '0', transform: 'translateY(-20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                 fadeInUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                 slideInBottom: { '0%': { opacity: '0', transform: 'translateY(50px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                 formMessageIn: { '0%': { opacity: '0', transform: 'translateY(10px) scale(0.98)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
              },
              boxShadow: {
                'glow-primary': '0 0 15px 3px var(--color-primary-glow)',
                'glow-accent': '0 0 15px 3px var(--color-accent-glow)',
                'card': '0 5px 15px rgba(0, 0, 0, 0.1), 0 3px 6px rgba(0, 0, 0, 0.05)',
                'card-dark': '0 6px 20px rgba(0, 0, 0, 0.25), 0 4px 8px rgba(0, 0, 0, 0.2)',
              },
            }
          }
        }
    </script>
    <style type="text/tailwindcss">
        :root { /* Light Theme Defaults */
          --color-primary: #059669; /* Emerald 600 */
          --color-secondary: #6366f1; /* Indigo 500 */
          --color-accent: #dc2626; /* Red 600 */
          --color-success: #16a34a; /* Green 600 */
          --color-warning: #f59e0b; /* Amber 500 */
          --color-info: #0ea5e9; /* Sky 500 */
          --color-bg: #f8fafc; /* Slate 50 */
          --color-surface: #ffffff;
          --color-text: #1f2937; /* Gray 800 */
          --color-text-muted: #6b7280; /* Gray 500 */
          --color-border: #e5e7eb; /* Gray 200 */
          --color-primary-glow: rgba(5, 150, 105, 0.3);
          --color-accent-glow: rgba(220, 38, 38, 0.3);
          --scrollbar-thumb: #a1a1aa; /* Zinc 400 */
          --scrollbar-track: #e4e4e7; /* Zinc 200 */
        }

        html.dark {
          --color-primary: #2dd4bf; /* Teal 400 */
          --color-secondary: #a78bfa; /* Violet 400 */
          --color-accent: #f87171; /* Red 400 */
          --color-success: #4ade80; /* Green 400 */
          --color-warning: #facc15; /* Yellow 400 */
          --color-info: #38bdf8; /* Sky 400 */
          --color-bg: #111827; /* Gray 900 */
          --color-surface: #1f2937; /* Gray 800 */
          --color-text: #e5e7eb; /* Gray 200 */
          --color-text-muted: #9ca3af; /* Gray 400 */
          --color-border: #4b5563; /* Gray 600 */
          --color-primary-glow: rgba(45, 212, 191, 0.3);
          --color-accent-glow: rgba(248, 113, 113, 0.3);
          --scrollbar-thumb: #52525b; /* Zinc 600 */
          --scrollbar-track: #1f2937; /* Gray 800 */
          color-scheme: dark;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; border: 1px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 80%, white); }


        @layer base {
            html { @apply scroll-smooth; }
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300; }
            h1, h2, h3, h4 { @apply font-heading font-semibold tracking-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-bold text-theme-primary; }
            h2 { @apply text-3xl md:text-4xl font-bold text-theme-primary mb-4; } /* Section Title Base */
            h3 { @apply text-xl md:text-2xl font-bold text-theme-secondary mb-3 mt-6; } /* Card/Sub-section Title Base */
            h4 { @apply text-lg font-semibold text-theme-text mb-2; }
            p { @apply mb-4 leading-relaxed; }
            a { @apply text-theme-primary hover:text-theme-secondary transition-colors duration-200; }
            hr { @apply border-theme-border/50 my-8; } /* Adjusted opacity */
        }

        @layer components {
            .section-padding { @apply py-16 md:py-24 px-4; }
            .section-title { @apply text-center mb-12 md:mb-16; }
            .btn { @apply inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-theme-surface transition-all duration-200 transform hover:-translate-y-1 disabled:opacity-50 disabled:cursor-not-allowed; }
            .btn-primary { @apply text-white bg-theme-primary hover:bg-opacity-90 focus:ring-theme-primary; }
            .btn-secondary { @apply text-white bg-theme-secondary hover:bg-opacity-90 focus:ring-theme-secondary; }
            .btn-accent { @apply text-white bg-theme-accent hover:bg-opacity-90 focus:ring-theme-accent; }
            .btn-outline { @apply text-theme-primary border border-current bg-transparent hover:bg-theme-primary/10 focus:ring-theme-primary; } /* Use border-current */
            .btn-icon { @apply p-2 rounded-full; } /* For icon-only buttons like theme toggle */

            /* Enhanced Card Style */
            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/50 overflow-hidden relative transition-all duration-300; }
            .card:hover { @apply shadow-lg dark:shadow-xl transform scale-[1.02]; }

            /* Glassmorphism Panel Style */
            .panel { @apply bg-theme-surface/70 dark:bg-theme-surface/60 backdrop-blur-lg border border-theme-border/30 rounded-2xl shadow-lg p-6 md:p-8; }

            /* Form Input Styling */
             .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-surface/50 dark:bg-theme-surface/80 border-theme-border placeholder-theme-text-muted text-theme-text shadow-sm transition duration-150 ease-in-out focus:border-theme-primary focus:ring focus:ring-theme-primary focus:ring-opacity-50 focus:outline-none disabled:opacity-60; }
             label { @apply block text-sm font-medium text-theme-text-muted mb-1.5; }
             label.required::after { content: '*'; @apply text-theme-accent ml-1; }
             select.form-input { @apply pr-10 bg-no-repeat appearance-none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 0.5rem center; background-size: 1.5em 1.5em; } /* Custom arrow */
             html.dark select.form-input { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); } /* Dark mode arrow */
             textarea.form-input { @apply min-h-[100px]; }
             .form-input-error { @apply border-theme-accent ring-1 ring-theme-accent focus:border-theme-accent focus:ring-theme-accent; }
             .form-section { @apply card border-l-4 border-theme-primary mt-8; } /* Default border */
             #request-blood .form-section { @apply !border-theme-accent; } /* Specific border for request */

            .honeypot-field { @apply !absolute !-left-[5000px] !w-0 !h-0 !overflow-hidden; } /* Improved hiding */

            /* Spinner for Buttons */
             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current; } /* No margin needed if used inside flex button */
        }
        @layer utilities {
            .animation-delay-100 { animation-delay: 0.1s; }
            .animation-delay-200 { animation-delay: 0.2s; }
            .animation-delay-300 { animation-delay: 0.3s; }
            .animation-delay-400 { animation-delay: 0.4s; }
            .animation-delay-500 { animation-delay: 0.5s; }
            /* Add more delays as needed */
        }
        /* Specific Overrides / Adjustments */
         #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/80 dark:bg-theme-surface/70 backdrop-blur-md z-50 shadow-sm transition-all duration-300 border-b border-theme-border/50; min-height: 70px; @apply py-2 md:py-0; }
        body { @apply pt-[70px]; } /* Adjust based on final header height */
        /* Hero Specific */
        #hero-blood { @apply bg-gradient-to-br from-red-50 dark:from-gray-900 via-red-100 dark:via-red-900/20 to-sky-100 dark:to-sky-900/20 text-center section-padding relative overflow-hidden; }
         #hero-blood h1 { @apply text-4xl md:text-6xl font-extrabold text-theme-accent dark:text-red-400 mb-4 drop-shadow-lg; }
         #hero-blood p.lead { @apply text-lg md:text-xl text-gray-700 dark:text-gray-300 font-medium max-w-3xl mx-auto mb-8 drop-shadow-sm; }
         #hero-blood .icon-drop { @apply text-6xl text-theme-accent mb-4 animate-pulse; }
         #hero-blood .cta-buttons { @apply flex flex-col sm:flex-row items-center justify-center gap-4 mt-10; }

         /* Eligibility Icons */
         .eligibility-list li i { @apply text-lg w-5 text-center flex-shrink-0; } /* Prevent shrinking */
         .eligibility-list li i.fa-check { @apply text-theme-success; }
         .eligibility-list li i.fa-times { @apply text-theme-accent; }
         .eligibility-list li i.fa-info-circle { @apply text-theme-info; }

         /* Camps Section Styling */
         .camp-card { @apply card border-l-4 border-theme-secondary hover:!border-theme-primary; }
         .camp-card .camp-date { @apply text-theme-secondary font-bold text-lg mb-1; }
         .camp-card .camp-location { @apply text-theme-primary font-semibold; }
         .camp-card .camp-note { @apply text-xs bg-theme-primary/10 dark:bg-theme-primary/20 text-theme-primary dark:text-teal-300 p-2 rounded italic mt-3 border border-theme-primary/20;}

         /* Facts Section */
         #blood-facts .fact-card { @apply bg-theme-info/10 dark:bg-theme-info/20 border border-theme-info/30 p-4 rounded-lg text-center flex flex-col items-center justify-center min-h-[140px] transition-transform duration-300 hover:scale-105; }
         #blood-facts .fact-icon { @apply text-4xl mb-3 text-theme-info; }
         #blood-facts .fact-text { @apply text-sm font-medium text-theme-text dark:text-theme-text-muted; }
         #blood-facts .fact-card.highlight { @apply !bg-theme-primary/10 dark:!bg-theme-primary/20 !border-theme-primary/30; }
         #blood-facts .fact-card.highlight .fact-icon { @apply !text-theme-primary; }
         #blood-facts .fact-card.highlight .fact-text { @apply !text-theme-primary dark:!text-teal-300 font-semibold; }

    </style>

</head>
<body class="bg-theme-bg font-sans">

    <!-- Header -->
    <!-- ... (Keep the enhanced <header> from your enhanced code) ... -->
    <header id="main-header">
       <div class="container mx-auto px-4 flex flex-wrap items-center justify-between">
           <div class="logo flex-shrink-0 py-2">
               <a href="index.php#hero" class="text-3xl font-black text-theme-accent dark:text-red-400 font-heading leading-none flex items-center transition-opacity hover:opacity-80">
                   <img src="icon.webp" alt="PAHAL Icon" class="h-9 w-9 mr-2 object-contain"> <!-- Ensure icon.webp exists -->
                   PAHAL
               </a>
           </div>
           <nav aria-label="Site Navigation" class="flex items-center space-x-2 md:space-x-3">
                <a href="index.php" class="text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">Home</a>
                <a href="e-waste.php" class="text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">E-Waste</a>
                 <a href="index.php#contact" class="text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">Contact</a>
                 <!-- Theme Toggle Button -->
                <button id="theme-toggle" type="button" title="Toggle theme" class="btn-icon text-theme-text-muted hover:text-theme-primary hover:bg-theme-primary/10 transition-colors duration-200">
                    <i class="fas fa-moon text-lg" id="theme-toggle-dark-icon"></i>
                    <i class="fas fa-sun text-lg hidden" id="theme-toggle-light-icon"></i>
                </button>
           </nav>
       </div>
    </header>

    <main>
        <!-- Hero Section -->
        <!-- ... (Keep the enhanced <section id="hero-blood"> from your enhanced code) ... -->
        <section id="hero-blood" class="animate-fade-in">
             <div class="container mx-auto relative z-10 px-4">
                 <div class="icon-drop"><i class="fas fa-tint"></i></div>
                 <h1 class="animate-fade-in-down">Donate Blood, Give the Gift of Life</h1>
                 <p class="lead animate-fade-in-down animation-delay-200">Join PAHAL's mission to ensure a readily available and safe blood supply for our community. Your generosity makes a profound difference.</p>
                 <div class="cta-buttons animate-fade-in-up animation-delay-400">
                     <a href="#donor-registration" class="btn btn-secondary text-lg shadow-lg"><i class="fas fa-user-plus mr-2"></i> Register as Donor</a>
                     <a href="#request-blood" class="btn btn-accent text-lg shadow-lg"><i class="fas fa-ambulance mr-2"></i> Request Blood</a>
                 </div>
             </div>
             <!-- Subtle background shapes -->
             <div class="absolute top-1/4 left-1/4 w-32 h-32 bg-theme-secondary/10 dark:bg-theme-secondary/5 rounded-full blur-2xl opacity-50 animate-pulse-slow -translate-x-1/2 -translate-y-1/2"></div>
             <div class="absolute bottom-1/4 right-1/4 w-40 h-40 bg-theme-accent/10 dark:bg-theme-accent/5 rounded-full blur-2xl opacity-50 animate-pulse-slow animation-delay-2s translate-x-1/2 translate-y-1/2"></div>
         </section>

        <!-- Informational Section Grid -->
        <!-- ... (Keep the enhanced "Understanding Blood Donation" section) ... -->
        <section class="section-padding">
            <div class="container mx-auto">
                <h2 class="section-title">Understanding Blood Donation</h2>
                <div class="grid md:grid-cols-2 gap-10 lg:gap-12 mt-12">
                    <!-- Why Donate? -->
                    <div class="card animate-slide-in-bottom animation-delay-100">
                         <h3 class="!mt-0 flex items-center gap-3"><i class="fas fa-heartbeat text-3xl text-theme-accent"></i>Why Your Donation Matters</h3>
                         <p class="text-theme-text-muted">Blood is a critical resource, constantly needed for surgeries, accident victims, cancer patients, and individuals with blood disorders. It cannot be artificially created.</p>
                         <ul class="text-theme-text list-none pl-0 space-y-3 mt-4">
                             <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Directly saves lives in emergencies and medical treatments.</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Supports patients undergoing long-term therapies (e.g., chemotherapy).</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Crucial component for maternal care during childbirth.</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Represents a vital act of community solidarity and support.</li>
                         </ul>
                         <p class="mt-6 font-semibold text-theme-primary text-lg border-t border-theme-border pt-4">Be a lifeline. Your single donation can impact multiple lives.</p>
                    </div>

                    <!-- Who Can Donate? -->
                    <div class="card animate-slide-in-bottom animation-delay-200">
                        <h3 class="text-theme-info !mt-0 flex items-center gap-3"><i class="fas fa-user-check text-3xl text-theme-info"></i>Eligibility Essentials</h3>
                        <p class="text-theme-text-muted">Ensuring the safety of both donors and recipients is paramount. General guidelines include:</p>
                        <div class="grid sm:grid-cols-2 gap-x-6 gap-y-4 mt-5 eligibility-list">
                            <div>
                                 <h4 class="text-lg text-theme-success mb-2 flex items-center gap-2"><i class="fas fa-check"></i>Likely CAN donate if:</h4>
                                 <ul class="text-theme-text-muted list-none pl-0 space-y-1.5 text-sm">
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-alt"></i> Are 18-65 years old.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-weight-hanging"></i> Weigh ≥ 50 kg (110 lbs).</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-heart"></i> Are in good general health.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-tint"></i> Meet hemoglobin levels.</li>
                                </ul>
                            </div>
                             <div>
                                <h4 class="text-lg text-theme-warning mb-2 flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i>Consult staff if you:</h4>
                                 <ul class="text-theme-text-muted list-none pl-0 space-y-1.5 text-sm">
                                    <li class="flex items-center gap-2"><i class="fas fa-pills"></i> Take certain medications.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-procedures"></i> Have specific medical conditions.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-plane"></i> Traveled internationally.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-minus"></i> Donated recently.</li>
                                 </ul>
                             </div>
                        </div>
                        <p class="text-xs text-theme-text-muted mt-6 pt-4 border-t border-theme-border"><i class="fas fa-info-circle mr-1"></i> Final eligibility confirmed via confidential screening at the donation site.</p>
                    </div>
                </div>
            </div>
        </section>

        <hr class="border-theme-border/50">

        <!-- Donor Registration Section -->
        <!-- ... (Keep the enhanced <section id="donor-registration"> - Use the new helper functions) ... -->
        <section id="donor-registration" class="section-padding">
            <div class="container mx-auto">
                <h2 class="section-title"><i class="fas fa-user-plus mr-2"></i>Become a Registered Donor</h2>
                 <p class="text-center max-w-3xl mx-auto mb-10 text-lg text-theme-text-muted">Join our network of heroes! Registering allows us to contact you when your blood type is needed or for upcoming camps. Your information is kept confidential.</p>

                 <div class="panel max-w-4xl mx-auto animate-fade-in animation-delay-100">
                     <?= get_form_status_html('donor_registration_form') ?>
                     <form id="donor-registration-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#donor-registration" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <input type="hidden" name="form_id" value="donor_registration_form">
                         <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_donor">Keep Blank</label><input type="text" id="website_url_blood_donor" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div> <label for="donor_name" class="required">Full Name</label> <input type="text" id="donor_name" name="donor_name" required value="<?= $donor_reg_name_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_name') ?>" placeholder="e.g., Priya Sharma" <?= get_aria_describedby('donor_registration_form', 'donor_name') ?>> <?= get_field_error_html('donor_registration_form', 'donor_name') ?> </div>
                             <div> <label for="donor_dob" class="required">Date of Birth</label> <input type="date" id="donor_dob" name="donor_dob" required value="<?= $donor_reg_dob_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_dob') ?>" max="<?= date('Y-m-d') ?>" <?= get_aria_describedby('donor_registration_form', 'donor_dob') ?>> <p class="text-xs text-theme-text-muted mt-1" id="donor_dob_hint">YYYY-MM-DD. Must be 18-65 yrs.</p> <?= get_field_error_html('donor_registration_form', 'donor_dob') ?> </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div> <label for="donor_email" class="required">Email Address</label> <input type="email" id="donor_email" name="donor_email" required value="<?= $donor_reg_email_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_email') ?>" placeholder="e.g., priya.sharma@email.com" <?= get_aria_describedby('donor_registration_form', 'donor_email') ?>> <?= get_field_error_html('donor_registration_form', 'donor_email') ?> </div>
                             <div> <label for="donor_phone" class="required">Mobile Number</label> <input type="tel" id="donor_phone" name="donor_phone" required value="<?= $donor_reg_phone_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_phone') ?>" placeholder="e.g., 98xxxxxxxx" <?= get_aria_describedby('donor_registration_form', 'donor_phone') ?>> <?= get_field_error_html('donor_registration_form', 'donor_phone') ?> </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div> <label for="donor_blood_group" class="required">Blood Group</label> <select id="donor_blood_group" name="donor_blood_group" required aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_blood_group') ?>" <?= get_aria_describedby('donor_registration_form', 'donor_blood_group') ?>> <option value="" disabled <?= empty($donor_reg_blood_group_value) ? 'selected' : '' ?>>-- Select --</option> <?php foreach($blood_types as $type): ?> <option value="<?= $type ?>" <?= ($donor_reg_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option> <?php endforeach; ?> </select> <?= get_field_error_html('donor_registration_form', 'donor_blood_group') ?> </div>
                             <div> <label for="donor_location" class="required">Location (Area/City)</label> <input type="text" id="donor_location" name="donor_location" required value="<?= $donor_reg_location_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_location') ?>" placeholder="e.g., Maqsudan, Jalandhar" <?= get_aria_describedby('donor_registration_form', 'donor_location') ?>> <?= get_field_error_html('donor_registration_form', 'donor_location') ?> </div>
                         </div>
                         <div class="mt-6 pt-2"> <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer p-3 rounded-md hover:bg-theme-primary/10 dark:hover:bg-theme-primary/20 transition-colors"> <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?> class="h-5 w-5 text-theme-primary rounded border-theme-border focus:ring-theme-primary form-checkbox shrink-0" <?= get_aria_describedby('donor_registration_form', 'donor_consent') ?>> <span class="text-sm text-theme-text-muted dark:text-theme-text">I consent to PAHAL contacting me for donation needs/camps & understand this is not eligibility confirmation.</span> </label> <?= get_field_error_html('donor_registration_form', 'donor_consent') ?> </div>
                         <div class="pt-5 text-center md:text-left"> <button type="submit" class="btn btn-secondary w-full sm:w-auto"> <span class="spinner hidden mr-2"></span> {/* Spinner placeholder */} <i class="fas fa-check-circle mr-2"></i>Register Now </button> </div>
                     </form>
                </div>
             </div>
         </section>

        <hr class="border-theme-border/50">

        <!-- Blood Request Section -->
        <!-- ... (Keep the enhanced <section id="request-blood"> - Use the new helper functions) ... -->
        <section id="request-blood" class="section-padding">
             <div class="container mx-auto">
                <h2 class="section-title text-theme-accent dark:text-red-400"><i class="fas fa-first-aid mr-2"></i>Request Blood Assistance</h2>
                 <p class="text-center max-w-3xl mx-auto mb-6 text-lg text-theme-text-muted">If you or someone you know requires blood urgently or for a planned procedure, please submit a request. PAHAL will try to connect you with registered donors or guide you to local blood banks.</p>
                 <div class="text-center max-w-3xl mx-auto mb-10 text-sm font-semibold text-red-800 dark:text-red-300 bg-red-100 dark:bg-red-900/30 p-4 rounded-lg border border-red-300 dark:border-red-700 shadow-md"><i class="fas fa-exclamation-triangle mr-2"></i> <strong>Disclaimer:</strong> PAHAL acts as a facilitator and does not operate a blood bank directly. Availability depends on donor responses and blood bank stocks. For emergencies, please contact hospitals/blood banks directly first.</div>


                 <div class="panel max-w-4xl mx-auto !border-theme-accent animate-fade-in animation-delay-100">
                      <?= get_form_status_html('blood_request_form') ?>
                    <form id="blood-request-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#request-blood" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="blood_request_form">
                         <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_req">Keep Blank</label><input type="text" id="website_url_blood_req" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div> <label for="request_patient_name" class="required">Patient's Full Name</label> <input type="text" id="request_patient_name" name="request_patient_name" required value="<?= $blood_req_patient_name_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_patient_name') ?>" <?= get_aria_describedby('blood_request_form', 'request_patient_name') ?>> <?= get_field_error_html('blood_request_form', 'request_patient_name') ?> </div>
                             <div> <label for="request_blood_group" class="required">Blood Group Needed</label> <select id="request_blood_group" name="request_blood_group" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_blood_group') ?>" <?= get_aria_describedby('blood_request_form', 'request_blood_group') ?>> <option value="" disabled <?= empty($blood_req_blood_group_value) ? 'selected' : '' ?>>-- Select --</option> <?php foreach($blood_types as $type): ?> <option value="<?= $type ?>" <?= ($blood_req_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option> <?php endforeach; ?> </select> <?= get_field_error_html('blood_request_form', 'request_blood_group') ?> </div>
                         </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div> <label for="request_units" class="required">Units Required</label> <input type="number" id="request_units" name="request_units" required value="<?= $blood_req_units_value ?>" min="1" max="20" step="1" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_units') ?>" placeholder="e.g., 2" <?= get_aria_describedby('blood_request_form', 'request_units') ?>> <?= get_field_error_html('blood_request_form', 'request_units') ?> </div>
                            <div> <label for="request_urgency" class="required">Urgency</label> <select id="request_urgency" name="request_urgency" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_urgency') ?>" <?= get_aria_describedby('blood_request_form', 'request_urgency') ?>> <option value="" disabled <?= empty($blood_req_urgency_value) ? 'selected' : '' ?>>-- Select --</option> <option value="Emergency (Immediate)" <?= ($blood_req_urgency_value === 'Emergency (Immediate)') ? 'selected' : '' ?>>Emergency (Immediate)</option> <option value="Urgent (Within 24 Hours)" <?= ($blood_req_urgency_value === 'Urgent (Within 24 Hours)') ? 'selected' : '' ?>>Urgent (Within 24 Hours)</option> <option value="Within 2-3 Days" <?= ($blood_req_urgency_value === 'Within 2-3 Days)') ? 'selected' : '' ?>>Within 2-3 Days</option> <option value="Planned (Within 1 Week)" <?= ($blood_req_urgency_value === 'Planned (Within 1 Week)') ? 'selected' : '' ?>>Planned (Within 1 Week)</option> </select> <?= get_field_error_html('blood_request_form', 'request_urgency') ?> </div>
                        </div>
                         <div> <label for="request_hospital" class="required">Hospital Name & Location</label> <input type="text" id="request_hospital" name="request_hospital" required value="<?= $blood_req_hospital_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_hospital') ?>" placeholder="e.g., Civil Hospital, Jalandhar" <?= get_aria_describedby('blood_request_form', 'request_hospital') ?>> <?= get_field_error_html('blood_request_form', 'request_hospital') ?> </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div> <label for="request_contact_person" class="required">Contact Person</label> <input type="text" id="request_contact_person" name="request_contact_person" required value="<?= $blood_req_contact_person_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_person') ?>" placeholder="e.g., Attendant's Name" <?= get_aria_describedby('blood_request_form', 'request_contact_person') ?>> <?= get_field_error_html('blood_request_form', 'request_contact_person') ?> </div>
                             <div> <label for="request_contact_phone" class="required">Contact Phone</label> <input type="tel" id="request_contact_phone" name="request_contact_phone" required value="<?= $blood_req_contact_phone_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_phone') ?>" <?= get_aria_describedby('blood_request_form', 'request_contact_phone') ?>> <?= get_field_error_html('blood_request_form', 'request_contact_phone') ?> </div>
                         </div>
                         <div> <label for="request_message">Additional Info (Optional)</label> <textarea id="request_message" name="request_message" rows="4" class="<?= get_field_error_class('blood_request_form', 'request_message') ?>" placeholder="e.g., Patient condition, doctor's name..." <?= get_aria_describedby('blood_request_form', 'request_message') ?>><?= $blood_req_message_value ?></textarea> <?= get_field_error_html('blood_request_form', 'request_message') ?> </div>
                         <div class="pt-5 text-center md:text-left"> <button type="submit" class="btn btn-accent w-full sm:w-auto"> <span class="spinner hidden mr-2"></span> {/* Spinner placeholder */} <i class="fas fa-paper-plane mr-2"></i>Submit Request </button> </div>
                     </form>
                </div>
             </div>
         </section>

        <hr class="border-theme-border/50">

        <!-- Upcoming Camps Section -->
        <!-- ... (Keep the enhanced <section id="upcoming-camps">) ... -->
        <section id="upcoming-camps" class="section-padding">
            <div class="container mx-auto">
                 <h2 class="section-title"><i class="far fa-calendar-alt mr-2"></i>Upcoming Blood Donation Camps</h2>
                <?php if (!empty($upcoming_camps)): ?>
                    <p class="text-center max-w-3xl mx-auto mb-12 text-lg text-theme-text-muted">Join us at one of our upcoming events and be a hero!</p>
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php foreach ($upcoming_camps as $index => $camp): ?>
                        <div class="camp-card animate-fade-in animation-delay-<?= ($index + 1) * 100 ?>">
                            <p class="camp-date flex items-center gap-2"><i class="fas fa-calendar-check"></i><?= $camp['date']->format('F j, Y (l)') ?></p>
                            <p class="text-sm text-theme-text-muted mb-2 flex items-center gap-2"><i class="far fa-clock"></i><?= htmlspecialchars($camp['time']) ?></p>
                            <p class="camp-location mb-3 flex items-start gap-2"><i class="fas fa-map-marker-alt mt-1"></i><?= htmlspecialchars($camp['location']) ?></p>
                            <p class="text-sm text-theme-text-muted mb-3 flex items-center gap-2"><i class="fas fa-sitemap"></i>Organized by: <?= htmlspecialchars($camp['organizer']) ?></p>
                            <?php if (!empty($camp['notes'])): ?> <p class="camp-note"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($camp['notes']) ?></p> <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                 <?php else: ?>
                     <div class="text-center panel max-w-2xl mx-auto !border-theme-info">
                         <i class="fas fa-info-circle text-5xl text-theme-info mb-4"></i>
                         <h3 class="text-xl font-semibold text-theme-info mb-2 !mt-0">No Camps Currently Scheduled</h3>
                         <p class="text-theme-text-muted">Please check back soon for updates. You can <a href="#donor-registration" class="font-semibold underline hover:text-theme-secondary">register as a donor</a> to be notified.</p>
                     </div>
                <?php endif; ?>
            </div>
        </section>

        <hr class="border-theme-border/50">

        <!-- Facts & Figures Section -->
        <!-- ... (Keep the enhanced <section id="blood-facts">) ... -->
         <section id="blood-facts" class="section-padding bg-theme-surface/30 dark:bg-black/20">
            <div class="container mx-auto">
                <h2 class="section-title !mt-0">Did You Know? Blood Facts</h2>
                 <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5 md:gap-6 mt-12">
                    <?php
                        $icons = ['fa-users', 'fa-hourglass-half', 'fa-hospital-user', 'fa-calendar-days', 'fa-flask-vial', 'fa-hand-holding-medical', 'fa-heart-circle-check'];
                        shuffle($icons); // Randomize icons each load
                    ?>
                    <?php foreach ($blood_facts as $index => $fact): ?>
                    <div class="fact-card animate-fade-in animation-delay-<?= ($index + 1) * 100 ?>">
                         <i class="fas <?= $icons[$index % count($icons)] ?> fact-icon"></i>
                         <p class="fact-text"><?= htmlspecialchars($fact) ?></p>
                    </div>
                    <?php endforeach; ?>
                     <div class="fact-card highlight animate-fade-in animation-delay-<?= (count($blood_facts) + 1) * 100 ?>">
                         <i class="fas fa-hand-holding-heart fact-icon"></i>
                         <p class="fact-text">Your single donation matters greatly!</p>
                     </div>
                </div>
            </div>
        </section>

        <hr class="border-theme-border/50">

        <!-- Final CTA / Contact Info -->
        <!-- ... (Keep the enhanced <section id="contact-info">) ... -->
         <section id="contact-info" class="section-padding">
             <div class="container mx-auto text-center max-w-3xl">
                <h2 class="section-title !mt-0">Questions? Contact Us</h2>
                 <p class="text-lg mb-8 text-theme-text-muted">For specific questions about the blood program, eligibility, camps, or partnerships, please reach out:</p>
                <div class="panel inline-block text-left space-y-4 max-w-md mx-auto">
                    <p class="flex items-center gap-3"><i class="fas fa-user-tie text-xl text-theme-primary"></i> <strong class="text-theme-text">Coordinator:</strong> [Coordinator Name]</p> <!-- CHANGE -->
                    <p class="flex items-center gap-3"><i class="fas fa-phone text-xl text-theme-primary"></i> <strong class="text-theme-text">Direct Line:</strong> <a href="tel:+919855614230" class="font-semibold text-theme-secondary hover:underline ml-1">+91 98556-14230</a></p>
                    <p class="flex items-center gap-3"><i class="fas fa-envelope text-xl text-theme-primary"></i> <strong class="text-theme-text">Email:</strong> <a href="mailto:bloodprogram@your-pahal-domain.com?subject=Blood%20Donation%20Inquiry" class="font-semibold text-theme-secondary hover:underline ml-1 break-all">bloodprogram@your-pahal-domain.com</a></p> <!-- CHANGE -->
                </div>
                <div class="mt-12">
                    <a href="index.php#contact" class="btn btn-outline"><i class="fas fa-address-book mr-2"></i>General PAHAL Contact</a>
                 </div>
            </div>
        </section>

    </main>

    <!-- Footer -->
    <!-- ... (Keep the enhanced <footer>) ... -->
    <footer class="bg-gray-800 dark:bg-gray-900 text-gray-400 pt-16 pb-10 mt-16">
        <div class="container mx-auto px-4 text-center">
             <div class="mb-6">
                <a href="index.php" class="text-3xl font-black text-white hover:text-gray-300 font-heading leading-none inline-flex items-center">
                   <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL NGO
                </a>
                <p class="text-xs text-gray-500 mt-1">Promoting Health and Well-being in Jalandhar</p>
             </div>
            <nav class="mb-6 text-sm space-x-4">
                <a href="index.php" class="hover:text-white hover:underline">Home</a> |
                <a href="#donor-registration" class="hover:text-white hover:underline">Register Donor</a> |
                <a href="#request-blood" class="hover:text-white hover:underline">Request Blood</a> |
                <a href="#upcoming-camps" class="hover:text-white hover:underline">Camps</a> |
                <a href="e-waste.php" class="hover:text-white hover:underline">E-Waste</a> |
                <a href="index.php#contact" class="hover:text-white hover:underline">Contact</a>
            </nav>
             <p class="text-xs text-gray-500 mt-8">
                 © <?= $current_year ?> PAHAL NGO. All Rights Reserved. <span class="mx-1 hidden sm:inline">|</span> <br class="sm:hidden">
                 <a href="index.php#profile" class="hover:text-white hover:underline">About Us</a> |
                 <a href="privacy-policy.php" class="hover:text-white hover:underline">Privacy Policy (Example)</a>
             </p>
       </div>
    </footer>

    <!-- JS for interactions -->
    <!-- ... (Keep the enhanced <script> block from your enhanced code) ... -->
    <script>
     document.addEventListener('DOMContentLoaded', () => {
        // --- Theme Toggle ---
        const themeToggleBtn = document.getElementById('theme-toggle');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const htmlElement = document.documentElement;

        // Function to apply theme
        const applyTheme = (theme) => {
            if (theme === 'light') {
                htmlElement.classList.remove('dark');
                lightIcon?.classList.remove('hidden'); // Use optional chaining
                darkIcon?.classList.add('hidden');
            } else {
                htmlElement.classList.add('dark');
                darkIcon?.classList.remove('hidden');
                lightIcon?.classList.add('hidden');
            }
            localStorage.setItem('theme', theme);
        };

        // Apply theme on initial load
        const storedTheme = localStorage.getItem('theme');
        const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
        const initialTheme = storedTheme ? storedTheme : (prefersLight ? 'light' : 'dark');
        applyTheme(initialTheme);

        // Add click listener
        themeToggleBtn?.addEventListener('click', () => {
            const newTheme = htmlElement.classList.contains('dark') ? 'light' : 'dark';
            applyTheme(newTheme);
        });

        // --- Age Hint Logic (Improved) ---
         const dobInput = document.getElementById('donor_dob');
         const dobHint = document.getElementById('donor_dob_hint'); // Get hint element directly

         if (dobInput && dobHint) {
             const updateAgeHint = () => {
                 try {
                     if (!dobInput.value) { // Reset if empty
                         dobHint.textContent = 'YYYY-MM-DD. Must be 18-65 yrs.';
                         dobHint.className = 'text-xs text-theme-text-muted mt-1'; // Reset classes
                         return;
                     }
                     const birthDate = new Date(dobInput.value);
                     const today = new Date();
                     // Basic validation: check if date is valid and not in the future
                     if (isNaN(birthDate.getTime()) || birthDate > today) { throw new Error('Invalid Date'); }

                     let age = today.getFullYear() - birthDate.getFullYear();
                     const m = today.getMonth() - birthDate.getMonth();
                     if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) { age--; }

                     dobHint.className = 'text-xs mt-1 font-medium'; // Base classes for hint text
                     if (age >= 18 && age <= 65) {
                        dobHint.textContent = `Approx. age: ${age}. Eligibility OK.`;
                        dobHint.classList.add('text-theme-success');
                     } else if (age >= 0) { // Check age >=0 to avoid negative age display
                         dobHint.textContent = `Approx. age: ${age}. Note: Must be 18-65.`;
                         dobHint.classList.add('text-theme-warning');
                     } else { // Should not happen with date validation, but safety net
                         throw new Error('Calculated age is negative');
                     }
                 } catch (e) {
                      dobHint.textContent = 'Invalid date entered.';
                      dobHint.className = 'text-xs mt-1 font-medium text-theme-accent';
                 }
             };
             dobInput.addEventListener('change', updateAgeHint);
             dobInput.addEventListener('input', updateAgeHint); // Update as user types potentially
             updateAgeHint(); // Run on load in case field is pre-filled
         }

          // --- Form Message Animation ---
          document.querySelectorAll('[data-form-message-id]').forEach(msgElement => {
              // Use a small timeout to ensure the element is rendered before adding 'show' class
              setTimeout(() => {
                  msgElement.style.opacity = '1';
                  msgElement.style.transform = 'translateY(0)';
              }, 50); // 50ms delay
          });

          // --- Scroll Target Restoration (Improved Offset) ---
           const hash = window.location.hash;
           if (hash) {
              // Decode URI component for safety, although less likely needed for simple IDs
              const decodedHash = decodeURIComponent(hash);
              try {
                  const targetElement = document.querySelector(decodedHash);
                  if (targetElement) {
                       setTimeout(() => {
                          const header = document.getElementById('main-header');
                          const headerOffset = header ? header.offsetHeight : 70; // Get dynamic height or fallback
                          const elementPosition = targetElement.getBoundingClientRect().top;
                          // Calculate offset considering current scroll position and header height + extra space
                          const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20; // 20px extra space

                          window.scrollTo({
                              top: offsetPosition,
                              behavior: 'smooth'
                          });
                      }, 150); // Increased timeout for potentially complex layouts
                  }
              } catch (e) {
                  console.warn("Error finding or scrolling to hash target:", decodedHash, e);
              }
           }

           console.log("Enhanced Blood Donation Page JS Loaded");
     });
     </script>

</body>
</html>
