<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 3.6 (UI Refactored to match E-Waste Page Styling)
// Features: Tailwind UI (E-Waste Style), Responsive Design, Animations,
//           PHP mail(), CSRF, Honeypot, Logging
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration (Keep Existing - CHANGE ME reminders) ---
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com"); // CHANGE ME
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Website');             // CHANGE ME
define('CSRF_TOKEN_NAME', 'csrf_token');
// Unique honeypot name for this page
define('HONEYPOT_FIELD_NAME', 'website_url_main'); // Unique name

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
// **** Copying from the refined Blood/E-Waste helpers ****

/**
 * Logs a message to a specified file.
 */
function log_message(string $message, string $logFile): void {
    if (!ENABLE_LOGGING) return;
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) { error_log("Failed to create log directory: " . $logDir); error_log("Original Log Message ($logFile): " . $message); return; }
        if (is_dir($logDir) && !file_exists($logDir . '/.htaccess')) { @file_put_contents($logDir . '/.htaccess', 'Deny from all'); }
         if (is_dir($logDir) && !file_exists($logDir . '/index.html')) { @file_put_contents($logDir . '/index.html', ''); } // Prevent directory listing
    }
    $timestamp = date('Y-m-d H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP'; // Get user IP
    $logEntry = "[{$timestamp}] [IP: {$ipAddress}] {$message}" . PHP_EOL;
    if (@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) { $error = error_get_last(); error_log("Failed to write log: " . $logFile . " | Error: " . ($error['message'] ?? 'Unknown file write error')); error_log("Original Log: " . $message); }
}

/**
 * Generates or retrieves a CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) { try { $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32)); } catch (Exception $e) { $_SESSION[CSRF_TOKEN_NAME] = md5(uniqid(mt_rand(), true)); log_message("CSRF token generated using fallback method. Exception: " . $e->getMessage(), LOG_FILE_ERROR); } }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validates the submitted CSRF token. Unsets the session token after comparison attempt.
 */
function validate_csrf_token(?string $submittedToken): bool {
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION[CSRF_TOKEN_NAME])) { log_message("CSRF Validation Failed: Session token missing.", LOG_FILE_ERROR); return false; }
    if (empty($submittedToken)) { log_message("CSRF Validation Failed: Submitted token missing.", LOG_FILE_ERROR); unset($_SESSION[CSRF_TOKEN_NAME]); return false; } // Unset on missing submitted token too
    $result = hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
    unset($_SESSION[CSRF_TOKEN_NAME]); // Always unset after use
    if (!$result) { log_message("CSRF Validation Failed: Token mismatch.", LOG_FILE_ERROR); }
    return $result;
}

/**
 * Sanitize input string.
 */
function sanitize_string(?string $input): string { if ($input === null) return ''; return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

/**
 * Sanitize email address.
 */
function sanitize_email(?string $email): string { if ($email === null) return ''; $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL); return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : ''; }

/**
 * Validates input data based on rules. (Using more robust version)
 */
function validate_data(array $data, array $rules): array {
     $errors = [];
     foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null; $ruleList = explode('|', $ruleString); $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));
        foreach ($ruleList as $rule) {
            $params = []; if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }
            $isValid = true; $errorMessage = '';
             // Trim string values before validation (unless rule is 'required')
             if (is_string($value) && $rule !== 'required') { $value = trim($value); if ($value === '') $value = null; }

            switch ($rule) {
                case 'required': if ($value === null || $value === '' || (is_array($value) && empty($value))) { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please enter a valid email."; } break;
                case 'minLength': $len = (int)($params[0] ?? 0); if ($value !== null && mb_strlen((string)$value, 'UTF-8') < $len) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$len} characters."; } break;
                case 'maxLength': $len = (int)($params[0] ?? 255); if ($value !== null && mb_strlen((string)$value, 'UTF-8') > $len) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed {$len} characters."; } break;
                case 'alpha_space': if ($value !== null && !preg_match('/^[\p{L}\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if ($value !== null && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) { $isValid = false; $errorMessage = "Invalid phone format."; } break;
                case 'in': if ($value !== null && is_array($params) && !in_array($value, $params)) { $isValid = false; $errorMessage = "Invalid selection for {$fieldNameFormatted}."; } break;
                case 'required_without': $otherField = $params[0] ?? null; if ($otherField && ($value === null || trim($value) === '') && empty(trim($data[$otherField] ?? ''))) { $isValid = false; $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_',' ',$otherField)). " is required."; } break;
                // Add other rules like 'date', 'integer', 'min', 'max' if needed for other forms not shown
                default: log_message("Unknown validation rule '{$rule}' for field '{$field}'.", LOG_FILE_ERROR); break;
            }
            if (!$isValid && !isset($errors[$field])) { $errors[$field] = $errorMessage; break; }
         }
     }
     return $errors;
}

/**
 * Sends an email using the standard PHP mail() function. (Using more robust version)
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool {
    $senderName = SENDER_NAME_DEFAULT; $senderEmail = SENDER_EMAIL_DEFAULT;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid recipient: {$to}", LOG_FILE_ERROR); return false; }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) { log_message("{$logContext} Invalid sender in config: {$senderEmail}", LOG_FILE_ERROR); return false; }
    $fromHeader = "From: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n";
    $replyToValidEmail = sanitize_email($replyToEmail); // Sanitize reply-to
    $replyToHeader = "";
    if (!empty($replyToValidEmail)) { $replyToNameClean = sanitize_string($replyToName); $replyToFormatted = $replyToNameClean ? "=?UTF-8?B?".base64_encode($replyToNameClean)."?= <{$replyToValidEmail}>" : $replyToValidEmail; $replyToHeader = "Reply-To: {$replyToFormatted}\r\n"; }
    else { $replyToHeader = "Reply-To: =?UTF-8?B?".base64_encode($senderName)."?= <{$senderEmail}>\r\n"; } // Fallback Reply-To
    $headers = $fromHeader . $replyToHeader;
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n"; $headers .= "MIME-Version: 1.0\r\n"; $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $encodedSubject = "=?UTF-8?B?".base64_encode($subject)."?="; $wrapped_body = wordwrap($body, 70, "\r\n");
    $additionalParams = "-f{$senderEmail}"; // Set envelope sender

    if (@mail($to, $encodedSubject, $wrapped_body, $headers, $additionalParams)) { log_message("{$logContext} Email submitted via mail() to {$to}. Subject: {$subject}", LOG_FILE_CONTACT); return true; } // Log success
    else { $errorInfo = error_get_last(); $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error.'); log_message($errorMsg, LOG_FILE_ERROR); error_log($errorMsg); return false; } // Log error
}

/**
 * Retrieves a form value safely for HTML output, using global state. (Using more robust version)
 */
function get_form_value(string $formId, string $fieldName, string $default = ''): string {
    global $form_submissions; $value = $form_submissions[$formId][$fieldName] ?? $default;
    if (is_array($value) || is_object($value)) { log_message("Attempted to get non-scalar value for form '{$formId}', field '{$fieldName}'.", LOG_FILE_ERROR); return $default; } // Handle non-scalar
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates form status HTML (success/error) using E-Waste page's Tailwind classes.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    // Use classes from the E-Waste page styling
    $baseClasses = 'px-4 py-3 rounded relative mb-6 form-message text-sm shadow-md border'; // E-Waste base style for messages
    $typeClasses = $isSuccess ? 'bg-green-100 border-green-400 text-green-800' : 'bg-red-100 border-red-400 text-red-800'; // E-Waste colors
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-600' : 'fas fa-exclamation-triangle text-red-600'; // E-Waste icons
    $title = $isSuccess ? 'Success!' : 'Error:';
    // data-form-message-id can still be used for potential JS animation, but the primary animation is handled by CSS now
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\"><strong class=\"font-bold flex items-center\"><i class=\"{$iconClass} mr-2\"></i>{$title}</strong> <span class=\"block sm:inline mt-1 ml-7\">" . htmlspecialchars($message['text']) . "</span></div>"; // Simplified structure
}

