<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact/Volunteer Forms
// Version: 4.1 (UI Refactored to match E-Waste/Blood Page Styling)
// Features: Tailwind UI (E-Waste/Blood Style), Responsive Design, Animations
// Backend: PHP mail(), CSRF, Honeypot, Logging (Functionality Preserved)
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration ---
// CHANGE THESE EMAIL ADDRESSES to the correct recipients for your NGO
define('RECIPIENT_EMAIL_CONTACT', "contact@your-pahal-domain.com"); // CHANGE ME
define('RECIPIENT_EMAIL_VOLUNTEER', "volunteer@your-pahal-domain.com"); // CHANGE ME

// CHANGE THESE POTENTIALLY to an email address associated with your domain for better deliverability
define('SENDER_EMAIL_DEFAULT', 'webmaster@your-pahal-domain.com'); // CHANGE ME (email mails appear FROM)
define('SENDER_NAME_DEFAULT', 'PAHAL NGO Website');                       // CHANGE ME (name mails appear FROM)

// --- Security Settings ---
define('CSRF_TOKEN_NAME', 'csrf_token'); // Matching other pages for consistency
define('HONEYPOT_FIELD_NAME', 'website_url'); // Keep unique for this form to distinguish POSTs
// Note: Original code used website_url for both forms,
// but the blood page used contact_preference_blood.
// For consistency WITH THE ORIGINAL index logic, keep one honeypot name,
// but for safety BETWEEN the two forms, it's better if honeypot names differ.
// The refactored blood page used two unique names (contact_preference_blood_donor, contact_preference_blood_req)
// Let's keep the original index name (website_url) but make it unique per form to be safe,
// matching the blood page's refined approach.
define('HONEYPOT_FIELD_NAME_CONTACT', 'website_url_contact');
define('HONEYPOT_FIELD_NAME_VOLUNTEER', 'website_url_volunteer');


// --- Logging ---
define('ENABLE_LOGGING', true); // Set to true to log submissions/errors
$baseDir = __DIR__; // Directory of the current script
// Ensure logs directory is writable and outside web root if possible, or protected by .htaccess
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log'); // Shared general form error log file
define('LOG_FILE_CONTACT', $baseDir . '/logs/contact_submissions.log'); // Path to contact form log file
define('LOG_FILE_VOLUNTEER', $baseDir . '/logs/volunteer_submissions.log'); // Path to volunteer form log file
// --- END CONFIG ---


