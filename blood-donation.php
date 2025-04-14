<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Version: 4.0 (Tailwind UI Remodification & Interactivity)
// Features: Modern UI, Responsive Design, Theme Toggle, Animations,
//           Real-time Age Hint, Enhanced Form UX
// Backend: PHP mail(), CSRF, Honeypot, Logging (Functionality Preserved)
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration ---
define('RECIPIENT_EMAIL_DONOR_REG', "bloodbank@your-pahal-domain.com");         // CHANGE ME
define('RECIPIENT_EMAIL_BLOOD_REQUEST', "bloodrequests@your-pahal-domain.com"); // CHANGE ME
define('SENDER_EMAIL_DEFAULT', 'noreply@your-pahal-domain.com');                // CHANGE ME (Needs to be configured on server for mail())
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Blood Program');                       // CHANGE ME
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url_blood'); // Unique honeypot name
define('ENABLE_LOGGING', true);
$baseDir = __DIR__;
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
define('LOG_FILE_BLOOD_DONOR', $baseDir . '/logs/blood_donor_regs.log');
define('LOG_FILE_BLOOD_REQUEST', $baseDir . '/logs/blood_requests.log');
// --- END CONFIG ---

// --- Helper Functions ---

/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            error_log("Failed to create log directory: " . $logDir);
            error_log("Original Log Message ($logFile): " . $message);
            return;
        }
         if (!file_exists($logDir . '/.htaccess')) @file_put_contents($logDir . '/.htaccess', 'Deny from all');
         if (!file_exists($logDir . '/index.html')) @file_put_contents($logDir . '/index.html', ''); // Add index file
    }
    $timestamp = date('Y-m-d H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP'; // Get user IP
    $logEntry = "[{$timestamp}] [IP: {$ipAddress}] {$message}" . PHP_EOL;
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        $error = error_get_last();
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
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true));
            log_message("CSRF token generated using fallback method. Exception: " . $e->getMessage(), LOG_FILE_ERROR);
        }
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token.
 */