/**
 * Generates HTML for a field error message using E-Waste page's Tailwind classes.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
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

// Define E-Waste page theme colors using PHP variables for use in Tailwind config (as per E-Waste style)
$primary_color = '#2E7D32'; // Green 800 (closer to E-Waste primary)
$primary_dark_color = '#1B5E20'; // Green 900
$accent_color = '#FFA000'; // Amber 500 (E-Waste accent)
$accent_dark_color = '#FF8F00'; // Amber 600
$secondary_color = '#F9FAFB'; // Gray 50 (E-Waste background)
$neutral_dark_color = '#374151'; // Gray 700
$neutral_medium_color = '#6B7280'; // Gray 500
$red_color = '#DC2626'; // Red 600 for errors/dangers
$info_color = '#3B82F6'; // Blue for info messages
$info_light_color = '#EFF6FF'; // Light blue for info background

// --- Initialize Form State Variables ---
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token();

// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = sanitize_string($_POST['form_id'] ?? '');
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME]);
    $logContext = "[Main Page POST]"; // Default context

    // --- Security Checks ---
    if ($honeypot_filled) {
        log_message("{$logContext} Honeypot triggered. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Silently fail or redirect
        $_SESSION['form_messages'][$submitted_form_id] = ['type' => 'success', 'text' => 'Thank you for your submission!'];
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id), true, 303);
        exit;
    }
    // validate_csrf_token now unsets the token on comparison attempt
    if (!validate_csrf_token($submitted_token)) {
        log_message("{$logContext} Invalid CSRF token. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        $displayFormId = !empty($submitted_form_id) ? $submitted_form_id : 'general_error'; // Fallback ID
        $_SESSION['form_messages'][$displayFormId] = ['type' => 'error', 'text' => 'Security token invalid or expired. Please refresh the page and try submitting the form again.'];
         // Generate a new token for the next request *after* validation failure
         $csrf_token = generate_csrf_token();
         header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "#" . urlencode($submitted_form_id), true, 303);
        exit;
    }
    // Regenerate token *after* successful validation check for the *next* request
    $csrf_token = generate_csrf_token();


    // --- Process Contact Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        $logContext = "[Contact Form]";
        // Sanitize
        $name = sanitize_string($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $message = sanitize_string($_POST['message'] ?? '');

        $submitted_data = ['name' => $name, 'email' => $email, 'message' => $message];

        // Validate
        $rules = ['name' => 'required|alpha_space|minLength:2|maxLength:100', 'email' => 'required|email|maxLength:255', 'message' => 'required|minLength:10|maxLength:2000'];
        $validation_errors = validate_data($submitted_data, $rules);
        $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_CONTACT;
            $subject = "Website Contact Form: " . $name;
            $body = "Name: {$name}\nEmail: {$email}\n\nMessage:\n{$message}\n\nIP Address: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T');

            if (send_email($to, $subject, $body, $email, $name, $logContext)) {
                $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Your message has been sent."];
                log_message("{$logContext} Success. Name: {$name}, Email: {$email}.", LOG_FILE_CONTACT);
                unset($submitted_data); // Clear on success
            } else {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$name}, there was an internal error sending your message. Please try again later."];
                 // Error logged in send_email()
            }
        } else {
            $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below."];
            // Submitted data kept automatically
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
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

        $submitted_data = [
             'volunteer_name' => $volunteer_name, 'volunteer_email' => $volunteer_email,
             'volunteer_phone' => $volunteer_phone, 'volunteer_area' => $volunteer_area,
             'volunteer_availability' => $volunteer_availability, 'volunteer_message' => $volunteer_message
         ];

        // Validate
        $rules = [
            'volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255', // Email OR phone required
            'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20', // Phone OR email required
            'volunteer_area' => 'required|in:Health,Education,Environment,Communication Skills,Other', // Add your actual areas
            'volunteer_availability' => 'required|maxLength:150',
            'volunteer_message' => 'maxLength:1000',
        ];
        $validation_errors = validate_data($submitted_data, $rules);
        $form_errors[$form_id] = $validation_errors;


        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_VOLUNTEER;
            $subject = "New Volunteer Interest: " . $volunteer_name;
            $body = "Volunteer Interest submitted via PAHAL website:\n\n"
                  . "Name: {$volunteer_name}\n"
                  . "Email: " . (!empty($volunteer_email) ? $volunteer_email : "(Not Provided)") . "\n"
                  . "Phone: " . (!empty($volunteer_phone) ? $volunteer_phone : "(Not Provided)") . "\n"
                  . "Area of Interest: {$volunteer_area}\n"
                  . "Availability: {$volunteer_availability}\n\n"
                  . "Message:\n" . (!empty($volunteer_message) ? $volunteer_message : "(None)") . "\n\n"
                  . "IP Address: {$_SERVER['REMOTE_ADDR']}\nTimestamp: " . date('Y-m-d H:i:s T');


            if (send_email($to, $subject, $body, $volunteer_email, $volunteer_name, $logContext)) {
                $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your volunteer interest is received. We will contact you soon."];
                log_message("{$logContext} Success. Name: {$volunteer_name}, Area: {$volunteer_area}.", LOG_FILE_VOLUNTEER);
                 unset($submitted_data); // Clear on success
            } else {
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, {$volunteer_name}, there was an internal error processing your volunteer interest. Please try again later."];
                  // Error logged in send_email()
            }
        } else {
            $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the " . count($validation_errors) . " error(s) below."];
            // Submitted data kept automatically
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
        }
        $_SESSION['scroll_to'] = '#volunteer-section'; // Set scroll target
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
    // --- GET Request: Retrieve session data ---
     if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = []; }
     if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = []; }
    // Retrieve submitted data only if redirection happened due to errors
     if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = []; }

    $csrf_token = generate_csrf_token(); // Ensure token exists for GET
}

// --- Prepare Form Data for HTML Template ---
// Use get_form_value helper to safely output potentially submitted data
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
$news_items = [
    // Example News Items
    ['id' => 1, 'date' => '2023-10-27', 'title' => 'Successful Blood Donation Camp Held', 'excerpt' => 'PAHAL NGO successfully organized a large blood donation camp...', 'image' => 'https://via.placeholder.com/400x250.png/dc2626/ffffff?text=Blood+Camp', 'link' => '#'], // Placeholder link
    ['id' => 2, 'date' => '2023-10-15', 'title' => 'Awareness Workshop on E-Waste Management', 'excerpt' => 'A workshop was conducted to educate the community on responsible e-waste disposal.', 'image' => 'https://via.placeholder.com/400x250.png/2E7D32/ffffff?text=E-Waste+Workshop', 'link' => '#'], // Placeholder link
    ['id' => 3, 'date' => '2023-09-30', 'title' => 'Community Clean-up Drive in Maqsudan', 'excerpt' => 'Volunteers participated in a clean-up drive focusing on plastic waste...', 'image' => 'https://via.placeholder.com/400x250.png/FFA000/ffffff?text=Clean-up+Drive', 'link' => '#'], // Placeholder link
];

$gallery_images = [
    // Example Gallery Images
    ['id' => 1, 'src' => 'https://via.placeholder.com/800x600.png/2E7D32/ffffff?text=Gallery+Image+1', 'alt' => 'PAHAL Activity 1'],
    ['id' => 2, 'src' => 'https://via.placeholder.com/800x600.png/FFA000/ffffff?text=Gallery+Image+2', 'alt' => 'PAHAL Activity 2'],
    ['id' => 3, 'src' => 'https://via.placeholder.com/800x600.png/DC2626/ffffff?text=Gallery+Image+3', 'alt' => 'PAHAL Activity 3'],
    ['id' => 4, 'src' => 'https://via.placeholder.com/800x600.png/3B82F6/ffffff?text=Gallery+Image+4', 'alt' => 'PAHAL Activity 4'],
    ['id' => 5, 'src' => 'https://via.placeholder.com/800x600.png/1B5E20/ffffff?text=Gallery+Image+5', 'alt' => 'PAHAL Activity 5'],
     ['id' => 6, 'src' => 'https://via.placeholder.com/800x600.png/FF8F00/ffffff?text=Gallery+Image+6', 'alt' => 'PAHAL Activity 6'],
];

$associates = [
    // Example Associates/Partners
    ['id' => 1, 'name' => 'Associate 1', 'img' => 'https://via.placeholder.com/150x80.png/f3f4f6/6b7280?text=Logo1'],
    ['id' => 2, 'name' => 'Associate 2', 'img' => 'https://via.placeholder.com/150x80.png/f3f4f6/6b7280?text=Logo2'],
    ['id' => 3, 'name' => 'Associate 3', 'img' => 'https://via.placeholder.com/150x80.png/f3f4f6/6b7280?text=Logo3'],
    ['id' => 4, 'name' => 'Associate 4', 'img' => 'https://via.placeholder.com/150x80.png/f3f4f6/6b7280?text=Logo4'],
];

// Bank Details (for modal)
$bank_details = [
    'account_name' => 'PAHAL (Regd.)',
    'account_number' => '[YOUR_BANK_ACCOUNT_NUMBER]', // CHANGE ME
    'bank_name' => '[YOUR_BANK_NAME]', // CHANGE ME
    'branch' => '[YOUR_BANK_BRANCH]', // CHANGE ME
    'ifsc_code' => '[YOUR_IFSC_CODE]', // CHANGE ME
];

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

    <!-- Open Graph / Social Media Meta Tags - Update URLs and image -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-og-enhanced.jpg"> <!-- CHANGE -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">

    <!-- Twitter Card - Update URLs and image -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/images/pahal-twitter-enhanced.jpg"> <!-- CHANGE -->

    <!-- Favicon - Use the same as E-Waste -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <!-- Removed site.webmanifest if not used -->

    <!-- Tailwind CSS CDN with Forms Plugin -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Lato & Open Sans) - Matching E-Waste page -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome - Matching E-Waste page version -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple Lightbox CSS (Keep if gallery used) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.css">

    <script>
        // Tailwind Config (Using E-Waste Palette, Fonts, Animations)
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
                         info: '<?= $info_color ?>', 'info-light': '<?= $info_light_color ?>', // Blue for info (matching E-Waste)
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
                        // Use E-Waste animations (simplified list)
                        'fade-in-scale': 'fadeInScale 0.6s ease-out forwards',
                        'slide-up': 'slideUp 0.5s ease-out forwards',
                        'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
                         // Add specific animations needed from v3.5 if not covered by E-Waste ones
                         'spin-slow': 'spin 2s linear infinite', // Standard Tailwind spin
                    },
                    keyframes: {
                        // Use E-Waste keyframes (simplified list)
                        fadeInScale: { '0%': { opacity: 0, transform: 'scale(0.95)' }, '100%': { opacity: 1, transform: 'scale(1)' } },
                        slideUp: { '0%': { opacity: 0, transform: 'translateY(20px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                        pulseGlow: { '0%, 100%': { opacity: 1, boxShadow: '0 0 0 0 rgba(255, 160, 0, 0.7)' }, '50%': { opacity: 0.8, boxShadow: '0 0 10px 5px rgba(255, 160, 0, 0)' } }
                        // Add specific keyframes needed from v3.5 if not covered
                    },
                     boxShadow: {
                         // Use E-Waste shadows
                         'md': '0 4px 6px rgba(0, 0, 0, 0.1)',
                         'lg': '0 10px 15px rgba(0, 0, 0, 0.1)',
                          'xl': '0 20px 25px rgba(0, 0, 0, 0.1), 0 8px 10px rgba(0, 0, 0, 0.05)',
                          '2xl': '0 25px 50px rgba(0, 0, 0, 0.25)',
                           'inner': 'inset 0 2px 4px rgba(0, 0, 0, 0.06)',
                           'sm': '0 1px 2px rgba(0, 0, 0, 0.05)'
                         // Re-define shadows based on E-Waste usage
                     },
                     // Removed custom focus shadow variable
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
            h4 { @apply text-xl md:text-2xl text-primary; } /* Adjusted h4 size to fit E-Waste pattern */
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

            /* Adopt E-Waste table styles (if any needed for specific sections) */
            table { @apply w-full border-collapse text-left text-sm text-neutral; }
            thead { @apply bg-primary/10; }
            th { @apply border border-primary/20 px-4 py-2 font-semibold text-primary; }
            td { @apply border border-gray-300 px-4 py-2; }
            tbody tr:nth-child(odd) { @apply bg-white; }
            tbody tr:nth-child(even) { @apply bg-neutral-light; }
            tbody tr:hover { @apply bg-primary/5; }

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

            /* Honeypot Field - Matching E-Waste style utility */
            .honeypot-field { @apply absolute left-[-5000px] w-px h-px overflow-hidden; } /* Original E-Waste utility */

            /* Blockquote - Matching E-Waste base/utility style */
            blockquote { @apply mt-6 mb-6 px-4 py-3 border-l-4 border-accent bg-accent/10 text-accent-dark italic rounded-r-md; }
             blockquote cite { @apply block not-italic mt-2 text-sm text-accent-dark/80;}
             address { @apply not-italic; }
        }

        @layer components {
            /* Adopt E-Waste component styles */
            .btn { @apply inline-flex items-center justify-center bg-primary text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-primary-dark hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
            .btn i { @apply mr-2 -ml-1; }
             /* Defined btn-secondary and btn-accent based on E-Waste structure and colors */
             /* btn-secondary matches E-Waste's btn-secondary (accent color) */
            .btn-secondary { @apply inline-flex items-center justify-center bg-accent text-black font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:text-white hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             /* btn-accent uses danger color (often red) - used for Blood */
             .btn-accent { @apply inline-flex items-center justify-center bg-danger text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-red-700 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-danger focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             /* btn-outline matches E-Waste outline */
             .btn-outline { @apply inline-flex items-center justify-center bg-transparent border-2 border-primary text-primary font-semibold py-2 px-6 rounded-full hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out; }
             /* New outline-secondary button style using E-Waste colors */
              .btn-outline.secondary { @apply !text-accent border-accent hover:bg-accent hover:text-white focus:ring-accent; }

            /* Removed btn-icon component - use btn + padding/rounded utilities if needed */

            .section-padding { @apply py-16 md:py-24; } /* Matching E-Waste padding */
            .card { @apply bg-white p-6 rounded-lg shadow-md transition-shadow duration-300 hover:shadow-lg overflow-hidden; } /* Matching E-Waste card */
            /* Removed panel component */

            /* Form Section - Matching E-Waste style */
            .form-section { @apply bg-white p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-accent mt-12; } /* Default border matches E-Waste form */
             /* Apply specific border colors for main page forms */
             #volunteer-section .form-section { @apply !border-primary; } /* Volunteer form border */
             #contact .form-section { @apply !border-secondary; } /* Contact form border */


             /* Section Title - Matching E-Waste style */
            .section-title { @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark; }
            .section-title::after { content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-primary rounded-full; }
            /* Removed section-title-inverted */

            /* Removed Blood page form component classes (.form-label, .form-input, .form-error-message) - using base styles + utility */
             /* Form message base style (details handled in get_form_status_html) */
            .form-message { /* Base class for status messages - styling comes from helper */ }

             /* Spinner utility - Matching E-Waste */
             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current align-middle; }

             /* Back to Top Button - Matching E-Waste button concept */
             #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-primary text-white shadow-md hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 opacity-0 invisible transition-all duration-300 hover:-translate-y-0.5; }
             #back-to-top.visible { @apply opacity-100 visible; }


             /* Modal Styles - Simplified to match E-Waste basic styles */
             .modal-container { @apply fixed inset-0 bg-black/50 z-[100] hidden items-center justify-center p-4 transition-opacity duration-300 ease-out; }
             .modal-container.flex { @apply flex; opacity: 1; }
             .modal-container.hidden { @apply hidden; opacity: 0; }

             .modal-box { @apply bg-white rounded-lg shadow-xl p-6 md:p-8 w-full max-w-lg text-left relative transform transition-all duration-300 scale-95 opacity-0; }
             .modal-container.flex .modal-box { @apply scale-100 opacity: 1; }

             #bank-details-modal h3 { @apply !text-primary !mt-0 mb-4 border-b border-gray-200 pb-3 text-2xl font-bold; } /* Adjusted modal title style */
             .modal-content-box { @apply bg-neutral-light p-4 rounded-md border border-gray-300 space-y-2 my-5 text-sm text-neutral-dark; } /* Match E-Waste neutral colors */
             .modal-content-box p strong { @apply font-semibold text-primary-dark; }
             .modal-footer-note { @apply text-xs text-neutral text-center mt-6 italic; }
             .close-button { @apply absolute top-4 right-4 text-gray-700 hover:text-danger p-1 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-danger; }


        }

        @layer utilities {
            /* Animation Delays - Matching E-Waste */
            .animate-delay-50 { animation-delay: 0.05s; } .animate-delay-100 { animation-delay: 0.1s; } .animate-delay-150 { animation-delay: 0.15s; } .animate-delay-200 { animation-delay: 0.2s; } .animate-delay-300 { animation-delay: 0.3s; } .animate-delay-400 { animation-delay: 0.4s; } .animate-delay-500 { animation-delay: 0.5s; } .animate-delay-700 { animation-delay: 0.7s; }
             .animate-delay-800 { animation-delay: 0.8s; } .animate-delay-900 { animation-delay: 0.9s; } .animate-delay-1000 { animation-delay: 1s; }

             /* Animation on Scroll Classes - Simplified to match E-Waste animation types */
             .animate-on-scroll { opacity: 0; transition: opacity 0.6s ease-out, transform 0.6s ease-out; } /* Base for AoS */
             /* Use E-Waste animation types for AoS variants */
             .animate-on-scroll.fade-in-scale { @apply animate-fade-in-scale; } /* No initial transform needed */
             .animate-on-scroll.slide-up { transform: translateY(20px); } /* Initial transform for slide-up */

             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); } /* End state */


             /* Animated Gradient Background Utilities - Adapting E-Waste concept */
             .animated-gradient-primary {
                 background: linear-gradient(-45deg, <?= $primary_color ?>, <?= $secondary_color ?>, <?= $info_color ?>, <?= $primary_color ?>); /* Use E-Waste colors */
                 background-size: 400% 400%;
                 animation: gradientBg 15s ease infinite; /* Use E-Waste keyframe name if defined */
             }
             .animated-gradient-secondary { /* Use a different color combination */
                  background: linear-gradient(-45deg, <?= $accent_color ?>, <?= $danger_color ?? '#DC2626' ?>, <?= $accent_color ?>); /* Amber/Red/Amber */
                  background-size: 400% 400%;
                 animation: gradientBg 18s ease infinite;
             }
             /* Add gradientBg keyframe if not in E-Waste config */
            @keyframes gradientBg { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }


        }

        /* --- Specific Section Styles (Refactored) --- */

         /* Header - Matching E-Waste style */
         #main-header { @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200; min-height: 70px; @apply py-2 md:py-0; }
         /* Removed scrolled state from CSS - handled by JS */

         /* Navigation - Matching E-Waste structure/style */
         #navbar ul { @apply flex flex-col lg:flex-row lg:items-center lg:space-x-4 py-4 lg:py-0 px-4 lg:px-0; } /* E-Waste spacing */
         #navbar ul li a { @apply text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors text-sm lg:text-base block lg:inline-block lg:py-0; } /* E-Waste link style */
         /* Removed animated underline and active state from CSS - handled by JS */
         #navbar { @apply w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-white lg:bg-transparent shadow-xl lg:shadow-none lg:border-none border-t border-gray-200 transition-all duration-500 ease-in-out; } /* E-Waste layout/transition */
         #navbar.open { @apply block max-h-screen; } /* Ensure it opens */

         /* Mobile menu toggle - Matching E-Waste */
         .menu-toggle { @apply text-gray-700 hover:text-primary transition-colors duration-200; }
         .menu-toggle span { @apply block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out; }
         .menu-toggle span:nth-child(1) { @apply mb-1.5; }
         .menu-toggle span:nth-child(3) { @apply mt-1.5; }
         .menu-toggle.open span:nth-child(1) { @apply transform rotate-45 translate-y-[7px]; } /* Adjusted translate based on gap */
         .menu-toggle.open span:nth-child(2) { @apply opacity-0; }
         .menu-toggle.open span:nth-child(3) { @apply transform -rotate-45 -translate-y-[7px]; } /* Adjusted translate */


         /* Hero Section - Adapting E-Waste hero style */
         #hero {
             /* Use the animated gradient utility */
             @apply animated-gradient-primary text-white min-h-[calc(100vh-70px)] flex items-center py-20 relative overflow-hidden;
         }
         #hero .container { @apply relative z-10 flex flex-col-reverse lg:flex-row items-center justify-between gap-10 text-center lg:text-left; }
         .hero-text { @apply flex-1 order-2 lg:order-1 flex flex-col items-center lg:items-start justify-center text-center lg:text-left; } /* Basic layout */
         .hero-text h1 { @apply !text-white mb-4 drop-shadow-lg; } /* E-Waste hero h1 style */
         .hero-text p { @apply text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-8 drop-shadow; text-base md:text-lg; } /* E-Waste hero p style */
         .hero-buttons { @apply flex flex-wrap justify-center lg:justify-start gap-4; } /* E-Waste hero button layout */
         .hero-buttons .btn { @apply text-white; } /* Ensure text color on dark hero */
         .hero-logo { @apply order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto; }
         .hero-logo img { @apply mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-60 lg:h-60 rounded-full shadow-md bg-white/20 p-3; } /* Adapted logo style */
         .hero-scroll-indicator { @apply absolute bottom-8 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
         .hero-scroll-indicator a { @apply text-white/60 hover:text-white text-3xl animate-bounce; } /* Simple bounce for icon */


         /* Profile Section - Adapting E-Waste info section style */
         #profile { @apply section-padding bg-neutral-light; } /* E-Waste neutral background */
         #profile h2 { @apply section-title; } /* E-Waste title style */
         .profile-text h3 { @apply !text-primary-dark text-2xl mb-4 mt-6; } /* Primary-dark heading color */
         .profile-text p { @apply text-neutral text-base md:text-lg; } /* Neutral text color */
         .profile-image img { @apply rounded-lg shadow-md mx-auto w-full object-cover h-full max-h-[500px] border-2 border-gray-300; } /* E-Waste image border/shadow */

         /* Objectives Section - Adapting E-Waste section/item style */
         #objectives { @apply section-padding bg-white; } /* White background */
         #objectives h2 { @apply section-title; } /* E-Waste title style */
         .objective-item { @apply bg-primary/5 p-4 rounded-lg shadow-sm border-l-4 border-primary flex items-start space-x-4 transition duration-300 ease-in-out hover:shadow-md hover:border-accent; } /* Card-like item */
         .objective-item i { @apply text-primary group-hover:text-accent transition-colors duration-300 flex-shrink-0 w-6 text-center text-xl; } /* Icon styling */
         .objective-item p { @apply text-sm text-neutral leading-relaxed; } /* Text style */
         .objective-item p strong { @apply font-semibold text-primary-dark; } /* Strong text style */


         /* Areas of Focus Section - Adapting E-Waste card grid style */
         #areas-focus { @apply section-padding bg-neutral-light; } /* E-Waste neutral background */
         #areas-focus h2 { @apply section-title; } /* E-Waste title style */
         .focus-item { @apply card text-center flex flex-col items-center border-t-4 border-primary hover:border-accent transition-colors; } /* E-Waste card style */
         .focus-item .icon { @apply text-5xl text-accent mb-4 inline-block; } /* Accent color for icons */
         .focus-item h3 { @apply text-xl text-primary-dark mb-2; } /* Primary-dark heading */
         .focus-item p { @apply text-sm text-neutral leading-relaxed flex-grow mb-4 text-center; } /* Neutral text */
         .focus-item .read-more-link { @apply relative block text-sm font-semibold text-primary mt-auto pt-2 border-t border-gray-200; } /* Primary link, border top */
         .focus-item .read-more-link::after { content: '\f061'; font-family: 'Font Awesome 6 Free'; @apply font-black text-xs ml-1.5 inline-block;} /* Arrow icon */


         /* How to Join / Get Involved Section - Adapting E-Waste animated gradient style */
          #volunteer-section { @apply section-padding animated-gradient-secondary text-white relative overflow-hidden; } /* Use accent gradient utility */
          #volunteer-section h2 { @apply section-title !text-white; } /* White title on dark background */
          #volunteer-section h2::after { @apply !bg-accent; } /* Accent underline */
          #volunteer-section h3 { @apply text-white text-2xl md:text-3xl font-bold mb-4; } /* White heading */
          #volunteer-section p { @apply text-gray-200 text-base md:text-lg mb-6; } /* Light gray text */
          #volunteer-section .form-section { @apply bg-white/20 backdrop-blur-sm p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-primary mt-8; } /* Semi-transparent form background */
          #volunteer-section .form-section label { @apply !text-white; } /* White labels */
          #volunteer-section .form-section input[type="text"], #volunteer-section .form-section input[type="email"], #volunteer-section .form-section input[type="tel"],
          #volunteer-section .form-section select, #volunteer-section .form-section textarea {
               @apply !bg-white/10 !border-gray-300/50 !text-white placeholder:!text-gray-300/80 focus:!border-white focus:!ring-white/50;
          } /* Inverted form inputs */
          #volunteer-section .form-section .form-input-error { @apply !border-danger !ring-danger/50 focus:!border-danger focus:!ring-danger/50; } /* Error color for inverted inputs */
          #volunteer-section .form-section .text-red-600 { @apply !text-danger; } /* Ensure error text is red */
          #volunteer-section .form-section p.text-xs { @apply text-gray-200; } /* Light hint text */
          #volunteer-section .form-section button { @apply btn btn-accent; } /* Accent button for submit */


         /* News & Events Section - Adapting E-Waste section/card style */
         #news-section { @apply section-padding bg-white; } /* White background */
         #news-section h2 { @apply section-title; } /* E-Waste title style */
         #news-section .news-card { @apply card flex flex-col; } /* E-Waste card style */
         #news-section .news-card img { @apply rounded-t-lg; } /* Ensure img corners match card */
         #news-section .news-card .news-content { @apply p-4 flex flex-col flex-grow; } /* Reduced padding */
         #news-section .news-card .date { @apply block text-xs text-neutral mb-2; } /* Neutral text */
         #news-section .news-card .date i { @apply opacity-70; } /* Icon opacity */
         #news-section .news-card h4 { @apply text-lg font-semibold text-primary-dark mb-2 leading-snug flex-grow; } /* Primary-dark heading */
         #news-section .news-card h4 a { @apply text-inherit hover:text-primary; } /* Inherit color, hover primary */
         #news-section .news-card p { @apply text-sm text-neutral mb-4 leading-relaxed; } /* Neutral text */
         #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-gray-200; } /* Border top */
         #news-section .news-card .read-more-action a { @apply btn-outline !text-sm !py-1 !px-3; } /* Outline button */


         /* Gallery Section - Adapting E-Waste section/item style */
         #gallery-section { @apply section-padding bg-neutral-light; } /* E-Waste neutral background */
         #gallery-section h2 { @apply section-title; } /* E-Waste title style */
         .gallery-item { @apply block aspect-video rounded-lg overflow-hidden shadow-md group transition-all duration-300 hover:shadow-xl hover:scale-105; } /* Card-like item */
         .gallery-item img { @apply w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-110; } /* Simple scale hover */
         #gallery-section p.text-neutral { @apply text-center mt-8 text-neutral italic text-sm; } /* Neutral italic text */


         /* Associates Section - Adapting E-Waste section/item style */
         #associates { @apply section-padding bg-white; } /* White background */
         #associates h2 { @apply section-title; } /* E-Waste title style */
         #associates p.text-neutral { @apply text-center max-w-3xl mx-auto text-lg text-neutral mb-12; } /* Neutral text */
         .associate-logo { @apply text-center group transform transition duration-300 hover:scale-110; }
         .associate-logo img { @apply max-h-16 md:max-h-20 w-auto mx-auto mb-3 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100; } /* E-Waste logo style */
         .associate-logo p { @apply text-xs font-medium text-neutral group-hover:text-primary transition-colors; } /* Neutral text, primary hover */


         /* Donation CTA Section - Adapting E-Waste animated gradient style */
          #donate-section { @apply section-padding text-center relative overflow-hidden animated-gradient-primary; } /* Use primary gradient utility */
           #donate-section i.fas.fa-donate { @apply text-white bg-accent p-4 rounded-full shadow-md mb-6 inline-block; } /* Accent background icon */
          #donate-section h2 { @apply section-title !text-white; } /* White title on dark background */
           #donate-section h2::after { @apply !bg-white; } /* White underline */
           #donate-section p { @apply text-gray-200 text-base md:text-lg mb-6; } /* Light gray text */
           #donate-section p.bg-black\/25 { @apply text-gray-200 bg-black/20 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-8 backdrop-blur-sm border border-white/20; } /* Light background text */
          #donate-section .space-y-4 > *, #donate-section .space-x-6 > * { @apply !bg-white !text-primary hover:!bg-gray-100 shadow-md; } /* White buttons, primary text */
           #donate-section .space-y-4 > *:last-child, #donate-section .space-x-6 > *:last-child { @apply btn-outline !border-white !text-white hover:!bg-white hover:!text-primary; } /* Last button is outline */


         /* Contact Section - Adapting E-Waste section/form style */
          #contact { @apply section-padding bg-neutral-light; } /* E-Waste neutral background */
          #contact h2 { @apply section-title; } /* E-Waste title style */
          #contact p.text-neutral { @apply text-center max-w-3xl mx-auto text-lg text-neutral mb-12; } /* Neutral text */
          #contact .contact-info { @apply space-y-6 text-neutral text-base mb-10; } /* Neutral text */
          .contact-info-item { @apply flex items-start gap-4; } /* Layout */
          .contact-info-item i { @apply text-primary text-lg mt-1 w-5 text-center flex-shrink-0; } /* Primary icon */
          .contact-info-item a { @apply text-primary hover:text-primary-dark underline; } /* Primary link */
          #contact h4 { @apply text-primary-dark text-xl font-semibold mb-4 mt-8; } /* Primary-dark heading */
          #contact iframe { @apply rounded-lg shadow-md border border-gray-300; } /* E-Waste border/shadow */
          #contact .registration-info { @apply bg-white p-4 rounded-md border border-gray-200 text-xs text-neutral mt-8 shadow-inner;} /* White info box */
          #contact .registration-info h4 { @apply text-primary-dark text-sm font-semibold mb-2 !mt-0; } /* Primary-dark heading */
          #contact .form-section { @apply bg-white p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-accent mt-0; } /* E-Waste form section */
          #contact .form-section h3 { @apply text-primary-dark text-2xl mb-6 text-center lg:text-left; } /* Primary-dark heading */
           #contact .form-section label { @apply text-gray-700; } /* Standard label color */
           #contact .form-section input, #contact .form-section textarea { @apply bg-white border-gray-300 text-neutral-dark; } /* Standard form inputs */
           #contact .form-section button { @apply btn btn-primary; } /* Primary button */


         /* Footer - Matching E-Waste style */
         footer { @apply bg-primary-dark text-gray-300 pt-12 pb-8 mt-12; } /* Matching E-Waste footer bg/padding */
         footer .container { @apply text-center px-4; } /* Centered text, add padding */
         footer .logo-col { @apply text-center md:text-left; } /* Alignment */
         footer .logo-col a { @apply inline-block mb-3; }
         footer .logo-col img { @apply w-14 h-14 rounded-full bg-white p-1 shadow-md mx-auto md:mx-0; } /* Logo image style */
         footer .logo-col p { @apply text-xs text-gray-400; } /* Text style */
         footer .footer-heading { @apply text-lg font-semibold text-white mb-4 relative pb-2; } /* Heading style */
         footer .footer-heading::after { @apply content-[''] absolute bottom-0 left-0 w-10 h-0.5 bg-accent rounded-full; } /* Accent underline */
         footer .footer-links { @apply list-none space-y-1.5 text-sm pl-0; } /* List style */
         footer .footer-links li a { @apply text-gray-300 hover:text-white hover:underline transition-colors flex items-center gap-1.5; } /* Link style */
         footer .footer-links li a i { @apply opacity-70 text-accent text-xs; } /* Icon style */
          footer address p { @apply mb-3 flex items-start gap-3; } /* Address item layout */
          footer address i { @apply text-accent mt-1 w-4 text-center flex-shrink-0; } /* Address icon color */
          footer address a { @apply text-gray-300 hover:text-white hover:underline; } /* Address link style */
          .footer-social-icon { @apply text-xl transition duration-300 text-gray-300 hover:scale-110; } /* Social icons */
         footer .social-icons-container { @apply mt-4 flex justify-center md:justify-start space-x-4; }
          footer .footer-quote blockquote { @apply !border-accent !bg-accent/10 !text-accent-dark !p-4 !my-0 !italic !rounded-md !rounded-l-none !border-l-4; } /* Quote style */
          footer .footer-quote blockquote cite { @apply !text-sm !text-accent-dark/80; }
          footer .footer-bottom { @apply border-t border-gray-700/50 pt-8 mt-12 text-center text-sm text-gray-500; } /* Bottom bar */
          footer .footer-bottom a { @apply hover:text-white hover:underline; }

    </style>
    <!-- Schema.org JSON-LD (Keep Existing) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org", "@type": "NGO", "name": "PAHAL NGO", /* ... rest of schema ... */
      "url": "https://your-pahal-domain.com/", "logo": "https://your-pahal-domain.com/icon.webp",
      "description": "PAHAL is a voluntary youth organization in Jalandhar dedicated to holistic personality development, community service, and fostering positive change in health, education, environment, and communication.",
      "address": {"@type": "PostalAddress", "streetAddress": "36 New Vivekanand Park, Maqsudan", "addressLocality": "Jalandhar", "addressRegion": "Punjab", "postalCode": "144008", "addressCountry": "IN" },
      "contactPoint": [ /* ... contact points ... */ ], "sameAs": [ /* ... social links ... */ ]
    }
    </script>