// --- Helper Functions ---
// Ensure these match the versions in the refactored blood-donation.php
// (Including logic, class names used in HTML output functions)

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
                    if ($value !== null && is_string($value) && mb_strlen((string)$value, 'UTF-8') < $minLength) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must be at least {$minLength} characters.";
                    }
                    break;
                case 'maxLength':
                    $maxLength = (int)($params[0] ?? 255);
                    if ($value !== null && is_string($value) && mb_strlen((string)$value, 'UTF-8') > $maxLength) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must not exceed {$maxLength} characters.";
                    }
                    break;
                case 'alpha_space':
                     // Allow letters and spaces, using Unicode property \p{L} for any letter
                    if ($value !== null && is_string($value) && !preg_match('/^[\p{L}\s]+$/u', $value)) {
                        $isValid = false;
                        $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces.";
                    }
                    break;
                case 'phone':
                     // Basic phone number format validation (allows + international, spaces, hyphens, parentheses, extensions)
                    if ($value !== null && is_string($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) {
                        $isValid = false;
                        $errorMessage = "Please enter a valid phone number format.";
                    }
                    break;
                case 'date': // Note: Blood page uses date:Y-m-d, this is a generic placeholder
                     // Need to implement date validation based on specific format if needed, or use a simpler check
                     if ($value !== null) {
                         // Simple check: if the rule requires a specific format like 'date:Y-m-d',
                         // you'd need a more robust validation here or rely on input type="date"
                         // For this main page, we don't have date inputs in the forms provided,
                         // but keeping the case structure if inherited.
                         // If a date rule is used, copy the logic from blood-donation.php's date check.
                         log_message("Validation rule 'date' used for field '{$field}' but not fully implemented in validate_data helper.", LOG_FILE_ERROR);
                     }
                     break;
                 case 'integer': // Note: Blood page uses integer validation, this is a placeholder
                     // If used, copy the logic from blood-donation.php
                     if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be a whole number.";
                     }
                     break;
                 case 'min': // Note: Blood page uses min validation, this is a placeholder
                 case 'max': // Note: Blood page uses max validation, this is a placeholder
                     if ($value !== null && is_numeric($value)) {
                         $floatValue = (float)$value;
                         if ($rule === 'min' && $floatValue < (float)($params[0] ?? 0)) {
                              $isValid = false;
                              $errorMessage = "{$fieldNameFormatted} must be at least " . ($params[0] ?? 0) . ".";
                         } elseif ($rule === 'max' && $floatValue > (float)($params[0] ?? PHP_FLOAT_MAX)) {
                              $isValid = false;
                              $errorMessage = "{$fieldNameFormatted} must be no more than " . ($params[0] ?? PHP_FLOAT_MAX) . ".";
                         }
                     } elseif ($value !== null && !is_numeric($value)) {
                         $isValid = false;
                         $errorMessage = "{$fieldNameFormatted} must be a number.";
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
                     // Trim values for comparison
                     $currentValueTrimmed = is_string($value) ? trim($value) : ($value === null ? '' : (is_array($value) ? (empty($value) ? '' : 'not-empty') : (string)$value));
                     $otherValueTrimmed = is_string($data[$otherField] ?? null) ? trim($data[$otherField] ?? '') : (($data[$otherField] ?? null) === null ? '' : (is_array($data[$otherField] ?? null) ? (empty($data[$otherField] ?? null) ? '' : 'not-empty') : (string)($data[$otherField] ?? null)));

                     if ($otherField && $currentValueTrimmed === '' && $otherValueTrimmed === '') {
                         $isValid = false;
                         $errors[$field] = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_', ' ', $otherField)) . " is required.";
                         // No need to break here, the error is set, and the outer loop handles breaking.
                         // This error message logic is slightly different than setting $errorMessage and breaking inside the inner loop,
                         // but achieves the same for 'required_without'.
                     }
                     break;
                 case 'recaptcha': // Placeholder - actual reCAPTCHA v3 validation needs server-side API call
                     // For this refactor, we'll assume reCAPTCHA validation happens elsewhere or isn't critical
                     // if this rule was intended but not fully implemented.
                     // If you add reCAPTCHA, uncomment and complete the logic here.
                     // Example basic check (DO NOT rely on this for security):
                      if (in_array('required', $ruleList) && empty($value)) {
                          $isValid = false; $errors[$field] = "reCAPTCHA verification is required.";
                      }
                    log_message("Placeholder reCAPTCHA validation rule triggered for field '{$field}'.", LOG_FILE_ERROR);
                    break;
                default:
                    log_message("Unknown validation rule '{$rule}' for field '{$field}'.", LOG_FILE_ERROR);
                    break;
            }

            // If validation failed for this rule and no error message has been set for this field yet, set it and break from inner loop
            if (!$isValid && !isset($errors[$field])) {
                $errors[$field] = $errorMessage;
                 // Note: required_without sets error directly, so check $errors[$field] status.
                 if(isset($errors[$field])) break; // Move to the next field after the first validation error
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
 * @param string $logContext Prefix for logging messages (e.g., "[Contact Form]").
 * @param string $successLogFile Log file for successful submissions.
 * @param string $errorLogFile Log file for errors.
 * @return bool True on success, false on failure.
 */
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext, string $successLogFile, string $errorLogFile): bool {
    $senderName = SENDER_NAME_DEFAULT;
    $senderEmail = SENDER_EMAIL_DEFAULT;

    // Basic validation of recipient and sender emails
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_message("{$logContext} Email send failed: Invalid recipient email: {$to}", $errorLogFile);
        return false;
    }
     if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
         log_message("{$logContext} Email send failed: Invalid sender email in config: {$senderEmail}", $errorLogFile);
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
        log_message("{$logContext} Email successfully submitted via mail() to {$to}. Subject: {$subject}", $successLogFile);
        return true;
    } else {
        $errorInfo = error_get_last(); // Get the last error if mail() failed
        $errorMsg = "{$logContext} Native mail() Error sending to {$to}. Subject: {$subject}. Server Error: " . ($errorInfo['message'] ?? 'Unknown mail() error. Check server mail config/logs.');
        log_message($errorMsg, $errorLogFile);
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
    // Handle arrays/non-scalar values gracefully if they somehow end up here
     $value = $form_submissions[$formId][$fieldName] ?? $default;
     if (is_array($value) || is_object($value)) {
         log_message("Attempted to get non-scalar value for form '{$formId}', field '{$fieldName}' using get_form_value.", LOG_FILE_ERROR);
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
     // Use the utility class defined in E-Waste components layer for errors, and default border color
     // Check for the presence of errors for this specific field
     return isset($form_errors[$formId][$fieldName])
         ? 'form-input-error' // Class defined in E-Waste components layer
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
$page_title = "PAHAL NGO Jalandhar | Empowering Communities, Inspiring Change";
$page_description = "'PAHAL' is a leading volunteer-driven youth NGO in Jalandhar, Punjab, fostering holistic development through impactful initiatives in health, education, environment, and communication skills.";
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health camps, blood donation, education, environment, e-waste, communication skills, personality development, non-profit";

// --- Initialize Form State Variables ---
// Initialize keys for expected forms to avoid warnings before POST processing
$form_submissions = ['contact_form' => [], 'volunteer_form' => []];
$form_messages = ['contact_form' => [], 'volunteer_form' => []];
$form_errors = ['contact_form' => [], 'volunteer_form' => []];

$csrf_token = generate_csrf_token(); // Generate initial token or retrieve existing

// --- Dummy Data (Keep Existing) ---
// Replace with real data source later
$news_items = [
    ['date' => '2023-10-27', 'title' => 'Successful Blood Donation Camp', 'excerpt' => 'PAHAL organized a high-impact blood donation camp, contributing significantly to local blood banks. Community support was overwhelming...', 'link' => 'blood-donation.php', 'image' => 'https://images.unsplash.com/photo-1628348068343-c6a882fc36d4?auto=format&fit=crop&w=400&q=80'],
    ['date' => '2023-10-20', 'title' => 'E-Waste Collection Drive Announced', 'excerpt' => 'Join us on [Date - TBD] for our next e-waste collection drive to promote responsible electronic waste disposal...', 'link' => 'e-waste.php', 'image' => 'https://images.unsplash.com/photo-1591797045312-105fe9f03a47?auto=format&fit=crop&w=400&q=80'],
    ['date' => '2023-10-15', 'title' => 'Personality Development Workshop', 'excerpt' => 'A free workshop held for local youth focusing on communication skills, confidence building, and public speaking...', 'link' => '#', 'image' => 'https://images.unsplash.com/photo-1524758631624-cd28ec2da04e?auto=format&fit=crop&w=400&q=80'],
];
$gallery_images = [
    ['src' => 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=800&q=80', 'alt' => 'PAHAL Volunteers working with kids'],
    ['src' => 'https://images.unsplash.com/photo-1591083592626-6e3a6f51475c?auto=format&fit=crop&w=800&q=80', 'alt' => 'Blood donation camp in progress'],
    ['src' => 'https://images.unsplash.com/photo-1526304640581-d35d4846aa08?auto=format&fit=crop&w=800&q=80', 'alt' => 'Group photo of volunteers'],
    ['src' => 'https://images.unsplash.com/photo-1518057912440-d21745117353?auto=format&fit=crop&w=800&q=80', 'alt' => 'Environmental cleanup drive'],
    ['src' => 'https://images.unsplash.com/photo-1560523183-8703230037ca?auto=format&fit=crop&w=800&q=80', 'alt' => 'Communication skills workshop'],
    ['src' => 'https://images.unsplash.com/photo-1488521787994-d263e6e0fdae?auto=format&fit=crop&w=800&q=80', 'alt' => 'Health checkup camp'],
];
$associates = [
    ['name' => 'Local Hospital', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Hospital+Partner'],
    ['name' => 'Educational Institute', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Edu.+Partner'],
    ['name' => 'Environmental Agency', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Env.+Partner'],
    ['name' => 'Community Center', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Community+Partner'],
    ['name' => 'Corporate Sponsor', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Corporate'],
    ['name' => 'Media Partner', 'img' => 'https://via.placeholder.com/150x80/D1D5DB/4B5563?text=Media+Partner'],
];


// --- Form Processing Logic (POST Request) ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = sanitize_string($_POST['form_id'] ?? ''); // Sanitize form ID
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $logContext = "[Index Page POST]"; // Default context

    // --- Security Checks ---
    // CSRF validation must happen regardless of which form ID is provided
    if (!validate_csrf_token($submitted_token)) {
        log_message("{$logContext} Invalid CSRF token. Form ID: {$submitted_form_id}.", LOG_FILE_ERROR);
        // Set a general error message or specific form message
        // Use a default form ID if the submitted one is missing/invalid to ensure message is displayed
        $displayFormId = !empty($submitted_form_id) ? $submitted_form_id : 'general_error';
        // Use a generic error message that applies to any form or the page itself
        $_SESSION['form_messages'][$displayFormId] = ['type' => 'error', 'text' => 'Security token invalid or expired. Please refresh the page and try submitting the form again.'];
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']), true, 303); // Redirect back to page
        exit;
    }
     // Regenerate token *after* successful validation check for the *next* request
     // Note: validate_csrf_token already unset the used one.
     $csrf_token = generate_csrf_token();


    // --- Process Contact Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form';
        $logContext = "[Contact Form]";
        $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME_CONTACT]); // Use unique honeypot name for contact form

        if ($honeypot_filled) {
            log_message("{$logContext} Honeypot triggered. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            // Silently fail or act like success to avoid signaling bot detection
            $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => 'Thank you for your message!']; // Generic message
            // Clear submitted data
            $submitted_data = []; // Treat as if empty valid submission
        } else {
             // Sanitize Contact Form Data
             $contact_name = sanitize_string($_POST['name'] ?? '');
             $contact_email = sanitize_email($_POST['email'] ?? '');
             $contact_message = sanitize_string($_POST['message'] ?? '');
             // Add reCAPTCHA response if applicable
             // $contact_recaptcha_response = sanitize_string($_POST['g-recaptcha-response'] ?? '');

             // Store submitted data before validation for repopulation on error
             $submitted_data = [
                 'name' => $contact_name,
                 'email' => $contact_email,
                 'message' => $contact_message,
                 // 'g-recaptcha-response' => $contact_recaptcha_response,
             ];

             // Validation Rules for Contact Form
             $rules = [
                 'name' => 'required|alpha_space|minLength:2|maxLength:100',
                 'email' => 'required|email|maxLength:255',
                 'message' => 'required|minLength:10|maxLength:2000',
                 // Add reCAPTCHA rule here if implemented and required
                 // 'g-recaptcha-response' => 'required|recaptcha',
             ];

             $validation_errors = validate_data($submitted_data, $rules);
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

                 if (send_email($to, $subject, $body, $contact_email, $contact_name, $logContext, LOG_FILE_CONTACT, LOG_FILE_ERROR)) {
                     $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$contact_name}! Your message has been sent successfully. We will get back to you shortly."];
                     // Clear submitted data on success
                     unset($submitted_data); // This prevents it from being stored in session later
                 } else {
                     $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, there was an issue sending your message. Please try again later or contact us directly."];
                     // Error is logged within send_email() helper
                 }
             } else {
                 // Validation errors occurred
                 $errorCount = count($validation_errors);
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) below to send your message."];
                 log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
                 // Submitted data is kept automatically by the POST handling logic below
             }
         }
         $_SESSION['scroll_to'] = '#contact'; // Set scroll target

    } // --- End Contact Form Processing ---

    // --- Process Volunteer Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form';
        $logContext = "[Volunteer Form]";
        $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME_VOLUNTEER]); // Use unique honeypot name for volunteer form

        if ($honeypot_filled) {
             log_message("{$logContext} Honeypot triggered. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
             $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you for your interest!"]; // Generic message
            // Clear submitted data
            $submitted_data = []; // Treat as if empty valid submission
        } else {
            // Sanitize Volunteer Form Data
            $volunteer_name = sanitize_string($_POST['volunteer_name'] ?? '');
            $volunteer_email = sanitize_email($_POST['volunteer_email'] ?? '');
            $volunteer_phone = sanitize_string($_POST['volunteer_phone'] ?? '');
            $volunteer_area = sanitize_string($_POST['volunteer_area'] ?? '');
            $volunteer_availability = sanitize_string($_POST['volunteer_availability'] ?? '');
            $volunteer_message = sanitize_string($_POST['volunteer_message'] ?? '');
            // Add reCAPTCHA response if applicable
            // $volunteer_recaptcha_response = sanitize_string($_POST['g-recaptcha-response'] ?? '');


            // Store submitted data before validation for repopulation on error
            $submitted_data = [
                'volunteer_name' => $volunteer_name,
                'volunteer_email' => $volunteer_email,
                'volunteer_phone' => $volunteer_phone,
                'volunteer_area' => $volunteer_area,
                'volunteer_availability' => $volunteer_availability,
                'volunteer_message' => $volunteer_message,
                // 'g-recaptcha-response' => $volunteer_recaptcha_response,
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
                // Add reCAPTCHA rule here if implemented and required
                // 'g-recaptcha-response' => 'required|recaptcha',
            ];

            $validation_errors = validate_data($submitted_data, $rules);
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

                // Use the volunteer's email or name for Reply-To if available, otherwise default sender
                $replyToEmail = !empty($volunteer_email) ? $volunteer_email : ''; // Only use if valid email provided
                $replyToName = $volunteer_name;

                if (send_email($to, $subject, $body, $replyToEmail, $replyToName, $logContext, LOG_FILE_VOLUNTEER, LOG_FILE_ERROR)) {
                    $_SESSION['form_messages'][$form_id] = ['type' => 'success', 'text' => "Thank you, {$volunteer_name}! Your volunteer interest has been submitted. We will be in touch shortly."];
                    // Clear submitted data on success
                     unset($submitted_data); // This prevents it from being stored in session later
                } else {
                    $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Sorry, there was an issue submitting your volunteer interest. Please try again later or contact us directly."];
                    // Error is logged within send_email() helper
                }
            } else {
                // Validation errors occurred
                 $errorCount = count($validation_errors);
                 $_SESSION['form_messages'][$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) below to submit your interest."];
                 log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors), LOG_FILE_ERROR);
                 // Submitted data is kept automatically by the POST handling logic below
             }
        }
        $_SESSION['scroll_to'] = '#volunteer-section'; // Set scroll target

    } // --- End Volunteer Form Processing ---


     // --- Post-Processing & Redirect ---
     // Store form results in session (only errors and messages, submissions only if errors occurred)
     $_SESSION['form_messages'] = $form_messages;
     $_SESSION['form_errors'] = $form_errors;

     // Only store submissions if there were errors for the form that was processed
     if (isset($submitted_form_id) && !empty($form_errors[$submitted_form_id])) {
         $_SESSION['form_submissions'][$submitted_form_id] = $submitted_data ?? []; // Store submitted data if available
     } else {
         // If no errors for the submitted form, clear any old submissions for that form
         // Note: This clears data for *any* form if the most recent submission had no errors.
         // A more robust approach might clear *only* the successfully submitted form's data.
         // For simplicity matching the original index logic, we clear all if current was error-free.
         // However, let's adopt the blood page's logic: only store the data for the *current* form if *that* form had errors.
          if (isset($_SESSION['form_submissions'][$submitted_form_id])) {
              unset($_SESSION['form_submissions'][$submitted_form_id]);
          }
     }

     // Determine scroll target from session, then clear it
     $scrollTarget = $_SESSION['scroll_to'] ?? '';
     unset($_SESSION['scroll_to']);

     // Redirect using PRG pattern (HTTP 303 See Other is best practice for POST redirects)
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303);
     exit; // Terminate script after redirect

 } else {
     // --- GET Request: Retrieve session data after potential redirect ---
    // Retrieve state for all possible form IDs
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = ['contact_form' => [], 'volunteer_form' => []]; }
     if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = ['contact_form' => [], 'volunteer_form' => []]; }
     if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = ['contact_form' => [], 'volunteer_form' => []]; }

    $csrf_token = generate_csrf_token(); // Ensure token exists for GET request render
 }

