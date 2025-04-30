<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Version: 4.1 (UI Refactored to match E-Waste Page Styling)
// Features: Tailwind UI (E-Waste Style), Responsive Design, Animations,
//           PHP mail(), CSRF, Honeypot, Logging (Functionality Preserved)
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration ---
// CHANGE THESE EMAIL ADDRESSES to the correct recipients for your NGO
define('RECIPIENT_EMAIL_DONOR_REG', "bloodbank@your-pahal-domain.com");         // CHANGE ME
define('RECIPIENT_EMAIL_BLOOD_REQUEST', "bloodrequests@your-pahal-domain.com"); // CHANGE ME

// CHANGE THESE POTENTIALLY to an email address associated with your domain for better deliverability
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME (email mails appear FROM)
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Blood Program');                       // CHANGE ME (name mails appear FROM)

// --- Security Settings ---
define('CSRF_TOKEN_NAME', 'csrf_token');
// Unique honeypot name, different from ewaste if both pages are on the same domain and share a form processing script
define('HONEYPOT_FIELD_NAME', 'contact_preference_blood'); // Unique honeypot name

// --- Logging ---
define('ENABLE_LOGGING', true); // Set to true to log submissions/errors
$baseDir = __DIR__; // Directory of the current script
// Ensure logs directory is writable and outside web root if possible, or protected by .htaccess
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log'); // Path to general form error log file
define('LOG_FILE_BLOOD_DONOR', $baseDir . '/logs/blood_donor_regs.log'); // Path to donor registration log file
define('LOG_FILE_BLOOD_REQUEST', $baseDir . '/logs/blood_requests.log'); // Path to blood request log file
// --- END CONFIG ---


// --- Helper Functions ---
// Using the more robust helper functions from the original Blood Donation code,
// but adjusting UI-related ones to match E-Waste page's class names.

/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    // Attempt to create log directory and protect it
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            // If directory creation fails, log error and return
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ($logFile): " . $message);
            return;
        }
        // Attempt to write .htaccess and index.html for protection
        if (is_dir($logDir)) {
             if (!file_exists($logDir . '/.htaccess')) @file_put_contents($logDir . '/.htaccess', 'Deny from all');
             if (!file_exists($logDir . '/index.html')) @file_put_contents($logDir . '/index.html', ''); // Add index file to prevent directory listing
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP'; // Get user IP
    $logEntry = "[{$timestamp}] [IP: {$ipAddress}] {$message}" . PHP_EOL;

    // Attempt to write to the log file with locking
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        $error = error_get_last(); // Get the last error if file writing failed
        error_log("Failed to write log: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown file write error'));
        error_log("Original Log Message: " . $message);
    }
}

/**
 * Generates or retrieves a CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        try {
            // Use cryptographically secure random_bytes
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            // Fallback for systems without random_bytes (less secure)
            $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true));
            log_message("CSRF token generated using fallback method. Exception: " . $e->getMessage(), LOG_FILE_ERROR);
        }
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token.
 * Unsets the session token after validation attempt to prevent reuse.
 */
function validate_csrf_token(?string $submittedToken): bool {
    // Check if session token exists and is not empty
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        log_message("CSRF Validation Failed: Session token missing or empty.", LOG_FILE_ERROR);
         // Unset submitted token to be safe (though unlikely to matter if session token is missing)
         // No session token to unset here.
        return false;
    }

    // Check if submitted token is provided
    if (empty($submittedToken)) {
         log_message("CSRF Validation Failed: Submitted token missing or empty.", LOG_FILE_ERROR);
         unset($_SESSION[CSRF_TOKEN_NAME]); // Invalidate used token
        return false;
    }

    // Use hash_equals for timing attack resistance
    $result = hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);

    // Always unset the session token after it's used/compared
    unset($_SESSION[CSRF_TOKEN_NAME]);

    if (!$result) {
         log_message("CSRF Validation Failed: Token mismatch.", LOG_FILE_ERROR);
    }

    return $result;
}

/**
 * Sanitize input string. Removes tags, trims whitespace, encodes special characters.
 */
function sanitize_string(?string $input): string {
    if ($input === null) return '';
    // Trim whitespace, remove HTML tags, convert special characters to HTML entities
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize email address. Removes illegal characters and validates format.
 */
function sanitize_email(?string $email): string {
    if ($email === null) return '';
    // Remove characters not allowed in email addresses
    $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    // Validate the sanitized email format
    return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : '';
}

/**
 * Validates input data based on rules array.
 *
 * @param array $data Associative array of field names => values.
 * @param array $rules Associative array of field names => pipe-separated rules (e.g., 'required|email|maxLength:255').
 * @return array Associative array of field names => error messages for validation failures.
 */
function validate_data(array $data, array $rules): array {
     $errors = [];

     foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        $ruleList = explode('|', $ruleString);
        // Format field name nicely for error messages
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));

        foreach ($ruleList as $rule) {
            $params = [];
            if (strpos($rule, ':') !== false) {
                list($rule, $paramString) = explode(':', $rule, 2);
                $params = explode(',', $paramString);
            }

            $isValid = true; $errorMessage = '';

            // Trim string values before validation (unless rule is 'required')
            // Note: required handles empty strings correctly.
            if (is_string($value) && $rule !== 'required') {
                 $value = trim($value);
                 // If trimming makes it empty, treat as null for non-required checks
                 if ($value === '') $value = null;
            }

            switch ($rule) {
                case 'required':
                    // Check for null, empty string, or empty array
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} is required.";
                    }
                    break;
                case 'email':
                    if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $isValid = false;
                        $errorMessage = "Please provide a valid email address.";
                    }
                    break;
                case 'minLength':
                    $minLength = (int)($params[0] ?? 0);
                    if ($value !== null && mb_strlen((string)$value, 'UTF-8') < $minLength) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must be at least {$minLength} characters.";
                    }
                    break;
                case 'maxLength':
                    $maxLength = (int)($params[0] ?? 255);
                    if ($value !== null && mb_strlen((string)$value, 'UTF-8') > $maxLength) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must not exceed {$maxLength} characters.";
                    }
                    break;
                case 'alpha_space':
                     // Allow letters and spaces, using Unicode property \p{L} for any letter
                    if ($value !== null && !preg_match('/^[\p{L}\s]+$/u', $value)) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces.";
                    }
                    break;
                case 'phone':
                     // Basic phone number format validation (allows + international, spaces, hyphens, parentheses, extensions)
                    if ($value !== null && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid phone number format.";
                    }
                    break;
                case 'date':
                     $format = $params[0] ?? 'Y-m-d'; // Default date format
                     if ($value !== null) {
                         // Attempt to create a date object from the value using the specified format
                         $d = DateTime::createFromFormat($format, $value);
                         // Check if the date object was created successfully AND if formatting it back
                         // matches the original value (prevents invalid dates like Feb 30)
                         if (!($d && $d->format($format) === $value)) {
                             $isValid = false;
                             $errorMessage = "{$fieldNameFormatted} must be a valid date in {$format} format.";
                         }
                     }
                     break;
                 case 'integer':
                     // Strict integer validation (filter_var returns false for non-integers, including empty string)
                     if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be a whole number.";
                     }
                     break;
                 case 'min':
                     $minValue = (float)($params[0] ?? 0);
                     if ($value !== null && is_numeric($value) && (float)$value < $minValue) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be at least {$minValue}.";
                     }
                     break;
                 case 'max':
                     $maxValue = (float)($params[0] ?? PHP_FLOAT_MAX);
                      if ($value !== null && is_numeric($value) && (float)$value > $maxValue) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be no more than {$maxValue}.";
                     }
                     break;
                 case 'in':
                     // Checks if value is in a list of allowed values (params)
                     if ($value !== null && is_array($params) && !in_array($value, $params)) {
                         $isValid = false;
                         $errorMessage = "Invalid selection for {$fieldNameFormatted}.";
                     }
                     break;
                 case 'required_without':
                     $otherField = $params[0] ?? null;
                     // Checks if the current field is empty AND the other specified field is also empty
                     if ($otherField && ($value === null || trim($value) === '') && empty(trim($data[$otherField] ?? ''))) {
                         $isValid = false;
                         $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_', ' ', $otherField)) . " is required.";
                     }
                     break;
             }

             // If validation failed for this rule and no error message has been set for this field yet, set it and break from inner loop
             if (!$isValid && !isset($errors[$field])) {
                 $errors[$field] = $errorMessage;
                 break; // Move to the next field after the first validation error
             }
         }
     }
     return $errors;
}