function validate_csrf_token(?string $submittedToken): bool {
    if (empty($submittedToken) || !isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        log_message("CSRF Validation Failed: Token missing or empty.", LOG_FILE_ERROR);
        return false;
    }
    $result = hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
    if (!$result) {
         log_message("CSRF Validation Failed: Token mismatch.", LOG_FILE_ERROR);
    }
    // Invalidate the token after first use (prevents reuse)
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
        $value = $data[$field] ?? null;
        $ruleList = explode('|', $ruleString);
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));

        foreach ($ruleList as $rule) {
            $params = [];
            if (strpos($rule, ':') !== false) {
                list($rule, $paramString) = explode(':', $rule, 2);
                $params = explode(',', $paramString);
            }

            $isValid = true; $errorMessage = '';
            // Trim string values before validation (unless rule is 'required')
            if (is_string($value) && $rule !== 'required') {
                 $value = trim($value);
                 // If trimming makes it empty, treat as null for non-required checks
                 if ($value === '') $value = null;
            }

            switch ($rule) {
                case 'required': if ($value === null || $value === '' || (is_array($value) && empty($value))) { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please provide a valid email address."; } break;
                case 'minLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)($params[0] ?? 0)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters."; } break;
                case 'maxLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)($params[0] ?? 255)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters."; } break;
                case 'alpha_space': if ($value !== null && !preg_match('/^[\p{L}\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if ($value !== null && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) { $isValid = false; $errorMessage = "Please enter a valid phone number format."; } break;
                case 'date': $format = $params[0] ?? 'Y-m-d'; if ($value !== null) { $d = DateTime::createFromFormat($format, $value); if (!($d && $d->format($format) === $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be a valid date in {$format} format."; } } break;
                case 'integer': if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be a whole number."; } break;
                case 'min': if ($value !== null && is_numeric($value) && (float)$value < (float)($params[0] ?? 0)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]}."; } break;
                case 'max': if ($value !== null && is_numeric($value) && (float)$value > (float)($params[0] ?? PHP_FLOAT_MAX)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be no more than {$params[0]}."; } break;
                case 'in': if ($value !== null && is_array($params) && !in_array($value, $params)) { $isValid = false; $errorMessage = "Invalid selection for {$fieldNameFormatted}."; } break;
                case 'required_without': $otherField = $params[0] ?? null; if ($otherField && ($value === null) && empty(trim($data[$otherField] ?? ''))) { $isValid = false; $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_', ' ', $otherField)) . " is required."; } break;
            }
            if (!$isValid && !isset($errors[$field])) { $errors[$field] = $errorMessage; break; }
        }
     }
     return $errors;
}

/**
 * Sends an email using mail(). Consider PHPMailer for production.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid recipient email: {$to}", LOG_FILE_ERROR); return false; }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid sender email in config: {$senderEmail}", LOG_FILE_ERROR); return false; }

    $headers = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    $replyToValidEmail = sanitize_email($replyToEmail); // Sanitize reply-to just in case
    if (!empty($replyToValidEmail)) {
         $replyToNameClean = sanitize_string($replyToName);
         $replyToFormatted = $replyToNameClean ? "=?UTF-8?B?".base64_encode($replyToNameClean)."?= <{$replyToValidEmail}>" : $replyToValidEmail;
         $headers .= "Reply-To: {$replyToFormatted}\r\n";
    } else {
        $headers .= "Reply-To: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?=";
    $wrapped_body = wordwrap($body, 70, "\r\n");

    // Use -f parameter to set envelope sender (helps deliverability if server allows)
    if (@mail($to, $encodedSubject, $wrapped_body, $headers, "-f{$senderEmail}")) {
        // Determine correct log file based on context
        $logFile = ($logContext === '[Donor Reg Form]') ? LOG_FILE_BLOOD_DONOR : LOG_FILE_BLOOD_REQUEST;
        log_message("{$logContext} Email successfully submitted via mail() to {$to}. Subject: {$subject}", $logFile);
        return true;
    } else {
        $errorInfo = error_get_last();
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs.');
        log_message($errorMsg, LOG_FILE_ERROR);
        error_log($errorMsg);
        return false;
    }
}

/**
 * Retrieves a form value safely for HTML output.
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions;
    $value = $form_submissions[$formId][$fieldName] ?? $default;
    if (is_array($value)) { log_message("Attempted get non-scalar value for {$formId}[{$fieldName}]", LOG_FILE_ERROR); return ''; }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error) with Tailwind classes.
 */
function get_form_status_html(string $formId): string {
    global $form_messages;
    if (empty($form_messages[$formId])) return '';

    $message = $form_messages[$formId];
    $isSuccess = ($message['type'] === 'success');
    // Base classes + initial animation state (invisible, slightly scaled down)
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg transition-all duration-300 ease-out transform opacity-0 scale-95';
    $typeClasses = $isSuccess
        ? 'bg-green-100 border-theme-success text-green-900 dark:bg-green-900/30 dark:border-green-700 dark:text-green-200'
        : 'bg-red-100 border-theme-accent text-red-900 dark:bg-red-900/30 dark:border-red-700 dark:text-red-200';
    $iconClass = $isSuccess
        ? 'fas fa-check-circle text-theme-success dark:text-green-400'
        : 'fas fa-exclamation-triangle text-theme-accent dark:text-red-400';
    $title = $isSuccess ? 'Success!' : 'Error:';

    // Use data attribute for JS targeting to trigger animation
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\">"
         . "<strong class=\"font-semibold text-base flex items-center\"><i class=\"{$iconClass} mr-2 text-lg\"></i>{$title}</strong> "
         . "<span class=\"block sm:inline mt-1 ml-8\">" . htmlspecialchars($message['text']) . "</span>" // Indent message text
         . "</div>";
}

/**
 * Generates HTML for a field error message with Tailwind classes.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors;
    $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) {
        // Use .form-error-message component class defined in CSS
        return '<p class="form-error-message" id="' . $errorId . '">'
             . '<i class="fas fa-exclamation-circle mr-1 text-xs"></i>' // Smaller icon
             . htmlspecialchars($form_errors[$formId][$fieldName])
             . '</p>';
    }
    return '';
}

/**
 * Returns Tailwind CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors;
     $base = 'form-input'; // Base component class
     $error = 'form-input-error'; // Error state component class
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
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token(); // Generate initial token

// --- Dummy Data & Logic ---
$upcoming_camps = [
    ['id' => 1, 'date' => new DateTime('2024-11-15'), 'time' => '10:00 AM - 3:00 PM', 'location' => 'PAHAL NGO Main Office, Maqsudan, Jalandhar', 'organizer' => 'PAHAL & Local Hospital Partners', 'notes' => 'Walk-ins welcome, pre-registration encouraged. Refreshments provided.' ],
    ['id' => 2, 'date' => new DateTime('2024-12-10'), 'time' => '9:00 AM - 1:00 PM', 'location' => 'Community Centre, Model Town, Jalandhar', 'organizer' => 'PAHAL Youth Wing', 'notes' => 'Special drive focusing on Thalassemia awareness.' ],
    // Add more camps
];
// Filter out past camps and sort by date
$today = new DateTime('today midnight'); // Ensure comparison starts from the beginning of today
$upcoming_camps = array_filter($upcoming_camps, fn($camp) => $camp['date'] >= $today);
usort($upcoming_camps, fn($a, $b) => $a['date'] <=> $b['date']);

$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$blood_facts = ["One donation can save up to three lives.", "Blood cannot be manufactured – it only comes from generous donors.", "About 1 in 7 people entering a hospital need blood.", "The shelf life of donated blood is typically 42 days.", "Type O negative blood is the universal red cell donor type.", "Type AB positive plasma is the universal plasma donor type.", "Regular blood donation may help keep iron levels in check."];


// --- Form Processing Logic (POST Request) ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = sanitize_string($_POST['form_id'] ?? ''); // Sanitize form ID
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME]);
    $logContext = "[Blood Page POST]";

    // --- Security Checks ---
    if ($honeypot_filled) {
        log_message("{$logContext} Honeypot triggered. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Silently fail or redirect to a thank you page (acting like success)
        $_SESSION['form_messages'][$submitted_form_id] = ['type' => 'success', 'text' => 'Thank you for your submission!']; // Generic message
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id), true, 303);
        exit;
    }
    if (!validate_csrf_token($submitted_token)) { // validate_csrf_token now unsets the token
        log_message("{$logContext} Invalid CSRF token. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Set a general error message or specific form message
        $_SESSION['form_messages'][$submitted_form_id ?? 'general_error'] = ['type' => 'error', 'text' => 'Security token expired or invalid. Please try submitting the form again.'];
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id ?? ''), true, 303);
        exit;
    }
     // Regenerate token after successful validation check for the *next* request
     // Note: validate_csrf_token already unsets the used one.
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
            'donor_dob' => 'required|date:Y-m-d',
            'donor_location' => 'required|maxLength:150',
        ];
        $validation_errors = validate_data($submitted_data, $rules);

        // Custom Age & Consent Validation
        $age = null;
        if (empty($validation_errors['donor_dob']) && !empty($donor_dob)) {
            try {
                $birthDate = new DateTime($donor_dob); $today = new DateTime();
                 if ($birthDate > $today) { $validation_errors['donor_dob'] = "Date of birth cannot be in the future."; }
                 else { $age = $today->diff($birthDate)->y; if ($age < 18 || $age > 65) $validation_errors['donor_dob'] = "Donors must be between 18 and 65 years old. Your age: {$age}."; }
            } catch (Exception $e) { $validation_errors['donor_dob'] = "Invalid date format."; log_message("{$logContext} DOB Exception: " . $e->getMessage(), LOG_FILE_ERROR); }
        }
        if (!$donor_consent) { $validation_errors['donor_consent'] = "You must consent to be contacted."; }

        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_DONOR_REG;
            $subject = "New Blood Donor Registration: " . $donor_name;
            $body = "Potential blood donor registered:\n\n"
                  . "Name: {$donor_name}\nDOB: {$donor_dob}" . ($age !== null ? " (Age Approx: {$age})" : "") . "\n"
                  . "Email: {$donor_email}\nPhone: {$donor_phone}\nBlood Group: {$donor_blood_group}\n"
                  . "Location: {$donor_location}\nConsent Given: Yes\n"
                  . "IP Address: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T') . "\n\n"
                  . "ACTION: Verify eligibility and add to database.";

            if (send_email($to, $subject, $body, $donor_email, $donor_name, $logContext)) {
                $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$donor_name}! Your registration is received. We will contact you regarding donation opportunities."];
                log_message("{$logContext} Success. Name: {$donor_name}, BG: {$donor_blood_group}.", LOG_FILE_BLOOD_DONOR);
            } else {
                $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$donor_name}, error processing registration. Please try again later."];
                $_SESSION['form_submissions'][$form_id] = $submitted_data; // Keep data on error
            }
        } else {
            $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below."];
            $_SESSION['form_submissions'][$form_id] = $submitted_data; // Keep data on validation error
            log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
        }
        $_SESSION['scroll_to'] = '#donor-registration';
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
            'request_units' => 'required|integer|min:1|max:20', // Strict integer validation
            'request_hospital' => 'required|maxLength:200',
            'request_contact_person' => 'required|alpha_space|minLength:2|maxLength:100',
            'request_contact_phone' => 'required|phone|maxLength:20',
            'request_urgency' => 'required|in:Emergency (Immediate),Urgent (Within 24 Hours),Within 2-3 Days,Planned (Within 1 Week)', // Ensure it's one of the options
            'request_message' => 'maxLength:2000',
        ];
        $validation_errors = validate_data($submitted_data, $rules);

        // Re-validate units after basic validation, as 'integer' rule covers most cases now
        $request_units = null; // Use this validated value for email/logs
        if(empty($validation_errors['request_units'])) {
             $request_units = (int)$request_units_raw; // Cast to int if basic validation passed
        }

        $form_errors[$form_id] = $validation_errors;

         if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_BLOOD_REQUEST;
             $subject = "Blood Request ({$request_urgency}): {$request_blood_group} for {$request_patient_name}";
             $body = "Blood request submitted via PAHAL website:\n\n"
                   . "!!! BLOOD REQUEST - {$request_urgency} !!!\n\n"
                   . "Patient: {$request_patient_name}\nBlood Group: {$request_blood_group}\n"
                   . "Units: {$request_units}\nHospital: {$request_hospital}\n\n" // Use validated units
                   . "Contact Person: {$request_contact_person}\nContact Phone: {$request_contact_phone}\n\n"
                   . "Additional Info:\n" . (!empty($request_message) ? $request_message : "(None)") . "\n\n"
                   . "Submitted By IP: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T') . "\n\n"
                   . "ACTION: Verify request and assist if possible.";

             if (send_email($to, $subject, $body, '', $request_contact_person, $logContext)) {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Your blood request submitted. We will try our best to assist. We may contact {$request_contact_person}."];
                 log_message("{$logContext} Success. Patient: {$request_patient_name}, BG: {$request_blood_group}, Units: {$request_units}.", LOG_FILE_BLOOD_REQUEST);
             } else {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Error submitting request. Please try again or call us directly."];
                 $_SESSION['form_submissions'][$form_id] = $submitted_data; // Keep data on error
             }
         } else {
             $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please fix the " . count($validation_errors) . " error(s) below."];
             $_SESSION['form_submissions'][$form_id] = $submitted_data; // Keep data on validation error
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
         }
         $_SESSION['scroll_to'] = '#request-blood';
    }

    // --- Post-Processing & Redirect ---
     // Store results in session
     $_SESSION['form_messages'] = $form_messages;
     $_SESSION['form_errors'] = $form_errors;
     if (!empty($form_errors[$submitted_form_id ?? ''])) {
         $_SESSION['form_submissions'] = $form_submissions; // Only store submissions if there were errors
     } else {
         if (isset($_SESSION['form_submissions'])) unset($_SESSION['form_submissions']); // Clear if successful
     }

     // Get scroll target and clear it
     $scrollTarget = $_SESSION['scroll_to'] ?? '';
     unset($_SESSION['scroll_to']);

     // Redirect using PRG pattern
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303);
     exit;

} else {
    // --- GET Request: Retrieve session data after potential redirect ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    // Keep submissions if redirected due to error, unset otherwise (done in POST block now)
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    // CSRF token should be generated if not already set (first visit or after token use)
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML (using helper function) ---
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
$donor_reg_email_value = get_form_value('donor_registration_form', 'donor_email');
$donor_reg_phone_value = get_form_value('donor_registration_form', 'donor_phone');
$donor_reg_blood_group_value = get_form_value('donor_registration_form', 'donor_blood_group');
$donor_reg_dob_value = get_form_value('donor_registration_form', 'donor_dob');
$donor_reg_location_value = get_form_value('donor_registration_form', 'donor_location');
$donor_reg_consent_value = (get_form_value('donor_registration_form', 'donor_consent') === 'yes');

$blood_req_patient_name_value = get_form_value('blood_request_form', 'request_patient_name');
$blood_req_blood_group_value = get_form_value('blood_request_form', 'request_blood_group');
$blood_req_units_value = get_form_value('blood_request_form', 'request_units');
$blood_req_hospital_value = get_form_value('blood_request_form', 'request_hospital');
$blood_req_contact_person_value = get_form_value('blood_request_form', 'request_contact_person');
$blood_req_contact_phone_value = get_form_value('blood_request_form', 'request_contact_phone');
$blood_req_urgency_value = get_form_value('blood_request_form', 'request_urgency');
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');

?>
<!DOCTYPE html>
<!-- Applying dark mode by default, can be changed via JS toggle -->
<html lang="en" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <!-- Theme Color for Dark Mode -->
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111827"> <!-- Gray 900 -->
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f8fafc"> <!-- Slate 50 -->

    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/blood-donation.php"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-blood-og.jpg"/> <!-- CHANGE/CREATE -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg"> <!-- Optional SVG Favicon -->
    <link rel="apple-touch-icon" href="/apple-touch-icon.png"> <!-- Optional Apple Touch Icon -->

    <!-- Tailwind CSS CDN with Forms Plugin -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Poppins & Fira Code) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Fira+Code:wght@400&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" /> <!-- Updated FA -->

    <!-- Tailwind Config & Custom CSS -->
    <script>
        tailwind.config = {
          darkMode: 'class', // or 'media'
          theme: {
            extend: {
              fontFamily: {
                sans: ['Poppins', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                heading: ['Poppins', 'sans-serif'],
                mono: ['Fira Code', 'ui-monospace', 'monospace'],
              },
              colors: {
                // Define theme colors using CSS vars for easy override
                'theme-primary': 'var(--color-primary)',
                'theme-primary-hover': 'var(--color-primary-hover)',
                'theme-secondary': 'var(--color-secondary)',
                'theme-secondary-hover': 'var(--color-secondary-hover)',
                'theme-accent': 'var(--color-accent)', // Use red/pink for blood theme accent
                'theme-accent-hover': 'var(--color-accent-hover)',
                'theme-success': 'var(--color-success)',
                'theme-warning': 'var(--color-warning)',
                'theme-info': 'var(--color-info)',
                'theme-bg': 'var(--color-bg)',
                'theme-surface': 'var(--color-surface)', // Background for cards/panels
                'theme-surface-alt': 'var(--color-surface-alt)', // Alt background
                'theme-text': 'var(--color-text)',
                'theme-text-muted': 'var(--color-text-muted)',
                'theme-text-heading': 'var(--color-text-heading)',
                'theme-border': 'var(--color-border)',
                'theme-glow': 'var(--color-glow)', // Glow color variable
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
                 'card': '0 5px 15px rgba(0, 0, 0, 0.07), 0 3px 6px rgba(0, 0, 0, 0.04)',
                 'card-dark': '0 8px 25px rgba(0, 0, 0, 0.2), 0 5px 10px rgba(0, 0, 0, 0.15)', // Darker shadow for dark mode
                 'input-focus': '0 0 0 3px var(--color-primary-focus)', // Focus ring shadow
                 'glow-accent': '0 0 15px 3px var(--color-glow)', // Glow using variable
              },
              container: { // Consistent container setup
                center: true,
                padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' },
              },
            }
          }
        }
    </script>
    <style type="text/tailwindcss">
        /* --- Define CSS Variables for Theming --- */
        :root { /* Light Theme */
          --color-primary: #059669;        /* Emerald 600 */
          --color-primary-hover: #047857;  /* Emerald 700 */
          --color-secondary: #4f46e5;      /* Indigo 600 */
          --color-secondary-hover: #4338ca;/* Indigo 700 */
          --color-accent: #dc2626;         /* Red 600 - Appropriate for blood theme */
          --color-accent-hover: #b91c1c;   /* Red 700 */
          --color-success: #16a34a;        /* Green 600 */
          --color-warning: #f59e0b;        /* Amber 500 */
          --color-info: #0ea5e9;           /* Sky 500 */
          --color-bg: #f8fafc;             /* Slate 50 */
          --color-surface: #ffffff;        /* White */
          --color-surface-alt: #f1f5f9;    /* Slate 100 */
          --color-text: #1f2937;           /* Gray 800 */
          --color-text-muted: #6b7280;     /* Gray 500 */
          --color-text-heading: #111827;   /* Gray 900 */
          --color-border: #e5e7eb;         /* Gray 200 */
          --color-glow: rgba(220, 38, 38, 0.4); /* Red glow - Light */
          --color-primary-focus: rgba(5, 150, 105, 0.3); /* Focus ring color */
          --scrollbar-thumb: #a1a1aa;      /* Zinc 400 */
          --scrollbar-track: #e4e4e7;      /* Zinc 200 */
          color-scheme: light;
        }

        html.dark { /* Dark Theme Overrides */
          --color-primary: #2dd4bf;        /* Teal 400 */
          --color-primary-hover: #5eead4;  /* Teal 300 */
          --color-secondary: #a78bfa;      /* Violet 400 */
          --color-secondary-hover: #c4b5fd;/* Violet 300 */
          --color-accent: #f87171;         /* Red 400 */
          --color-accent-hover: #fb7185;   /* Rose 400 */
          --color-success: #4ade80;        /* Green 400 */
          --color-warning: #facc15;        /* Yellow 400 */
          --color-info: #38bdf8;           /* Sky 400 */
          --color-bg: #111827;             /* Gray 900 */
          --color-surface: #1f2937;        /* Gray 800 */
          --color-surface-alt: #374151;    /* Gray 700 */
          --color-text: #e5e7eb;           /* Gray 200 */
          --color-text-muted: #9ca3af;     /* Gray 400 */
          --color-text-heading: #f9fafb;   /* Gray 50 */
          --color-border: #4b5563;         /* Gray 600 */
          --color-glow: rgba(248, 113, 113, 0.4); /* Red glow - Dark */
          --color-primary-focus: rgba(45, 212, 191, 0.4);
          --scrollbar-thumb: #52525b;      /* Zinc 600 */
          --scrollbar-track: #1f2937;      /* Gray 800 */
          color-scheme: dark;
        }

        /* Custom Scrollbar (Webkit & Firefox) */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; border: 1px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 80%, white); }
        * { scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-track); scrollbar-width: thin; }

        @layer base {
            html { @apply scroll-smooth antialiased; }
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300 overflow-x-hidden; }
            h1, h2, h3, h4, h5, h6 { @apply font-heading font-semibold text-theme-text-heading tracking-tight; }
            /* Specific heading styles */
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-extrabold leading-tight; }
            h2 { @apply text-3xl md:text-4xl font-bold mb-4 leading-snug; } /* Section Title Base */
            h3 { @apply text-xl md:text-2xl font-bold text-theme-primary mb-3 mt-6 leading-normal; } /* Card/Sub-section Title */
            h4 { @apply text-lg font-semibold text-theme-secondary mb-2 leading-normal; }
            p { @apply mb-4 leading-relaxed text-base max-w-prose; }
            a { @apply text-theme-secondary hover:text-theme-primary transition-colors duration-200; }
            /* Default link style (underlined) */
            a:not(.btn):not(.btn-secondary):not(.btn-accent):not(.btn-outline):not(.nav-link):not(.card-link) {
                 @apply underline decoration-theme-secondary/50 hover:decoration-theme-primary decoration-2 underline-offset-2;
            }
            hr { @apply border-theme-border/50 my-12 md:my-16; }
            /* Global focus style */
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-offset-theme-bg ring-theme-primary/70 rounded; }
        }

        @layer components {
            /* Section Padding */
            .section-padding { @apply py-16 md:py-20 lg:py-24; } /* Container handles horizontal */

            /* Section Title */
            .section-title {
                @apply text-center mb-12 md:mb-16 text-3xl md:text-4xl font-bold text-theme-primary;
                @apply relative pb-4;
            }
            .section-title::after {
                content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 block w-20 h-1 bg-gradient-to-r from-theme-secondary to-theme-accent rounded-full opacity-80;
            }
            /* Inverted title for dark/gradient backgrounds */
            .section-title-inverted { @apply !text-white; }
            .section-title-inverted::after { @apply from-white/80 to-theme-accent/80; }

            /* Buttons */
            .btn { @apply relative inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-md focus:outline-none overflow-hidden transition-all duration-200 ease-out transform hover:-translate-y-1 hover:shadow-lg disabled:opacity-60 disabled:cursor-not-allowed group; }
            .btn > * { @apply relative z-10; } /* Ensure text/icon is above pseudo-elements */
            .btn i, .btn svg { @apply mr-2 text-sm group-hover:scale-110 transition-transform duration-200; }
            .btn-primary { @apply text-white bg-theme-primary hover:bg-theme-primary-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary; }
            .btn-secondary { @apply text-white bg-theme-secondary hover:bg-theme-secondary-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-secondary; }
            .btn-accent { @apply text-white bg-theme-accent hover:bg-theme-accent-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-accent; }
            .btn-outline { @apply text-theme-primary border-2 border-current bg-transparent hover:bg-theme-primary/10 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary; }
            .btn-icon { @apply px-3 py-3 rounded-full; } /* Make icon buttons round */
            .btn-icon i, .btn-icon svg { @apply !mr-0 text-lg; }

            /* Cards */
            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/50 overflow-hidden relative transition-all duration-300 ease-out; }
            .card:hover { @apply shadow-lg dark:shadow-xl transform scale-[1.02]; } /* Subtle hover */

            /* Panels (Glassmorphism) */
            .panel { @apply bg-theme-surface/70 dark:bg-theme-surface/60 backdrop-blur-lg border border-theme-border/30 rounded-2xl shadow-lg p-6 md:p-8; }

            /* Forms */
             .form-label { @apply block text-sm font-medium text-theme-text-muted mb-1.5; }
             .form-label.required::after { content: '*'; @apply text-theme-accent ml-1 font-semibold; }
             .form-input {
                 @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-bg dark:bg-theme-surface/60 border-theme-border placeholder:text-theme-text-muted/70 text-theme-text shadow-sm transition duration-200 ease-in-out;
                 @apply focus:border-theme-primary focus:ring-2 focus:ring-theme-primary/50 focus:outline-none disabled:opacity-60 disabled:cursor-not-allowed;
             }
             /* Custom select arrow using forms plugin + background image */
             select.form-input { @apply pr-10 bg-no-repeat appearance-none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 0.5rem center; background-size: 1.5em 1.5em; }
             html.dark select.form-input { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); }
             /* Checkbox styling via forms plugin */
             input[type='checkbox'].form-checkbox { @apply rounded text-theme-primary border-theme-border focus:ring-theme-primary focus:ring-offset-0; }
             textarea.form-input { @apply min-h-[100px] resize-vertical; }
             .form-input-error { @apply !border-theme-accent dark:!border-red-400 ring-1 ring-theme-accent/50 dark:ring-red-400/50 focus:!border-theme-accent focus:!ring-theme-accent/50; }
             .form-error-message { @apply text-theme-accent dark:text-red-400 text-xs italic mt-1.5 font-medium flex items-center gap-1 animate-fade-in; }
             .form-message { /* Base class for status messages */ }

             /* Honeypot Field */
            .honeypot-field { @apply !absolute !-left-[5000px] !w-0 !h-0 !overflow-hidden !opacity-0 !pointer-events-none; }

             /* Spinner */
             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current align-middle; }
        }

        @layer utilities {
            /* Animation Delays */
            .animation-delay-100 { animation-delay: 0.1s; } .animation-delay-200 { animation-delay: 0.2s; } .animation-delay-300 { animation-delay: 0.3s; } .animation-delay-400 { animation-delay: 0.4s; } .animation-delay-500 { animation-delay: 0.5s; }
        }

        /* --- Specific Component Styles --- */
        /* Header */
        #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/90 dark:bg-theme-bg/85 backdrop-blur-lg z-50 shadow-sm transition-all duration-300 border-b border-theme-border/40; min-height: 70px; }
        body { @apply pt-[70px]; } /* Offset for fixed header */

        /* Hero Section */
        #hero-blood {
            @apply bg-gradient-to-br from-red-50 dark:from-gray-900 via-rose-100 dark:via-red-900/20 to-sky-100 dark:to-sky-900/20 text-center section-padding relative overflow-hidden;
        }
        #hero-blood h1 { @apply text-4xl md:text-6xl font-extrabold text-theme-accent dark:text-red-400 mb-4 drop-shadow-lg; }
        #hero-blood p.lead { @apply text-lg md:text-xl text-gray-700 dark:text-gray-300 font-medium max-w-3xl mx-auto mb-8 drop-shadow-sm; }
        #hero-blood .icon-drop { @apply text-6xl text-theme-accent mb-4 animate-pulse; }
        #hero-blood .cta-buttons { @apply flex flex-col sm:flex-row items-center justify-center gap-4 mt-10; }

        /* Eligibility List Icons */
        .eligibility-list li i.fa-check { @apply text-theme-success; }
        .eligibility-list li i.fa-exclamation-triangle { @apply text-theme-warning; }

        /* Upcoming Camps Cards */
        .camp-card { @apply card border-l-4 border-theme-secondary hover:!border-theme-primary; }
        .camp-card .camp-date { @apply text-theme-secondary font-bold text-lg mb-1; }
        .camp-card .camp-location { @apply text-theme-primary font-semibold; }
        .camp-card .camp-note { @apply text-xs bg-theme-info/10 dark:bg-theme-info/20 text-theme-info dark:text-sky-300 p-2 rounded italic mt-3 border border-theme-info/20;}

        /* Blood Facts Cards */
        #blood-facts .fact-card { @apply bg-theme-info/10 dark:bg-theme-info/20 border border-theme-info/30 p-4 rounded-lg text-center flex flex-col items-center justify-center min-h-[140px] transition-transform duration-300 hover:scale-105; }
        #blood-facts .fact-icon { @apply text-4xl mb-3 text-theme-info; }
        #blood-facts .fact-text { @apply text-sm font-medium text-theme-text dark:text-theme-text-muted; }
        #blood-facts .fact-card.highlight { @apply !bg-theme-accent/10 dark:!bg-theme-accent/20 !border-theme-accent/30 shadow-lg; } /* Highlighted fact */
        #blood-facts .fact-card.highlight .fact-icon { @apply !text-theme-accent; }
        #blood-facts .fact-card.highlight .fact-text { @apply !text-theme-accent dark:!text-red-300 font-semibold; }

        /* Footer */
        footer { @apply bg-gray-800 dark:bg-gray-950 text-gray-400 pt-16 pb-10 border-t-4 border-theme-accent; } /* Accent border */
        footer nav a { @apply text-gray-400 hover:text-white hover:underline px-2; }
        footer .footer-bottom p { @apply text-xs text-gray-500; }

    </style>