// --- Prepare Form Data for HTML (using helper function) ---
// These variables are used to pre-fill form fields, typically after a validation error
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message');

$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area');
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');


// Define E-Waste page theme colors using PHP variables for use in Tailwind config
$primary_color = '#2E7D32'; // Green 800 (closer to E-Waste primary)
$primary_dark_color = '#1B5E20'; // Green 900
$accent_color = '#FFA000'; // Amber 500 (E-Waste accent)
$accent_dark_color = '#FF8F00'; // Amber 600
$secondary_color = '#F9FAFB'; // Gray 50 (E-Waste background)
$neutral_dark_color = '#374151'; // Gray 700
$neutral_medium_color = '#6B7280'; // Gray 500
$red_color = '#DC2626'; // Red 600 for errors/dangers
$green_color_success = '#16a34a'; // Green 600 (used for checkmarks, success indicators)
$blue_color_info = '#3B82F6'; // Blue 500 (used for info boxes)


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
    <meta property="og:url" content="https://your-pahal-domain.com/"/> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-og-enhanced.jpg"/> <!-- CHANGE/CREATE -->
    <meta property="og:site_name" content="PAHAL NGO Jalandhar">
    <!-- OG image dimensions are good practice -->
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter Card - Update URLs and image -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image" content="https://your-pahal-domain.com/images/pahal-twitter-enhanced.jpg"> <!-- CHANGE/CREATE -->


    <!-- Favicon - Use the same as E-Waste -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
     <link rel="icon" type="image/svg+xml" href="/favicon.svg"> <!-- Optional SVG Favicon -->
     <link rel="apple-touch-icon" href="/apple-touch-icon.png"> <!-- Optional Apple Touch Icon -->
     <link rel="manifest" href="/site.webmanifest">


    <!-- Tailwind CSS CDN - Keep forms plugin as it's helpful, but style inputs via base layer -->
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <!-- Google Fonts (Lato & Open Sans) - Matching E-Waste page -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome - Matching E-Waste page version -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple Lightbox CSS (Needed for Gallery) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.css">

    <!-- Removed custom CSS variables, using Tailwind config colors directly -->


    <!-- Tailwind Config & Custom CSS -->
    <script>
        tailwind.config = {
          // Removed darkMode setting to match target blood page
          theme: {
            extend: {
              colors: {
                // Define E-Waste theme colors using PHP variables
                primary: '<?= $primary_color ?>', // Green 800
                'primary-dark': '<?= $primary_dark_color ?>', // Green 900
                accent: '<?= $accent_color ?>', // Amber 500
                'accent-dark': '<?= $accent_dark_color ?>', // Amber 600
                secondary: '<?= $secondary_color ?>', // Gray 50 (E-Waste background)
                neutral: { light: '#F3F4F6', DEFAULT: '<?= $neutral_medium_color ?>', dark: '<?= $neutral_dark_color ?>' }, // Gray shades
                 danger: '<?= $red_color ?>', 'danger-light': '#FECACA', // Red for errors/danger
                 info: '<?= $blue_color_info ?>', 'info-light': '#EFF6FF', // Blue for info (matching E-Waste)
                 success: '<?= $green_color_success ?>', 'success-light': '#D1FAE5', // Green for success (matching E-Waste)
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
                // Use E-Waste animations + keep relevant ones from original index if desired
                'fade-in-scale': 'fadeInScale 0.6s ease-out forwards',
                'slide-up': 'slideUp 0.5s ease-out forwards',
                'pulse-glow': 'pulseGlow 2s ease-in-out infinite', // From refactored blood page
                'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite', // From original index
                'bounce-subtle': 'bounceSubtle 2s infinite ease-in-out', // From original index
                'gradient-bg': 'gradientBg 15s ease infinite', // From original index
                'glow-pulse': 'glowPulse 2.5s infinite alternate ease-in-out', // From original index
                 'icon-bounce': 'iconBounce 0.6s ease-out', // From original index
                 // Removed form-message-in animation as simplified in blood page
              },
              keyframes: {
                 // Use E-Waste keyframes + keep relevant ones from original index
                 fadeInScale: { '0%': { opacity: 0, transform: 'scale(0.95)' }, '100%': { opacity: 1, transform: 'scale(1)' } },
                 slideUp: { '0%': { opacity: 0, transform: 'translateY(20px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                 pulseGlow: { '0%, 100%': { opacity: 1, boxShadow: '0 0 0 0 rgba(255, 160, 0, 0.7)' }, '50%': { opacity: 0.8, boxShadow: '0 0 10px 5px rgba(255, 160, 0, 0)' } }, // Amber glow
                 pulse: { '0%, 100%': { opacity: 1 }, '50%': { opacity: .5 } }, // Default pulse
                 bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                 gradientBg: { '0%': { backgroundPosition: '0% 50%' }, '50%': { backgroundPosition: '100% 50%' }, '100%': { backgroundPosition: '0% 50%' } },
                 glowPulse: { '0%': { boxShadow: '0 0 5px 0px rgba(255, 160, 0, 0.4)' }, '100%': { boxShadow: '0 0 20px 5px rgba(255, 160, 0, 0.4)' } }, // Use accent color for glow
                 iconBounce: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-3px)' } },
                 // Removed fadeIn, fadeInDown, fadeInUp, slideInLeft, slideInRight keyframes as covered by slideUp/fadeInScale or handled by JS Intersection Observer
              },
              boxShadow: {
                   // Use E-Waste shadow definition
                  'card': '0 5px 15px rgba(0, 0, 0, 0.07)',
                  'lg': '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)', // Default lg
                  'xl': '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)', // Default xl
                   '2xl': '0 25px 50px -12px rgba(0, 0, 0, 0.25)', // Default 2xl
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
            html { @apply scroll-smooth antialiased; } /* Kept antialiased from original index */
            body { @apply font-sans text-neutral-dark leading-relaxed bg-secondary pt-[70px]; } /* Added pt-[70px] for fixed header */
            h1, h2, h3, h4, h5, h6 { @apply font-heading text-primary-dark font-bold leading-tight mb-4 tracking-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-black; } /* Kept black weight for H1 */
            h2 { @apply text-3xl md:text-4xl text-primary-dark; }
            h3 { @apply text-2xl md:text-3xl text-primary; }
            h4 { @apply text-xl font-semibold text-neutral-dark mb-2; } /* Using neutral dark for h4 as in E-Waste */
            p { @apply mb-5 text-base md:text-lg text-neutral; }
            a { @apply text-primary hover:text-primary-dark transition duration-300; } /* Green links */
            a:not(.btn):not(.nav-link):not(.footer-link) { /* Specific default link style from original index */
                 @apply underline decoration-primary/50 hover:decoration-primary decoration-1 underline-offset-2;
            }
            hr { @apply border-gray-300 my-12 md:my-16; } /* Matching E-Waste border/spacing */

             /* Adopt E-Waste list styles */
            ul.checkmark-list { @apply list-none space-y-2 mb-6 pl-0; }
            ul.checkmark-list li { @apply flex items-start; }
            ul.checkmark-list li::before { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-green-600 mr-3 mt-1 text-sm flex-shrink-0; } /* Using green-600 explicitly */
            ul.cross-list { @apply list-none space-y-2 mb-6 pl-0; }
            ul.cross-list li { @apply flex items-start; }
            ul.cross-list li::before { content: '\f00d'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-danger mr-3 mt-1 text-sm flex-shrink-0; }

            /* Adopt E-Waste table styles (if needed) */
            table { @apply w-full border-collapse text-left text-sm text-neutral; }
            thead { @apply bg-primary/10; }
            th { @apply border border-primary/20 px-4 py-2 font-semibold text-primary-dark; } /* Primary dark for table headers */
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
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-accent; } /* Accent ring for focus-visible */

             /* Blockquote matching E-Waste style (if any) or simplified */
             blockquote { @apply border-l-4 border-accent bg-gray-100 p-5 my-6 italic text-neutral shadow-inner rounded-r-md;} /* Accent border */
             blockquote cite { @apply block not-italic mt-2 text-sm text-neutral/80;}
        }

        @layer components {
            /* Adopt E-Waste component styles */
            .btn { @apply inline-flex items-center justify-center bg-primary text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-primary-dark hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed group; } /* Added group for nested icon animation */
            .btn i { @apply mr-2 -ml-1 transition-transform duration-300 group-hover:scale-110; } /* Adjusted icon class */
             /* Defined btn-secondary and btn-accent based on E-Waste structure and colors */
             /* btn-secondary matches E-Waste's btn-secondary (accent color) */
            .btn-secondary { @apply inline-flex items-center justify-center bg-accent text-black font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:text-white hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             /* btn-accent uses danger color for Blood Request, using amber here for general accent button */
             .btn-accent { @apply inline-flex items-center justify-center bg-accent text-black font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:text-white hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             .btn-outline { @apply inline-flex items-center justify-center bg-transparent border-2 border-primary text-primary font-semibold py-2 px-6 rounded-full hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; }
             /* Additional button variations if needed from original index, mapping to new palette */
             .btn-outline.secondary { @apply !text-neutral !border-neutral hover:!bg-neutral hover:!text-white focus-visible:ring-neutral; } /* Neutral outline button */
             .btn-light { @apply inline-flex items-center justify-center bg-white text-primary font-semibold py-3 px-8 rounded-full shadow-md hover:bg-gray-100 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed; } /* Light button with primary text */
             .btn-icon { @apply p-2.5 rounded-full focus-visible:ring-offset-0 text-neutral hover:text-primary transition-colors duration-200; } /* Icon button style */


            .section-padding { @apply py-16 md:py-24 px-4; } /* Matching E-Waste padding */
            .card { @apply bg-white p-6 rounded-lg shadow-md transition-shadow duration-300 hover:shadow-lg overflow-hidden border border-gray-200; } /* Matching E-Waste card, added border */
            .card-hover { @apply hover:shadow-xl hover:border-primary/50 hover:scale-[1.02] z-10; } /* Simple hover effect */
             /* Removed .panel as card serves a similar purpose with padding */
             .form-section { @apply card border-l-4 border-accent mt-12; } /* Use E-Waste card base, add left border, default accent border */
             #volunteer-section .form-section { @apply !border-primary; } /* Primary border for volunteer form */
             #contact .form-section { @apply !border-neutral; } /* Neutral border for contact form */


             /* Section Title - Matching E-Waste style */
            .section-title { @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark; }
            .section-title::after { content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-primary rounded-full; }
             /* Section Title with Gradient Underline (if needed from original index) */
             .section-title-underline::after { content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-gradient-to-r from-primary to-accent rounded-full opacity-80; }


            /* Removed Blood page form component classes (.form-label, .form-input, .form-error-message) - using base styles + utility */
             /* Form message base style (details handled in get_form_status_html) */
            .form-message { /* Base class for status messages */ }
        }

        @layer utilities {
            /* Adopt E-Waste utilities */
             .honeypot-field { @apply absolute left-[-5000px] w-px h-px overflow-hidden; } /* Simplified positioning */
             .animate-delay-50 { animation-delay: 0.05s; } .animate-delay-100 { animation-delay: 0.1s; } .animate-delay-150 { animation-delay: 0.15s; } .animate-delay-200 { animation-delay: 0.2s; } .animate-delay-300 { animation-delay: 0.3s; } .animate-delay-400 { animation-delay: 0.4s; } .animate-delay-500 { animation-delay: 0.5s; } .animate-delay-700 { animation-delay: 0.7s; }


            /* Form Error Class - Matching E-Waste style */
            .form-input-error { @apply border-red-500 ring-1 ring-red-500 focus:border-red-500 focus:ring-red-500; } /* Error Class */

             /* Spinner utility - Matching E-Waste/Blood page */
             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current align-middle; } /* Use smaller spinner */

             /* Animated Gradient Background Utility (Using new colors) */
             .animated-gradient-primary-bg {
                background: linear-gradient(-45deg, <?= $primary_color ?>, <?= $neutral_medium_color ?>, <?= $blue_color_info ?>, <?= $primary_color ?>); /* Primary, Neutral, Info */
                background-size: 400% 400%;
                animation: gradientBg 18s ease infinite;
             }
             .animated-gradient-accent-bg { /* Used for Volunteer section */
                 background: linear-gradient(-45deg, <?= $accent_color ?>, <?= $red_color ?>, <?= $primary_color ?>, <?= $accent_color ?>); /* Accent, Danger, Primary */
                 background-size: 400% 400%;
                 animation: gradientBg 20s ease infinite;
             }

             /* Animation on Scroll Classes (From original index - keep if needed) */
             .animate-on-scroll { opacity: 0; transition: opacity 0.8s cubic-bezier(0.165, 0.84, 0.44, 1), transform 0.8s cubic-bezier(0.165, 0.84, 0.44, 1); }
             .animate-on-scroll.fade-in-up { transform: translateY(40px); }
             .animate-on-scroll.fade-in-left { transform: translateX(-50px); }
             .animate-on-scroll.fade-in-right { transform: translateX(50px); }
             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }
        }

        /* --- Specific Component Styles --- */
        /* Header - Matching E-Waste style */
        #main-header { @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200; min-height: 70px; @apply py-2 md:py-0; }
         body { @apply pt-[70px]; } /* Offset for fixed header, redundant with base but ensures clarity */
         #main-header.scrolled { @apply shadow-lg bg-white/95 border-gray-300; }

        /* Navigation - Matching E-Waste structure */
        #navbar { @apply w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-white lg:bg-transparent shadow-xl lg:shadow-none lg:border-none border-t border-gray-200 transition-all duration-500 ease-in-out; }
        #navbar.open { @apply block; max-height: calc(100vh - 70px); }
         #navbar ul { @apply flex flex-col lg:flex-row lg:items-center lg:space-x-5 xl:space-x-6 py-4 lg:py-0 px-4 lg:px-0; }
         #navbar ul li a { @apply text-neutral-dark hover:text-primary font-medium py-2 relative transition duration-300 ease-in-out text-sm lg:text-base block lg:inline-block lg:py-0 px-3 lg:px-2 xl:px-3; }
         #navbar ul li a::after { content: ''; @apply absolute bottom-[-5px] left-0 w-0 h-[3px] bg-gradient-to-r from-primary to-accent opacity-0 transition-all duration-300 ease-out rounded-full group-hover:opacity-100 group-hover:w-full; } /* Primary to accent gradient underline */
         #navbar ul li a.active { @apply text-primary font-semibold; }
         #navbar ul li a.active::after { @apply w-full opacity-100; }

         /* Mobile menu toggle (Matching E-Waste) */
         .menu-toggle { @apply text-neutral-dark hover:text-primary transition-colors duration-200 p-2 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary; }
         .menu-toggle span { @apply block w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out; }
         .menu-toggle span:nth-child(1) { @apply mb-1.5; }
         .menu-toggle span:nth-child(3) { @apply mt-1.5; }
         .menu-toggle.open span:nth-child(1) { @apply transform rotate-45 translate-y-[6px]; }
         .menu-toggle.open span:nth-child(2) { @apply opacity-0; }
         .menu-toggle.open span:nth-child(3) { @apply transform -rotate-45 -translate-y-[6px]; }

        /* Hero Section - Adapting E-Waste hero style */
         #hero {
             /* Using animated gradient utility with new colors */
             @apply animated-gradient-primary-bg text-white section-padding flex items-center relative overflow-hidden min-h-[calc(100vh-70px)];
         }
         #hero::before { /* Subtle overlay */
             content: ''; @apply absolute inset-0 bg-black/20; /* Dark overlay matching E-Waste hero */
         }
         .hero-text h1 { @apply !text-white mb-6 drop-shadow-lg leading-tight font-black; } /* Matching E-Waste hero h1, ensure white */
         .hero-text p.lead { @apply text-gray-200 max-w-3xl mx-auto drop-shadow text-xl md:text-2xl mb-8; } /* Matching E-Waste hero p */
         .hero-logo img { @apply drop-shadow-2xl animate-pulse-glow bg-white/10 p-3 backdrop-blur-sm; animation-duration: 4s; } /* Matching E-Waste/Blood page logo style */
         .hero-scroll-indicator { @apply absolute bottom-8 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
         .hero-scroll-indicator a { @apply text-white/60 hover:text-white text-4xl animate-bounce-subtle; }


        /* Profile Section - Basic section/text block styling */
         #profile .profile-image img { @apply rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px] border-4 border-white; } /* Use E-Waste card shadow/border ideas */

         /* Objectives Section - Adapting card/list item style ideas */
         #objectives { @apply bg-neutral-light; } /* Light background like E-Waste */
         .objective-item { @apply bg-white p-5 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:border-accent border-l-4 border-transparent flex items-start space-x-4; } /* Use white background, border ideas from E-Waste */
         .objective-item i { @apply text-primary group-hover:text-accent transition-all duration-300 flex-shrink-0 w-6 text-center text-xl group-hover:rotate-[15deg]; } /* Primary icon, accent on hover */
         .objective-item p { @apply text-sm text-neutral leading-relaxed; } /* Neutral text color */


        /* Focus Areas Cards - Adapting E-Waste card style */
        #areas-focus { @apply bg-white; } /* White background like E-Waste */
        .focus-item { @apply card card-hover border-t-4 border-primary bg-white p-6 md:p-8 text-center flex flex-col items-center group; } /* Use E-Waste card and primary top border */
        .focus-item .icon { @apply text-5xl text-primary mb-6 inline-block transition-transform duration-300 group-hover:scale-110 group-hover:animate-icon-bounce; } /* Primary icon color */
        .focus-item h3 { @apply text-xl text-primary-dark mb-3 transition-colors duration-300 group-hover:text-primary; } /* Primary dark heading, primary on hover */
        .focus-item p { @apply text-sm text-neutral leading-relaxed flex-grow mb-4 text-center; } /* Neutral text color */
        .focus-item .read-more-link { @apply relative block text-sm font-semibold text-accent mt-auto opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:underline pt-2; } /* Accent color for read more */
        .focus-item .read-more-link::after { content: '\f061'; font-family: 'Font Awesome 6 Free'; @apply font-black text-xs ml-1.5 opacity-0 group-hover:opacity-100 translate-x-[-5px] group-hover:translate-x-0 transition-all duration-300 inline-block;}


        /* News Section - Adapting E-Waste section/card style */
        #news-section { @apply bg-neutral-light; } /* Light background */
        #news-section .news-card { @apply card card-hover flex flex-col; } /* Use E-Waste card base */
        #news-section .news-card img { @apply rounded-t-lg; } /* Match card border-radius */
        #news-section .news-card .news-content { @apply p-5 flex flex-col flex-grow; }
        #news-section .news-card .date { @apply block text-xs text-neutral-medium mb-2; } /* Neutral medium for muted text */
        #news-section .news-card h4 { @apply text-lg font-semibold text-primary-dark mb-2 leading-snug flex-grow; } /* Primary dark heading */
        #news-section .news-card h4 a { @apply text-inherit hover:text-primary; } /* Primary color on hover */
        #news-section .news-card p { @apply text-sm text-neutral mb-4 leading-relaxed; } /* Neutral text */
         #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-gray-200; } /* Border style */

        /* How to Join / Get Involved Section - Adapting Blood/E-Waste form section style */
         #volunteer-section { @apply animated-gradient-accent-bg text-white relative; } /* Use accent gradient utility */
         #volunteer-section::before { content:''; @apply absolute inset-0 bg-black/25 z-0;} /* Darkening overlay */
         #volunteer-section .section-title, #volunteer-section .section-title::after { @apply relative z-10; } /* Ensure title is above overlay */
         #volunteer-section .section-title::after { @apply !bg-primary; } /* Primary underline for Volunteer title */
         #volunteer-section form { @apply relative z-10; } /* Ensure form is above overlay */

         /* Override form input colors for dark/gradient sections */
         #volunteer-section input[type="text"], #volunteer-section input[type="email"], #volunteer-section input[type="tel"], #volunteer-section input[type="number"], #volunteer-section input[type="date"], #volunteer-section select, #volunteer-section textarea {
             @apply !bg-white/10 !border-gray-400/40 !text-white placeholder:!text-gray-300/60 focus:!bg-white/20 focus:!border-white focus:!ring-white/50;
         }
         #volunteer-section label { @apply !text-gray-200; }
         #volunteer-section .text-xs.text-gray-300 { @apply !text-gray-300; } /* Ensure small text color is right */
          #volunteer-section .form-input-error { @apply !border-red-400 !ring-red-400/60 focus:!border-red-400 focus:!ring-red-400/60; } /* Error state for inverted inputs */

         /* Gallery (Simple styling) */
         #gallery-section { @apply bg-white; } /* White background */
         .gallery-item img { @apply transition-all duration-400 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110; } /* Kept original index hover effect */


         /* Associates (Simple styling) */
         #associates { @apply bg-neutral-light; } /* Light background */
         .associate-logo img { @apply filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100; } /* Kept effect */
         .associate-logo p { @apply text-xs font-medium text-neutral group-hover:text-primary transition-colors; } /* Primary color on hover */


         /* Donation CTA Section - Adapting Blood/E-Waste section style */
          #donate-section { @apply animated-gradient-primary-bg text-white relative text-center overflow-hidden; } /* Use primary gradient utility */
          #donate-section::before { content:''; @apply absolute inset-0 bg-black/35 z-0;} /* Darkening overlay */
          #donate-section .section-title, #donate-section .section-title::after { @apply !text-white relative z-10; } /* Ensure title is above overlay */
          #donate-section .section-title::after { @apply !bg-accent/70; } /* Accent underline for Donate title */
          #donate-section > div { @apply relative z-10; } /* Ensure content is above overlay */
          #donate-section .fa-donate { @apply text-4xl text-white bg-accent p-4 rounded-full shadow-lg mb-6 inline-block animate-bounce-subtle; } /* Accent icon bg */
          #donate-section .bg-black\/25 { @apply dark:bg-black/50; } /* Ensure dark mode works for this specific element if re-added */


        /* Contact Section - Adapting E-Waste form section style */
         #contact { @apply bg-secondary; } /* Secondary (gray) background */
         #contact .contact-info-item { @apply flex items-start gap-4 text-neutral; } /* Neutral text for info */
         #contact .contact-info-item i { @apply text-primary text-lg mt-1 w-5 text-center flex-shrink-0; } /* Primary color for icons */
         #contact .registration-info { @apply bg-neutral-light p-4 rounded-md border border-gray-200 text-xs text-neutral mt-8 shadow-inner;} /* Light neutral background */
         #contact .registration-info h4 { @apply text-sm font-semibold text-primary-dark mb-2; } /* Primary dark heading */


         /* Footer - Matching E-Waste style */
         footer { @apply bg-primary-dark text-gray-300 pt-12 pb-8 mt-12 border-t-4 border-accent; } /* Dark primary bg, Accent border */
         footer .footer-heading { @apply text-lg font-semibold text-white mb-5 relative pb-2; }
         footer .footer-heading::after { @apply content-[''] absolute bottom-0 left-0 w-10 h-0.5 bg-primary rounded-full; } /* Primary underline */
         footer .footer-link { @apply text-gray-400 hover:text-white hover:underline text-sm transition-colors duration-200; }
         footer ul.footer-links li { @apply mb-1.5; }
         footer ul.footer-links li a { @apply footer-link inline-flex items-center gap-1.5; }
         footer ul.footer-links i { @apply opacity-70; }
         footer address p { @apply mb-3 flex items-start gap-3 text-gray-400; } /* Gray 400 for address text */
         footer address i { @apply text-primary mt-1 w-4 text-center flex-shrink-0; } /* Primary color for icons */
         .footer-social-icon { @apply text-xl transition duration-300 text-gray-400 hover:scale-110; }
         .footer-bottom { @apply border-t border-gray-700/50 pt-8 mt-12 text-center text-sm text-gray-500; }

        /* Back to Top Button - Matching E-Waste/Blood page */
         #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-accent text-white shadow-lg hover:bg-accent-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-accent opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95; } /* Accent color */
         #back-to-top.visible { @apply opacity-100 visible; }

         /* Modal Styles - Matching E-Waste/Blood page style ideas */
         .modal-container { @apply fixed inset-0 bg-black/70 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity duration-300 ease-out; } /* Reduced blur, removed dark mode */
         .modal-container.flex { @apply flex; opacity: 1; }
         .modal-container.hidden { @apply hidden; opacity: 0; }

         .modal-box { @apply bg-white rounded-lg shadow-xl p-6 md:p-8 w-full max-w-lg text-left relative transform transition-all duration-300 scale-95 opacity-0; }
         .modal-container.flex .modal-box { @apply scale-100 opacity: 100; }

         #bank-details-modal h3 { @apply !text-primary !mt-0 mb-5 border-b border-gray-200 pb-3; } /* Primary title */
         .modal-content-box { @apply bg-neutral-light p-4 rounded-md border border-gray-200 space-y-2 my-5 text-sm text-neutral; } /* Neutral text */
         .modal-content-box p strong { @apply font-medium text-primary-dark; } /* Primary dark text */
         .modal-footer-note { @apply text-xs text-neutral text-center mt-6 italic; }
         .close-button { @apply absolute top-4 right-4 text-neutral hover:text-danger p-1 rounded-full transition-colors focus-visible:ring-accent; } /* Neutral text, Danger on hover, Accent ring */
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
<body class="bg-secondary text-neutral-dark font-sans pt-[70px]"> <!-- Use secondary bg, neutral dark text, and add padding for fixed header -->

<!-- Header -->
<header id="main-header" class="py-2 md:py-0">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <div class="logo flex-shrink-0 py-2">
             <a href="#hero" aria-label="PAHAL NGO Home" class="text-3xl md:text-4xl font-black text-primary font-heading leading-none flex items-center transition-opacity hover:opacity-80">
                <img src="icon.webp" alt="" class="h-9 w-9 mr-2 inline object-contain animate-pulse-slow" aria-hidden="true"> <!-- Subtle pulse on logo -->
                PAHAL
             </a>
             <p class="text-xs text-neutral -mt-1.5 ml-11 hidden sm:block">An Endeavour for a Better Tomorrow</p> <!-- Neutral text color -->
        </div>
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-md">
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
                <!-- Removed Theme Toggle Button -->
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
              <p class="lead text-lg lg:text-xl my-6 max-w-xl mx-auto lg:mx-0 text-gray-200 drop-shadow-md">
                Join PAHAL, a youth-driven NGO in Jalandhar, committed to holistic development and tangible social impact through dedicated action in health, education, environment, and communication.
              </p>
              <div class="mt-8 flex flex-wrap justify-center lg:justify-start gap-4">
                <a href="#profile" class="btn btn-light text-base md:text-lg shadow-lg"><i class="fas fa-info-circle"></i>Discover More</a>
                 <a href="#volunteer-section" class="btn btn-primary text-base md:text-lg shadow-lg"><i class="fas fa-hands-helping"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto animate-on-scroll fade-in-right animate-delay-200">
                 <img src="icon.webp" alt="PAHAL NGO Large Logo Icon" class="mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-60 lg:h-60 rounded-full shadow-2xl bg-white/25 p-3 backdrop-blur-sm"> <!-- Increased padding/bg alpha -->
            </div>
        </div>
        <div class="hero-scroll-indicator">
             <a href="#profile" aria-label="Scroll down"><i class="fas fa-chevron-down"></i></a>
        </div>
    </section>

    <!-- Profile Section -->
    <section id="profile" class="section-padding bg-secondary"> <!-- Use secondary background -->
        <div class="container mx-auto animate-on-scroll fade-in-up">
             <h2 class="section-title section-title-underline">Our Profile & Vision</h2>
             <div class="grid md:grid-cols-5 gap-12 items-center mt-16">
                 <div class="md:col-span-3 profile-text">
                    <h3 class="text-2xl mb-4 !text-primary-dark">Who We Are</h3> <!-- Primary dark heading -->
                    <p class="mb-6 text-neutral text-lg">'PAHAL' (Initiative) stands as a testament to collective action... driven by a singular vision: to catalyze perceptible, positive transformation within our social fabric.</p> <!-- Neutral text -->
                    <blockquote><p>"PAHAL is an endeavour for a Better Tomorrow"</p></blockquote>
                    <h3 class="text-2xl mb-4 mt-10 !text-primary-dark">Our Core Vision</h3> <!-- Primary dark heading -->
                    <p class="text-neutral text-lg">We aim to cultivate <strong class="text-primary font-medium">Holistic Personality Development</strong>... thereby building a more compassionate and equitable world.</p> <!-- Primary text highlight -->
                 </div>
                 <div class="md:col-span-2 profile-image animate-on-scroll fade-in-right animate-delay-200">
                    <img src="https://via.placeholder.com/500x600.png/2E7D32/F9FAFB?text=PAHAL+Vision" alt="PAHAL NGO team vision" class="rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px] border-4 border-white"> <!-- Updated placeholder image colors, border -->
                </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section -->
     <section id="objectives" class="section-padding bg-neutral-light"> <!-- Use neutral light background -->
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Our Guiding Objectives</h2>
             <div class="max-w-6xl mx-auto grid md:grid-cols-2 lg:grid-cols-3 gap-6 mt-16">
                 <div class="objective-item group animate-on-scroll fade-in-up"><i class="fas fa-users"></i><p>To collaborate genuinely <strong>with and among the people</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up animate-delay-100"><i class="fas fa-people-carry"></i><p>To engage in <strong>creative & constructive social action</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up animate-delay-200"><i class="fas fa-lightbulb"></i><p>To enhance knowledge of <strong>self & community realities</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up animate-delay-300"><i class="fas fa-seedling"></i><p>To apply scholarship for <strong>mitigating social problems</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up animate-delay-400"><i class="fas fa-tools"></i><p>To gain and apply skills in <strong>humanity development</strong>.</p></div>
                 <div class="objective-item group animate-on-scroll fade-in-up animate-delay-500"><i class="fas fa-recycle"></i><p>To promote <strong>sustainable practices</strong> & awareness.</p></div>
            </div>
        </div>
    </section>

    <!-- Areas of Focus Section -->
    <section id="areas-focus" class="section-padding bg-white"> <!-- Use white background -->
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Key Focus Areas</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-16">
                 <!-- Health -->
                 <a href="blood-donation.php" title="Health Initiatives" class="focus-item group animate-on-scroll fade-in-up card-hover">
                     <span class="icon"><i class="fas fa-heart-pulse"></i></span> <h3>Health & Wellness</h3>
                     <p>Prioritizing community well-being via awareness campaigns, blood drives, and promoting healthy lifestyles.</p>
                     <span class="read-more-link">Blood Donation Program <i class="fas fa-arrow-right text-xs ml-1 opacity-0 group-hover:opacity-100 translate-x-[-5px] group-hover:translate-x-0 transition-all duration-300"></i></span>
                 </a>
                 <!-- Education -->
                 <div class="focus-item group animate-on-scroll fade-in-up animate-delay-100 card-hover">
                     <span class="icon"><i class="fas fa-user-graduate"></i></span> <h3>Education & Skilling</h3>
                     <p>Empowering youth by fostering ethical foundations, essential life skills, and professional readiness.</p>
                     <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span>
                  </div>
                 <!-- Environment -->
                 <a href="e-waste.php" title="E-waste Recycling" class="focus-item group animate-on-scroll fade-in-up animate-delay-200 card-hover">
                      <span class="icon"><i class="fas fa-leaf"></i></span> <h3>Environment</h3>
                      <p>Championing stewardship through plantation drives, waste management, and e-waste recycling.</p>
                      <span class="read-more-link">E-Waste Program <i class="fas fa-arrow-right text-xs ml-1 opacity-0 group-hover:opacity-100 translate-x-[-5px] group-hover:translate-x-0 transition-all duration-300"></i></span>
                 </a>
                 <!-- Communication -->
                 <div class="focus-item group animate-on-scroll fade-in-up animate-delay-300 card-hover">
                     <span class="icon"><i class="fas fa-comments"></i></span> <h3>Communication Skills</h3>
                     <p>Enhancing verbal, non-verbal, and presentation abilities in youth via interactive programs.</p>
                      <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon</span>
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section -->
     <section id="volunteer-section" class="section-padding text-white relative animated-gradient-accent-bg">
        <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div> <!-- Darkening Overlay -->
        <div class="container mx-auto relative z-10">
             <h2 class="section-title !text-white section-title-underline after:!bg-primary">Join the PAHAL Movement</h2> <!-- Primary underline for Volunteer title -->
            <div class="grid lg:grid-cols-2 gap-12 items-center mt-16">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left">
                    <h3 class="text-3xl lg:text-4xl font-bold mb-4 text-white leading-snug drop-shadow-md">Make a Difference, Volunteer With Us</h3>
                    <p class="text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed drop-shadow-sm">PAHAL welcomes passionate individuals... Your time, skills, and dedication are invaluable assets.</p>
                    <p class="text-gray-200 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed drop-shadow-sm">Volunteering offers a rewarding experience... Express your interest below!</p>
                     <div class="mt-10 flex flex-wrap justify-center lg:justify-start gap-4">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary"><i class="fas fa-phone-alt mr-2"></i>Contact Directly</a>
                         <!-- <a href="volunteer-opportunities.php" class="btn !bg-white !text-neutral-dark hover:!bg-gray-100"><i class="fas fa-list-alt"></i>View Opportunities</a> -->
                     </div>
                 </div>
                 <!-- Volunteer Sign-up Form -->
                 <div class="form-section !border-primary animate-on-scroll fade-in-right animate-delay-100"> <!-- Primary border -->
                     <h3 class="text-2xl mb-6 text-primary font-semibold text-center">Register Your Volunteer Interest</h3> <!-- Primary color for heading -->
                     <?= get_form_status_html('volunteer_form') ?>
                    <form id="volunteer-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5" novalidate> <!-- Add novalidate for custom validation -->
                        <!-- Hidden Fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="volunteer_form">
                        <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_volunteer">Keep Blank</label>
                            <input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME_VOLUNTEER ?>" tabindex="-1" autocomplete="off">
                        </div>
                        <!-- Form Fields (using updated classes) -->
                        <div>
                            <label for="volunteer_name" class="form-label required">Full Name</label>
                            <input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>>
                            <?= get_field_error_html('volunteer_form', 'volunteer_name') ?>
                        </div>
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label for="volunteer_email" class="form-label">Email</label>
                                <input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>>
                                <?= get_field_error_html('volunteer_form', 'volunteer_email') ?>
                            </div>
                            <div>
                                <label for="volunteer_phone" class="form-label">Phone</label>
                                <input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>>
                                <?= get_field_error_html('volunteer_form', 'volunteer_phone') ?>
                            </div>
                        </div>
                        <p class="text-xs text-neutral mt-1" id="volunteer_contact_note">Provide Email or Phone.</p> <!-- Neutral text color -->
                        <div>
                            <label for="volunteer_area" class="form-label required">Area of Interest</label>
                            <select id="volunteer_area" name="volunteer_area" required class="<?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>>
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
                            <label for="volunteer_availability" class="form-label required">Availability</label>
                            <input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="<?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>>
                            <?= get_field_error_html('volunteer_form', 'volunteer_availability') ?>
                        </div>
                        <div>
                            <label for="volunteer_message" class="form-label">Message (Optional)</label>
                            <textarea id="volunteer_message" name="volunteer_message" rows="3" class="<?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Your motivation or skills..." <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea>
                            <?= get_field_error_html('volunteer_form', 'volunteer_message') ?>
                        </div>
                        <div class="pt-5 text-center"> <!-- E-Waste button alignment -->
                            <button type="submit" class="btn btn-accent w-full sm:w-auto"><span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Submit Interest</span><span class="spinner hidden ml-2"></span></button>
                         </div>
                    </form>
                 </div>
            </div>
        </div>
    </section>


    <!-- News & Events Section -->
    <section id="news-section" class="section-padding bg-neutral-light"> <!-- Light neutral background -->
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Latest News & Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-16">
                <?php if (!empty($news_items)): ?>
                    <?php foreach ($news_items as $index => $item): ?>
                    <div class="news-card group animate-on-scroll fade-in-up animate-delay-<?= ($index * 100) ?> card-hover">
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="block aspect-[16/10] overflow-hidden rounded-t-lg" title="Read more"> <!-- Match card border radius -->
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                        </a>
                        <div class="news-content">
                             <span class="date"><i class="far fa-calendar-alt mr-1 opacity-70"></i><?= date('M j, Y', strtotime($item['date'])) ?></span>
                             <h4 class="my-2"><a href="<?= htmlspecialchars($item['link']) ?>" class="group-hover:!text-primary"><?= htmlspecialchars($item['title']) ?></a></h4> <!-- Primary color on hover -->
                             <p class="text-sm text-neutral"><?= htmlspecialchars($item['excerpt']) ?></p> <!-- Neutral text -->
                              <div class="read-more-action">
                                  <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-outline secondary !text-sm !py-1 !px-3 group">Read More <i class="fas fa-arrow-right text-xs ml-1 group-hover:translate-x-1 transition-transform"></i></a>
                              </div>
                         </div>
                    </div>
                    <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-neutral md:col-span-2 lg:col-span-3">No recent news.</p> <!-- Neutral text -->
                 <?php endif; ?>
            </div>
            <div class="text-center mt-12"><a href="/news-archive.php" class="btn btn-primary"><i class="far fa-newspaper mr-2"></i>View News Archive</a></div> <!-- Primary button -->
        </div>
    </section>

    <!-- Gallery Section -->
    <section id="gallery-section" class="section-padding bg-white"> <!-- White background -->
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Glimpses of Our Work</h2>
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4 mt-16">
                    <?php foreach ($gallery_images as $index => $image): ?>
                    <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block aspect-video rounded-lg overflow-hidden shadow-md group animate-on-scroll fade-in-up animate-delay-<?= ($index * 50) ?> transition-all duration-300 hover:shadow-xl hover:scale-105">
                         <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110">
                     </a>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-8 text-neutral italic">Click images to view larger.</p> <!-- Neutral text -->
            <?php else: ?>
                 <p class="text-center text-neutral">Gallery coming soon.</p> <!-- Neutral text -->
            <?php endif; ?>
        </div>
    </section>

    <!-- Associates Section -->
    <section id="associates" class="section-padding bg-neutral-light"> <!-- Light neutral background -->
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-neutral mb-16">Collaboration amplifies impact. We value the support of these esteemed organizations.</p> <!-- Neutral text -->
             <div class="flex flex-wrap justify-center items-center gap-x-10 md:gap-x-16 gap-y-10"> <!-- Increased gap -->
                <?php foreach ($associates as $index => $associate): ?>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll fade-in-up animate-delay-<?= ($index * 50) ?>">
                    <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-3 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100">
                    <p class="text-xs font-medium text-neutral group-hover:text-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p> <!-- Primary color on hover -->
                 </div>
                 <?php endforeach; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <section id="donate-section" class="section-padding text-center relative overflow-hidden animated-gradient-primary-bg">
         <div class="absolute inset-0 bg-black/35 mix-blend-multiply z-0"></div>
         <div class="container mx-auto relative z-10">
             <i class="fas fa-donate text-4xl text-white bg-accent p-4 rounded-full shadow-lg mb-6 inline-block animate-bounce-subtle"></i> <!-- Accent icon bg -->
             <h2 class="section-title !text-white section-title-underline after:!bg-accent"><span class="drop-shadow-md">Support Our Initiatives</span></h2> <!-- Accent underline -->
            <p class="text-gray-200 max-w-3xl mx-auto mb-8 text-lg leading-relaxed drop-shadow">Your contribution fuels our mission in health, education, and environment within Jalandhar.</p>
            <p class="text-gray-200 bg-black/25 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-10 backdrop-blur-sm border border-white/20">Donations Tax Exempt under Sec 80G.</p>
            <div class="space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center items-center gap-4">
                 <a href="#contact" class="btn btn-light shadow-xl"><i class="fas fa-info-circle mr-2"></i> Donation Inquiries</a> <!-- Light button, primary text -->
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-primary shadow-xl group" data-modal-target="bank-details-modal"><span class="button-text flex items-center justify-center"><i class="fas fa-university mr-2 group-hover:!text-primary"></i>View Bank Details</span></button>
            </div>
        </div>
     </section>

    <!-- Contact Section -->
     <section id="contact" class="section-padding bg-secondary"> <!-- Secondary (gray) background -->
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Connect With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-neutral mb-16">Questions, suggestions, partnerships, or just want to learn more? We're here to connect.</p> <!-- Neutral text -->
             <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                 <!-- Contact Details -->
                 <div class="lg:col-span-2 animate-on-scroll fade-in-left">
                     <h3 class="text-2xl mb-6 font-semibold !text-primary-dark">Contact Information</h3> <!-- Primary dark heading -->
                     <address class="space-y-6 text-neutral text-base mb-10"> <!-- Neutral text -->
                        <div class="contact-info-item"><i class="fas fa-map-marker-alt"></i><div><span>Our Office:</span> 36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008</div></div>
                        <div class="contact-info-item"><i class="fas fa-phone-alt"></i><div><span>Phone Lines:</span> <a href="tel:+911812672784">181-267-2784</a><br><a href="tel:+919855614230">98556-14230</a></div></div>
                        <div class="contact-info-item"><i class="fas fa-envelope"></i><div><span>Email Us:</span> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></div></div>
                     </address>
                    <div class="mb-10 pt-8 border-t border-gray-200"><h4 class="text-lg font-semibold text-neutral mb-4">Follow Our Journey</h4><div class="flex justify-center md:justify-start space-x-5 social-icons"><!-- Social Icons --></div></div> <!-- Neutral heading -->
                     <div class="mb-10 pt-8 border-t border-gray-200"><h4 class="text-lg font-semibold text-neutral mb-4">Visit Us</h4><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3406.124022090013!2d75.5963185752068!3d31.339546756899223!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b7f02a86379%3A0x4c61457c43d15b97!2s36%2C%20New%20Vivekanand%20Park%2C%20Maqsudan%2C%20Jalandhar%2C%20Punjab%20144008!5e0!3m2!1sen!2sin!4v1700223266482!5m2!1sen!2sin" width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" class="rounded-lg shadow-md border border-gray-200"></iframe></div> <!-- Border -->
                     <div class="registration-info"><h4 class="text-sm font-semibold text-primary-dark mb-2">Registration</h4><!-- Reg Details --></div> <!-- Primary dark heading -->
                 </div>
                <!-- Contact Form -->
                <div class="lg:col-span-3 form-section !border-neutral animate-on-scroll fade-in-right animate-delay-100"> <!-- Neutral border -->
                    <h3 class="text-2xl mb-8 font-semibold !text-primary text-center lg:text-left">Send Us a Message</h3> <!-- Primary color heading -->
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6" novalidate> <!-- Add novalidate -->
                        <!-- Hidden fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                        <div class="honeypot-field" aria-hidden="true">
                            <label for="website_url_contact">Leave this field blank</label>
                            <input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME_CONTACT ?>" tabindex="-1" autocomplete="off">
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
                        <div class="pt-5 text-center"> <!-- E-Waste button alignment -->
                            <button type="submit" class="btn btn-primary w-full sm:w-auto" id="contact-submit-button"><span class="button-text flex items-center justify-center"><i class="fas fa-paper-plane mr-2"></i>Send Message</span><span class="spinner hidden ml-2"></span></button>
                        </div>
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
        <p class="text-neutral">Use the following details for direct bank transfers. Mention "Donation" in the description.</p> <!-- Neutral text -->
         <div class="modal-content-box">
            <p><strong>Account Name:</strong> PAHAL (Regd.)</p>
            <p><strong>Account Number:</strong> [YOUR_BANK_ACCOUNT_NUMBER]</p> <!-- REPLACE -->
             <p><strong>Bank Name:</strong> [YOUR_BANK_NAME]</p> <!-- REPLACE -->
             <p><strong>Branch:</strong> [YOUR_BANK_BRANCH]</p> <!-- REPLACE -->
             <p><strong>IFSC Code:</strong> [YOUR_IFSC_CODE]</p> <!-- REPLACE -->
        </div>
        <p class="modal-footer-note text-neutral-medium">For queries or receipts, contact us. Thank you!</p> <!-- Neutral medium text -->
      </div>
    </div>

</main>

<!-- Footer -->
<footer class="bg-primary-dark text-gray-300 pt-12 pb-8 mt-12 border-t-4 border-accent">
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12 text-center md:text-left">
            <!-- Footer About -->
            <div>
                <h4 class="footer-heading">About PAHAL</h4>
                <a href="#hero" class="inline-block mb-3"><img src="icon.webp" alt="PAHAL Icon" class="w-14 h-14 rounded-full bg-white p-1 shadow-md mx-auto md:mx-0"></a>
                <p class="text-gray-400 text-sm">Jalandhar NGO fostering holistic growth & community service.</p> <!-- Gray 400 text -->
                <p class="text-xs text-gray-500 mt-2">Reg No: 737 | 80G & 12A</p>
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
                 <address class="text-gray-400 text-sm"> <!-- Gray 400 text -->
                     <p class="mb-3 flex items-start gap-3"><i class="fas fa-map-marker-alt text-primary mt-1 w-4 text-center flex-shrink-0"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008</p> <!-- Primary icon -->
                     <p class="mb-3 flex items-start gap-3"><i class="fas fa-phone-alt text-primary mt-1 w-4 text-center flex-shrink-0"></i> <a href="tel:+911812672784">181-267-2784</a></p> <!-- Primary icon -->
                     <p class="mb-3 flex items-start gap-3"><i class="fas fa-mobile-alt text-primary mt-1 w-4 text-center flex-shrink-0"></i> <a href="tel:+919855614230">98556-14230</a></p> <!-- Primary icon -->
                     <p class="mb-3 flex items-start gap-3"><i class="fas fa-envelope text-primary mt-1 w-4 text-center flex-shrink-0"></i> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></p> <!-- Primary icon -->
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

<!-- Main JavaScript (Keep existing logic, remove theme toggle) -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        console.log("PAHAL Main Page JS Loaded (E-Waste UI)");

        // Elements
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const navbar = document.getElementById('navbar');
        const navLinks = document.querySelectorAll('#navbar a.nav-link[href^="#"]');
        const header = document.getElementById('main-header');
        const backToTopButton = document.getElementById('back-to-top');
        const sections = document.querySelectorAll('main section[id]');
        let headerHeight = header?.offsetHeight ?? 70; // Default if header not found

        // --- Removed Theme Toggle ---
        // Theming is now handled via the fixed Tailwind config based on E-Waste style.

        // --- Header & Layout ---
        let scrollTimeout;
        const updateLayout = () => {
            // Recalculate header height on scroll/resize for accurate smooth scrolling
            headerHeight = header?.offsetHeight ?? 70;

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

             // Close mobile menu on resize if it goes above mobile breakpoint (handled by CSS default display)
             // Close mobile menu on scroll if open (optional, might be jarring)
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
                 // Check if screen size is actually mobile before toggling
                 const isMobile = window.innerWidth < 1024; // Tailwind's 'lg' breakpoint
                 if (!isMobile && !forceClose) return; // Don't toggle if desktop and not forced closed

                 isMobileMenuOpen = forceClose ? false : !isMobileMenuOpen;

                 menuToggle.setAttribute('aria-expanded', isMobileMenuOpen);
                 menuToggle.classList.toggle('open', isMobileMenuOpen);
                 navbar.classList.toggle('open', isMobileMenuOpen);
                // htmlElement.classList.toggle('overflow-hidden', isMobileMenuOpen); // Prevent background scroll (optional)
             }
         };

        menuToggle?.addEventListener('click', () => toggleMobileMenu());

         // Close menu when clicking a nav link on mobile
         navLinks.forEach(link => {
             link.addEventListener('click', () => {
                 // A small delay might be needed if smooth scroll starts immediately
                 setTimeout(() => {
                     toggleMobileMenu(true); // Force close after click
                 }, 100); // Adjust delay if needed
             });
         });

         // Close menu on resize if it becomes desktop size
        window.addEventListener('resize', () => {
             const isMobile = window.innerWidth < 1024;
             if (!isMobile && isMobileMenuOpen) {
                 toggleMobileMenu(true); // Force close if menu is open and screen becomes desktop
             }
        });


        // --- Active Link Highlighting ---
        const setActiveLink = () => {
             // Add a small tolerance to the scroll position calculation
             const scrollPos = window.scrollY + headerHeight + 50; // Add tolerance (e.g., 50px)
             let currentActiveSectionId = null;

             // Iterate over sections to find which one is currently in view
            sections.forEach(section => {
                 // Check if the section is within the visible part of the viewport
                 // using getBoundingClientRect is more robust
                 const rect = section.getBoundingClientRect();
                 // isPartiallyVisible = rect.top < window.innerHeight && rect.bottom >= 0;

                 // Check if the top of the section is past the scroll position offset by header
                 if (section.offsetTop <= scrollPos && section.offsetTop + section.offsetHeight > scrollPos) {
                     currentActiveSectionId = section.id;
                 }
             });

            // If no section is active and we are at the very top, highlight the Hero link
            if (currentActiveSectionId === null && window.scrollY < sections[0]?.offsetTop) {
                 currentActiveSectionId = sections[0]?.id === 'hero' ? 'hero' : null;
            }


            // Update nav links based on the active section ID
             navLinks.forEach(link => {
                link.classList.remove('active');
                 // Special handling for the home link pointing to #hero
                 const linkHref = link.getAttribute('href').substring(1);
                 if (linkHref === currentActiveSectionId) {
                    link.classList.add('active');
                 } else if (linkHref === '' && currentActiveSectionId === 'hero') { // Handles href="#"
                      link.classList.add('active');
                 }
             });
         };

         let activeLinkTimeout;
         window.addEventListener('scroll', () => {
             clearTimeout(activeLinkTimeout);
             activeLinkTimeout = setTimeout(setActiveLink, 100); // Adjust delay for performance
         }, { passive: true });

         // Initial call to set active link on page load, and after potential session restore redirect
         setActiveLink();


        // --- Smooth Scroll ---
        // Using native smooth scroll behavior, with header offset calculation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
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
                 } else if (href === '#' || href === '#hero') { // Handle # or #hero links
                     e.preventDefault(); // Prevent default hash behavior
                     window.scrollTo({ top: 0, behavior: 'smooth' });
                 }

                // Mobile menu close handled by separate event listener on navLinks
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
                 // Basic HTML5 validity check before disabling button
                 if (form.checkValidity()) {
                    if (submitButton) {
                        submitButton.disabled = true;
                        if (buttonTextSpan) buttonTextSpan.classList.add('opacity-0'); // Hide text gracefully
                        if (spinner) spinner.classList.remove('hidden'); // Show spinner
                     }
                 }
                 // Note: Server-side validation is still the primary security measure.
             });

             // Form status messages are now styled by CSS based on get_form_status_html output.
             // No specific JS animation needed based on the refactored blood page.
        });


        // --- Gallery Lightbox ---
        // Initialize SimpleLightbox if the element exists and library is loaded
        try {
            if (typeof SimpleLightbox !== 'undefined') {
                const galleryExists = document.querySelector('.gallery');
                if (galleryExists) {
                    new SimpleLightbox('.gallery a', {
                        captionDelay: 250,
                        fadeSpeed: 200,
                        animationSpeed: 200,
                        // Add more options as needed
                     });
                }
            } else {
                console.warn("SimpleLightbox library not found. Gallery images will not open in a lightbox.");
            }
        } catch(e) {
            console.error("SimpleLightbox initialization failed:", e);
        }


        // --- Animation on Scroll (Intersection Observer) ---
        // Keep Intersection Observer logic from original index
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
         // Keep modal handling logic from original index, adapting class names
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
                 }, 10); // Small delay
                document.documentElement.classList.add('overflow-hidden'); // Prevent background scroll
            }
        };

        // Function to close a modal
        const closeModal = (modal) => {
            if (modal) {
                // Trigger CSS transition out
                 modal.classList.remove('flex');

                // Wait for the transition to finish before hiding completely
                // Get duration from CSS, accounting for both transform and opacity
                const style = getComputedStyle(modal.querySelector('.modal-box'));
                const transformDuration = parseFloat(style.transitionDuration.split(',')[1] || style.transitionDuration) * 1000;
                 const opacityDuration = parseFloat(getComputedStyle(modal).transitionDuration) * 1000;
                 const transitionDuration = Math.max(transformDuration, opacityDuration); // Use the longer duration

                setTimeout(() => {
                    modal.classList.add('hidden'); // Hide completely after transition
                    // Remove overflow-hidden only if no other modals are open
                    if (!document.querySelectorAll('.modal-container.flex').length) {
                         document.documentElement.classList.remove('overflow-hidden');
                    }
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


        console.log("PAHAL Main Page JS Initialized.");

         // Handle scroll-to-hash after PRG redirect
         // This uses the standard browser hash scrolling, but we might need to adjust for fixed header
         // Let's implement the blood page's approach for consistency: get hash on load and scroll with offset
         const hash = window.location.hash;
         if (hash) {
             try {
                 // Use a timeout to ensure header height is calculated and DOM is ready
                 setTimeout(() => {
                      const targetElement = document.querySelector(decodeURIComponent(hash));
                      if (targetElement) {
                           const header = document.getElementById('main-header');
                           const headerOffset = header ? header.offsetHeight : 0; // Get header height
                           const elementPosition = targetElement.getBoundingClientRect().top;
                           const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20; // Adjust by header height and add a small margin

                           window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                       }
                 }, 150); // Small delay
             } catch (e) {
                 console.warn("Error scrolling to hash:", hash, e);
             }
         }
    });
</script>

</body>
</html>
