<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Version: 2.1 (PHPMailer Removed, using standard mail())
// Features: CSRF, Honeypot, Logging for Forms, Expanded Content, Dynamic Camps (Example)
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
// --- Recipient Emails Specific to Blood Program ---
define('RECIPIENT_EMAIL_DONOR_REG', "bloodbank@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_BLOOD_REQUEST', "bloodrequests@your-pahal-domain.com"); // CHANGE ME

// --- Email Sending Defaults (for mail() function) ---
// CHANGE THIS potentially to an email address associated with your domain for better deliverability
define('SENDER_EMAIL_DEFAULT', 'noreply@your-pahal-domain.com'); // CHANGE ME (email mails appear FROM)
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Blood Program');        // CHANGE ME (name mails appear FROM)

// --- Security Settings ---
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url_blood'); // Unique honeypot name for this page
define('ENABLE_LOGGING', true);

// --- Logging Paths ---
$baseDir = __DIR__; // Base directory for logs relative to this file
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
define('LOG_FILE_BLOOD_DONOR', $baseDir . '/logs/blood_donor_regs.log');
define('LOG_FILE_BLOOD_REQUEST', $baseDir . '/logs/blood_requests.log');
// --- END CONFIG ---
// ------------------------------------------------------------------------


// --- Helper Functions ---
// MUST include or redeclare necessary functions here:
// log_message, generate_csrf_token, validate_csrf_token, sanitize_string, sanitize_email,
// validate_data, send_email (updated), get_form_value, get_form_status_html, get_field_error_html, get_field_error_class

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
 * Validates input data based on rules. (Basic implementation - Consider a validation library)
 */
function validate_data(array $data, array $rules): array {
     $errors = [];
     foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        $ruleList = explode('|', $ruleString);
        $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));
        foreach ($ruleList as $rule) {
            $params = []; if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }
            $isValid = true; $errorMessage = '';
            switch ($rule) {
                case 'required': if ($value === null || $value === '') { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please enter a valid email."; } break;
                case 'minLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters."; } break;
                case 'maxLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters."; } break;
                case 'alpha_space': if (!empty($value) && !preg_match('/^[A-Za-z\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) { $isValid = false; $errorMessage = "Invalid phone format."; } break;
                case 'date': // Basic YYYY-MM-DD format check
                     $format = $params[0] ?? 'Y-m-d'; // Default format
                     if (!empty($value)) { $d = DateTime::createFromFormat($format, $value); if (!($d && $d->format($format) === $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be a valid date in {$format} format."; } } break;
                 case 'integer': if (!empty($value) && filter_var($value, FILTER_VALIDATE_INT) === false) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be a whole number."; } break;
                 case 'min': if (!empty($value) && is_numeric($value) && $value < (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]}."; } break;
                 case 'max': if (!empty($value) && is_numeric($value) && $value > (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be no more than {$params[0]}."; } break;
                 case 'in': if (!empty($value) && is_array($params) && !in_array($value, $params)) { $isValid = false; $errorMessage = "Invalid selection for {$fieldNameFormatted}."; } break;
                 case 'required_without': $otherField = $params[0] ?? null; if ($otherField && empty($value) && empty($data[$otherField])) { $isValid = false; $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_',' ',$otherField)). " is required."; } break;
                 case 'required': // Already handled above, this case for explicit 'required' rule check
                      if ($value === null || $value === '') { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
            }
            if (!$isValid && !isset($errors[$field])) { $errors[$field] = $errorMessage; break; }
         }
     }
     return $errors;
}


/**
 * Sends an email using the standard PHP mail() function.
 *
 * @param string $to Recipient email address.
 * @param string $subject Email subject.
 * @param string $body Email body (plain text).
 * @param string $replyToEmail Email for Reply-To header.
 * @param string $replyToName Name for Reply-To header.
 * @param string $logContext Prefix for logging messages (e.g., "[Blood Donor Form]").
 * @return bool True on success, false on failure.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT;

    $headers = "From: {$senderName} <{$senderEmail}>\r\n";
    if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
         $replyToFormatted = $replyToName ? "{$replyToName} <{$replyToEmail}>" : $replyToEmail;
         $headers .= "Reply-To: {$replyToFormatted}\r\n";
    }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
     $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $wrapped_body = wordwrap($body, 70, "\r\n"); // Wrap long lines

    if (@mail($to, $subject, $wrapped_body, $headers)) {
        // Adjust log file based on context perhaps? For now use a generic 'sent' log or the context-specific one.
        log_message("{$logContext} Email submitted via mail() to {$to}. Subject: {$subject}", LOG_FILE_BLOOD_DONOR); // Example: Use Donor log
        return true;
    } else {
        $errorInfo = error_get_last();
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs.');
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
    return htmlspecialchars($form_submissions[$formId][$fieldName] ?? $default, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error).
 */
function get_form_status_html(string $formId): string {
    global $form_messages;
    if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId];
    $isSuccess = ($message['type'] === 'success');
    $baseClasses = 'px-4 py-3 rounded relative mb-6 form-message text-sm shadow-md border';
    $typeClasses = $isSuccess ? 'bg-green-100 border-green-400 text-green-800' : 'bg-red-100 border-red-400 text-red-800';
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-600' : 'fas fa-exclamation-triangle text-red-600';
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\">" . "<strong class=\"font-bold\"><i class=\"{$iconClass} mr-2\"></i>" . ($isSuccess ? 'Success!' : 'Error:') . "</strong> " . "<span class=\"block sm:inline\">" . htmlspecialchars($message['text']) . "</span>" . "</div>";
}

/**
 * Generates HTML for a field error message.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors;
    if (isset($form_errors[$formId][$fieldName])) { return '<p class="text-red-600 text-xs italic mt-1" id="' . $fieldName . '_error">' . '<i class="fas fa-times-circle mr-1"></i>' . htmlspecialchars($form_errors[$formId][$fieldName]) . '</p>'; } return '';
}

/**
 * Returns CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors; return isset($form_errors[$formId][$fieldName]) ? 'form-input-error' : 'border-gray-300';
}

// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "Blood Donation & Assistance - PAHAL NGO";
$page_description = "Learn about blood donation eligibility, register as a donor, find upcoming camps, or request blood assistance through PAHAL NGO in Jalandhar.";
$page_keywords = "blood donation, pahal ngo, jalandhar, donate blood, blood request, blood camp, save life, blood donor registration";

// --- Initialize Form State Variables ---
$form_submissions = [];
$form_messages = [];
$form_errors = [];
$csrf_token = generate_csrf_token(); // Generate initial token for GET request

// --- Dummy Data & Logic (Same as before) ---
$upcoming_camps = [
    ['id' => 1, 'date' => new DateTime('2024-11-15'), 'time' => '10:00 AM - 3:00 PM', 'location' => 'PAHAL NGO Main Office, Maqsudan, Jalandhar', 'organizer' => 'PAHAL & Local Hospital Partners', 'notes' => 'Walk-ins welcome, pre-registration encouraged. Refreshments provided.' ],
    ['id' => 2, 'date' => new DateTime('2024-12-10'), 'time' => '9:00 AM - 1:00 PM', 'location' => 'Community Centre, Model Town, Jalandhar', 'organizer' => 'PAHAL Youth Wing', 'notes' => 'Special drive focusing on Thalassemia awareness.' ],
];
$upcoming_camps = array_filter($upcoming_camps, fn($camp) => $camp['date'] >= new DateTime('today'));
usort($upcoming_camps, fn($a, $b) => $a['date'] <=> $b['date']);

$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$blood_facts = ["One donation can save up to three lives.", "Blood cannot be manufactured – it only comes from generous donors.", "About 1 in 7 people entering a hospital need blood.", "The shelf life of donated blood is typically 42 days.", "Type O negative blood is the universal red cell donor type.", "Type AB positive plasma is the universal plasma donor type.", "Regular blood donation may help keep iron levels in check."];


// --- Form Processing Logic ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = $_POST['form_id'] ?? null;

    // Anti-Spam & CSRF Checks
    if (!empty($_POST[HONEYPOT_FIELD_NAME]) || !validate_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        log_message("[SPAM/CSRF DETECTED] Blood Donation Page Failed security check. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        http_response_code(403); die("Security validation failed. Please refresh and try again.");
    }

    // --- Process Donor Registration Form ---
    if ($submitted_form_id === 'donor_registration_form') {
        $form_id = 'donor_registration_form'; $form_errors[$form_id] = [];
        // Sanitize
        $donor_name = sanitize_string($_POST['donor_name'] ?? '');
        $donor_email = sanitize_email($_POST['donor_email'] ?? '');
        $donor_phone = sanitize_string($_POST['donor_phone'] ?? '');
        $donor_blood_group = sanitize_string($_POST['donor_blood_group'] ?? '');
        $donor_dob = sanitize_string($_POST['donor_dob'] ?? '');
        $donor_location = sanitize_string($_POST['donor_location'] ?? '');
        $donor_consent = isset($_POST['donor_consent']) && $_POST['donor_consent'] === 'yes';
        $form_submissions[$form_id] = ['donor_name' => $donor_name, 'donor_email' => $donor_email, 'donor_phone' => $donor_phone, 'donor_blood_group' => $donor_blood_group, 'donor_dob' => $donor_dob, 'donor_location' => $donor_location, 'donor_consent' => $donor_consent ? 'yes' : ''];

        // Validate
        $rules = [ 'donor_name' => 'required|alpha_space|minLength:2|maxLength:100', 'donor_email' => 'required|email|maxLength:255', 'donor_phone' => 'required|phone|maxLength:20', 'donor_blood_group' => 'required|in:'.implode(',', $blood_types), 'donor_dob' => 'required|date:Y-m-d', 'donor_location' => 'required|maxLength:150', /*'donor_consent' => 'required' handled separately below */ ];
        $validation_errors = validate_data($form_submissions[$form_id], $rules);
        // Custom Age Check
        $age = null; if (!empty($donor_dob)) { try { $birthDate = new DateTime($donor_dob); $today = new DateTime(); $age = $today->diff($birthDate)->y; if ($age < 18 || $age > 65) $validation_errors['donor_dob'] = "Donors must typically be 18-65 years old."; } catch (Exception $e) { $validation_errors['donor_dob'] = "Invalid date format."; } }
        // Custom Consent Check
        if (!$donor_consent) { $validation_errors['donor_consent'] = "You must consent to be contacted."; }
        $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_DONOR_REG; $subject = "New Blood Donor Registration: " . $donor_name;
            $body = "A potential blood donor has registered via the PAHAL website.\n\n" . str_repeat('-', 49) . "\n Donor Details:\n" . str_repeat('-', 49) . "\n" . " Name:        " . $donor_name . "\n" . " DOB:         " . $donor_dob . ($age !== null ? " (Age Approx: {$age})" : "") . "\n" . " Email:       " . $donor_email . "\n" . " Phone:       " . $donor_phone . "\n" . " Blood Group: " . $donor_blood_group . "\n" . " Location:    " . $donor_location . "\n" . " Consent Given: Yes\n" . " IP Address:   " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n" . " Timestamp:    " . date('Y-m-d H:i:s T') . "\n" . str_repeat('-', 49) . "\n" . "ACTION: Please verify eligibility and add to the donor database/contact list.\n" . str_repeat('-', 49) . "\n";
            $logContext = "[Donor Reg Form]";
            if (send_email($to, $subject, $body, $donor_email, $donor_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$donor_name}! Your registration is received. We'll contact you regarding donation opportunities and eligibility."];
                log_message("{$logContext} Success. Name: {$donor_name}, BG: {$donor_blood_group}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_DONOR); $form_submissions[$form_id] = [];
            } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$donor_name}, there was an internal error processing your registration. Please try again or contact us."]; log_message("{$logContext} FAILED Email Send via mail(). Name: {$donor_name}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) to complete registration."]; log_message("[Donor Reg Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR); }
        $_SESSION['scroll_to'] = '#donor-registration';
    }

    // --- Process Blood Request Form ---
    elseif ($submitted_form_id === 'blood_request_form') {
         $form_id = 'blood_request_form'; $form_errors[$form_id] = [];
         // Sanitize
         $request_patient_name = sanitize_string($_POST['request_patient_name'] ?? ''); $request_blood_group = sanitize_string($_POST['request_blood_group'] ?? ''); $request_units_raw = $_POST['request_units'] ?? null; $request_hospital = sanitize_string($_POST['request_hospital'] ?? ''); $request_contact_person = sanitize_string($_POST['request_contact_person'] ?? ''); $request_contact_phone = sanitize_string($_POST['request_contact_phone'] ?? ''); $request_urgency = sanitize_string($_POST['request_urgency'] ?? ''); $request_message = sanitize_string($_POST['request_message'] ?? '');
         $form_submissions[$form_id] = [ 'request_patient_name' => $request_patient_name, 'request_blood_group' => $request_blood_group, 'request_units' => $request_units_raw, 'request_hospital' => $request_hospital, 'request_contact_person' => $request_contact_person, 'request_contact_phone' => $request_contact_phone, 'request_urgency' => $request_urgency, 'request_message' => $request_message ];
         // Validate
         $rules = [ 'request_patient_name' => 'required|alpha_space|minLength:2|maxLength:100', 'request_blood_group' => 'required|in:'.implode(',', $blood_types), 'request_units' => 'required|integer|min:1|max:20', 'request_hospital' => 'required|maxLength:200', 'request_contact_person' => 'required|alpha_space|minLength:2|maxLength:100', 'request_contact_phone' => 'required|phone|maxLength:20', 'request_urgency' => 'required|maxLength:50', 'request_message' => 'maxLength:2000', ];
         $validation_errors = validate_data($form_submissions[$form_id], $rules);
         $form_errors[$form_id] = $validation_errors;
         $request_units = filter_var($request_units_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]); // Re-validate after using rule

         if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_BLOOD_REQUEST; $subject = "Blood Request ({$request_urgency}): {$request_blood_group} for {$request_patient_name}";
             $body = "A blood request was submitted via PAHAL website.\n\n" . str_repeat('!', 49) . "\n          BLOOD REQUEST DETAILS - {$request_urgency}\n" . str_repeat('!', 49) . "\n\n" . " Patient Name:      " . $request_patient_name . "\n" . " Blood Group Needed: " . $request_blood_group . "\n" . " Units Required:    " . ($request_units ?: 'N/A') . "\n" . " Urgency:           " . $request_urgency . "\n" . " Hospital Name/Addr:" . $request_hospital . "\n\n" . str_repeat('-', 49) . "\n Contact Information:\n" . str_repeat('-', 49) . "\n" . " Contact Person:    " . $request_contact_person . "\n" . " Contact Phone:     " . $request_contact_phone . "\n\n" . str_repeat('-', 49) . "\n Additional Info:\n" . str_repeat('-', 49) . "\n" . (!empty($request_message) ? $request_message : "(None)") . "\n\n" . str_repeat('-', 49) . "\n Details Submitted By:\n" . str_repeat('-', 49) . "\n IP Address:        " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n" . " Timestamp:         " . date('Y-m-d H:i:s T') . "\n" . str_repeat('-', 49) . "\n ACTION: Verify request and assist if possible (contact donors/blood banks).\n" . str_repeat('-', 49) . "\n";
             $logContext = "[Blood Req Form]";
             if (send_email($to, $subject, $body, '', $request_contact_person, $logContext)) {
                 $form_messages[$form_id] = ['type' => 'success', 'text' => "Your blood request has been submitted. We understand the urgency and will try our best to assist. Contact {$request_contact_person} shortly."];
                 log_message("{$logContext} Success. Patient: {$request_patient_name}, BG: {$request_blood_group}, Units: {$request_units}. Contact: {$request_contact_person}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_REQUEST); $form_submissions[$form_id] = [];
             } else {
                 $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, error submitting blood request. Please try again or call us directly for urgent needs."]; log_message("{$logContext} FAILED Email Send via mail(). Patient: {$request_patient_name}. Contact: {$request_contact_person}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
             }
         } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix {$errorCount} error(s) to submit your request."]; log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR); }
         $_SESSION['scroll_to'] = '#request-blood';
    }

    // --- Post-Processing & Redirect ---
     unset($_SESSION[CSRF_TOKEN_NAME]); $csrf_token = generate_csrf_token(); // Regenerate token
     $_SESSION['form_messages'] = $form_messages; $_SESSION['form_errors'] = $form_errors;
     if (!empty($form_errors[$submitted_form_id ?? ''])) { $_SESSION['form_submissions'] = $form_submissions; } else { unset($_SESSION['form_submissions']); }
     $scrollTarget = $_SESSION['scroll_to'] ?? ''; unset($_SESSION['scroll_to']);
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget); exit; // Redirect

} else {
    // --- GET Request: Retrieve session data after potential redirect ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    $csrf_token = generate_csrf_token(); // Ensure token exists
}