</head>
<body class="bg-theme-bg font-sans">

    <!-- Header -->
    <header id="main-header" class="py-2 md:py-0">
       <div class="container mx-auto flex flex-wrap items-center justify-between">
           <!-- Logo -->
           <div class="logo flex-shrink-0 py-2">
               <a href="index.php#hero" aria-label="PAHAL NGO Home" class="text-3xl font-black text-theme-accent dark:text-red-400 font-heading leading-none flex items-center transition-opacity hover:opacity-80 group">
                   <img src="icon.webp" alt="PAHAL Icon" class="h-9 w-9 mr-2 inline object-contain group-hover:animate-pulse-slow" aria-hidden="true"> <!-- Pulse on hover -->
                   PAHAL
               </a>
           </div>
           <!-- Navigation & Theme Toggle -->
           <div class="flex items-center space-x-3 md:space-x-4">
                <nav aria-label="Site Navigation" class="hidden sm:flex items-center space-x-2 md:space-x-3">
                    <a href="index.php" class="nav-link text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">Home</a>
                    <a href="e-waste.php" class="nav-link text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">E-Waste</a>
                    <a href="index.php#contact" class="nav-link text-theme-text hover:text-theme-primary font-medium px-3 py-2 transition-colors text-sm md:text-base">Contact</a>
                </nav>
                <!-- Theme Toggle Button -->
                <button id="theme-toggle" type="button" title="Toggle theme" class="btn-icon text-theme-text-muted hover:text-theme-primary hover:bg-theme-primary/10 dark:hover:bg-theme-primary/20 transition-colors duration-200 p-2.5">
                    <i class="fas fa-moon text-lg block dark:hidden" id="theme-toggle-dark-icon"></i>
                    <i class="fas fa-sun text-lg hidden dark:block" id="theme-toggle-light-icon"></i>
                    <span class="sr-only">Toggle theme</span>
                </button>
                <!-- Add Mobile Menu Toggle Here if needed -->
           </div>
       </div>
       <!-- Mobile Menu Container (if needed) -->
       <!-- <div id="mobile-menu" class="sm:hidden hidden"> ... </div> -->
    </header>

    <main>
        <!-- Hero Section -->
        <section id="hero-blood" class="animate-fade-in">
             <div class="container mx-auto relative z-10">
                 <div class="icon-drop"><i class="fas fa-tint drop-shadow-lg"></i></div>
                 <h1 class="animate-fade-in-down">Donate Blood, Give the Gift of Life</h1>
                 <p class="lead animate-fade-in-down animation-delay-200">Join PAHAL's mission to ensure a readily available and safe blood supply for our community in Jalandhar. Your generosity makes a profound difference.</p>
                 <div class="cta-buttons animate-fade-in-up animation-delay-400">
                     <a href="#donor-registration" class="btn btn-secondary text-lg shadow-lg"><i class="fas fa-user-plus mr-2"></i> Register as Donor</a>
                     <a href="#request-blood" class="btn btn-accent text-lg shadow-lg"><i class="fas fa-ambulance mr-2"></i> Request Blood Assistance</a>
                 </div>
             </div>
             <!-- Subtle background shapes -->
             <div aria-hidden="true" class="absolute top-1/4 left-1/4 w-32 h-32 bg-theme-secondary/10 dark:bg-theme-secondary/5 rounded-full blur-2xl opacity-50 animate-pulse-slow -translate-x-1/2 -translate-y-1/2"></div>
             <div aria-hidden="true" class="absolute bottom-1/4 right-1/4 w-40 h-40 bg-theme-accent/10 dark:bg-theme-accent/5 rounded-full blur-2xl opacity-50 animate-pulse-slow animation-delay-2s translate-x-1/2 translate-y-1/2"></div>
        </section>

        <!-- Informational Section Grid -->
        <section class="section-padding">
            <div class="container mx-auto">
                <h2 class="section-title">Understanding Blood Donation</h2>
                <div class="grid md:grid-cols-2 gap-10 lg:gap-12 mt-12">
                    <!-- Why Donate? -->
                    <div class="card animate-slide-in-bottom animation-delay-100">
                         <h3 class="!mt-0 flex items-center gap-3 text-theme-accent dark:text-red-400"><i class="fas fa-heart-pulse text-3xl"></i>Why Your Donation Matters</h3>
                         <p class="text-theme-text-muted">Blood is a critical resource, constantly needed for surgeries, accident victims, cancer patients, and individuals with blood disorders like Thalassemia. It cannot be artificially created, relying solely on volunteer donors.</p>
                         <ul class="text-theme-text list-none pl-0 space-y-3 mt-4 text-sm md:text-base">
                             <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Directly saves lives in emergencies and medical treatments.</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Supports patients undergoing long-term therapies (e.g., chemotherapy).</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Crucial component for maternal care during childbirth complications.</li>
                            <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1 flex-shrink-0"></i> Represents a vital act of community solidarity and support.</li>
                         </ul>
                         <p class="mt-6 font-semibold text-theme-accent text-lg border-t border-theme-border pt-4">Be a lifeline. Your single donation can impact multiple lives.</p>
                    </div>

                    <!-- Who Can Donate? -->
                    <div class="card animate-slide-in-bottom animation-delay-200">
                        <h3 class="text-theme-info dark:text-sky-400 !mt-0 flex items-center gap-3"><i class="fas fa-user-check text-3xl"></i>Eligibility Essentials</h3>
                        <p class="text-theme-text-muted">Ensuring the safety of both donors and recipients is paramount. General guidelines include:</p>
                        <div class="grid sm:grid-cols-2 gap-x-6 gap-y-4 mt-5 eligibility-list">
                            <div>
                                 <h4 class="text-lg text-theme-success mb-2 flex items-center gap-2"><i class="fas fa-check"></i>Likely CAN donate if:</h4>
                                 <ul class="text-theme-text-muted list-none pl-0 space-y-1.5 text-sm">
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-alt"></i> Are 18-65 years old.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-weight-hanging"></i> Weigh ≥ 50 kg (110 lbs).</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-heart"></i> Are in good general health.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-tint"></i> Meet hemoglobin levels (checked at site).</li>
                                </ul>
                            </div>
                             <div>
                                <h4 class="text-lg text-theme-warning mb-2 flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i>Consult staff if you:</h4>
                                 <ul class="text-theme-text-muted list-none pl-0 space-y-1.5 text-sm">
                                    <li class="flex items-center gap-2"><i class="fas fa-pills"></i> Take certain medications.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-procedures"></i> Have specific medical conditions.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-plane"></i> Traveled internationally recently.</li>
                                    <li class="flex items-center gap-2"><i class="fas fa-calendar-minus"></i> Donated blood recently.</li>
                                 </ul>
                             </div>
                        </div>
                        <p class="text-xs text-theme-text-muted mt-6 pt-4 border-t border-theme-border"><i class="fas fa-info-circle mr-1"></i> Final eligibility is always confirmed via a confidential screening at the donation site.</p>
                    </div>
                </div>
            </div>
        </section>

        <hr>

        <!-- Donor Registration Section -->
        <section id="donor-registration" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/30">
            <div class="container mx-auto">
                <h2 class="section-title"><i class="fas fa-user-plus mr-2"></i>Become a Registered Donor</h2>
                 <p class="text-center max-w-3xl mx-auto mb-10 text-lg text-theme-text-muted">Join our network of heroes! Registering allows us to contact you when your blood type is needed or for upcoming camps. Your information is kept confidential and used solely for donation purposes.</p>

                 <div class="panel max-w-4xl mx-auto animate-fade-in-up animation-delay-100">
                     <?= get_form_status_html('donor_registration_form') ?>
                     <form id="donor-registration-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#donor-registration" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <input type="hidden" name="form_id" value="donor_registration_form">
                         <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_donor">Keep Blank</label><input type="text" id="website_url_blood_donor" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_name" class="form-label required">Full Name</label>
                                 <input type="text" id="donor_name" name="donor_name" required value="<?= $donor_reg_name_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_name') ?>" placeholder="e.g., Priya Sharma" <?= get_aria_describedby('donor_registration_form', 'donor_name') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_name') ?>
                            </div>
                             <div>
                                 <label for="donor_dob" class="form-label required">Date of Birth</label>
                                 <input type="date" id="donor_dob" name="donor_dob" required value="<?= $donor_reg_dob_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_dob') ?>" max="<?= date('Y-m-d') ?>" <?= get_aria_describedby('donor_registration_form', 'donor_dob') ?> aria-describedby="donor_dob_hint">
                                 <p class="text-xs text-theme-text-muted mt-1" id="donor_dob_hint">YYYY-MM-DD. Must be 18-65 yrs.</p> {/* JS will update this */}
                                 <?= get_field_error_html('donor_registration_form', 'donor_dob') ?>
                            </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_email" class="form-label required">Email Address</label>
                                 <input type="email" id="donor_email" name="donor_email" required value="<?= $donor_reg_email_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_email') ?>" placeholder="e.g., priya.sharma@email.com" <?= get_aria_describedby('donor_registration_form', 'donor_email') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_email') ?>
                            </div>
                             <div>
                                 <label for="donor_phone" class="form-label required">Mobile Number</label>
                                 <input type="tel" id="donor_phone" name="donor_phone" required value="<?= $donor_reg_phone_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_phone') ?>" placeholder="e.g., 98xxxxxxxx" <?= get_aria_describedby('donor_registration_form', 'donor_phone') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_phone') ?>
                            </div>
                         </div>
                         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="donor_blood_group" class="form-label required">Blood Group</label>
                                 <select id="donor_blood_group" name="donor_blood_group" required aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_blood_group') ?> form-input" <?= get_aria_describedby('donor_registration_form', 'donor_blood_group') ?>>
                                     <option value="" disabled <?= empty($donor_reg_blood_group_value) ? 'selected' : '' ?>>-- Select Blood Group --</option>
                                     <?php foreach($blood_types as $type): ?>
                                         <option value="<?= $type ?>" <?= ($donor_reg_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                     <?php endforeach; ?>
                                 </select>
                                 <?= get_field_error_html('donor_registration_form', 'donor_blood_group') ?>
                             </div>
                             <div>
                                 <label for="donor_location" class="form-label required">Location (Area/City)</label>
                                 <input type="text" id="donor_location" name="donor_location" required value="<?= $donor_reg_location_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_location') ?>" placeholder="e.g., Maqsudan, Jalandhar" <?= get_aria_describedby('donor_registration_form', 'donor_location') ?>>
                                 <?= get_field_error_html('donor_registration_form', 'donor_location') ?>
                             </div>
                         </div>
                         <div class="mt-6 pt-4 border-t border-theme-border/50">
                             <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer p-3 rounded-md hover:bg-theme-primary/5 dark:hover:bg-theme-primary/10 transition-colors">
                                 <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?> class="h-5 w-5 form-checkbox shrink-0" <?= get_aria_describedby('donor_registration_form', 'donor_consent') ?>>
                                 <span class="text-sm text-theme-text-muted dark:text-gray-300">I consent to PAHAL contacting me via Phone/Email regarding blood donation needs or upcoming camps. I understand this registration does not guarantee eligibility, which will be confirmed at the donation site.</span>
                             </label>
                             <?= get_field_error_html('donor_registration_form', 'donor_consent') ?>
                        </div>
                         <div class="pt-5 text-center md:text-left">
                             <button type="submit" class="btn btn-secondary w-full sm:w-auto">
                                 <span class="spinner hidden mr-2"></span> {/* Spinner added */}
                                 <span class="button-text flex items-center justify-center"><i class="fas fa-check-circle mr-2"></i>Register Now</span>
                             </button>
                         </div>
                     </form>
                </div>
             </div>
         </section>

        <hr>

        <!-- Blood Request Section -->
        <section id="request-blood" class="section-padding">
             <div class="container mx-auto">
                <h2 class="section-title text-theme-accent dark:text-red-400"><i class="fas fa-first-aid mr-2"></i>Request Blood Assistance</h2>
                 <p class="text-center max-w-3xl mx-auto mb-6 text-lg text-theme-text-muted">If you or someone you know requires blood urgently or for a planned procedure, please submit a request. PAHAL will try to connect you with registered donors or guide you to local blood banks.</p>
                 <div class="text-center max-w-3xl mx-auto mb-10 text-sm font-semibold text-red-800 dark:text-red-300 bg-red-100 dark:bg-red-900/30 p-4 rounded-lg border border-red-300 dark:border-red-700 shadow-md"><i class="fas fa-exclamation-triangle mr-2"></i> <strong>Disclaimer:</strong> PAHAL acts as a facilitator and does not operate a blood bank directly. Availability depends on donor responses and blood bank stocks. For critical emergencies, please contact hospitals/blood banks directly first.</div>

                 <div class="panel max-w-4xl mx-auto !border-theme-accent animate-fade-in-up animation-delay-100">
                      <?= get_form_status_html('blood_request_form') ?>
                    <form id="blood-request-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#request-blood" method="POST" class="space-y-6 w-full">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="blood_request_form">
                         <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_req">Keep Blank</label><input type="text" id="website_url_blood_req" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="request_patient_name" class="form-label required">Patient's Full Name</label>
                                <input type="text" id="request_patient_name" name="request_patient_name" required value="<?= $blood_req_patient_name_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_patient_name') ?>" <?= get_aria_describedby('blood_request_form', 'request_patient_name') ?>>
                                <?= get_field_error_html('blood_request_form', 'request_patient_name') ?>
                            </div>
                             <div>
                                 <label for="request_blood_group" class="form-label required">Blood Group Needed</label>
                                 <select id="request_blood_group" name="request_blood_group" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_blood_group') ?> form-input" <?= get_aria_describedby('blood_request_form', 'request_blood_group') ?>>
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
                                 <label for="request_units" class="form-label required">Units Required</label>
                                 <input type="number" id="request_units" name="request_units" required value="<?= $blood_req_units_value ?>" min="1" max="20" step="1" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_units') ?>" placeholder="e.g., 2" <?= get_aria_describedby('blood_request_form', 'request_units') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_units') ?>
                            </div>
                            <div>
                                <label for="request_urgency" class="form-label required">Urgency</label>
                                <select id="request_urgency" name="request_urgency" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_urgency') ?> form-input" <?= get_aria_describedby('blood_request_form', 'request_urgency') ?>>
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
                             <label for="request_hospital" class="form-label required">Hospital Name & Location</label>
                             <input type="text" id="request_hospital" name="request_hospital" required value="<?= $blood_req_hospital_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_hospital') ?>" placeholder="e.g., Civil Hospital, Jalandhar" <?= get_aria_describedby('blood_request_form', 'request_hospital') ?>>
                             <?= get_field_error_html('blood_request_form', 'request_hospital') ?>
                         </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                 <label for="request_contact_person" class="form-label required">Contact Person (Attendant)</label>
                                 <input type="text" id="request_contact_person" name="request_contact_person" required value="<?= $blood_req_contact_person_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_person') ?>" placeholder="e.g., Attendant's Name" <?= get_aria_describedby('blood_request_form', 'request_contact_person') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_contact_person') ?>
                             </div>
                             <div>
                                 <label for="request_contact_phone" class="form-label required">Contact Phone</label>
                                 <input type="tel" id="request_contact_phone" name="request_contact_phone" required value="<?= $blood_req_contact_phone_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_phone') ?>" <?= get_aria_describedby('blood_request_form', 'request_contact_phone') ?>>
                                 <?= get_field_error_html('blood_request_form', 'request_contact_phone') ?>
                             </div>
                         </div>
                         <div>
                             <label for="request_message" class="form-label">Additional Info (Optional)</label>
                             <textarea id="request_message" name="request_message" rows="4" class="<?= get_field_error_class('blood_request_form', 'request_message') ?> form-input" placeholder="e.g., Patient condition, doctor's name, specific timing needs..." <?= get_aria_describedby('blood_request_form', 'request_message') ?>><?= $blood_req_message_value ?></textarea>
                             <?= get_field_error_html('blood_request_form', 'request_message') ?>
                         </div>
                         <div class="pt-5 text-center md:text-left">
                             <button type="submit" class="btn btn-accent w-full sm:w-auto">
                                 <span class="spinner hidden mr-2"></span> {/* Spinner added */}
                                 <span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Submit Request</span>
                             </button>
                         </div>
                     </form>
                </div>
             </div>
         </section>

        <hr>

        <!-- Upcoming Camps Section -->
        <section id="upcoming-camps" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/30">
            <div class="container mx-auto">
                 <h2 class="section-title"><i class="far fa-calendar-alt mr-2"></i>Upcoming Blood Donation Camps</h2>
                <?php if (!empty($upcoming_camps)): ?>
                    <p class="text-center max-w-3xl mx-auto mb-12 text-lg text-theme-text-muted">Join us at one of our upcoming events and be a hero! Your presence makes a difference.</p>
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php foreach ($upcoming_camps as $index => $camp): ?>
                        <div class="camp-card animate-fade-in-up animation-delay-<?= ($index + 1) * 100 ?>">
                            <p class="camp-date flex items-center gap-2"><i class="fas fa-calendar-check"></i><?= $camp['date']->format('F j, Y (l)') ?></p>
                            <p class="text-sm text-theme-text-muted mb-2 flex items-center gap-2"><i class="far fa-clock"></i><?= htmlspecialchars($camp['time']) ?></p>
                            <p class="camp-location mb-3 flex items-start gap-2"><i class="fas fa-map-marker-alt mt-1"></i><?= htmlspecialchars($camp['location']) ?></p>
                            <p class="text-sm text-theme-text-muted mb-3 flex items-center gap-2"><i class="fas fa-sitemap"></i>Organized by: <?= htmlspecialchars($camp['organizer']) ?></p>
                            <?php if (!empty($camp['notes'])): ?>
                                <p class="camp-note"><i class="fas fa-info-circle mr-1.5"></i> <?= htmlspecialchars($camp['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                 <?php else: ?>
                     <div class="text-center panel max-w-2xl mx-auto !border-theme-info animate-fade-in">
                         <i class="fas fa-info-circle text-5xl text-theme-info mb-4"></i>
                         <h3 class="text-xl font-semibold text-theme-info mb-2 !mt-0">No Camps Currently Scheduled</h3>
                         <p class="text-theme-text-muted">We are actively planning our next donation camps. Please check back soon for updates, or <a href="#donor-registration" class="font-semibold underline hover:text-theme-secondary">register as a donor</a> to be notified directly!</p>
                     </div>
                <?php endif; ?>
            </div>
        </section>

        <hr>

        <!-- Facts & Figures Section -->
         <section id="blood-facts" class="section-padding">
            <div class="container mx-auto">
                <h2 class="section-title !mt-0">Did You Know? Blood Facts</h2>
                 <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5 md:gap-6 mt-12">
                    <?php
                        // More relevant icons
                        $icons = ['fa-users', 'fa-hourglass-half', 'fa-hospital-user', 'fa-calendar-days', 'fa-flask-vial', 'fa-hand-holding-medical', 'fa-heart-circle-check', 'fa-universal-access'];
                        shuffle($icons); // Randomize icons
                    ?>
                    <?php foreach ($blood_facts as $index => $fact): ?>
                    <div class="fact-card animate-fade-in animation-delay-<?= ($index + 1) * 100 ?>">
                         <i class="fas <?= $icons[$index % count($icons)] ?> fact-icon"></i>
                         <p class="fact-text"><?= htmlspecialchars($fact) ?></p>
                    </div>
                    <?php endforeach; ?>
                    <!-- Highlighted final fact -->
                     <div class="fact-card highlight animate-fade-in animation-delay-<?= (count($blood_facts) + 1) * 100 ?>">
                         <i class="fas fa-hand-holding-heart fact-icon"></i>
                         <p class="fact-text">Your single donation matters greatly!</p>
                     </div>
                </div>
            </div>
        </section>

        <hr>

        <!-- Final CTA / Contact Info -->
         <section id="contact-info" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/30">
             <div class="container mx-auto text-center max-w-3xl">
                <h2 class="section-title !mt-0">Questions? Contact Our Blood Program Team</h2>
                 <p class="text-lg mb-8 text-theme-text-muted">For specific questions about the blood donation program, eligibility, upcoming camps, or potential partnerships, please reach out directly:</p>
                <div class="panel inline-block text-left space-y-4 max-w-md mx-auto animate-fade-in-up">
                    <p class="flex items-center gap-3"><i class="fas fa-user-tie text-xl text-theme-primary w-6 text-center"></i> <strong class="text-theme-text-heading">Coordinator:</strong> [Coordinator Name]</p> {/* CHANGE */}
                    <p class="flex items-center gap-3"><i class="fas fa-phone text-xl text-theme-primary w-6 text-center"></i> <strong class="text-theme-text-heading">Direct Line:</strong> <a href="tel:+919855614230" class="font-semibold text-theme-secondary hover:underline ml-1">+91 98556-14230</a></p>
                    <p class="flex items-center gap-3"><i class="fas fa-envelope text-xl text-theme-primary w-6 text-center"></i> <strong class="text-theme-text-heading">Email:</strong> <a href="mailto:bloodprogram@your-pahal-domain.com?subject=Blood%20Donation%20Inquiry" class="font-semibold text-theme-secondary hover:underline ml-1 break-all">bloodprogram@your-pahal-domain.com</a></p> {/* CHANGE */}
                </div>
                <div class="mt-12 animate-fade-in-up animation-delay-200">
                    <a href="index.php#contact" class="btn btn-outline"><i class="fas fa-address-book mr-2"></i>General PAHAL Contact Info</a>
                 </div>
            </div>
        </section>

    </main>

    <!-- Footer -->
    <footer class="pt-16 pb-10">
        <div class="container mx-auto px-4 text-center">
             <div class="mb-6">
                <a href="index.php" class="text-3xl font-black text-white hover:text-gray-300 font-heading leading-none inline-flex items-center">
                   <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL NGO
                </a>
                <p class="text-xs text-gray-500 mt-1">Promoting Health and Well-being in Jalandhar</p>
             </div>
            <nav class="mb-6 text-sm space-x-2 sm:space-x-4">
                <a href="index.php">Home</a> |
                <a href="#donor-registration">Register Donor</a> |
                <a href="#request-blood">Request Blood</a> |
                <a href="#upcoming-camps">Camps</a> |
                <a href="e-waste.php">E-Waste</a> |
                <a href="index.php#contact">Contact</a>
            </nav>
             <div class="footer-bottom">
                 <p> © <?= $current_year ?> PAHAL NGO (Regd.). All Rights Reserved. </p>
                 <p class="mt-1"> <a href="/privacy-policy.php" class="hover:text-white hover:underline">Privacy Policy</a> | <a href="/terms.php" class="hover:text-white hover:underline">Terms of Service</a> </p>
             </div>
       </div>
    </footer>

    <!-- Main Application JavaScript -->
    <script>
     document.addEventListener('DOMContentLoaded', () => {
        // --- Elements Cache ---
        const themeToggleBtn = document.getElementById('theme-toggle');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const htmlElement = document.documentElement;
        const dobInput = document.getElementById('donor_dob');
        const dobHint = document.getElementById('donor_dob_hint');
        const forms = document.querySelectorAll('form[id$="-form-tag"]'); // Target forms by suffix

        // --- Theme Toggle Functionality ---
        const applyTheme = (theme) => {
            if (theme === 'light') {
                htmlElement.classList.remove('dark');
                lightIcon?.classList.remove('hidden');
                darkIcon?.classList.add('hidden');
            } else { // Default to dark
                htmlElement.classList.add('dark');
                darkIcon?.classList.remove('hidden');
                lightIcon?.classList.add('hidden');
            }
             // Only save if user explicitly chose, otherwise respect preference/default
             if (localStorage.getItem('theme_explicitly_set')) {
                localStorage.setItem('theme', theme);
             }
        };

        // Apply theme on initial load
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        // If theme is stored, use it. Otherwise, use system preference.
        const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
        applyTheme(initialTheme);

        // Add click listener
        themeToggleBtn?.addEventListener('click', () => {
            const newTheme = htmlElement.classList.contains('dark') ? 'light' : 'dark';
             localStorage.setItem('theme_explicitly_set', 'true'); // Mark user choice
            applyTheme(newTheme);
        });


        // --- Donor DOB Age Hint Logic ---
         if (dobInput && dobHint) {
             const updateAgeHint = () => {
                 try {
                     const dobValue = dobInput.value;
                     // Reset hint if input is empty
                     if (!dobValue) {
                         dobHint.textContent = 'YYYY-MM-DD. Must be 18-65 yrs.';
                         dobHint.className = 'text-xs text-theme-text-muted mt-1';
                         dobInput.classList.remove('border-theme-success', 'border-theme-warning', 'border-theme-accent'); // Reset border color hint
                         return;
                     }

                     // Basic format check (might not catch all invalid dates like 2023-02-30)
                     if (!/^\d{4}-\d{2}-\d{2}$/.test(dobValue)) {
                        throw new Error('Invalid Format');
                     }

                     const birthDate = new Date(dobValue);
                     const today = new Date();
                     today.setHours(0, 0, 0, 0); // Compare dates only

                     // Check if date is valid and not in the future
                     // getTime() check catches invalid dates like Feb 30th
                     if (isNaN(birthDate.getTime()) || birthDate > today) {
                          throw new Error('Invalid Date or Future Date');
                     }

                     let age = today.getFullYear() - birthDate.getFullYear();
                     const m = today.getMonth() - birthDate.getMonth();
                     if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) { age--; }

                      // Reset potential border color hints first
                     dobInput.classList.remove('border-theme-success', 'border-theme-warning', 'border-theme-accent');

                     // Update hint text and optionally border color
                     dobHint.className = 'text-xs mt-1 font-medium'; // Reset color classes
                     if (age >= 18 && age <= 65) {
                        dobHint.textContent = `Approx. age: ${age}. Eligibility OK.`;
                        dobHint.classList.add('text-theme-success');
                        dobInput.classList.add('border-theme-success'); // Green border hint
                     } else if (age >= 0 && age < 18) {
                         dobHint.textContent = `Approx. age: ${age}. Note: Must be 18+.`;
                         dobHint.classList.add('text-theme-warning');
                          dobInput.classList.add('border-theme-warning'); // Yellow border hint
                     } else if (age > 65) {
                         dobHint.textContent = `Approx. age: ${age}. Note: Must be under 65.`;
                         dobHint.classList.add('text-theme-warning');
                         dobInput.classList.add('border-theme-warning'); // Yellow border hint
                     } else { // Should be rare
                         throw new Error('Age Calculation Error');
                     }
                 } catch (e) {
                      // Reset border color on error
                      dobInput.classList.remove('border-theme-success', 'border-theme-warning');
                      dobInput.classList.add('border-theme-accent'); // Red border hint for invalid format/date

                      dobHint.textContent = 'Invalid date or format (YYYY-MM-DD).';
                      dobHint.className = 'text-xs mt-1 font-medium text-theme-accent';
                 }
             };
             // Use 'input' for more immediate feedback, 'change' is also fine
             dobInput.addEventListener('input', updateAgeHint);
             // Also run on load in case of pre-filled values (e.g., from form error reload)
             updateAgeHint();
         }


          // --- Form Message Animation Trigger ---
          document.querySelectorAll('[data-form-message-id]').forEach(msgElement => {
              // Use rAF for smoother start
              requestAnimationFrame(() => {
                   setTimeout(() => { // Small delay
                        msgElement.style.opacity = '1';
                        msgElement.style.transform = 'translateY(0) scale(1)';
                    }, 50);
              });
          });


          // --- Scroll Target Restoration ---
           const hash = window.location.hash;
           if (hash) {
              const decodedHash = decodeURIComponent(hash);
              try {
                  const targetElement = document.querySelector(decodedHash);
                  if (targetElement) {
                       setTimeout(() => {
                          const header = document.getElementById('main-header');
                          const headerOffset = header ? header.offsetHeight : 70;
                          const elementPosition = targetElement.getBoundingClientRect().top;
                          const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20; // Add 20px margin

                          window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                      }, 150); // Delay to allow rendering
                  }
              } catch (e) { console.warn("Error scrolling to hash:", decodedHash, e); }
           }


          // --- Form Submission Spinner Logic ---
           forms.forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             if (!submitButton) return; // Skip if no submit button found

             const spinner = submitButton.querySelector('.spinner');
             const buttonTextSpan = submitButton.querySelector('.button-text'); // Assuming text is wrapped

             form.addEventListener('submit', (e) => {
                 // Basic HTML5 validity check before showing spinner
                if (form.checkValidity()) {
                    submitButton.disabled = true;
                    spinner?.classList.remove('hidden');
                    spinner?.classList.add('inline-block'); // Make sure it displays
                    buttonTextSpan?.classList.add('opacity-0'); // Hide text
                } else {
                     // Optionally add a small delay before re-enabling if validation fails immediately
                     // setTimeout(() => { submitButton.disabled = false; }, 100);
                     console.log("Form validation failed, submission prevented.");
                }
             });

             // Note: You might need more robust logic to re-enable the button
             // if the server responds with an error after a redirect.
             // This basic example only handles the initial client-side submission click.
             // The page reload after submission will reset the button state naturally.
          });

        console.log("PAHAL Blood Donation Page JS Initialized.");
     });
     </script>

</body>
</html>