</head>
<!-- Body class removed - base styles handle background/font -->
<body class="pt-[70px]"> <!-- Explicitly add padding for fixed header -->

<!-- Header - Matching E-Waste style -->
<header id="main-header">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <div class="logo flex-shrink-0 py-2">
             <a href="#hero" aria-label="PAHAL NGO Home" class="text-3xl md:text-4xl font-black text-primary font-heading leading-none flex items-center transition-opacity hover:opacity-80">
                <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2 inline object-contain" aria-hidden="true"> <!-- Removed pulse animation from icon -->
                PAHAL
             </a>
             <p class="text-xs text-neutral italic ml-10 -mt-1.5 hidden sm:block">An Endeavour for a Better Tomorrow</p> <!-- Adjusted ml -->
        </div>
        <!-- Mobile menu toggle - Matching E-Waste structure (but need to implement JS) -->
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus:ring-2 focus:ring-primary rounded">
            <span class="sr-only">Open menu</span> <span></span> <span></span> <span></span>
        </button>
        <!-- Navigation - Matching E-Waste structure -->
        <nav id="navbar" aria-label="Main Navigation" class="navbar-container"> <!-- Use class for JS targeting -->
            <ul>
                <li><a href="#hero" class="nav-link">Home</a></li>
                <li><a href="#profile" class="nav-link">Profile</a></li>
                <li><a href="#objectives" class="nav-link">Objectives</a></li>
                <li><a href="#areas-focus" class="nav-link">Focus Areas</a></li>
                <li><a href="#news-section" class="nav-link">News</a></li>
                <li><a href="#volunteer-section" class="nav-link">Get Involved</a></li>
                <li><a href="blood-donation.php" class="nav-link">Blood Drive</a></li>
                <li><a href="e-waste.php" class="nav-link">E-Waste</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                <!-- Removed theme toggle from header HTML -->
            </ul>
        </nav>
    </div>