// --- Prepare Form Data for HTML ---
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
$donor_reg_email_value = get_form_value('donor_registration_form', 'donor_email');
$donor_reg_phone_value = get_form_value('donor_registration_form', 'donor_phone');
$donor_reg_blood_group_value = get_form_value('donor_registration_form', 'donor_blood_group');
$donor_reg_dob_value = get_form_value('donor_registration_form', 'donor_dob');
$donor_reg_location_value = get_form_value('donor_registration_form', 'donor_location');
$donor_reg_consent_value = get_form_value('donor_registration_form', 'donor_consent') === 'yes';

$blood_req_patient_name_value = get_form_value('blood_request_form', 'request_patient_name');
$blood_req_blood_group_value = get_form_value('blood_request_form', 'request_blood_group');
$blood_req_units_value = get_form_value('blood_request_form', 'request_units');
$blood_req_hospital_value = get_form_value('blood_request_form', 'request_hospital');
$blood_req_contact_person_value = get_form_value('blood_request_form', 'request_contact_person');
$blood_req_contact_phone_value = get_form_value('blood_request_form', 'request_contact_phone');
$blood_req_urgency_value = get_form_value('blood_request_form', 'request_urgency');
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');

// Theme colors (match previous state)
$primary_color = '#008000';
$accent_color = '#DC143C';
$primary_dark_color = '#006400';
$accent_dark_color = '#a5102f';
$light_bg_color = '#f8f9fa';
$dark_text_color = '#333333';

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
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/blood-donation.php"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/blood-donation-og.jpg"/> <!-- CHANGE -->

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