/**
 * Sends an email using the standard PHP mail() function.
 * Includes UTF-8 encoding for headers and Reply-To.
 *
 * @param string $to Recipient email address.
 * @param string $subject Email subject.
 * @param string $body Email body (plain text).
 * @param string $replyToEmail Email for Reply-To header.
 * @param string $replyToName Name for Reply-To header.
 * @param string $logContext Prefix for logging messages (e.g., "[Donor Reg Form]").
 * @return bool True on success, false on failure.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT;

    // Basic validation of recipient and sender emails
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_message("{$logContext} Email send failed: Invalid recipient email: {$to}", LOG_FILE_ERROR);
        return false;
    }
     if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
         log_message("{$logContext} Email send failed: Invalid sender email in config: {$senderEmail}", LOG_FILE_ERROR);
         return false;
     }

    // Encode sender name for non-ASCII characters
    $fromHeader = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";

    // Build Reply-To header if a valid reply-to email is provided
    $replyToValidEmail = sanitize_email($replyToEmail); // Sanitize reply-to just in case
    $replyToHeader = "";
    if (!empty($replyToValidEmail)) {
         $replyToNameClean = sanitize_string($replyToName); // Sanitize name
         $replyToFormatted = $replyToNameClean ? "=?UTF-8?B?".base64_encode($replyToNameClean)."?= <{$replyToValidEmail}>" : $replyToValidEmail;
         $replyToHeader = "Reply-To: {$replyToFormatted}\r\n";
    } else {
        // Fallback Reply-To to sender if no valid reply-to is provided
         $replyToHeader = "Reply-To: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    }


    $headers = $fromHeader . $replyToHeader;
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    // Encode subject for non-ASCII characters
    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?=";

    // Wrap long lines in the email body as per RFC 2822 (optional but good practice)
    $wrapped_body = wordwrap($body, 70, "\r\n");

    // Attempt to send the email using mail()
    // Use the fourth parameter for additional headers, and the fifth parameter (-f)
    // to set the envelope sender, which can improve deliverability with some mail servers.
    $additionalParams = "-f{$senderEmail}";

    if (@mail($to, $encodedSubject, $wrapped_body, $headers, $additionalParams)) {
        // Determine correct log file based on context
        $logFile = ($logContext === '[Donor Reg Form]') ? LOG_FILE_BLOOD_DONOR : LOG_FILE_BLOOD_REQUEST;
        log_message("{$logContext} Email successfully submitted via mail() to {$to}. Subject: {$subject}", $logFile);
        return true;
    } else {
        $errorInfo = error_get_last(); // Get the last error if mail() failed
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs.');
        log_message($errorMsg, LOG_FILE_ERROR);
        error_log($errorMsg); // Also log error server-side via PHP's default error handling
        return false;
    }
}


/**
 * Retrieves a form value safely for HTML output.
 * Protects against XSS by using htmlspecialchars.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions;
    $value = $form_submissions[$formId][$fieldName] ?? $default;
    // Handle arrays/non-scalar values gracefully if they somehow end up here
    if (is_array($value) || is_object($value)) {
         log_message("Attempted to get non-scalar value for form '{$formId}', field '{$fieldName}'.", LOG_FILE_ERROR);
        return $default; // Return default for non-scalar values
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error message) using E-Waste page's Tailwind classes.
 */
function get_form_status_html(string $formId): string {
    global $form_messages;
    if (empty($form_messages[$formId])) return '';

    $message = $form_messages[$formId];
    $isSuccess = ($message['type'] === 'success');

    // Use classes from the E-Waste page styling
    $baseClasses = 'px-4 py-3 rounded relative mb-6 form-message text-sm shadow-md border'; // E-Waste base style for messages
    $typeClasses = $isSuccess
        ? 'bg-green-100 border-green-400 text-green-800' // E-Waste success colors
        : 'bg-red-100 border-red-400 text-red-800';     // E-Waste error colors
    $iconClass = $isSuccess
        ? 'fas fa-check-circle text-green-600'        // E-Waste success icon color
        : 'fas fa-exclamation-triangle text-red-600'; // E-Waste error icon color
    $title = $isSuccess ? 'Success!' : 'Error:';

    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\">"
           . "<strong class=\"font-bold flex items-center\"><i class=\"{$iconClass} mr-2\"></i>{$title}</strong> "
           . "<span class=\"block sm:inline mt-1 ml-7\">" . htmlspecialchars($message['text']) . "</span>" // Small indent for message text
           . "</div>";
}

/**
 * Generates HTML for a field error message using E-Waste page's Tailwind classes.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors;
    $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error'); // Use formId_fieldName_error for uniqueness
    if (isset($form_errors[$formId][$fieldName])) {
        // Use classes matching E-Waste page's utility definition for errors
        return '<p class="text-red-600 text-xs italic mt-1" id="' . $errorId . '">'
               . '<i class="fas fa-times-circle mr-1"></i>' // Font Awesome icon
               . htmlspecialchars($form_errors[$formId][$fieldName])
               . '</p>';
    }
    return '';
}

/**
 * Returns Tailwind CSS classes for field highlighting based on errors,
 * matching the E-Waste page's approach.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors;
     // Use the utility class defined in E-Waste CSS for errors, and default border color
     return isset($form_errors[$formId][$fieldName])
         ? 'form-input-error' // Class defined in E-Waste utilities/components layer
         : 'border-gray-300'; // Default border class from E-Waste base styles
}

/**
 * Gets ARIA describedby attribute value if error exists, linking field to error message.
 */