</header>

<main>
    <!-- Hero Section - Adapting E-Waste hero style -->
    <section id="hero" class="relative animated-gradient-primary">
        <!-- Background overlay removed -->
        <div class="container mx-auto relative z-10 flex flex-col-reverse lg:flex-row items-center justify-between gap-10 text-center lg:text-left">
             <div class="hero-text flex-1 order-2 lg:order-1 flex flex-col items-center lg:items-start justify-center text-center lg:text-left animate-on-scroll fade-in-scale animation-delay-100"> <!-- Use E-Waste animation -->
              <h1 class="font-heading !text-white"> <!-- Ensure white text -->
                 Empowering Communities,<br> Inspiring Change
              </h1>
              <p class="text-lg lg:text-xl my-4 max-w-xl mx-auto lg:mx-0 text-gray-200"> <!-- Reduced margin, light text -->
                Join PAHAL, a youth-driven NGO in Jalandhar, committed to holistic development and tangible social impact through dedicated action in health, education, environment, and communication.
              </p>
              <div class="hero-buttons mt-6 flex flex-wrap justify-center lg:justify-start gap-4 animate-on-scroll fade-in-scale animation-delay-200"> <!-- Use E-Waste animation -->
                <!-- Button classes using E-Waste definitions -->
                <a href="#profile" class="btn btn-secondary"><i class="fas fa-info-circle"></i>Discover More</a>
                 <a href="#volunteer-section" class="btn btn-primary"><i class="fas fa-hands-helping"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto animate-on-scroll fade-in-scale animation-delay-300"> <!-- Use E-Waste animation -->
                 <img src="icon.webp" alt="PAHAL NGO Large Logo Icon" class="mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-60 lg:h-60 rounded-full shadow-md bg-white/20 p-3"> <!-- Adapted logo style -->
            </div>
        </div>
        <div class="hero-scroll-indicator">
             <a href="#profile" aria-label="Scroll down"><i class="fas fa-chevron-down"></i></a>
        </div>
    </section>

    <!-- Profile Section - Adapting E-Waste info section style -->
    <section id="profile" class="section-padding bg-white"> <!-- White background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
             <h2 class="section-title">Our Profile & Vision</h2>
             <div class="grid md:grid-cols-5 gap-8 items-center mt-12"> <!-- Gap matching E-Waste -->
                 <div class="md:col-span-3 profile-text">
                    <h3>Who We Are</h3> <!-- H3 base style -->
                    <p class="text-neutral text-base md:text-lg">'PAHAL' (Initiative) stands as a testament to collective action... driven by a singular vision: to catalyze perceptible, positive transformation within our social fabric.</p> <!-- Neutral text -->
                    <blockquote><p>"PAHAL is an endeavour for a Better Tomorrow"</p></blockquote> <!-- Blockquote base style -->
                    <h3 class="mt-8">Our Core Vision</h3> <!-- H3 base style -->
                    <p class="text-neutral text-base md:text-lg">We aim to cultivate <strong class="text-primary-dark font-semibold">Holistic Personality Development</strong>... thereby building a more compassionate and equitable world.</p> <!-- Neutral text, primary-dark strong -->
                 </div>
                 <div class="md:col-span-2 profile-image animate-on-scroll slide-up animation-delay-200"> <!-- Use E-Waste animation -->
                    <img src="https://via.placeholder.com/500x600.png/059669/f9fafb?text=PAHAL+Vision" alt="PAHAL NGO team vision" class="rounded-lg shadow-md mx-auto w-full object-cover h-full max-h-[500px] border-2 border-gray-300"> <!-- E-Waste image style -->
                </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section - Adapting E-Waste section/item style -->
     <section id="objectives" class="section-padding bg-neutral-light"> <!-- E-Waste neutral background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
             <h2 class="section-title">Our Guiding Objectives</h2>
             <div class="max-w-6xl mx-auto grid md:grid-cols-2 lg:grid-cols-3 gap-6 mt-12"> <!-- Gap matching E-Waste -->
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-100"> <!-- Card-like item -->
                     <i class="fas fa-users"></i><p>To collaborate genuinely <strong>with and among the people</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-200">
                     <i class="fas fa-people-carry"></i><p>To engage in <strong>creative & constructive social action</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-300">
                     <i class="fas fa-lightbulb"></i><p>To enhance knowledge of <strong>self & community realities</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-400">
                     <i class="fas fa-seedling"></i><p>To apply scholarship for <strong>mitigating social problems</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-500">
                     <i class="fas fa-tools"></i><p>To gain and apply skills in <strong>humanity development</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll slide-up animation-delay-600">
                     <i class="fas fa-recycle"></i><p>To promote <strong>sustainable practices</strong> & awareness.</p>
                 </div>
            </div>
        </div>
    </section>

    <!-- Areas of Focus Section - Adapting E-Waste card grid style -->
    <section id="areas-focus" class="section-padding bg-white"> <!-- White background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
            <h2 class="section-title">Our Key Focus Areas</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-12"> <!-- Gap matching E-Waste -->
                 <!-- Health -->
                 <a href="blood-donation.php" title="Health Initiatives" class="focus-item group animate-on-scroll slide-up animation-delay-100 card-hover"> <!-- Card style -->
                     <span class="icon"><i class="fas fa-heart-pulse"></i></span> <h3>Health & Wellness</h3> <!-- H3 base style -->
                     <p>Prioritizing community well-being via awareness campaigns, blood drives, and promoting healthy lifestyles.</p> <!-- Neutral text -->
                     <span class="read-more-link">Blood Donation Program</span> <!-- Link style -->
                 </a>
                 <!-- Education -->
                 <div class="focus-item group animate-on-scroll slide-up animation-delay-200 card-hover"> <!-- Card style -->
                     <span class="icon"><i class="fas fa-user-graduate"></i></span> <h3>Education & Skilling</h3> <!-- H3 base style -->
                     <p>Empowering youth by fostering ethical foundations, essential life skills, and professional readiness.</p> <!-- Neutral text -->
                     <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span> <!-- Link style -->
                  </div>
                 <!-- Environment -->
                 <a href="e-waste.php" title="E-waste Recycling" class="focus-item group animate-on-scroll slide-up animation-delay-300 card-hover"> <!-- Card style -->
                      <span class="icon"><i class="fas fa-leaf"></i></span> <h3>Environment</h3> <!-- H3 base style -->
                      <p>Championing stewardship through plantation drives, waste management, and e-waste recycling.</p> <!-- Neutral text -->
                      <span class="read-more-link">E-Waste Program</span> <!-- Link style -->
                 </a>
                 <!-- Communication -->
                 <div class="focus-item group animate-on-scroll slide-up animation-delay-400 card-hover"> <!-- Card style -->
                     <span class="icon"><i class="fas fa-comments"></i></span> <h3>Communication Skills</h3> <!-- H3 base style -->
                     <p>Enhancing verbal, non-verbal, and presentation abilities in youth via interactive programs.</p> <!-- Neutral text -->
                      <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span> <!-- Link style -->
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section - Adapting E-Waste animated gradient style -->
     <section id="volunteer-section" class="section-padding animated-gradient-secondary text-white relative">
        <!-- Darkening Overlay not strictly needed with animated gradient background, but kept for visual effect -->
        <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div> <!-- Darkening Overlay -->
        <div class="container mx-auto relative z-10 grid lg:grid-cols-2 gap-12 items-center mt-0"> <!-- Added grid/gap -->
             <div class="text-center lg:text-left animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
                <h2 class="section-title !text-white">Join the PAHAL Movement</h2> <!-- White title -->
                 <h3>Make a Difference, Volunteer With Us</h3> <!-- H3 style adapted for white text -->
                <p class="text-gray-200 text-base md:text-lg mb-6">PAHAL welcomes passionate individuals... Your time, skills, and dedication are invaluable assets.</p> <!-- Light gray text -->
                <p class="text-gray-200 text-base md:text-lg mb-8">Volunteering offers a rewarding experience... Express your interest below!</p> <!-- Light gray text -->
                 <div class="mt-6 flex flex-wrap justify-center lg:justify-start gap-4">
                     <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary"><i class="fas fa-phone-alt"></i>Contact Directly</a> <!-- White outline, primary text on hover -->
                     <!-- Removed View Opportunities button -->
                 </div>
             </div>
             <!-- Volunteer Sign-up Form - Adapting E-Waste form section style -->
             <div class="form-section max-w-lg mx-auto lg:mx-0 animate-on-scroll slide-up animation-delay-200"> <!-- Use E-Waste form-section, animation -->
                 <h3 class="text-2xl mb-6 text-primary font-semibold text-center">Register Your Volunteer Interest</h3> <!-- Primary heading -->
                 <?= get_form_status_html('volunteer_form') ?>
                <form id="volunteer-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5">
                    <!-- Hidden Fields -->
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                    <input type="hidden" name="form_id" value="volunteer_form">
                    <div class="honeypot-field" aria-hidden="true">
                        <label for="website_url_volunteer">Keep This Blank</label>
                        <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                    </div>
                    <!-- Form Fields (using base styles + error classes) -->
                    <div>
                         <label for="volunteer_name" class="required">Full Name</label> <!-- Base label style -->
                        <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>> <!-- Base input + error class -->
                        <?= get_field_error_html('volunteer_form', 'volunteer_name') ?> <!-- Error helper -->
                    </div>
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                             <label for="volunteer_email">Email</label>
                             <input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>>
                             <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                         </div>
                        <div>
                             <label for="volunteer_phone">Phone</label>
                             <input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>>
                             <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                         </div>
                    </div>
                    <p class="text-xs text-gray-500 -mt-3" id="volunteer_contact_note">Provide Email or Phone.</p>
                    <div>
                         <label for="volunteer_area" class="required">Area of Interest</label>
                         <select id="volunteer_area" name="volunteer_area" required class="<?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>>
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
                         <label for="volunteer_availability" class="required">Availability</label>
                         <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>>
                         <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                     </div>
                    <div>
                         <label for="volunteer_message">Message (Optional)</label>
                         <textarea id="volunteer_message" name="volunteer_message" rows="3" class="<?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Your motivation or skills..." <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea>
                         <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                     </div>
                    <div class="pt-4 text-center"> <!-- E-Waste button alignment -->
                         <button type="submit" class="btn btn-accent w-full sm:w-auto" id="volunteer-submit-button"> <!-- Accent button -->
                             <span class="spinner hidden mr-2"></span>
                             <span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Submit Interest</span>
                         </button>
                     </div>
                </form>
             </div>
         </div>
     </section>


    <!-- News & Events Section - Adapting E-Waste section/card style -->
    <section id="news-section" class="section-padding bg-neutral-light"> <!-- Neutral background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
            <h2 class="section-title">Latest News & Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-12"> <!-- Gap matching E-Waste -->
                <?php if (!empty($news_items)): ?>
                    <?php foreach ($news_items as $index => $item): ?>
                    <div class="news-card group animate-on-scroll slide-up animation-delay-<?= ($index * 100) ?> card-hover"> <!-- Card style, animation -->
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="block aspect-[16/10] overflow-hidden rounded-t-lg" title="Read more"> <!-- Rounded top corners -->
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110"> <!-- Simple scale hover -->
                        </a>
                        <div class="news-content p-4"> <!-- Padding -->
                             <span class="date"><i class="far fa-calendar-alt mr-1 text-neutral-medium"></i><?= date('M j, Y', strtotime($item['date'])) ?></span> <!-- Neutral text, icon -->
                             <h4 class="my-2"><a href="<?= htmlspecialchars($item['link']) ?>" class="text-primary-dark hover:text-primary"><?= htmlspecialchars($item['title']) ?></a></h4> <!-- Primary-dark heading, primary hover -->
                             <p class="text-sm text-neutral mb-4 leading-relaxed"><?= htmlspecialchars($item['excerpt']) ?></p> <!-- Neutral text -->
                              <div class="read-more-action mt-auto pt-3 border-t border-gray-200"> <!-- Border top -->
                                   <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-outline !text-sm !py-1 !px-3">Read More <i class="fas fa-arrow-right text-xs ml-1"></i></a> <!-- Outline button -->
                              </div>
                         </div>
                    </div>
                    <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-neutral md:col-span-2 lg:col-span-3">No recent news.</p> <!-- Neutral text -->
                 <?php endif; ?>
            </div>
            <div class="text-center mt-10"> <!-- Margin top -->
                <a href="/news-archive.php" class="btn btn-secondary"><i class="far fa-newspaper"></i>View News Archive</a> <!-- Secondary button -->
            </div>
        </div>
    </section>

    <!-- Gallery Section - Adapting E-Waste section/item style -->
    <section id="gallery-section" class="section-padding bg-white"> <!-- White background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
            <h2 class="section-title">Glimpses of Our Work</h2>
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4 mt-12"> <!-- Gap matching E-Waste -->
                    <?php foreach ($gallery_images as $index => $image): ?>
                    <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block aspect-video rounded-lg overflow-hidden shadow-md group animate-on-scroll slide-up animation-delay-<?= ($index * 50) ?> transition-all duration-300 hover:shadow-xl hover:scale-105"> <!-- Item style, animation -->
                         <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-105"> <!-- Simple scale hover -->
                     </a>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-6 text-neutral italic text-sm">Click images to view larger.</p> <!-- Neutral italic text -->
            <?php else: ?>
                 <p class="text-center text-neutral">Gallery coming soon.</p> <!-- Neutral text -->
            <?php endif; ?>
        </div>
    </section>

    <!-- Associates Section - Adapting E-Waste section/item style -->
    <section id="associates" class="section-padding bg-neutral-light"> <!-- Neutral background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
            <h2 class="section-title">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-neutral mb-12">Collaboration amplifies impact. We value the support of these esteemed organizations.</p> <!-- Neutral text -->
             <div class="flex flex-wrap justify-center items-center gap-x-10 md:gap-x-16 gap-y-10"> <!-- Gap matching E-Waste -->
                <?php foreach ($associates as $index => $associate): ?>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll slide-up animation-delay-<?= ($index * 50) ?>"> <!-- Item style, animation -->
                    <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-3 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100"> <!-- E-Waste logo style -->
                    <p class="text-xs font-medium text-neutral group-hover:text-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p> <!-- Neutral text, primary hover -->
                 </div>
                 <?php endforeach; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section - Adapting E-Waste animated gradient style -->
     <section id="donate-section" class="section-padding text-center relative overflow-hidden animated-gradient-primary">
         <!-- Darkening Overlay not strictly needed with animated gradient background, but kept for visual effect -->
         <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div>
         <div class="container mx-auto relative z-10">
             <i class="fas fa-donate text-4xl text-white bg-accent p-4 rounded-full shadow-md mb-6 inline-block animate-pulse-glow"></i> <!-- Accent background icon, E-Waste animation -->
             <h2 class="section-title !text-white">Support Our Initiatives</h2> <!-- White title -->
            <p class="text-gray-200 text-base md:text-lg mb-6">Your contribution fuels our mission in health, education, and environment within Jalandhar.</p> <!-- Light gray text -->
            <p class="text-gray-200 bg-black/20 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-8 backdrop-blur-sm border border-white/20">Donations Tax Exempt under Sec 80G.</p> <!-- Light background text -->
            <div class="mt-6 space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center items-center gap-4"> <!-- Gap matching E-Waste -->
                 <a href="#contact" class="btn btn-secondary"><i class="fas fa-info-circle"></i> Donation Inquiries</a> <!-- Secondary button -->
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary" data-modal-target="bank-details-modal"><i class="fas fa-university"></i>View Bank Details</button> <!-- White outline, primary text hover -->
            </div>
        </div>
     </section>

    <!-- Contact Section - Adapting E-Waste section/form style -->
     <section id="contact" class="section-padding bg-white"> <!-- White background -->
        <div class="container mx-auto animate-on-scroll slide-up"> <!-- Use E-Waste animation -->
             <h2 class="section-title">Connect With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-neutral mb-12">Questions, suggestions, partnerships, or just want to learn more? We're here to connect.</p> <!-- Neutral text -->
             <div class="grid lg:grid-cols-5 gap-8 lg:gap-12 items-start"> <!-- Gap matching E-Waste -->
                 <!-- Contact Details -->
                 <div class="lg:col-span-2 animate-on-scroll slide-up animation-delay-100"> <!-- Use E-Waste animation -->
                     <h3>Contact Information</h3> <!-- H3 base style -->
                     <address class="contact-info space-y-6 text-neutral text-base mb-10"> <!-- Neutral text -->
                        <div class="contact-info-item"><i class="fas fa-map-marker-alt"></i><div><span>Our Office:</span> 36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008</div></div>
                        <div class="contact-info-item"><i class="fas fa-phone-alt"></i><div><span>Phone Lines:</span> <a href="tel:+911812672784">181-267-2784</a><br><a href="tel:+919855614230">98556-14230</a></div></div>
                        <div class="contact-info-item"><i class="fas fa-envelope"></i><div><span>Email Us:</span> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></div></div>
                     </address>
                    <div class="mb-8 pt-6 border-t border-gray-200"> <!-- Border top -->
                        <h4>Follow Our Journey</h4> <!-- H4 base style -->
                        <div class="flex justify-center lg:justify-start space-x-5 social-icons mt-4">
                            <!-- Social Icons HTML here -->
                            <!-- Example: <a href="#" class="text-gray-600 hover:text-primary text-xl transition-colors"><i class="fab fa-facebook-f"></i></a> -->
                        </div>
                    </div>
                     <div class="mb-8 pt-6 border-t border-gray-200"> <!-- Border top -->
                         <h4>Visit Us</h4> <!-- H4 base style -->
                         <!-- Placeholder Map -->
                         <div id="contact-map" class="h-[300px] w-full rounded-lg shadow-md border border-gray-300 mt-4 z-0">
                            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3410.489041988969!2d75.59636197570526!3d31.339703354305917!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5a4b351b651b%3A0x98ac38930b192e0f!2sPahal%20NGO!5e0!3m2!1sen!2sin!4v1700000000000!5m2!1sen!2sin" width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" class="rounded-lg shadow-md border border-gray-300"></iframe>
                         </div>
                     </div>
                     <div class="registration-info bg-neutral-light p-4 rounded-md border border-gray-200 text-xs text-neutral mt-8 shadow-inner"> <!-- Info box style -->
                         <h4 class="text-primary-dark text-sm font-semibold mb-2 !mt-0">Registration</h4> <!-- Primary-dark heading -->
                         <p>Registered under: Registrar of Firms & Societies, Punjab</p>
                         <p>Reg No: 737</p>
                         <p>Tax Exemptions: 80G & 12A Certified</p>
                     </div>
                 </div>
                <!-- Contact Form - Adapting E-Waste form section style -->
                <div class="lg:col-span-3 form-section max-w-xl mx-auto lg:mx-0 animate-on-scroll slide-up animation-delay-200"> <!-- Use E-Waste form section, animation -->
                    <h3 class="text-2xl mb-6 text-primary font-semibold text-center lg:text-left">Send Us a Message</h3> <!-- Primary heading -->
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <!-- Hidden fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                        <div class="honeypot-field" aria-hidden="true">
                             <label for="website_url_main">Keep This Blank</label>
                             <input type="text" id="website_url_main" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                        </div>
                        <!-- Form Fields -->
                        <div>
                             <label for="contact_name" class="required">Name</label>
                             <input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="<?= get_field_error_class('contact_form', 'name') ?>" placeholder="e.g., Jane Doe" aria-required="true" <?= get_aria_describedby('contact_form', 'name') ?>>
                             <?= get_field_error_html('contact_form', 'name') ?>
                         </div>
                        <div>
                             <label for="contact_email" class="required">Email</label>
                             <input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="<?= get_field_error_class('contact_form', 'email') ?>" placeholder="e.g., jane.doe@example.com" aria-required="true" <?= get_aria_describedby('contact_form', 'email') ?>>
                             <?= get_field_error_html('contact_form', 'email') ?>
                         </div>
                        <div>
                             <label for="contact_message" class="required">Message</label>
                             <textarea id="contact_message" name="message" rows="5" required class="<?= get_field_error_class('contact_form', 'message') ?>" placeholder="Your thoughts..." aria-required="true" <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea>
                             <?= get_field_error_html('contact_form', 'message') ?>
                         </div>
                        <div class="pt-4 text-center lg:text-left"> <!-- Button alignment -->
                             <button type="submit" class="btn btn-primary w-full sm:w-auto" id="contact-submit-button"> <!-- Primary button -->
                                 <span class="spinner hidden mr-2"></span>
                                 <span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Send Message</span>
                             </button>
                         </div>
                    </form>
                 </div>
            </div>
        </div>
    </section>

    <!-- Donation Modal - Adapting E-Waste modal style -->
     <div id="bank-details-modal" class="modal-container" aria-labelledby="modal-title" role="dialog" aria-modal="true">
      <div class="modal-box">
         <button type="button" class="close-button" aria-label="Close modal" data-modal-close="bank-details-modal"><i class="fas fa-times fa-lg"></i></button>
         <h3 id="modal-title">Bank Transfer Details</h3> <!-- Primary modal title -->
        <p class="text-neutral text-base mb-4">Use the following details for direct bank transfers. Mention "Donation" in the description.</p> <!-- Neutral text -->
         <div class="modal-content-box"> <!-- Info box style -->
            <p><strong>Account Name:</strong> PAHAL (Regd.)</p> <!-- Primary-dark strong text -->
            <p><strong>Account Number:</strong> <?= htmlspecialchars($bank_details['account_number']) ?></p> <!-- REPLACE -->
             <p><strong>Bank Name:</strong> <?= htmlspecialchars($bank_details['bank_name']) ?></p> <!-- REPLACE -->
             <p><strong>Branch:</strong> <?= htmlspecialchars($bank_details['branch']) ?></p> <!-- REPLACE -->
             <p><strong>IFSC Code:</strong> <?= htmlspecialchars($bank_details['ifsc_code']) ?></p> <!-- REPLACE -->
        </div>
        <p class="modal-footer-note">For queries or receipts, contact us. Thank you!</p> <!-- Neutral italic text -->
      </div>
    </div>