<script>
    // Tailwind config stays same as previous blood-donation file
    tailwind.config = { /* ... keep existing config ... */ }
</script>
<style type="text/tailwindcss">
    /* Styles stay same as previous blood-donation file */
    @layer base { /* ... */ }
    @layer components { /* ... */ }
    @layer utilities { /* ... */ }
    /* --- Page Specific Styles --- */
     /* ... */
     #main-header { @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200; min-height: 70px; @apply py-2 md:py-0; }
    body { @apply pt-[70px]; }
    /* Hero Specific */
    #hero-blood { @apply bg-gradient-to-br from-blood-light via-red-100 to-info-light text-center section-padding relative overflow-hidden; }
     #hero-blood h1 { @apply text-4xl md:text-6xl font-extrabold text-blood-dark mb-4 drop-shadow-lg; }
     #hero-blood p.lead { @apply text-lg md:text-xl text-gray-700 font-medium max-w-3xl mx-auto mb-8 drop-shadow-sm; }
     #hero-blood .icon-drop { @apply text-6xl text-accent mb-4 animate-pulse; }
     /* Eligibility Icons */
     .eligibility-list li i.fa-check { @apply text-green-600; }
     .eligibility-list li i.fa-times { @apply text-red-600; }
     .eligibility-list li i.fa-info-circle { @apply text-blue-600; }
     /* Camps Section Styling */
     .camp-card { @apply bg-white p-5 rounded-lg shadow-md border-l-4 border-accent transition-all duration-300 hover:shadow-lg hover:border-primary hover:scale-[1.01]; }
     .camp-card .camp-date { @apply text-accent font-bold text-lg; }
     .camp-card .camp-location { @apply text-primary-dark font-semibold; }
     /* Form Error Highlighting */
    .form-input-error { @apply border-red-500 ring-1 ring-red-500 focus:border-red-500 focus:ring-red-500; }
     /* Facts Section */
     #blood-facts .fact-card { @apply bg-info/10 border border-info/30 p-4 rounded-md text-center text-info flex flex-col items-center justify-center min-h-[120px]; } /* Added min height */
     #blood-facts .fact-icon { @apply text-3xl mb-2 text-info; }
     #blood-facts .fact-text { @apply text-sm font-semibold text-text-main; }
    /* Other page specific styles... */