function get_aria_describedby(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) {
        // Match the ID generated by get_field_error_html
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
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token(); // Generate initial token or retrieve existing

// --- Blood Donation Specific Data ---
$upcoming_camps = [
    // Example Camp Data (replace with actual data)
    ['id' => 1, 'date' => new DateTime('2024-11-15'), 'time' => '10:00 AM - 3:00 PM', 'location' => 'PAHAL NGO Main Office, Maqsudan, Jalandhar', 'organizer' => 'PAHAL & Local Hospital Partners', 'notes' => 'Walk-ins welcome, pre-registration encouraged. Refreshments provided.' ],
    ['id' => 2, 'date' => new DateTime('2024-12-10'), 'time' => '9:00 AM - 1:00 PM', 'location' => 'Community Centre, Model Town, Jalandhar', 'organizer' => 'PAHAL Youth Wing', 'notes' => 'Special drive focusing on Thalassemia awareness.' ],
     ['id' => 3, 'date' => new DateTime('2025-01-26'), 'time' => '10:00 AM - 2:00 PM', 'location' => 'Guru Gobind Singh Stadium, Jalandhar', 'organizer' => 'PAHAL & District Administration', 'notes' => 'Republic Day Blood Donation Camp.' ],
    // Add more camps here...
];

// Filter out past camps and sort by date
$today = new DateTime('today midnight'); // Ensure comparison starts from the beginning of today
$upcoming_camps = array_filter($upcoming_camps, fn($camp) => $camp['date'] >= $today);
usort($upcoming_camps, fn($a, $b) => $a['date'] <=> $b['date']); // Sort upcoming camps by date

$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']; // Standard blood types

$blood_facts = [ // Facts for the "Did You Know" section
    "One donation can save up to three lives.",
    "Blood cannot be manufactured – it only comes from generous donors.",
    "About 1 in 7 people entering a hospital need blood.",
    "The shelf life of donated blood is typically 42 days.",
    "Type O negative blood is the universal red cell donor type.",
    "Type AB positive plasma is the universal plasma donor type.",
    "Regular blood donation may help keep iron levels in check.",
    "Your body replaces the blood volume within 24-48 hours.",
    "Donating blood can help identify potential health problems.",
];


// --- Form Processing Logic (POST Request) ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = sanitize_string($_POST['form_id'] ?? ''); // Sanitize form ID
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME]);
    $logContext = "[Blood Page POST]"; // Default context

    // --- Security Checks ---
    if ($honeypot_filled) {
        log_message("{$logContext} Honeypot triggered. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Silently fail or redirect to a thank you page (acting like success)
        $_SESSION['form_messages'][$submitted_form_id] = ['type' => 'success', 'text' => 'Thank you for your submission!']; // Generic message
        // Use 303 See Other for PRG pattern compliance
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id), true, 303);
        exit;
    }
    // validate_csrf_token unsets the token on comparison attempt (success or failure)
    if (!validate_csrf_token($submitted_token)) {
        log_message("{$logContext} Invalid CSRF token. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Set a general error message or specific form message
        // Use a default form ID if the submitted one is missing/invalid to ensure message is displayed
        $displayFormId = !empty($submitted_form_id) ? $submitted_form_id : 'general_error';
        $_SESSION['form_messages'][$displayFormId] = ['type' => 'error', 'text' => 'Security token invalid or expired. Please refresh the page and try submitting the form again.'];
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_formId), true, 303); // Redirect back to page, potentially to the form section
        exit;
    }
     // Regenerate token *after* successful validation check for the *next* request
     // Note: validate_csrf_token already unset the used one.
     $csrf_token = generate_csrf_token();


    // --- Process Donor Registration Form ---
    if ($submitted_form_id === 'donor_registration_form') {
        $form_id = 'donor_registration_form';
        $logContext = "[Donor Reg Form]";
        // Sanitize Data
        $donor_name = sanitize_string($_POST['donor_name'] ?? '');
        $donor_email = sanitize_email($_POST['donor_email'] ?? '');
        $donor_phone = sanitize_string($_POST['donor_phone'] ?? '');
        $donor_blood_group = sanitize_string($_POST['donor_blood_group'] ?? '');
        $donor_dob = sanitize_string($_POST['donor_dob'] ?? '');
        $donor_location = sanitize_string($_POST['donor_location'] ?? '');
        $donor_consent = isset($_POST['donor_consent']) && $_POST['donor_consent'] === 'yes';

        // Store submitted data before validation for repopulation on error
        $submitted_data = [
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
            'donor_dob' => 'required|date:Y-m-d', // Validate date format first
            'donor_location' => 'required|maxLength:150',
        ];
        $validation_errors = validate_data($submitted_data, $rules);

        // Custom Age & Consent Validation (run after basic validation)
        $age = null;
        // Only perform age check if DOB passed initial date format validation
        if (!isset($validation_errors['donor_dob']) && !empty($donor_dob)) {
            try {
                $birthDate = new DateTime($donor_dob);
                $today = new DateTime();
                // Check if DOB is not in the future
                 if ($birthDate > $today) {
                     $validation_errors['donor_dob'] = "Date of birth cannot be in the future.";
                 } else {
                     // Calculate age and check range (18-65)
                     $age = $today->diff($birthDate)->y;
                     if ($age < 18 || $age > 65) {
                         $validation_errors['donor_dob'] = "Donors must be between 18 and 65 years old. Your age: {$age}.";
                     }
                 }
            } catch (Exception $e) {
                 // This catch might be redundant if 'date:Y-m-d' rule works correctly, but kept for safety
                 $validation_errors['donor_dob'] = $validation_errors['donor_dob'] ?? "Invalid date format.";
                 log_message("{$logContext} DOB Exception during age calculation: " . $e->getMessage(), LOG_FILE_ERROR);
            }
        }

        // Consent validation
        if (!$donor_consent) {
            $validation_errors['donor_consent'] = "You must agree to be contacted.";
        }

        // Store errors
        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_DONOR_REG;
            $subject = "New Blood Donor Registration: " . $donor_name;
            $body = "Potential blood donor registered:\n\n"
                  . "Name: {$donor_name}\n"
                  . "DOB: {$donor_dob}" . ($age !== null ? " (Age Approx: {$age})" : "") . "\n"
                  . "Email: {$donor_email}\n"
                  . "Phone: {$donor_phone}\n"
                  . "Blood Group: {$donor_blood_group}\n"
                  . "Location: {$donor_location}\n"
                  . "Consent Given: Yes\n"
                  . "\n-------------------------------------------------\n"
                  . "Submitted By IP: {$_SERVER['REMOTE_ADDR']}\n"
                  . "Timestamp: " . date('Y-m-d H:i:s T') . "\n"
                  . "-------------------------------------------------\n\n"
                  . "ACTION REQUIRED: Verify eligibility and add to donor database/list.";

            // Send email using the helper function
            if (send_email($to, $subject, $body, $donor_email, $donor_name, $logContext)) {
                $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$donor_name}! Your registration is received. We will contact you regarding donation opportunities."];
                log_message("{$logContext} Success. Name: {$donor_name}, BG: {$donor_blood_group}.", LOG_FILE_BLOOD_DONOR);
                // Clear submitted data on successful submission
                unset($submitted_data); // This prevents it from being stored in session later
            } else {
                $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$donor_name}, there was an internal error processing your registration. Please try again later or contact us directly."];
                // Error logged within send_email() helper
            }
        } else {
            // Validation errors occurred
            $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below to submit your registration."];
            log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
            // Submitted data is kept automatically by the POST handling logic below
        }
         $_SESSION['scroll_to'] = '#donor-registration'; // Set scroll target
    }

    // --- Process Blood Request Form ---
    elseif ($submitted_form_id === 'blood_request_form') {
        $form_id = 'blood_request_form';
        $logContext = "[Blood Req Form]";
        // Sanitize
        $request_patient_name = sanitize_string($_POST['request_patient_name'] ?? '');
        $request_blood_group = sanitize_string($_POST['request_blood_group'] ?? '');
        $request_units_raw = $_POST['request_units'] ?? null; // Keep raw for repopulation if invalid
        $request_hospital = sanitize_string($_POST['request_hospital'] ?? '');
        $request_contact_person = sanitize_string($_POST['request_contact_person'] ?? '');
        $request_contact_phone = sanitize_string($_POST['request_contact_phone'] ?? '');
        $request_urgency = sanitize_string($_POST['request_urgency'] ?? '');
        $request_message = sanitize_string($_POST['request_message'] ?? '');

        // Store submitted data before validation for repopulation on error
        $submitted_data = [
            'request_patient_name' => $request_patient_name, 'request_blood_group' => $request_blood_group,
            'request_units' => $request_units_raw, 'request_hospital' => $request_hospital,
            'request_contact_person' => $request_contact_person, 'request_contact_phone' => $request_contact_phone,
            'request_urgency' => $request_urgency, 'request_message' => $request_message
        ];

        // Validation Rules
        $rules = [
            'request_patient_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'request_blood_group' => 'required|in:'.implode(',', $blood_types),
            'request_units' => 'required|integer|min:1|max:20', // Validate as integer between 1 and 20
            'request_hospital' => 'required|maxLength:200',
            'request_contact_person' => 'required|alpha_space|minLength:2|maxLength:100',
            'request_contact_phone' => 'required|phone|maxLength:20',
            // Ensure urgency is one of the allowed options
            'request_urgency' => 'required|in:Emergency (Immediate),Urgent (Within 24 Hours),Within 2-3 Days,Planned (Within 1 Week)',
            'request_message' => 'maxLength:2000',
        ];
        $validation_errors = validate_data($submitted_data, $rules);

        // After validation, cast units to integer if validation passed
        $request_units = null; // Initialize validated units variable
        if(empty($validation_errors['request_units'])) {
             $request_units = (int)$request_units_raw; // Safely cast to int if validation found no error for this field
        }

        // Store errors
        $form_errors[$form_id] = $validation_errors;


         if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_BLOOD_REQUEST;
             $subject = "Blood Request ({$request_urgency}): {$request_blood_group} for {$request_patient_name}";
             $body = "Blood request submitted via PAHAL website:\n\n"
                   . "!!! BLOOD REQUEST - {$request_urgency} !!!\n\n"
                   . "Patient Name: {$request_patient_name}\n"
                   . "Blood Group Needed: {$request_blood_group}\n"
                   . "Units Required: {$request_units}\n" // Use the potentially validated integer value
                   . "Hospital Name & Location: {$request_hospital}\n"
                   . "\n-------------------------------------------------\n"
                   . "Contact Person (Attendant): {$request_contact_person}\n"
                   . "Contact Phone: {$request_contact_phone}\n"
                   . "\n-------------------------------------------------\n"
                   . "Additional Info:\n" . (!empty($request_message) ? $request_message : "(None)") . "\n"
                   . "\n-------------------------------------------------\n"
                   . "Submitted By IP: {$_SERVER['REMOTE_ADDR']}\n"
                   . "Timestamp: " . date('Y-m-d H:i:s T') . "\n"
                   . "-------------------------------------------------\n\n"
                   . "ACTION REQUIRED: Verify request and assist if possible by contacting the Contact Person.";

             // Send email using the helper function
             // Use the contact person's phone number in the reply-to if no email was provided (mail() Reply-To expects email format)
             // Since we require phone but not email for request, Reply-To won't be useful for email replies.
             // So Reply-To will just be the default sender email.
             if (send_email($to, $subject, $body, '', $request_contact_person, $logContext)) {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you! Your blood request has been submitted. We will try our best to assist and may contact {$request_contact_person}."];
                 log_message("{$logContext} Success. Patient: {$request_patient_name}, BG: {$request_blood_group}, Units: {$request_units}.", LOG_FILE_BLOOD_REQUEST);
                 // Clear submitted data on successful submission
                 unset($submitted_data); // This prevents it from being stored in session later
             } else {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, there was an internal error submitting your blood request. Please try again later or contact us directly."];
                  // Error logged within send_email() helper
             }
         } else {
             // Validation errors occurred
             $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please fix the " . count($validation_errors) . " error(s) below to submit your request."];
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
             // Submitted data is kept automatically by the POST handling logic below
         }
         $_SESSION['scroll_to'] = '#request-blood'; // Set scroll target
    }

    // --- Post-Processing & Redirect ---
    // Store form results in session (only errors and messages, submissions only if errors occurred)
    $_SESSION['form_messages'] = $form_messages;
    $_SESSION['form_errors'] = $form_errors;
    // Only store submissions if there were errors on the form that was processed
     if (isset($form_errors[$submitted_form_id]) && !empty($form_errors[$submitted_form_id])) {
         $_SESSION['form_submissions'][$submitted_form_id] = $submitted_data ?? []; // Store submitted data if available
     } else {
         // If no errors for the submitted form, clear any old submissions for that form
         if (isset($_SESSION['form_submissions'][$submitted_form_id])) {
              unset($_SESSION['form_submissions'][$submitted_form_id]);
         }
     }


     // Get scroll target and clear it from session
     $scrollTarget = $_SESSION['scroll_to'] ?? '';
     unset($_SESSION['scroll_to']);

     // Redirect using PRG pattern (HTTP 303 See Other is best practice for POST redirects)
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303);
     exit; // Terminate script after redirect

} else {
    // --- GET Request: Retrieve session data after potential redirect ---
    // Retrieve form results stored in session by the POST handler
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    // Retrieve submitted data only if redirection happened due to errors
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    // Ensure a CSRF token is available for the form(s) to be displayed
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML (using helper function) ---
// These variables are used to pre-fill form fields, typically after a validation error
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
$donor_reg_email_value = get_form_value('donor_registration_form', 'donor_email');
$donor_reg_phone_value = get_form_value('donor_registration_form', 'donor_phone');
$donor_reg_blood_group_value = get_form_value('donor_registration_form', 'donor_blood_group');
$donor_reg_dob_value = get_form_value('donor_registration_form', 'donor_dob');
$donor_reg_location_value = get_form_value('donor_registration_form', 'donor_location');
// Checkbox value needs special handling as it's 'yes' or ''
$donor_reg_consent_value = (get_form_value('donor_registration_form', 'donor_consent') === 'yes');

$blood_req_patient_name_value = get_form_value('blood_request_form', 'request_patient_name');
$blood_req_blood_group_value = get_form_value('blood_request_form', 'request_blood_group');
$blood_req_units_value = get_form_value('blood_request_form', 'request_units');
$blood_req_hospital_value = get_form_value('blood_request_form', 'request_hospital');
$blood_req_contact_person_value = get_form_value('blood_request_form', 'request_contact_person');
$blood_req_contact_phone_value = get_form_value('blood_request_form', 'request_contact_phone');
$blood_req_urgency_value = get_form_value('blood_request_form', 'request_urgency');
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');

// Define E-Waste page theme colors using PHP variables for use in Tailwind config (as per E-Waste style)
$primary_color = '#2E7D32'; // Green 800 (closer to E-Waste primary)
$primary_dark_color = '#1B5E20'; // Green 900
$accent_color = '#FFA000'; // Amber 500 (E-Waste accent)
$accent_dark_color = '#FF8F00'; // Amber 600
$secondary_color = '#F9FAFB'; // Gray 50 (E-Waste background)
$neutral_dark_color = '#374151'; // Gray 700
$neutral_medium_color = '#6B7280'; // Gray 500
$red_color = '#DC2626'; // Red 600 for errors/dangers


?>
<!DOCTYPE html>
<!-- Removed dark class - using E-Waste's single theme -->
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <!-- Removed theme color meta tags -->

    <!-- Open Graph - Update URLs and image -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/blood-donation.php"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-blood-og.jpg"/> <!-- CHANGE/CREATE -->
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">
    <!-- OG image dimensions are good practice -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">


    <!-- Favicon - Use the same as E-Waste -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
     <link rel="icon" type="image/svg+xml" href="/favicon.svg"> <!-- Optional SVG Favicon -->
     <link rel="apple-touch-icon" href="/apple-touch-icon.png"> <!-- Optional Apple Touch Icon -->


    <!-- Tailwind CSS CDN - Keep forms plugin as it's helpful, but style inputs via base layer -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Lato & Open Sans) - Matching E-Waste page -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome - Matching E-Waste page version -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Removed Leaflet CSS link - Blood page has no map -->


    <!-- Tailwind Config & Custom CSS -->
    <script>
        tailwind.config = {
          // Removed darkMode setting
          theme: {
            extend: {
              colors: {
                // Define E-Waste theme colors
                primary: '<?= $primary_color ?>', // Green 800
                'primary-dark': '<?= $primary_dark_color ?>', // Green 900
                accent: '<?= $accent_color ?>', // Amber 500
                'accent-dark': '<?= $accent_dark_color ?>', // Amber 600
                secondary: '<?= $secondary_color ?>', // Gray 50 (background)
                neutral: { light: '#F3F4F6', DEFAULT: '<?= $neutral_medium_color ?>', dark: '<?= $neutral_dark_color ?>' }, // Gray shades
                 danger: '<?= $red_color ?>', 'danger-light': '#FECACA', // Red for errors/danger
                 info: '#3B82F6', 'info-light': '#EFF6FF', // Blue for info (matching E-Waste)
              },
              fontFamily: {
                // Use E-Waste font families
                'sans': ['Open Sans', 'sans-serif'],
                'heading': ['Lato', 'sans-serif'],
                // Removed 'mono'
              },
              container: {
                // Use E-Waste container definition
                center: true, padding: '1rem', screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1140px', '2xl': '1280px' }
              },
              animation: {
                // Use E-Waste animations
                'fade-in-scale': 'fadeInScale 0.6s ease-out forwards',
                'slide-up': 'slideUp 0.5s ease-out forwards',
                'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
                // Removed Blood page animations
              },
              keyframes: {
                 // Use E-Waste keyframes
                 fadeInScale: { '0%': { opacity: 0, transform: 'scale(0.95)' }, '100%': { opacity: 1, transform: 'scale(1)' } },
                 slideUp: { '0%': { opacity: 0, transform: 'translateY(20px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                 pulseGlow: { '0%, 100%': { opacity: 1, boxShadow: '0 0 0 0 rgba(255, 160, 0, 0.7)' }, '50%': { opacity: 0.8, boxShadow: '0 0 10px 5px rgba(255, 160, 0, 0)' } }
                 // Removed Blood page keyframes
              },
              // Removed Blood page custom shadows
            }
          }
        }
    </script>
    <style type="text/tailwindcss">
        /* --- Removed CSS Variables for Theming (Matching E-Waste Single Theme) --- */
        /* Removed custom scrollbar styles */

        @layer base {
            /* Adopt E-Waste base styles */
            html { @apply scroll-smooth; } /* Removed antialiased */
            body { @apply font-sans text-neutral-dark leading-relaxed bg-secondary pt-[70px]; } /* Added pt-[70px] for fixed header */
            h1, h2, h3, h4, h5, h6 { @apply font-heading text-primary-dark font-bold leading-tight mb-4 tracking-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl; }
            h2 { @apply text-3xl md:text-4xl text-primary-dark; }
            h3 { @apply text-2xl md:text-3xl text-primary; }
            p { @apply mb-5 text-base md:text-lg text-neutral; }
            a { @apply text-primary hover:text-primary-dark transition duration-300; } /* Green links */
            /* Removed Blood page's default link styling */
            hr { @apply border-gray-300 my-12 md:my-16; } /* Matching E-Waste border/spacing */

             /* Adopt E-Waste list styles */
            ul.checkmark-list { @apply list-none space-y-2 mb-6 pl-0; }
            ul.checkmark-list li { @apply flex items-start; }
            ul.checkmark-list li::before { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-green-500 mr-3 mt-1 text-sm flex-shrink-0; }
            ul.cross-list { @apply list-none space-y-2 mb-6 pl-0; }
            ul.cross-list li { @apply flex items-start; }
            ul.cross-list li::before { content: '\f00d'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-danger mr-3 mt-1 text-sm flex-shrink-0; }

            /* Adopt E-Waste table styles (if needed - Blood page doesn't use tables in its content) */
            /* table { @apply w-full border-collapse text-left text-sm text-neutral; }
            thead { @apply bg-primary/10; }
            th { @apply border border-primary/20 px-4 py-2 font-semibold text-primary; }
            td { @apply border border-gray-300 px-4 py-2; }
            tbody tr:nth-child(odd) { @apply bg-white; }
            tbody tr:nth-child(even) { @apply bg-neutral-light; }
            tbody tr:hover { @apply bg-primary/5; } */

            /* Adopt E-Waste form element base styles */
            label { @apply block text-sm font-medium text-gray-700 mb-1; } /* Matching E-Waste label spacing */
            label.required::after { content: ' *'; @apply text-red-500; }
            input[type="text"], input[type="email"], input[type="tel"], input[type="number"], input[type="date"], select, textarea { /* Added number and date types */
                 @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition sm:text-sm; }
            textarea { @apply min-h-[120px] resize-y; } /* Use E-Waste textarea height */
             /* Select arrow using base style override, not forms plugin specific class */
             select { @apply appearance-none bg-white bg-no-repeat; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="%236B7280"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>'); background-position: right 0.5rem center; background-size: 1.5em 1.5em; }
             /* Basic checkbox styling */
            input[type='checkbox'] { @apply rounded border-gray-300 text-primary shadow-sm focus:border-primary focus:ring-primary; }

            /* Global focus style matching E-Waste */
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-accent; }

        }

        @layer components {
            /* Adopt E-Waste component styles */
            .btn { @apply inline-flex items-center justify-center bg-primary text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-primary-dark hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
            .btn i { @apply mr-2 -ml-1; }
             /* Defined btn-secondary and btn-accent based on E-Waste structure and colors */
             /* btn-secondary matches E-Waste's btn-secondary (accent color) */
            .btn-secondary { @apply inline-flex items-center justify-center bg-accent text-black font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:text-white hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             /* btn-accent uses danger color for Blood Request */
             .btn-accent { @apply inline-flex items-center justify-center bg-danger text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-red-700 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-danger focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             .btn-outline { @apply inline-flex items-center justify-center bg-transparent border-2 border-primary text-primary font-semibold py-2 px-6 rounded-full hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out; }
            /* Removed btn-icon */

            .section-padding { @apply py-16 md:py-24; } /* Matching E-Waste padding */
            .card { @apply bg-white p-6 rounded-lg shadow-md transition-shadow duration-300 hover:shadow-lg overflow-hidden; } /* Matching E-Waste card */
            /* Removed panel component */

            /* Form Section - Matching E-Waste style */
            .form-section { @apply bg-white p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-accent mt-12; }

             /* Section Title - Matching E-Waste style */
            .section-title { @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark; }
            .section-title::after { content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-primary rounded-full; }
            /* Removed section-title-inverted */

            /* Removed Blood page form component classes (.form-label, .form-input, .form-error-message) - using base styles + utility */
             /* Form message base style (details handled in get_form_status_html) */
            .form-message { /* Base class for status messages */ }
        }

        @layer utilities {
            /* Adopt E-Waste utilities */
             .honeypot-field { @apply absolute left-[-5000px] w-px h-px overflow-hidden; }
            .animate-delay-100 { animation-delay: 0.1s; }
            .animate-delay-200 { animation-delay: 0.2s; }
            .animate-delay-300 { animation-delay: 0.3s; }
            .animate-delay-400 { animation-delay: 0.4s; } .animate-delay-500 { animation-delay: 0.5s; } .animation-delay-2s { animation-delay: 2s; }

            /* Form Error Class - Matching E-Waste style */
            .form-input-error { @apply border-red-500 ring-1 ring-red-500 focus:border-red-500 focus:ring-red-500; } /* Error Class */

             /* Spinner utility */
             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current align-middle; }
        }

        /* --- Specific Component Styles --- */
        /* Header - Matching E-Waste style */
        #main-header { @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200; min-height: 70px; @apply py-2 md:py-0; }
         body { @apply pt-[70px]; } /* Offset for fixed header, redundant with base but ensures clarity */

        /* Hero Section - Adapting E-Waste hero style */
         #hero-blood {
             background: linear-gradient(rgba(179, 0, 0, 0.7), rgba(70, 0, 0, 0.8)), url('https://images.unsplash.com/photo-1591083592626-6e3a6f51475c?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1770&q=80') no-repeat center center/cover; /* Blood-themed background */
              @apply text-white section-padding flex items-center justify-center text-center relative overflow-hidden min-h-[60vh] md:min-h-[70vh];
         }
         #hero-blood h1 { @apply text-white drop-shadow-lg; } /* Matching E-Waste hero h1 */
         #hero-blood p.lead { @apply text-gray-200 max-w-3xl mx-auto drop-shadow text-xl md:text-2xl mb-8; } /* Matching E-Waste hero p */
         #hero-blood .icon-drop { @apply text-6xl text-danger mb-4 animate-pulse-glow; } /* Blood icon, using E-Waste glow animation */
         #hero-blood .cta-buttons { @apply flex flex-wrap justify-center gap-4; } /* Matching E-Waste hero button layout */
         #hero-blood .cta-buttons .btn { @apply !text-white; } /* Ensure text color for btns on dark hero */


        /* Eligibility List Icons - Adapting E-Waste list item style ideas */
        .eligibility-list li { @apply flex items-start mb-2 text-neutral; }
        .eligibility-list li i { @apply mr-3 mt-1 text-sm flex-shrink-0; font-weight: 900; } /* FA Icon style */
        .eligibility-list li i.fa-check { @apply text-green-500; }
        .eligibility-list li i.fa-exclamation-triangle { @apply text-accent; }

        /* Upcoming Camps Cards - Adapting E-Waste card style */
        .camp-card { @apply card; border-left: 4px solid <?= $accent_color ?>; } /* Use E-Waste card base, add left border */
        .camp-card .camp-date { @apply text-primary-dark font-semibold text-lg mb-1 flex items-center gap-2; } /* Adjusted styling */
        .camp-card .camp-date i { @apply text-primary; }
        .camp-card p { @apply mb-2 text-sm text-neutral; } /* Smaller text in card details */
        .camp-card .camp-location { @apply text-primary font-semibold flex items-start gap-2; } /* Adjusted styling */
         .camp-card .camp-location i { @apply mt-1 text-primary; }
        .camp-card .camp-organizer { @apply flex items-center gap-2; }
         .camp-card .camp-organizer i { @apply text-neutral; }
        .camp-card .camp-note { @apply text-xs text-neutral mt-3 pt-3 border-t border-gray-200 italic; } /* Adjusted note style */
         .camp-card .camp-note i { @apply text-neutral; }

         /* No Camps Message - Adapting E-Waste info box style */
         .no-camps-message { @apply bg-info-light p-6 rounded-lg shadow-md border-l-4 border-info text-center max-w-2xl mx-auto flex flex-col items-center animate-fade-in-scale; } /* Use E-Waste info colors/border, animation */
         .no-camps-message i { @apply text-5xl text-info mb-4; }
         .no-camps-message h3 { @apply text-info text-xl !mt-0 mb-2; } /* Adjusted heading */
         .no-camps-message p { @apply text-neutral text-base mb-0; }
         .no-camps-message a { @apply font-semibold underline text-primary hover:text-primary-dark; }


        /* Blood Facts Cards - Adapting E-Waste item list/card grid style */
        #blood-facts .fact-card { @apply card bg-primary/5 flex flex-col items-center text-center p-4 transition-transform duration-300 hover:scale-105; } /* Use E-Waste card base, light background */
        #blood-facts .fact-icon { @apply text-4xl mb-3 text-primary; } /* Primary color for icons */
        #blood-facts .fact-text { @apply text-sm font-medium text-neutral-dark; } /* Text color */
        /* Removed highlight style */


        /* Contact Info Section - Simple styling like E-Waste contact */
        #contact-info .contact-block { @apply text-neutral-dark text-lg; } /* Base text style */
        #contact-info .contact-block p { @apply mb-3 flex items-center justify-center gap-3; }
         #contact-info .contact-block p i { @apply text-primary text-2xl w-6 text-center flex-shrink-0; }
         #contact-info .contact-block p strong { @apply text-primary-dark font-semibold; }
         #contact-info .contact-block a { @apply font-semibold text-primary hover:text-primary-dark underline; } /* Match E-Waste link style */


        /* Footer - Matching E-Waste style */
        footer { @apply bg-primary-dark text-gray-300 pt-12 pb-8 mt-12; } /* Matching E-Waste footer bg/padding */
        footer .container { @apply text-center px-4; } /* Centered text, add padding */
        footer .logo-text { @apply text-2xl font-black text-white hover:text-gray-300 font-heading leading-none; } /* Matching E-Waste logo style */
        footer nav { @apply mb-4 text-sm space-x-4; } /* Matching E-Waste nav spacing */
        footer nav a { @apply hover:text-white hover:underline; } /* Matching E-Waste link style */
        footer .footer-bottom p { @apply text-xs text-gray-500 mt-1; } /* Matching E-Waste text style */
        footer .footer-bottom a { @apply hover:text-white hover:underline; } /* Matching E-Waste link style */

    </style>
</head>
<!-- Body class removed - base styles handle background/font -->
<body class="pt-[70px]"> <!-- Explicitly add padding for fixed header -->

    <!-- Header - Matching E-Waste style -->
    <header id="main-header">
       <div class="container mx-auto flex flex-wrap items-center justify-between">
            <!-- Logo -->
            <div class="logo flex-shrink-0">
               <a href="index.php#hero" aria-label="PAHAL NGO Home" class="text-3xl font-black text-primary font-heading leading-none flex items-center">
                 <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL
                </a>
           </div>
           <!-- Navigation - Matching E-Waste structure, removed theme toggle -->
           <nav aria-label="Site Navigation">
                <a href="index.php" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">Home</a>
               <a href="e-waste.php" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">E-Waste</a>
                <a href="index.php#contact" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">Contact</a>
           </nav>
           <!-- Removed theme toggle button -->
       </div>
    </header>

    <main>
        <!-- Hero Section - Adapting E-Waste hero style -->
        <section id="hero-blood" class="animate-fade-in-scale">
             <div class="container mx-auto relative z-10 max-w-4xl">
                 <div class="icon-drop"><i class="fas fa-tint drop-shadow-lg"></i></div>
                 <h1 class="mb-4 animate-fade-in-scale animation-delay-100">Donate Blood, Give the Gift of Life</h1>
                 <p class="lead animate-fade-in-scale animation-delay-200">Join PAHAL's mission to ensure a readily available and safe blood supply for our community in Jalandhar. Your generosity makes a profound difference.</p>
                 <div class="cta-buttons mt-8 animate-fade-in-scale animation-delay-300">
                     <!-- Button classes using E-Waste definitions -->
                     <a href="#donor-registration" class="btn btn-secondary"><i class="fas fa-user-plus"></i> Register as Donor</a>
                     <a href="#request-blood" class="btn btn-accent"><i class="fas fa-ambulance"></i> Request Blood Assistance</a>
                 </div>
             </div>
             <!-- Removed subtle background shapes -->
        </section>

        <!-- Informational Section Grid - Adapting E-Waste section/card style -->
        <section class="section-padding bg-white"> <!-- White background like E-Waste info sections -->
            <div class="container mx-auto">
                <h2 class="section-title">Understanding Blood Donation</h2>
                <div class="grid md:grid-cols-2 gap-8 mt-12"> <!-- Gap matching E-Waste grid -->
                    <!-- Why Donate? -->
                    <div class="card animate-slide-up animation-delay-100"> <!-- Use E-Waste card and animation -->
                         <h3 class="!mt-0 flex items-center gap-2 text-primary-dark"><i class="fas fa-heart-pulse text-3xl text-danger"></i>Why Your Donation Matters</h3> <!-- Primary/danger colors for heading/icon -->
                         <p class="text-neutral">Blood is a critical resource, constantly needed for surgeries, accident victims, cancer patients, and individuals with blood disorders like Thalassemia. It cannot be artificially created, relying solely on volunteer donors.</p>
                         <!-- Use E-Waste checkmark list style -->
                         <ul class="checkmark-list mt-4 text-sm text-neutral">
                             <li>Directly saves lives in emergencies and medical treatments.</li>
                            <li>Supports patients undergoing long-term therapies (e.g., chemotherapy).</li>
                            <li>Crucial component for maternal care during childbirth complications.</li>
                            <li>Represents a vital act of community solidarity and support.</li>
                         </ul>
                         <p class="mt-6 font-semibold text-danger text-lg border-t border-gray-200 pt-4">Be a lifeline. Your single donation can impact multiple lives.</p> <!-- Danger color for highlight text -->
                    </div>

                    <!-- Who Can Donate? -->
                    <div class="card animate-slide-up animation-delay-200"> <!-- Use E-Waste card and animation -->
                        <h3 class="text-primary !mt-0 flex items-center gap-2"><i class="fas fa-user-check text-3xl text-primary"></i>Eligibility Essentials</h3> <!-- Primary color for heading/icon -->
                        <p class="text-neutral">Ensuring the safety of both donors and recipients is paramount. General guidelines include:</p>
                        <div class="grid sm:grid-cols-2 gap-x-6 gap-y-4 mt-5">
                            <div>
                                 <h4 class="text-lg text-primary-dark mb-2 flex items-center gap-2"><i class="fas fa-check text-green-500"></i>Likely CAN donate if:</h4> <!-- Green check -->
                                 <ul class="text-neutral text-sm space-y-1.5">
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-alt text-primary"></i> Are 18-65 years old.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-weight-hanging text-primary"></i> Weigh ≥ 50 kg (110 lbs).</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-heart text-primary"></i> Are in good general health.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-tint text-primary"></i> Meet hemoglobin levels (checked at site).</li>
                                </ul>
                            </div>
                             <div>
                                <h4 class="text-lg text-primary-dark mb-2 flex items-center gap-2"><i class="fas fa-exclamation-triangle text-accent"></i>Consult staff if you:</h4> <!-- Amber warning -->
                                 <ul class="text-neutral text-sm space-y-1.5">
                                    <li class="flex items-center gap-2"><i class="fas fa-pills text-accent"></i> Take certain medications.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-procedures text-accent"></i> Have specific medical conditions.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-plane text-accent"></i> Traveled internationally recently.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-minus text-accent"></i> Donated blood recently.</li>
                                 </ul>
                             </div>
                        </div>
                        <p class="text-xs text-neutral mt-6 pt-4 border-t border-gray-200"><i class="fas fa-info-circle mr-1"></i> Final eligibility is always confirmed via a confidential screening at the donation site.</p> <!-- Match E-Waste small text style -->
                    </div>
                </div>
            </div>
        </section>

        <hr>

        <!-- Donor Registration Section - Adapting E-Waste form section style -->
        <section id="donor-registration" class="section-padding bg-primary/5"> <!-- E-Waste form section background -->
            <div class="container mx-auto">
                <h2 class="section-title"><i class="fas fa-user-plus mr-2"></i>Become a Registered Donor</h2>
                 <p class="text-center max-w-3xl mx-auto mb-10 text-lg text-neutral">Join our network of heroes! Registering allows us to contact you when your blood type is needed or for upcoming camps. Your information is kept confidential and used solely for donation purposes.</p>

                 <div class="form-section max-w-4xl mx-auto animate-slide-up"> <!-- Use E-Waste form-section class and animation -->
                     <h3 class="text-2xl mb-6 text-center font-semibold text-primary">Submit Your Registration</h3> <!-- Center, primary color -->
                     <!-- Form Status Message - Uses helper, restyled by CSS now -->
                     <?= get_form_status_html('donor_registration_form') ?>

                     <form id="donor-registration-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#donor-registration" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <input type="hidden" name="form_id" value="donor_registration_form">
                         <div class="honeypot-field" aria-hidden="true">
                             <label for="contact_preference_blood_donor">Keep This Blank</label> <!-- Matching E-Waste label text -->
                             <input type="text" id="contact_preference_blood_donor" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                         </div>

                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_name" class="required">Full Name</label> <!-- Label base style used -->
                                 <!-- Input base style used, error class added conditionally -->
                                 <input type="text" id="donor_name" name="donor_name" required value="<?= $donor_reg_name_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_name') ?>" placeholder="e.g., Priya Sharma" <?= get_aria_describedby('donor_registration_form', 'donor_name') ?>>
                                 <!-- Error message uses helper, restyled by CSS -->
                                 <?= get_field_error_html('donor_registration_form', 'donor_name') ?>
                            </div>
                             <div>
                                 <label for="donor_dob" class="required">Date of Birth</label>
                                 <input type="date" id="donor_dob" name="donor_dob" required value="<?= $donor_reg_dob_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_dob') ?>" max="<?= date('Y-m-d') ?>" <?= get_aria_describedby('donor_registration_form', 'donor_dob') ?>>
                                 <p class="text-xs text-gray-500 mt-1" id="donor_dob_hint">YYYY-MM-DD. Must be 18-65 yrs.</p> 
                                 <?= get_field_error_html('donor_registration_form', 'donor_dob') ?>
                            </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_email" class="required">Email Address</label>
                                 <input type="email" id="donor_email" name="donor_email" required value="<?= $donor_reg_email_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_email') ?>" placeholder="e.g., priya.sharma@email.com" <?= get_aria_describedby('donor_registration_form', 'donor_email') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_email') ?>
                            </div>
                             <div>
                                 <label for="donor_phone" class="required">Mobile Number</label>
                                 <input type="tel" id="donor_phone" name="donor_phone" required value="<?= $donor_reg_phone_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_phone') ?>" placeholder="e.g., 98xxxxxxxx" <?= get_aria_describedby('donor_registration_form', 'donor_phone') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_phone') ?>
                            </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_blood_group" class="required">Blood Group</label>
                                 <select id="donor_blood_group" name="donor_blood_group" required aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_blood_group') ?>" <?= get_aria_describedby('donor_registration_form', 'donor_blood_group') ?>>
                                     <option value="" disabled <?= empty($donor_reg_blood_group_value) ? 'selected' : '' ?>>-- Select Blood Group --</option>
                                     <?php foreach($blood_types as $type): ?>
                                         <option value="<?= $type ?>" <?= ($donor_reg_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                     <?php endforeach; ?>
                                 </select>
                                 <?= get_field_error_html('donor_registration_form', 'donor_blood_group') ?>
                             </div>
                             <div>
                                 <label for="donor_location" class="required">Location (Area/City)</label>
                                 <input type="text" id="donor_location" name="donor_location" required value="<?= $donor_reg_location_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_location') ?>" placeholder="e.g., Maqsudan, Jalandhar" <?= get_aria_describedby('donor_registration_form', 'donor_location') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_location') ?>
                             </div>
                         </div>
                         <div class="mt-6 pt-4 border-t border-gray-200"> <!-- Match E-Waste border -->
                             <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer p-3 rounded-md hover:bg-primary/5 transition-colors"> <!-- Simple hover effect -->
                                 <!-- Checkbox base style used -->
                                 <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?> class="h-5 w-5 shrink-0" <?= get_aria_describedby('donor_registration_form', 'donor_consent') ?>>
                                 <span class="text-sm text-neutral">I consent to PAHAL contacting me via Phone/Email regarding blood donation needs or upcoming camps. I understand this registration does not guarantee eligibility, which will be confirmed at the donation site.</span> <!-- Text color matching E-Waste -->
                             </label>
                             <?= get_field_error_html('donor_registration_form', 'donor_consent') ?>
                        </div>
                         <div class="pt-5 text-center"> <!-- E-Waste button alignment -->
                             <!-- Button class using E-Waste definitions -->
                             <button type="submit" class="btn btn-secondary w-full sm:w-auto">
                                 <span class="button-text flex items-center justify-center"><i class="fas fa-check-circle mr-2"></i>Register Now</span>
                             </button>
                         </div>
                     </form>
                </div>
             </div>
         </section>

        <hr>

        <!-- Blood Request Section - Adapting E-Waste form section style -->
        <section id="request-blood" class="section-padding">
             <div class="container mx-auto">
                <h2 class="section-title text-danger"><i class="fas fa-first-aid mr-2"></i>Request Blood Assistance</h2> <!-- Danger color for blood request -->
                 <p class="text-center max-w-3xl mx-auto mb-6 text-lg text-neutral">If you or someone you know requires blood urgently or for a planned procedure, please submit a request. PAHAL will try to connect you with registered donors or guide you to local blood banks.</p>
                 <!-- Disclaimer Box - Adapting E-Waste info box style -->
                 <div class="text-center max-w-3xl mx-auto mb-10 text-sm font-semibold text-red-800 bg-red-100 p-4 rounded-lg border border-red-400 shadow-md"><i class="fas fa-exclamation-triangle mr-2"></i> <strong>Disclaimer:</strong> PAHAL acts as a facilitator and does not operate a blood bank directly. Availability depends on donor responses and blood bank stocks. For critical emergencies, please contact hospitals/blood banks directly first.</div>

                 <div class="form-section max-w-4xl mx-auto !border-danger animate-slide-up"> <!-- Use E-Waste form-section, danger border, animation -->
                      <h3 class="text-2xl mb-6 text-center font-semibold text-primary">Submit Your Request</h3> <!-- Center, primary color -->
                      <!-- Form Status Message - Uses helper, restyled by CSS now -->
                      <?= get_form_status_html('blood_request_form') ?>
                    <form id="blood-request-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#request-blood" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="blood_request_form">
                         <div class="honeypot-field" aria-hidden="true">
                             <label for="contact_preference_blood_req">Keep This Blank</label> <!-- Matching E-Waste label text -->
                             <input type="text" id="contact_preference_blood_req" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                         </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="request_patient_name" class="required">Patient's Full Name</label> <!-- Label base style used -->
                                <!-- Input base style used, error class added conditionally -->
                                <input type="text" id="request_patient_name" name="request_patient_name" required value="<?= $blood_req_patient_name_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_patient_name') ?>" <?= get_aria_describedby('blood_request_form', 'request_patient_name') ?>>
                                <!-- Error message uses helper, restyled by CSS -->
                                <?= get_field_error_html('blood_request_form', 'request_patient_name') ?>
                            </div>
                             <div>
                                 <label for="request_blood_group" class="required">Blood Group Needed</label>
                                 <select id="request_blood_group" name="request_blood_group" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_blood_group') ?>" <?= get_aria_describedby('blood_request_form', 'request_blood_group') ?>>
                                     <option value="" disabled <?= empty($blood_req_blood_group_value) ? 'selected' : '' ?>>-- Select Blood Group --</option>
                                     <?php foreach($blood_types as $type): ?>
                                         <option value="<?= $type ?>" <?= ($blood_req_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                     <?php endforeach; ?>
                                 </select>
                                 <?= get_field_error_html('blood_request_form', 'request_blood_group') ?>
                            </div>
                         </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="request_units" class="required">Units Required</label>
                                 <input type="number" id="request_units" name="request_units" required value="<?= $blood_req_units_value ?>" min="1" max="20" step="1" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_units') ?>" placeholder="e.g., 2" <?= get_aria_describedby('blood_request_form', 'request_units') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_units') ?>
                            </div>
                            <div>
                                <label for="request_urgency" class="required">Urgency</label>
                                <select id="request_urgency" name="request_urgency" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_urgency') ?>" <?= get_aria_describedby('blood_request_form', 'request_urgency') ?>>
                                    <option value="" disabled <?= empty($blood_req_urgency_value) ? 'selected' : '' ?>>-- Select Urgency --</option>
                                    <option value="Emergency (Immediate)" <?= ($blood_req_urgency_value === 'Emergency (Immediate)') ? 'selected' : '' ?>>Emergency (Immediate)</option>
                                    <option value="Urgent (Within 24 Hours)" <?= ($blood_req_urgency_value === 'Urgent (Within 24 Hours)') ? 'selected' : '' ?>>Urgent (Within 24 Hours)</option>
                                    <option value="Within 2-3 Days" <?= ($blood_req_urgency_value === 'Within 2-3 Days') ? 'selected' : '' ?>>Within 2-3 Days</option>
                                    <option value="Planned (Within 1 Week)" <?= ($blood_req_urgency_value === 'Planned (Within 1 Week)') ? 'selected' : '' ?>>Planned (Within 1 Week)</option>
                                </select>
                                <?= get_field_error_html('blood_request_form', 'request_urgency') ?>
                            </div>
                        </div>
                         <div>
                             <label for="request_hospital" class="required">Hospital Name & Location</label>
                             <input type="text" id="request_hospital" name="request_hospital" required value="<?= $blood_req_hospital_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_hospital') ?>" placeholder="e.g., Civil Hospital, Jalandhar" <?= get_aria_describedby('blood_request_form', 'request_hospital') ?>>
                             <?= get_field_error_html('blood_request_form', 'request_hospital') ?>
                         </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="request_contact_person" class="required">Contact Person (Attendant)</label>
                                 <input type="text" id="request_contact_person" name="request_contact_person" required value="<?= $blood_req_contact_person_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_person') ?>" placeholder="e.g., Attendant's Name" <?= get_aria_describedby('blood_request_form', 'request_contact_person') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_contact_person') ?>
                             </div>
                             <div>
                                 <label for="request_contact_phone" class="required">Contact Phone</label>
                                 <input type="tel" id="request_contact_phone" name="request_contact_phone" required value="<?= $blood_req_contact_phone_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_phone') ?>" <?= get_aria_describedby('blood_request_form', 'request_contact_phone') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_contact_phone') ?>
                             </div>
                         </div>
                         <div>
                             <label for="request_message" class="form-label">Additional Info (Optional)</label>
                             <textarea id="request_message" name="request_message" rows="4" class="<?= get_field_error_class('blood_request_form', 'request_message') ?>" placeholder="e.g., Patient condition, doctor's name, specific timing needs..." <?= get_aria_describedby('blood_request_form', 'request_message') ?>><?= $blood_req_message_value ?></textarea>
                             <?= get_field_error_html('blood_request_form', 'request_message') ?>
                         </div>
                         <div class="pt-5 text-center"> <!-- E-Waste button alignment -->
                             <!-- Button class using E-Waste definitions -->
                             <button type="submit" class="btn btn-accent w-full sm:w-auto">
                                 <span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Submit Request</span>
                             </button>
                         </div>
                     </form>
                </div>
             </div>
         </section>

        <hr>

        <!-- Upcoming Camps Section - Adapting E-Waste section/card style -->
        <section id="upcoming-camps" class="section-padding bg-neutral-light"> <!-- E-Waste section background -->
            <div class="container mx-auto">
                 <h2 class="section-title"><i class="far fa-calendar-alt mr-2"></i>Upcoming Blood Donation Camps</h2>
                <?php if (!empty($upcoming_camps)): ?>
                    <p class="text-center max-w-3xl mx-auto mb-12 text-lg text-neutral">Join us at one of our upcoming events and be a hero! Your presence makes a difference.</p>
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8"> <!-- Gap matching E-Waste grid -->
                        <?php foreach ($upcoming_camps as $index => $camp): ?>
                        <div class="camp-card animate-slide-up animation-delay-<?= ($index + 1) * 100 ?>"> <!-- Use E-Waste card and animation -->
                            <p class="camp-date"><i class="fas fa-calendar-check"></i><?= $camp['date']->format('F j, Y (l)') ?></p>
                            <p class="text-sm text-neutral mb-2 flex items-center gap-2"><i class="far fa-clock"></i><?= htmlspecialchars($camp['time']) ?></p> <!-- Text color matching E-Waste -->
                            <p class="camp-location"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($camp['location']) ?></p>
                            <p class="camp-organizer text-sm"><i class="fas fa-sitemap"></i>Organized by: <?= htmlspecialchars($camp['organizer']) ?></p>
                            <?php if (!empty($camp['notes'])): ?>
                                <p class="camp-note"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($camp['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                 <?php else: ?>
                     <!-- No Camps Message - Adapting E-Waste info box style -->
                     <div class="no-camps-message">
                         <i class="fas fa-info-circle"></i>
                         <h3>No Camps Currently Scheduled</h3>
                         <p>We are actively planning our next donation camps. Please check back soon for updates, or <a href="#donor-registration">register as a donor</a> to be notified directly!</p>
                     </div>
                <?php endif; ?>
            </div>
        </section>

        <hr>

        <!-- Facts & Figures Section - Adapting E-Waste item list/card grid style -->
         <section id="blood-facts" class="section-padding bg-white"> <!-- White background like E-Waste info sections -->
            <div class="container mx-auto">
                <h2 class="section-title !mt-0">Did You Know? Blood Facts</h2>
                 <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5 md:gap-6 mt-12">
                    <?php
                        // More relevant icons for facts
                        $icons = ['fa-users', 'fa-hourglass-half', 'fa-hospital-user', 'fa-calendar-days', 'fa-flask-vial', 'fa-hand-holding-medical', 'fa-heart-circle-check', 'fa-universal-access', 'fa-lungs']; // Added one more icon
                        // Randomize icons on each page load
                        shuffle($icons);
                    ?>
                    <?php foreach ($blood_facts as $index => $fact): ?>
                    <div class="fact-card animate-fade-in-scale animation-delay-<?= ($index + 1) * 100 ?>"> <!-- Use E-Waste card base, light background, animation -->
                         <i class="fas <?= $icons[$index % count($icons)] ?> fact-icon"></i> <!-- Icon using primary color -->
                         <p class="fact-text"><?= htmlspecialchars($fact) ?></p> <!-- Text color -->
                    </div>
                    <?php endforeach; ?>
                     <!-- Removed highlighted final fact -->
                </div>
            </div>
        </section>

        <hr>

        <!-- Final CTA / Contact Info - Simple styling like E-Waste contact -->
         <section id="contact-info" class="section-padding bg-neutral-light"> <!-- E-Waste section background -->
             <div class="container mx-auto text-center max-w-3xl">
                <h2 class="section-title !mt-0">Questions? Contact Our Blood Program Team</h2>
                 <p class="text-lg mb-8 text-neutral">For specific questions about the blood donation program, eligibility, upcoming camps, or potential partnerships, please reach out directly:</p>
                <div class="contact-block inline-block text-left space-y-4 max-w-md mx-auto animate-slide-up"> <!-- Simple div with spacing, animation -->
                    <p><i class="fas fa-user-tie"></i> <strong>Coordinator:</strong>Mr. Mohit Rubel</p> 
                    <p><i class="fas fa-phone"></i> <strong>Direct Line:</strong> <a href="tel:+919855614230">+91 98556-14230</a></p>
                    <p><i class="fas fa-envelope"></i> <strong>Email:</strong> <a href="mailto:engage@pahal-ngo.org?subject=Blood%20Donation%20Inquiry" class="break-all">engage@pahal-ngo.org</a></p> 
                </div>
                <div class="mt-12 animate-slide-up animation-delay-200">
                    <a href="index.php#contact" class="btn-outline"><i class="fas fa-address-book mr-2"></i>General PAHAL Contact Info</a>
                 </div>
            </div>
        </section>

    </main>

    <!-- Removed Leaflet JS -->

    <!-- Main Application JavaScript -->
    <script>
     document.addEventListener('DOMContentLoaded', () => {
        console.log("PAHAL Blood Donation Page JS Loaded (E-Waste UI)");

        // --- Form Submission Spinner Logic (Kept from Blood page, simplified) ---
        const forms = document.querySelectorAll('form[id$="-form-tag"]'); // Target forms by suffix

         forms.forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             if (!submitButton) return; // Skip if no submit button found

             const spinner = submitButton.querySelector('.spinner');
             const buttonTextSpan = submitButton.querySelector('.button-text');

             form.addEventListener('submit', (e) => {
                 // Basic HTML5 validity check before showing spinner
                if (form.checkValidity()) {
                    submitButton.disabled = true;
                    // Show spinner, hide text
                    spinner?.classList.remove('hidden');
                    spinner?.classList.add('inline-block');
                     buttonTextSpan?.classList.add('invisible'); // Use invisible instead of opacity for layout stability
                 } else {
                      // If HTML5 validation fails client-side, the form won't submit,
                      // and the button remains enabled. No action needed here other than default browser validation UI.
                     console.log("Client-side validation failed, submission prevented.");
                 }
             });

             // Note: The button state is reset on the page reload after the PHP redirect.
             // If you were using AJAX submission, you'd need more JS here to handle server responses.
          });


        // --- Scroll Target Restoration (PHP driven via URL hash, JS just needs to handle header offset) ---
        // This logic is slightly different from the original Blood page JS but simpler
        // and works with the PHP redirect + URL hash approach.
        const hash = window.location.hash;
        if (hash) {
            try {
                const targetElement = document.querySelector(decodeURIComponent(hash));
                if (targetElement) {
                     // Wait slightly for potential layout shifts before scrolling
                    setTimeout(() => {
                        const header = document.getElementById('main-header');
                        const headerOffset = header ? header.offsetHeight : 0; // Get header height
                        const elementPosition = targetElement.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20; // Adjust by header height and add a small margin

                        window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                    }, 150); // Small delay
                }
            } catch (e) {
                console.warn("Error scrolling to hash:", hash, e);
            }
        }

        // Removed Theme Toggle JS
        // Removed DOB Hint JS
        // Removed Form Message Animation JS
        // Removed Map JS (as no map exists on this page)

        console.log("PAHAL Blood Donation Page JS Initialized.");
     });
     </script>

</body>
</html>