</main>

<!-- Footer - Matching E-Waste style -->
<footer class="bg-primary-dark text-gray-300 pt-12 pb-8 mt-12">
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-8 text-center md:text-left"> <!-- Gap matching E-Waste -->
            <!-- Footer About -->
            <div class="logo-col"> <!-- Alignment helper -->
                <h4 class="footer-heading">About PAHAL</h4> <!-- Heading style -->
                <a href="#hero" class="inline-block mb-3"><img src="icon.webp" alt="PAHAL Icon" class="w-14 h-14 rounded-full bg-white p-1 shadow-md mx-auto md:mx-0"></a> <!-- Logo image style -->
                <p class="footer-text">Jalandhar NGO fostering holistic growth & community service.</p> <!-- Text style -->
                <p class="text-xs text-gray-400">Reg No: 737 | 80G & 12A</p>
                 <div class="social-icons-container mt-4 flex justify-center md:justify-start space-x-4"> <!-- Social icons container -->
                     <a href="https://www.instagram.com/pahal.ngo/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram" class="footer-social-icon hover:text-[#E1306C]"><i class="fab fa-instagram"></i></a>
                     <a href="https://www.facebook.com/pahal.ngo/" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook" class="footer-social-icon hover:text-[#1877F2]"><i class="fab fa-facebook-f"></i></a>
                     <a href="https://twitter.com/pahal_ngo" target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter" class="footer-social-icon hover:text-[#1DA1F2]"><i class="fab fa-twitter"></i></a>
                     <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn" class="footer-social-icon hover:text-[#0A66C2]"><i class="fab fa-linkedin-in"></i></a>
                 </div>
            </div>
             <!-- Footer Quick Links -->
             <div>
                 <h4 class="footer-heading">Explore</h4> <!-- Heading style -->
                 <ul class="footer-links space-y-1.5 text-sm pl-0 columns-2 md:columns-1"> <!-- List style -->
                     <li><a href="#profile"><i class="fas fa-chevron-right"></i>Profile</a></li> <!-- Link style -->
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
                 <h4 class="footer-heading">Reach Us</h4> <!-- Heading style -->
                 <address class="text-gray-300 text-sm"> <!-- Address text style -->
                     <p><i class="fas fa-map-marker-alt"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008</p> <!-- Item style -->
                     <p><i class="fas fa-phone-alt"></i> <a href="tel:+911812672784">181-267-2784</a></p> <!-- Link style -->
                     <p><i class="fas fa-mobile-alt"></i> <a href="tel:+919855614230">98556-14230</a></p>
                     <p><i class="fas fa-envelope"></i> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></p>
                 </address>
             </div>
             <!-- Footer Inspiration -->
             <div class="footer-quote"> <!-- Quote container -->
                  <h4 class="footer-heading">Inspiration</h4> <!-- Heading style -->
                 <blockquote>"The best way to find yourself is to lose yourself in the service of others."<cite>- Mahatma Gandhi</cite></blockquote> <!-- Quote style -->
             </div>
        </div>
        <!-- Footer Bottom -->
        <div class="footer-bottom"><p>© <?= $current_year ?> PAHAL (Regd.), Jalandhar. All Rights Reserved.</p></div> <!-- Bottom bar -->
    </div>
</footer>

<!-- Back to Top Button - Matching E-Waste button concept -->
<button id="back-to-top" aria-label="Back to Top" title="Back to Top">
   <i class="fas fa-arrow-up"></i> <!-- Icon -->
</button>

<!-- Simple Lightbox JS (Keep if gallery used) -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        console.log("PAHAL Main Page JS Loaded (E-Waste UI)");

        // --- Theme Toggle --- (Removed from header HTML, but keeping JS logic for system preference/local storage)
        // The E-Waste page didn't have dark mode, but let's keep the JS logic for completeness,
        // although the styling will be for a single theme.
        const htmlElement = document.documentElement;
         // These icons aren't in the HTML header anymore, but the JS tries to find them.
         // We can remove this if we completely remove the theme toggle button.
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
         const themeToggleBtn = document.getElementById('theme-toggle'); // This button is removed in HTML

         const applyTheme = (theme) => {
             // Since we only have one theme style, this function effectively does nothing visible
             // unless you uncomment the dark mode styles in the CSS.
             // We keep the logic for localStorage/prefers-color-scheme,
             // but remove class toggling.
             if (theme === 'dark') {
                // htmlElement.classList.add('dark'); // Removed
                 darkIcon?.classList.remove('hidden');
                 lightIcon?.classList.add('hidden');
             } else {
                // htmlElement.classList.remove('dark'); // Removed
                lightIcon?.classList.remove('hidden');
                darkIcon?.classList.add('hidden');
             }
             // Only save if user explicitly chose (no button, so this branch won't be hit)
             // if (localStorage.getItem('theme_explicitly_set')) { localStorage.setItem('theme', theme); }
         };
         // Apply initial theme logic (will default to light visually due to CSS)
         const storedTheme = localStorage.getItem('theme');
         const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
         const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
         applyTheme(initialTheme);

         // Removed themeToggleBtn listener as the button is removed.


        // --- Header & Layout ---
        const header = document.getElementById('main-header');
        let headerHeight = header?.offsetHeight ?? 70; // Get header height or default

        let scrollTimeout;
        const updateLayout = () => {
             // Update header height if it changes (unlikely with fixed height)
             headerHeight = header?.offsetHeight ?? 70;
             // Toggle header shadow on scroll (optional, but part of v3.5)
             if (window.scrollY > headerHeight) {
                 header?.classList.add('scrolled'); // Need to define '.scrolled' style in CSS
             } else {
                 header?.classList.remove('scrolled');
             }
             // Toggle back-to-top button visibility
             if (window.scrollY > window.innerHeight / 2) { // Show after scrolling half viewport
                 backToTopButton?.classList.add('visible');
             } else {
                 backToTopButton?.classList.remove('visible');
             }
             // Recalculate header height for smooth scroll offset if it's dynamic
             // (Not dynamic in E-Waste style, so this might be redundant but safe)
        };
        // Run on load and resize/scroll
        updateLayout();
        window.addEventListener('resize', updateLayout);
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(updateLayout, 50);
        }, { passive: true });


        // --- Mobile Menu --- (Keeping logic, adapting classes)
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const navbar = document.getElementById('navbar'); // The <nav> element
        let isMobileMenuOpen = false;

        const toggleMobileMenu = (forceClose = false) => {
             if (forceClose && !isMobileMenuOpen) return;
             isMobileMenuOpen = !isMobileMenuOpen || forceClose;

            // Use E-Waste classes
             menuToggle?.classList.toggle('open', isMobileMenuOpen);
             navbar?.classList.toggle('open', isMobileMenuOpen);

             // Accessibility attribute
             menuToggle?.setAttribute('aria-expanded', isMobileMenuOpen);

             // Prevent scrolling when menu is open (optional but good UX)
             // document.body.style.overflow = isMobileMenuOpen ? 'hidden' : ''; // This can interfere with smooth scroll
        };

        menuToggle?.addEventListener('click', () => toggleMobileMenu());

        // Close mobile menu when clicking a link inside it
        navbar?.querySelectorAll('a[href^="#"]').forEach(link => {
             link.addEventListener('click', () => {
                // Add a small delay before closing to allow click event to process (e.g., smooth scroll init)
                 setTimeout(() => { toggleMobileMenu(true); }, 150);
             });
        });


        // --- Active Navigation Link Highlighting --- (Keeping logic, adapting classes)
        const sections = document.querySelectorAll('main section[id]');
        const navLinks = document.querySelectorAll('#navbar a.nav-link'); // Select all nav links

        const setActiveLink = () => {
             const currentScroll = window.scrollY;
             sections.forEach(section => {
                 // Adjusted offset to account for fixed header and a little buffer
                 const sectionTop = section.offsetTop - headerHeight - 30; // Add buffer
                 const sectionBottom = sectionTop + section.offsetHeight;
                 const sectionId = section.getAttribute('id');

                 if (currentScroll >= sectionTop && currentScroll < sectionBottom) {
                     // Remove 'active' from all links
                     navLinks.forEach(link => link.classList.remove('active'));
                     // Add 'active' to the link corresponding to the current section
                     const activeLink = document.querySelector(`#navbar a[href="#${sectionId}"]`);
                     activeLink?.classList.add('active');
                 }
             });

            // Handle highlighting for links not targeting sections (like Blood Drive, E-Waste)
            // This requires checking the current page URL if on a sub-page.
            // For the main index page, we only highlight the Home link if at the very top.
             if (window.scrollY < sections[0].offsetTop - headerHeight - 30) { // Before the first section
                navLinks.forEach(link => link.classList.remove('active'));
                const homeLink = document.querySelector('#navbar a[href="#hero"]');
                 homeLink?.classList.add('active');
             }

             // Specific highlight for sub-pages if needed (e.g. blood-donation.php should highlight the Blood Drive link)
             // This logic would be added to the blood-donation.php and e-waste.php scripts, not the index.php script.
             // On index.php, we ensure links to sub-pages are *not* marked active based on scroll.
         };

        // Trigger on load and scroll
        let activeLinkTimeout;
        window.addEventListener('scroll', () => {
             clearTimeout(activeLinkTimeout);
             activeLinkTimeout = setTimeout(setActiveLink, 100); // Adjust delay if needed
         }, { passive: true });
        setActiveLink(); // Run on page load


        // --- Smooth Scroll with Header Offset --- (Keeping logic)
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
             anchor.addEventListener('click', function (e) {
                 const hash = this.getAttribute('href');
                 // Only handle internal links
                 if (hash.length > 1 && hash !== '#') { // Check if it's not just "#"
                     e.preventDefault();
                     const targetElement = document.querySelector(hash);
                     if (targetElement) {
                         // Recalculate header height just before scrolling
                         const currentHeaderHeight = header?.offsetHeight ?? 70;
                         const elementPosition = targetElement.getBoundingClientRect().top;
                         const offsetPosition = elementPosition + window.pageYOffset - currentHeaderHeight - 20; // Add a little extra margin

                         window.scrollTo({ top: offsetPosition, behavior: 'smooth' });

                         // Update URL hash without triggering another scroll event
                         history.pushState(null, null, hash);

                         // Close mobile menu if open
                         if (isMobileMenuOpen) { toggleMobileMenu(true); }
                     }
                 } else if (hash === '#') {
                     // Handle simple # link (usually goes to top)
                     e.preventDefault();
                      window.scrollTo({ top: 0, behavior: 'smooth' });
                      history.pushState(null, null, window.location.pathname + window.location.search); // Remove hash
                      // Close mobile menu if open
                     if (isMobileMenuOpen) { toggleMobileMenu(true); }
                 }
             });
        });

        // --- Back to Top Button --- (Keeping logic)
        const backToTopButton = document.getElementById('back-to-top');
        // Visibility logic is in updateLayout() function triggered by scroll


        // --- Form Submission Spinner --- (Keeping logic, adapting classes)
         document.querySelectorAll('form[id$="-form-tag"]').forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             // Ensure spinner exists before trying to select it
             const spinner = submitButton?.querySelector('.spinner');
             const buttonTextSpan = submitButton?.querySelector('.button-text'); // Ensure text is wrapped in this span

             if (submitButton) { // Only add listener if submit button exists
                 form.addEventListener('submit', (e) => {
                      // Basic HTML5 validity check before showing spinner
                     if (form.checkValidity()) {
                         submitButton.disabled = true;
                          // Use E-Waste spinner classes and hide text
                          spinner?.classList.remove('hidden');
                          spinner?.classList.add('inline-block'); // Make sure it displays
                          buttonTextSpan?.classList.add('invisible'); // Use invisible for layout stability
                     } else {
                          // If HTML5 validation fails, the browser will show default messages,
                          // and the form won't submit. The button stays enabled.
                          console.log("Client-side validation failed, submission prevented.");
                          // Optionally, you could add a small timeout here to briefly disable the button if needed
                          // setTimeout(() => { submitButton.disabled = false; }, 100);
                     }
                 });
                  // Note: The button state is reset on page reload after PHP redirect.
             }

             // --- Form Message Animation Trigger (CSS-driven primarily, JS handles visibility) ---
             // This JS is less crucial now with CSS animations, but can ensure the element is ready.
             // The get_form_status_html helper outputs the element with initial hidden/scaled state.
             // The CSS transition handles the animation when it becomes visible (which happens on page load after redirect).
             // No specific JS trigger needed for this E-Waste style.
             // Keeping the `data-form-message-id` attribute for reference if JS animation was *required*,
             // but the E-Waste style relies on CSS transitions + initial state.
         });


        // --- Gallery Lightbox --- (Keeping logic)
        try {
            if (typeof SimpleLightbox !== 'undefined') {
                const galleryElements = document.querySelectorAll('.gallery a');
                if (galleryElements.length > 0) {
                     new SimpleLightbox('.gallery a', {
                         captionDelay: 100, // E-Waste style has no specific delay mentioned, but simple-lightbox default might be slow
                         fadeSpeed: 250, // E-Waste style has no specific speed, reasonable default
                         animationSpeed: 250 // E-Waste style has no specific speed, reasonable default
                         // Use E-Waste style: no specific image/caption styling overrides needed,
                         // rely on SimpleLightbox defaults which are generally clean.
                     });
                 } else {
                     console.log("No gallery images found for Lightbox initialization.");
                 }
            } else {
                console.warn("SimpleLightbox library not found. Gallery images will not open in a lightbox.");
            }
        } catch(e) {
             console.error("Lightbox initialization failed:", e);
        }


        // --- Animation on Scroll --- (Keeping logic, adapting classes)
         // Need to ensure elements have the correct classes: `animate-on-scroll` and animation type (e.g., `fade-in-scale`, `slide-up`)
         const observerOptions = {
             root: null, // Use the viewport as the root
             rootMargin: '0px 0px -15% 0px', // Trigger when 15% of element is visible in viewport
             threshold: 0.01 // Trigger as soon as element enters viewport slightly
         };

         const intersectionCallback = (entries, observer) => {
             entries.forEach(entry => {
                 if (entry.isIntersecting) {
                     entry.target.classList.add('is-visible'); // Trigger animation
                     // observer.unobserve(entry.target); // Optional: Stop observing once animated
                 }
             });
         };

         // Only initialize if IntersectionObserver is supported
         if ('IntersectionObserver' in window) {
             const observer = new IntersectionObserver(intersectionCallback, observerOptions);
             // Observe all elements with the 'animate-on-scroll' class
             document.querySelectorAll('.animate-on-scroll').forEach(el => {
                 observer.observe(el);
             });
         } else {
             // Fallback for browsers that don't support IntersectionObserver
             // Just make all elements visible immediately
             document.querySelectorAll('.animate-on-scroll').forEach(el => {
                 el.classList.add('is-visible');
             });
             console.warn("IntersectionObserver not supported. AOS fallback applied.");
         }


        // --- Modal Handling --- (Keeping logic, adapting classes)
        const modalTriggers = document.querySelectorAll('[data-modal-target]');
        const modalClosers = document.querySelectorAll('[data-modal-close]');
        const modals = document.querySelectorAll('.modal-container'); // Use the new container class

        // Function to open a modal
        const openModal = (modalId) => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex'); // Use flex to make it visible and centered
                // Add class to body to prevent scrolling if needed
                 document.body.style.overflow = 'hidden';

                // Set initial state for animation (might be redundant with CSS transitions)
                 // modal.querySelector('.modal-box')?.classList.remove('scale-95', 'opacity-0');
                 // modal.querySelector('.modal-box')?.classList.add('scale-100', 'opacity-100');
            }
        };

        // Function to close a modal
        const closeModal = (modal) => {
            if (modal) {
                // Animate out first (if using JS animation)
                // modal.querySelector('.modal-box')?.classList.remove('scale-100', 'opacity-100');
                // modal.querySelector('.modal-box')?.classList.add('scale-95', 'opacity-0');

                 // Wait for animation to finish before hiding (adjust timeout to match CSS transition duration)
                 // setTimeout(() => {
                    modal.classList.remove('flex');
                    modal.classList.add('hidden'); // Hide the modal
                    document.body.style.overflow = ''; // Restore scrolling
                // }, 300); // Match CSS transition duration (e.g., 300ms)
            }
        };

        // Add click listeners to triggers
        modalTriggers.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal-target');
                if (modalId) {
                    openModal(modalId);
                }
            });
        });

        // Add click listeners to closers
        modalClosers.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal-close');
                 const modal = document.getElementById(modalId);
                if (modal) {
                    closeModal(modal);
                } else {
                    // Fallback: close the nearest modal container ancestor
                    const parentModal = button.closest('.modal-container');
                    if(parentModal) closeModal(parentModal);
                }
            });
        });

        // Close modal when clicking outside the modal box
        modals.forEach(modal => {
            modal.addEventListener('click', (event) => {
                // Check if the click target is the modal container itself, not a child inside the modal-box
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
        });

        // Close modal on Escape key press
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                // Find and close any currently open modals
                document.querySelectorAll('.modal-container.flex').forEach(modal => {
                    closeModal(modal);
                });
            }
        });


        console.log("PAHAL Main Page JS Initialized (E-Waste UI)");
    });
</script>

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
      "contactType": "General Inquiries"
    },
     {
      "@type": "ContactPoint",
      "telephone": "+919855614230",
      "contactType": "Mobile"
    },
    {
      "@type": "ContactPoint",
      "email": "engage@pahal-ngo.org",
      "contactType": "General Inquiries"
    }
    /* Add other specific contacts like blood, e-waste if applicable */
  ],
  "sameAs": [
    "https://www.facebook.com/pahal.ngo/", /* CHANGE */
    "https://twitter.com/pahal_ngo", /* CHANGE */
    "https://www.instagram.com/pahal.ngo/", /* CHANGE */
    "https://www.linkedin.com/company/pahal-ngo/" /* CHANGE */
    /* Add other social media links */
  ]
}
</script>


</body>
</html>