</style>
</head>
<body class="bg-bg-light">
    <!-- Shared Header -->
    <header id="main-header">
       <div class="container mx-auto px-4 flex flex-wrap items-center justify-between">
           <div class="logo flex-shrink-0">
               <a href="index.php#hero" class="text-3xl font-black text-accent font-heading leading-none flex items-center"> <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL </a>
           </div>
           <nav aria-label="Site Navigation">
                <a href="index.php" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">Home</a>
                <a href="index.php#contact" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">Contact</a>
                 <a href="e-waste.php" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">E-Waste</a>
           </nav>
       </div>
    </header>

<main>
    <!-- Hero Section -->
    <section id="hero-blood" class="animate-fade-in">
         <div class="container mx-auto relative z-10">
             <div class="icon-drop"><i class="fas fa-tint"></i></div>
             <h1>Donate Blood, Give the Gift of Life</h1>
             <p class="lead">Join PAHAL's mission to ensure a readily available and safe blood supply for our community. Your generosity can make a profound difference.</p>
             <div class="space-x-4 mt-10">
                 <a href="#donor-registration" class="btn btn-secondary text-lg"><i class="fas fa-user-plus mr-2"></i> Register as Donor</a>
                 <a href="#request-blood" class="btn text-lg"><i class="fas fa-ambulance mr-2"></i> Request Blood</a>
             </div>
         </div>
         <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-red-200/30 rounded-full blur-xl opacity-50 animate-pulse-slow"></div>
         <div class="absolute -top-10 -right-10 w-40 h-40 bg-blue-200/30 rounded-full blur-xl opacity-50 animate-pulse-slow animation-delay-2s"></div>
     </section>

    <!-- Informational Section Grid -->
    <section class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title !text-center !mt-0">Understanding Blood Donation</h2>
            <div class="grid md:grid-cols-2 gap-12 mt-12">
                <!-- Why Donate? -->
                <div class="card animate-slide-in-bottom animate-delay-100">
                     <h3 class="text-accent !mt-0 flex items-center"><i class="fas fa-heartbeat text-3xl mr-3 text-accent"></i>Why Your Donation Matters</h3>
                     <p>Blood is a critical resource, constantly needed for surgeries, accident victims, cancer patients, and individuals with blood disorders. It cannot be artificially created.</p>
                     <ul class="text-text-main list-none pl-0 space-y-3">
                         <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-primary mt-1"></i> Directly saves lives in emergencies and medical treatments.</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-primary mt-1"></i> Supports patients undergoing long-term therapies (e.g., chemotherapy).</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-primary mt-1"></i> Crucial component for maternal care during childbirth.</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-primary mt-1"></i> Represents a vital act of community solidarity and support.</li>
                     </ul>
                     <p class="mt-6 font-semibold text-primary-dark text-lg">Be a lifeline. Your single donation can impact multiple lives.</p>
                </div>

                <!-- Who Can Donate? -->
                <div class="card animate-slide-in-bottom animate-delay-200">
                    <h3 class="text-info !mt-0 flex items-center"><i class="fas fa-user-check text-3xl mr-3 text-info"></i>Eligibility Essentials</h3>
                    <p>Ensuring the safety of both donors and recipients is our top priority. General guidelines include:</p>
                    <div class="grid sm:grid-cols-2 gap-6 mt-4 eligibility-list">
                        <div>
                             <h4 class="text-lg text-primary mb-2"><i class="fas fa-check mr-2 text-green-600"></i>You likely CAN donate if you:</h4>
                             <ul class="text-text-main list-none pl-0 space-y-1 text-sm">
                                <li><i class="fas fa-calendar-alt mr-2 text-gray-500"></i> Are 18-65 years old (check limits).</li>
                                <li><i class="fas fa-weight-hanging mr-2 text-gray-500"></i> Weigh ≥ 50 kg (110 lbs).</li>
                                <li><i class="fas fa-heart mr-2 text-gray-500"></i> Are in good general health.</li>
                                <li><i class="fas fa-tint mr-2 text-gray-500"></i> Meet hemoglobin levels (tested).</li>
                            </ul>
                        </div>
                         <div>
                            <h4 class="text-lg text-accent mb-2"><i class="fas fa-times mr-2 text-red-600"></i>Consult staff if you:</h4>
                             <ul class="text-text-main list-none pl-0 space-y-1 text-sm">
                                <li><i class="fas fa-pills mr-2 text-gray-500"></i> Take certain medications.</li>
                                <li><i class="fas fa-procedures mr-2 text-gray-500"></i> Have specific medical conditions.</li>
                                <li><i class="fas fa-plane mr-2 text-gray-500"></i> Traveled internationally recently.</li>
                                <li><i class="fas fa-calendar-minus mr-2 text-gray-500"></i> Donated recently (~3 months).</li>
                             </ul>
                         </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-6"><i class="fas fa-info-circle mr-1"></i>Final eligibility confirmed via confidential screening at the donation site.</p>
                </div>
            </div>
        </div>
    </section>


     <!-- Donor Registration Section -->
     <section id="donor-registration" class="section-padding bg-primary/5">
        <div class="container mx-auto">
            <h2 class="section-title text-center"><i class="fas fa-user-plus mr-2"></i>Become a Registered Blood Donor</h2>
             <p class="text-center max-w-3xl mx-auto mb-10 text-lg">Join our network of heroes! Registering allows us to contact you when your blood type is needed or when camps are scheduled near you. Your information is kept confidential.</p>

             <div class="form-section max-w-3xl mx-auto animate-fade-in">
                 <?= get_form_status_html('donor_registration_form') ?>
                 <form id="donor-registration-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#donor-registration" method="POST" class="space-y-6">
                     <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                     <input type="hidden" name="form_id" value="donor_registration_form">
                     <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_donor">Keep Blank</label><input type="text" id="website_url_blood_donor" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div> <label for="donor_name" class="required">Full Name:</label> <input type="text" id="donor_name" name="donor_name" required value="<?= $donor_reg_name_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_name') ?>" placeholder="e.g., Priya Sharma"> <?= get_field_error_html('donor_registration_form', 'donor_name') ?> </div>
                         <div> <label for="donor_dob" class="required">Date of Birth:</label> <input type="date" id="donor_dob" name="donor_dob" required value="<?= $donor_reg_dob_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_dob') ?>" max="<?= date('Y-m-d') ?>"> <p class="text-xs text-gray-500 mt-1">YYYY-MM-DD. Must be 18-65 yrs.</p> <?= get_field_error_html('donor_registration_form', 'donor_dob') ?> </div>
                     </div>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div> <label for="donor_email" class="required">Email:</label> <input type="email" id="donor_email" name="donor_email" required value="<?= $donor_reg_email_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_email') ?>" placeholder="e.g., priya.sharma@email.com"> <?= get_field_error_html('donor_registration_form', 'donor_email') ?> </div>
                         <div> <label for="donor_phone" class="required">Mobile:</label> <input type="tel" id="donor_phone" name="donor_phone" required value="<?= $donor_reg_phone_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_phone') ?>" placeholder="e.g., 98xxxxxxxx"> <?= get_field_error_html('donor_registration_form', 'donor_phone') ?> </div>
                     </div>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div> <label for="donor_blood_group" class="required">Blood Group:</label> <select id="donor_blood_group" name="donor_blood_group" required aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_blood_group') ?>"> <option value="" disabled <?= empty($donor_reg_blood_group_value) ? 'selected' : '' ?>>Select Blood Group</option> <?php foreach($blood_types as $type): ?> <option value="<?= $type ?>" <?= ($donor_reg_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option> <?php endforeach; ?> </select> <?= get_field_error_html('donor_registration_form', 'donor_blood_group') ?> </div>
                         <div> <label for="donor_location" class="required">Location (Area/City):</label> <input type="text" id="donor_location" name="donor_location" required value="<?= $donor_reg_location_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_location') ?>" placeholder="e.g., Maqsudan, Jalandhar"> <?= get_field_error_html('donor_registration_form', 'donor_location') ?> </div>
                     </div>
                     <div class="mt-6"> <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer"> <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?> class="h-5 w-5 text-primary rounded border-gray-300 focus:ring-primary"> <span class="text-sm text-gray-700">I consent to PAHAL contacting me for donation needs/camps & understand this is not eligibility confirmation.</span> </label> <?= get_field_error_html('donor_registration_form', 'donor_consent') ?> </div>
                     <div class="pt-5"> <button type="submit" class="btn btn-secondary w-full sm:w-auto flex items-center justify-center"> <i class="fas fa-check-circle mr-2"></i>Register Now </button> </div>
                 </form>
            </div>
         </div>
     </section>


    <!-- Blood Request Section -->
    <section id="request-blood" class="section-padding bg-accent/5">
         <div class="container mx-auto">
            <h2 class="section-title text-center text-accent"><i class="fas fa-first-aid mr-2"></i>Request Blood Assistance</h2>
             <p class="text-center max-w-3xl mx-auto mb-10 text-lg">If you or someone you know requires blood urgently or for a planned procedure, please submit a request. PAHAL will try to connect you with registered donors or guide you to local blood banks.</p>
             <p class="text-center max-w-3xl mx-auto mb-10 text-sm font-semibold text-red-700 bg-red-100 p-3 rounded border border-red-300"><i class="fas fa-exclamation-triangle mr-1"></i> Disclaimer: PAHAL acts as a facilitator and does not operate a blood bank directly. Availability depends on donor responses and blood bank stocks. For emergencies, please contact hospitals/blood banks directly first.</p>


             <div class="form-section max-w-3xl mx-auto !border-accent animate-fade-in">
                  <?= get_form_status_html('blood_request_form') ?>
                <form id="blood-request-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#request-blood" method="POST" class="space-y-6">
                     <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                    <input type="hidden" name="form_id" value="blood_request_form">
                     <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_req">Keep Blank</label><input type="text" id="website_url_blood_req" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div> <label for="request_patient_name" class="required">Patient's Full Name:</label> <input type="text" id="request_patient_name" name="request_patient_name" required value="<?= $blood_req_patient_name_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_patient_name') ?>"> <?= get_field_error_html('blood_request_form', 'request_patient_name') ?> </div>
                         <div> <label for="request_blood_group" class="required">Blood Group Needed:</label> <select id="request_blood_group" name="request_blood_group" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_blood_group') ?>"> <option value="" disabled <?= empty($blood_req_blood_group_value) ? 'selected' : '' ?>>Select Blood Group</option> <?php foreach($blood_types as $type): ?> <option value="<?= $type ?>" <?= ($blood_req_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option> <?php endforeach; ?> </select> <?= get_field_error_html('blood_request_form', 'request_blood_group') ?> </div>
                     </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div> <label for="request_units" class="required">Units Required:</label> <input type="number" id="request_units" name="request_units" required value="<?= $blood_req_units_value ?>" min="1" max="20" step="1" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_units') ?>" placeholder="e.g., 2"> <?= get_field_error_html('blood_request_form', 'request_units') ?> </div>
                        <div> <label for="request_urgency" class="required">Urgency:</label> <select id="request_urgency" name="request_urgency" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_urgency') ?>"> <option value="" disabled <?= empty($blood_req_urgency_value) ? 'selected' : '' ?>>Select Urgency Level</option> <option value="Emergency (Immediate)" <?= ($blood_req_urgency_value === 'Emergency (Immediate)') ? 'selected' : '' ?>>Emergency (Immediate)</option> <option value="Urgent (Within 24 Hours)" <?= ($blood_req_urgency_value === 'Urgent (Within 24 Hours)') ? 'selected' : '' ?>>Urgent (Within 24 Hours)</option> <option value="Within 2-3 Days" <?= ($blood_req_urgency_value === 'Within 2-3 Days') ? 'selected' : '' ?>>Within 2-3 Days</option> <option value="Planned (Within 1 Week)" <?= ($blood_req_urgency_value === 'Planned (Within 1 Week)') ? 'selected' : '' ?>>Planned (Within 1 Week)</option> </select> <?= get_field_error_html('blood_request_form', 'request_urgency') ?> </div>
                    </div>
                     <div> <label for="request_hospital" class="required">Hospital Name & Location:</label> <input type="text" id="request_hospital" name="request_hospital" required value="<?= $blood_req_hospital_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_hospital') ?>" placeholder="e.g., Civil Hospital, Jalandhar"> <?= get_field_error_html('blood_request_form', 'request_hospital') ?> </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div> <label for="request_contact_person" class="required">Contact Person:</label> <input type="text" id="request_contact_person" name="request_contact_person" required value="<?= $blood_req_contact_person_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_person') ?>" placeholder="e.g., Attendant's Name"> <?= get_field_error_html('blood_request_form', 'request_contact_person') ?> </div>
                         <div> <label for="request_contact_phone" class="required">Contact Phone:</label> <input type="tel" id="request_contact_phone" name="request_contact_phone" required value="<?= $blood_req_contact_phone_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_phone') ?>"> <?= get_field_error_html('blood_request_form', 'request_contact_phone') ?> </div>
                     </div>
                     <div> <label for="request_message">Additional Info (Optional):</label> <textarea id="request_message" name="request_message" rows="4" class="<?= get_field_error_class('blood_request_form', 'request_message') ?>" placeholder="e.g., Patient condition, doctor's name..."><?= $blood_req_message_value ?></textarea> <?= get_field_error_html('blood_request_form', 'request_message') ?> </div>
                     <div class="pt-5"> <button type="submit" class="btn w-full sm:w-auto flex items-center justify-center"> <i class="fas fa-paper-plane mr-2"></i>Submit Request </button> </div>
                 </form>
            </div>
         </div>
     </section>

     <!-- Upcoming Camps Section -->
     <section id="upcoming-camps" class="section-padding">
        <div class="container mx-auto">
             <h2 class="section-title text-center"><i class="far fa-calendar-alt mr-2"></i>Upcoming Blood Donation Camps</h2>
            <?php if (!empty($upcoming_camps)): ?>
                <p class="text-center max-w-3xl mx-auto mb-10 text-lg">Join us at one of our upcoming events and be a hero!</p>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($upcoming_camps as $index => $camp): ?>
                    <div class="camp-card animate-fade-in animate-delay-<?= ($index + 1) * 100 ?>">
                        <p class="camp-date mb-2"><i class="fas fa-calendar-check mr-2"></i><?= $camp['date']->format('F j, Y (l)') ?></p>
                        <p class="text-sm text-gray-500 mb-2"><i class="far fa-clock mr-2"></i><?= htmlspecialchars($camp['time']) ?></p>
                        <p class="camp-location mb-2"><i class="fas fa-map-marker-alt mr-2"></i><?= htmlspecialchars($camp['location']) ?></p>
                        <p class="text-sm text-gray-600 mb-3"><i class="fas fa-sitemap mr-2"></i>Organized by: <?= htmlspecialchars($camp['organizer']) ?></p>
                        <?php if (!empty($camp['notes'])): ?> <p class="text-xs bg-primary/10 text-primary-dark p-2 rounded italic"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($camp['notes']) ?></p> <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
             <?php else: ?>
                 <div class="text-center bg-blue-50 p-8 rounded-lg border border-blue-200 max-w-2xl mx-auto shadow">
                     <i class="fas fa-info-circle text-4xl text-blue-500 mb-4"></i> <h3 class="text-xl font-semibold text-blue-800 mb-2">No Camps Currently Scheduled</h3> <p class="text-blue-700">Please check back soon for updates. You can <a href="#donor-registration" class="font-bold underline hover:text-blue-900">register as a donor</a> to be notified.</p>
                 </div>
            <?php endif; ?>
        </div>
    </section>


     <!-- Facts & Figures Section -->
     <section id="blood-facts" class="section-padding bg-gray-100">
        <div class="container mx-auto">
            <h2 class="section-title text-center !mt-0">Did You Know? Blood Facts</h2>
             <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
                <?php
                    $icons = ['fa-users', 'fa-hourglass-half', 'fa-hospital', 'fa-calendar-days', 'fa-flask-vial', 'fa-hand-holding-medical', 'fa-heart-circle-check']; // More diverse icons
                ?>
                <?php foreach ($blood_facts as $index => $fact): ?>
                <div class="fact-card animate-fade-in animate-delay-<?= ($index + 1) * 100 ?>">
                     <i class="fas <?= $icons[$index % count($icons)] ?> fact-icon"></i>
                     <p class="fact-text"><?= htmlspecialchars($fact) ?></p>
                </div>
                <?php endforeach; ?>
                 <div class="fact-card bg-primary/10 border-primary/30 text-primary animate-fade-in animate-delay-<?= (count($blood_facts) + 1) * 100 ?>">
                     <i class="fas fa-hand-holding-heart fact-icon !text-primary"></i>
                     <p class="fact-text !text-primary-dark">Your single donation matters greatly!</p>
                 </div>
            </div>
        </div>
    </section>


     <!-- Final CTA / Contact Info -->
    <section id="contact-info" class="section-padding">
         <div class="container mx-auto text-center max-w-3xl">
            <h2 class="section-title text-center !mt-0">Questions? Contact the Blood Program Coordinator</h2>
             <p class="text-lg mb-8">For specific questions about eligibility, the donation process, upcoming camps, or partnership opportunities related to our blood program, please contact:</p>
            <div class="bg-white p-8 rounded-lg shadow-lg border border-gray-200 inline-block text-left space-y-3">
                <p><strong class="text-primary-dark">Coordinator:</strong> [Coordinator Name or Health Dept]</p> <!-- CHANGE Placeholder -->
                <p> <i class="fas fa-phone mr-2 text-primary"></i> <strong class="text-primary-dark">Direct Line:</strong> <a href="tel:+919855614230" class="hover:underline font-semibold text-accent ml-1">+91 98556-14230</a> (Blood Program) </p>
                 <p> <i class="fas fa-envelope mr-2 text-primary"></i> <strong class="text-primary-dark">Email:</strong> <a href="mailto:bloodprogram@your-pahal-domain.com?subject=Blood%20Donation%20Inquiry" class="hover:underline font-semibold text-accent ml-1 break-all">bloodprogram@your-pahal-domain.com</a> </p> <!-- CHANGE Email -->
             </div>
            <div class="mt-10">
                <a href="index.php#contact" class="btn btn-secondary"><i class="fas fa-address-book mr-2"></i>General PAHAL Contact Info</a>
             </div>
        </div>
    </section>

</main>

<!-- Footer -->
<footer class="bg-primary-dark text-gray-300 pt-12 pb-8 mt-12">
    <div class="container mx-auto px-4 text-center">
         <div class="mb-4"> <a href="index.php" class="text-2xl font-black text-white hover:text-gray-300 font-heading leading-none">PAHAL NGO</a> <p class="text-xs text-gray-400">Promoting Health and Well-being</p> </div>
        <nav class="mb-4 text-sm space-x-4"> <a href="index.php" class="hover:text-white hover:underline">Home</a> | <a href="#donor-registration" class="hover:text-white hover:underline">Register Donor</a> | <a href="#request-blood" class="hover:text-white hover:underline">Request Blood</a> | <a href="#upcoming-camps" class="hover:text-white hover:underline">Camps</a> | <a href="index.php#contact" class="hover:text-white hover:underline">Contact</a> </nav>
         <p class="text-xs text-gray-500 mt-6"> © <?= $current_year ?> PAHAL NGO. All Rights Reserved. <br class="sm:hidden"> <a href="index.php#profile" class="hover:text-white hover:underline">About Us</a> | <a href="privacy-policy.php" class="hover:text-white hover:underline">Privacy Policy (Example)</a> </p>
   </div>
</footer>

<!-- JS for interactions -->
<script>
 document.addEventListener('DOMContentLoaded', () => {
    // --- Age Hint Logic ---
     const dobInput = document.getElementById('donor_dob');
     if (dobInput) {
         dobInput.addEventListener('change', () => {
             const ageHint = dobInput.parentElement.querySelector('.text-xs');
             if (!ageHint) return; // Exit if hint element not found
             try {
                 const birthDate = new Date(dobInput.value);
                 const today = new Date();
                 if (isNaN(birthDate.getTime())) { throw new Error('Invalid Date'); } // Check if date is valid

                 let age = today.getFullYear() - birthDate.getFullYear();
                 const m = today.getMonth() - birthDate.getMonth();
                 if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) { age--; }

                 if (age >= 18 && age <= 65) {
                    ageHint.textContent = `Approx. age: ${age}. Looks good!`; ageHint.style.color = 'green';
                 } else if (age > 0) {
                     ageHint.textContent = `Approx. age: ${age}. Note: Must be 18-65.`; ageHint.style.color = 'orange';
                 } else {
                    ageHint.textContent = 'YYYY-MM-DD. Must be 18-65 years old.'; ageHint.style.color = '';
                 }
             } catch (e) {
                  // Handle invalid date input gracefully
                  ageHint.textContent = 'Invalid date entered.'; ageHint.style.color = 'red';
             }
         });
     }

      // Add specific JS logic for this page if needed
      console.log("Blood Donation Page JS Loaded - No PHPMailer");

      // Handle scroll target restoration after redirect (basic version)
       const hash = window.location.hash;
       if (hash) {
          const targetElement = document.querySelector(hash);
          if (targetElement) {
              // Allow browser default behavior or implement smooth scroll with offset
              // Using a timeout to ensure layout is stable
               setTimeout(() => {
                  const headerOffset = document.getElementById('main-header')?.offsetHeight ?? 70;
                  const elementPosition = targetElement.getBoundingClientRect().top;
                  const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20;
                  window.scrollTo({ top: offsetPosition, behavior: 'smooth'});
              }, 100);
          }
       }

 });
 </script>

</body>
</html>
