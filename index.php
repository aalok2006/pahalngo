<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 3.0 (Enhanced UI/UX & Alignment)
// Features: Theme Toggle, Modern UI, Animations, Accessibility Improvements
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
//  sanitize_email, validate_data, send_email, get_form_value)
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
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $submittedToken);
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
        $value = $data[$field] ?? null; $ruleList = explode('|', $ruleString); $fieldNameFormatted = ucfirst(str_replace('_', ' ', $field));
        foreach ($ruleList as $rule) {
            $params = []; if (strpos($rule, ':') !== false) { list($rule, $paramString) = explode(':', $rule, 2); $params = explode(',', $paramString); }
            $isValid = true; $errorMessage = '';
            switch ($rule) {
                case 'required': if ($value === null || $value === '' || (is_array($value) && empty($value))) { $isValid = false; $errorMessage = "{$fieldNameFormatted} is required."; } break;
                case 'email': if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $isValid = false; $errorMessage = "Please enter a valid email."; } break;
                case 'minLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') < (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must be at least {$params[0]} characters."; } break;
                case 'maxLength': if ($value !== null && mb_strlen((string)$value, 'UTF-8') > (int)$params[0]) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must not exceed {$params[0]} characters."; } break;
                case 'alpha_space': if (!empty($value) && !preg_match('/^[\p{L}\s]+$/u', $value)) { $isValid = false; $errorMessage = "{$fieldNameFormatted} must only contain letters and spaces."; } break;
                case 'phone': if (!empty($value) && !preg_match('/^(\+?\d{1,3}[-.\s]?)?\(?\d{3,5}\)?[-.\s]?\d{3}[-.\s]?\d{3,4}(\s*(ext|x|extension)\s*\d+)?$/', $value)) { $isValid = false; $errorMessage = "Invalid phone format."; } break;
                case 'in': if (!empty($value) && !in_array($value, $params)) { $isValid = false; $errorMessage = "Invalid selection for {$fieldNameFormatted}."; } break;
                case 'required_without': $otherField = $params[0] ?? null; if ($otherField && empty($value) && empty($data[$otherField] ?? null)) { $isValid = false; $errorMessage = "Either {$fieldNameFormatted} or " . ucfirst(str_replace('_',' ',$otherField)). " is required."; } break;
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
    if (!empty($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) { $replyToFormatted = $replyToName ? "=?UTF-8?B?".base64_encode($replyToName)."?= <{$replyToEmail}>" : $replyToEmail; $headers .= "Reply-To: {$replyToFormatted}\r\n"; }
    else { $headers .= "Reply-To: {$senderName} <{$senderEmail}>\r\n"; }
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

// --- UI Helper Functions (from previous enhanced version) ---

/**
 * Generates form status HTML (success/error) with enhanced styling & animation.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg transition-all duration-500 transform opacity-0 translate-y-2';
    $typeClasses = $isSuccess ? 'bg-green-100 border-green-500 text-green-900 dark:bg-green-900/30 dark:border-green-700 dark:text-green-200' : 'bg-red-100 border-red-500 text-red-900 dark:bg-red-900/30 dark:border-red-700 dark:text-red-200';
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-600 dark:text-green-400' : 'fas fa-exclamation-triangle text-red-600 dark:text-red-400';
    $title = $isSuccess ? 'Success!' : 'Error:';
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\"><strong class=\"font-bold flex items-center\"><i class=\"{$iconClass} mr-2 text-lg\"></i>{$title}</strong> <span class=\"block sm:inline mt-1 ml-6\">" . htmlspecialchars($message['text']) . "</span></div>";
}

/**
 * Generates HTML for a field error message with accessibility link.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) { return '<p class="text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium" id="' . $errorId . '"><i class="fas fa-times-circle mr-1"></i>' . htmlspecialchars($form_errors[$formId][$fieldName]) . '</p>'; } return '';
}

/**
 * Returns CSS classes for field highlighting based on errors.
 */
function get_field_error_class(string $formId, string $fieldName): string {
     global $form_errors; $base = 'form-input'; $error = 'form-input-error'; return isset($form_errors[$formId][$fieldName]) ? ($base . ' ' . $error) : $base;
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
$page_title = "PAHAL NGO Jalandhar | Empowering Communities, Inspiring Change"; // More engaging
$page_description = "'PAHAL' is a leading volunteer-driven youth NGO in Jalandhar, Punjab, fostering holistic development through impactful initiatives in health, education, environment, and communication skills."; // Action-oriented
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health camps, blood donation, education, environment, e-waste, communication skills, personality development, non-profit"; // More detailed

// --- Initialize Form State Variables ---
$form_submissions = [];
$form_messages = [];
$form_errors = [];
$csrf_token = generate_csrf_token();

// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Security Checks (Honeypot, CSRF)
    $submitted_form_id = $_POST['form_id'] ?? null;
    $submitted_token = $_POST[CSRF_TOKEN_NAME] ?? null;
    $honeypot_filled = !empty($_POST[HONEYPOT_FIELD_NAME]);

    if ($honeypot_filled || !validate_csrf_token($submitted_token)) {
        $reason = $honeypot_filled ? "Honeypot filled" : "Invalid CSRF token";
        log_message("[SECURITY FAILED] Main Page - {$reason}. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        http_response_code(403);
        die("Security validation failed. Please refresh and try again.");
    }

    // --- Process CONTACT Form ---
    if ($submitted_form_id === 'contact_form') {
        $form_id = 'contact_form'; $form_errors[$form_id] = [];
        // Sanitize & Store
        $name = sanitize_string($_POST['name'] ?? ''); $email = sanitize_email($_POST['email'] ?? ''); $message = sanitize_string($_POST['message'] ?? '');
        $form_submissions[$form_id] = ['name' => $name, 'email' => $email, 'message' => $message];
        // Validate
        $rules = ['name' => 'required|alpha_space|minLength:2|maxLength:100', 'email' => 'required|email|maxLength:255', 'message' => 'required|minLength:10|maxLength:5000'];
        $validation_errors = validate_data($form_submissions[$form_id], $rules); $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_CONTACT; $subject = "PAHAL Website Contact: " . $name;
            $body = "Contact form submission:\n\nName: {$name}\nEmail: {$email}\nIP: {$_SERVER['REMOTE_ADDR']}\nTime: ".date('Y-m-d H:i:s T')."\n\nMessage:\n{$message}\n\n-- End --";
            $logContext = "[Contact Form]";
            if (send_email($to, $subject, $body, $email, $name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$name}! Message sent. We'll reply soon."];
                log_message("{$logContext} Success. From: {$name} <{$email}>. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_CONTACT); $form_submissions[$form_id] = [];
            } else { $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$name}, error sending message. Please try again or use other contact methods."]; }
        } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix {$errorCount} error(s) below."]; log_message("[Contact Form] Validation failed: ".json_encode($validation_errors).". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR); }
        $_SESSION['scroll_to'] = '#contact';
    }

    // --- Process VOLUNTEER Sign-up Form ---
    elseif ($submitted_form_id === 'volunteer_form') {
        $form_id = 'volunteer_form'; $form_errors[$form_id] = [];
        // Sanitize & Store
        $v_name = sanitize_string($_POST['volunteer_name'] ?? ''); $v_email = sanitize_email($_POST['volunteer_email'] ?? ''); $v_phone = sanitize_string($_POST['volunteer_phone'] ?? ''); $v_area = sanitize_string($_POST['volunteer_area'] ?? ''); $v_avail = sanitize_string($_POST['volunteer_availability'] ?? ''); $v_msg = sanitize_string($_POST['volunteer_message'] ?? '');
        $form_submissions[$form_id] = ['volunteer_name' => $v_name, 'volunteer_email' => $v_email, 'volunteer_phone' => $v_phone, 'volunteer_area' => $v_area, 'volunteer_availability' => $v_avail, 'volunteer_message' => $v_msg];
        // Validate
        $rules = ['volunteer_name' => 'required|alpha_space|minLength:2|maxLength:100', 'volunteer_email' => 'required_without:volunteer_phone|email|maxLength:255', 'volunteer_phone' => 'required_without:volunteer_email|phone|maxLength:20', 'volunteer_area' => 'required|maxLength:100', 'volunteer_availability' => 'required|maxLength:200', 'volunteer_message' => 'maxLength:2000'];
        $validation_errors = validate_data($form_submissions[$form_id], $rules); $form_errors[$form_id] = $validation_errors;

        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_VOLUNTEER; $subject = "PAHAL: New Volunteer Sign-up - " . $v_name;
            $body = "New volunteer interest:\n\nName: {$v_name}\nEmail: ".($v_email ?: 'N/A')."\nPhone: ".($v_phone ?: 'N/A')."\nArea: {$v_area}\nAvailability: {$v_avail}\nIP: {$_SERVER['REMOTE_ADDR']}\nTime: ".date('Y-m-d H:i:s T')."\n\nMessage:\n".($v_msg ?: '(None)')."\n\n-- Follow Up Required --";
            $logContext = "[Volunteer Form]";
            if (send_email($to, $subject, $body, $v_email, $v_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$v_name}! Info received. We'll contact you soon about opportunities."];
                log_message("{$logContext} Success. From: {$v_name}, Area: {$v_area}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_VOLUNTEER); $form_submissions[$form_id] = [];
            } else { $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$v_name}, error submitting interest. Please try again or contact us."]; }
        } else { $errorCount = count($validation_errors); $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix {$errorCount} error(s) below."]; log_message("{$logContext} Validation failed: ".json_encode($validation_errors).". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR); }
        $_SESSION['scroll_to'] = '#volunteer-section';
    }

    // --- Post-Processing & Redirect ---
     unset($_SESSION[CSRF_TOKEN_NAME]); $csrf_token = generate_csrf_token();
     $_SESSION['form_messages'] = $form_messages; $_SESSION['form_errors'] = $form_errors;
     if (!empty($form_errors[$submitted_form_id ?? ''])) { $_SESSION['form_submissions'] = $form_submissions; } else { unset($_SESSION['form_submissions']); }
     $scrollTarget = $_SESSION['scroll_to'] ?? ''; unset($_SESSION['scroll_to']);
     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget, true, 303); exit;

} else {
    // --- GET Request: Retrieve session data ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = []; }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = []; }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = []; }
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML Template ---
// Contact Form
$contact_form_name_value = get_form_value('contact_form', 'name');
$contact_form_email_value = get_form_value('contact_form', 'email');
$contact_form_message_value = get_form_value('contact_form', 'message');
// Volunteer Form
$volunteer_form_name_value = get_form_value('volunteer_form', 'volunteer_name');
$volunteer_form_email_value = get_form_value('volunteer_form', 'volunteer_email');
$volunteer_form_phone_value = get_form_value('volunteer_form', 'volunteer_phone');
$volunteer_form_area_value = get_form_value('volunteer_form', 'volunteer_area');
$volunteer_form_availability_value = get_form_value('volunteer_form', 'volunteer_availability');
$volunteer_form_message_value = get_form_value('volunteer_form', 'volunteer_message');

// --- Dummy Data for New Sections (Keep Existing) ---
$news_items = [ /* ... as before ... */ ];
$gallery_images = [ /* ... as before ... */ ];
$associates = [ /* ... as before ... */ ];

?>
<!DOCTYPE html>
<!-- Add class="dark" dynamically via JS based on preference -->
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff"> <!-- Light theme color -->
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111827"> <!-- Dark theme color -->

    <!-- Open Graph / Social Media Meta Tags -->
    <!-- ... (Keep existing OG/Twitter tags, update URLs/images) ... -->
    <meta property="og:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/pahal-og.jpg"> <!-- CHANGE -->
    <meta name="twitter:url" content="https://your-pahal-domain.com/"> <!-- CHANGE -->
    <meta name="twitter:image" content="https://your-pahal-domain.com/images/pahal-twitter.jpg"> <!-- CHANGE -->

    <!-- Favicon -->
    <!-- ... (Keep existing favicon links) ... -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">


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
        // Tailwind Config (Enhanced)
        tailwind.config = {
            darkMode: 'class', // REQUIRED for theme toggle
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'], // Primary font
                        heading: ['Poppins', 'sans-serif'], // Headings font
                        mono: ['Fira Code', 'monospace'], // Monospace font
                    },
                    colors: {
                        // Define theme colors accessible via CSS variables AND tailwind classes
                        'theme-primary': 'var(--color-primary)',
                        'theme-secondary': 'var(--color-secondary)',
                        'theme-accent': 'var(--color-accent)',
                        'theme-success': 'var(--color-success)',
                        'theme-warning': 'var(--color-warning)',
                        'theme-info': 'var(--color-info)',
                        'theme-bg': 'var(--color-bg)',
                        'theme-surface': 'var(--color-surface)', // Card/panel backgrounds
                        'theme-surface-alt': 'var(--color-surface-alt)', // Alternate surface (e.g., light gray)
                        'theme-text': 'var(--color-text)', // Main text
                        'theme-text-muted': 'var(--color-text-muted)', // Subdued text
                        'theme-text-heading': 'var(--color-text-heading)', // Heading text
                        'theme-border': 'var(--color-border)', // Borders
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out forwards',
                        'fade-in-down': 'fadeInDown 0.7s ease-out forwards',
                        'fade-in-up': 'fadeInUp 0.7s ease-out forwards',
                        'slide-in-left': 'slideInLeft 0.7s ease-out forwards',
                        'slide-in-right': 'slideInRight 0.7s ease-out forwards',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'form-message-in': 'formMessageIn 0.5s ease-out forwards',
                        'bounce-subtle': 'bounceSubtle 2s infinite ease-in-out',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInDown: { '0%': { opacity: '0', transform: 'translateY(-20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideInLeft: { '0%': { opacity: '0', transform: 'translateX(-30px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        slideInRight: { '0%': { opacity: '0', transform: 'translateX(30px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        formMessageIn: { '0%': { opacity: '0', transform: 'translateY(10px) scale(0.98)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
                        bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                    },
                    boxShadow: {
                        'card': '0 5px 15px rgba(0, 0, 0, 0.08)', // Softer shadow for light mode
                        'card-dark': '0 6px 20px rgba(0, 0, 0, 0.25)',
                        'input-focus': '0 0 0 3px var(--color-primary-focus)', // Focus ring variable
                    },
                    container: {
                      center: true,
                      padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' }, // Responsive padding
                      screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1280px', /* No need for 1140 */},
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Theme Variables */
        :root { /* Light Theme */
          --color-primary: #047857; /* Emerald 700 */
          --color-secondary: #4f46e5; /* Indigo 600 */
          --color-accent: #be123c; /* Rose 700 */
          --color-success: #16a34a; /* Green 600 */
          --color-warning: #f59e0b; /* Amber 500 */
          --color-info: #0ea5e9; /* Sky 500 */
          --color-bg: #f9fafb; /* Gray 50 */
          --color-surface: #ffffff;
          --color-surface-alt: #f3f4f6; /* Gray 100 */
          --color-text: #1f2937; /* Gray 800 */
          --color-text-muted: #6b7280; /* Gray 500 */
          --color-text-heading: #111827; /* Gray 900 */
          --color-border: #e5e7eb; /* Gray 200 */
          --color-primary-focus: rgba(4, 120, 87, 0.3); /* For focus rings */
          --scrollbar-thumb: #a1a1aa; /* Zinc 400 */
          --scrollbar-track: #e4e4e7; /* Zinc 200 */
          color-scheme: light;
        }

        html.dark {
          --color-primary: #34d399; /* Emerald 400 */
          --color-secondary: #a78bfa; /* Violet 400 */
          --color-accent: #fb7185; /* Rose 400 */
          --color-success: #4ade80; /* Green 400 */
          --color-warning: #facc15; /* Yellow 400 */
          --color-info: #38bdf8; /* Sky 400 */
          --color-bg: #0f172a; /* Slate 900 */
          --color-surface: #1e293b; /* Slate 800 */
          --color-surface-alt: #334155; /* Slate 700 */
          --color-text: #e2e8f0; /* Slate 200 */
          --color-text-muted: #94a3b8; /* Slate 400 */
          --color-text-heading: #f1f5f9; /* Slate 100 */
          --color-border: #475569; /* Slate 600 */
          --color-primary-focus: rgba(52, 211, 153, 0.4);
          --scrollbar-thumb: #52525b; /* Zinc 600 */
          --scrollbar-track: #1e293b; /* Slate 800 */
          color-scheme: dark;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 5px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 5px; border: 2px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 80%, white); }


        @layer base {
            html { @apply scroll-smooth antialiased; }
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300; }
            h1, h2, h3, h4, h5, h6 { @apply font-heading font-semibold text-theme-text-heading tracking-tight leading-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-bold; } /* Adjusted h1 style */
            h2 { @apply text-3xl md:text-4xl font-bold mb-4; }
            h3 { @apply text-xl md:text-2xl font-bold text-theme-primary mb-3 mt-5; } /* Primary color for H3 */
            h4 { @apply text-lg font-semibold text-theme-secondary mb-2; } /* Secondary color for H4 */
            p { @apply mb-5 leading-relaxed text-base max-w-prose; } /* Max width for readability */
             a { @apply text-theme-secondary hover:text-theme-primary transition-colors duration-200; }
             a:not(.btn):not(.btn-secondary):not(.btn-outline) { /* Specificity for default links */
                @apply text-theme-secondary hover:text-theme-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-secondary/50 rounded;
             }
            hr { @apply border-theme-border/50 my-10; }
            blockquote { @apply border-l-4 border-theme-primary bg-theme-surface-alt p-4 my-6 italic text-theme-text-muted shadow-sm rounded-r-md;}
             blockquote cite { @apply block not-italic mt-2 text-sm text-theme-text-muted/80;}
             address { @apply not-italic;}
             /* Focus visible styles */
             *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-offset-theme-surface ring-theme-primary/70 rounded-md; }
             .honeypot-field { @apply !absolute !-left-[9999px] !w-px !h-px !overflow-hidden !opacity-0; }
        }

        @layer components {
            .section-padding { @apply py-16 md:py-20 lg:py-24 px-4; } /* Consistent section padding */
            .section-title { @apply text-center mb-12 md:mb-16 text-theme-primary; }
            .section-title-underline::after { content: ''; @apply block w-20 h-1 bg-theme-accent mx-auto mt-4 rounded-full; } /* Optional underline */

            .btn { @apply inline-flex items-center justify-center px-6 py-2.5 border border-transparent text-base font-medium rounded-md shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-theme-surface transition-all duration-200 transform hover:-translate-y-1 disabled:opacity-60 disabled:cursor-not-allowed; }
            .btn-primary { @apply text-white bg-theme-primary hover:bg-opacity-85 focus-visible:ring-theme-primary; } /* Primary action */
            .btn-secondary { @apply text-white bg-theme-secondary hover:bg-opacity-85 focus-visible:ring-theme-secondary; } /* Secondary action */
            .btn-accent { @apply text-white bg-theme-accent hover:bg-opacity-85 focus-visible:ring-theme-accent; } /* Accent/Danger */
            .btn-outline { @apply text-theme-primary border-2 border-current bg-transparent hover:bg-theme-primary/10 focus-visible:ring-theme-primary; }
             .btn-icon { @apply p-2 rounded-full focus-visible:ring-offset-0; } /* Icon buttons */
             .btn i, .btn-secondary i, .btn-outline i { @apply mr-2 text-sm; } /* Consistent icon spacing */

            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/60 overflow-hidden relative transition-all duration-300; }
            .card-hover:hover { @apply shadow-xl dark:shadow-2xl transform scale-[1.03] z-10; } /* Separate hover class */

            .panel { @apply bg-theme-surface/80 dark:bg-theme-surface/70 backdrop-blur-lg border border-theme-border/40 rounded-2xl shadow-lg p-6 md:p-8; } /* Glassmorphism */

            .form-label { @apply block mb-1.5 text-sm font-medium text-theme-text-muted; }
            .form-label.required::after { content: '*'; @apply text-theme-accent ml-0.5; }
            .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-surface/70 dark:bg-theme-surface/90 border-theme-border placeholder-theme-text-muted/70 text-theme-text shadow-sm transition duration-200 ease-in-out focus:border-theme-primary focus:ring-2 focus:ring-theme-primary/50 focus:outline-none disabled:opacity-60; }
             input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus, textarea:-webkit-autofill, textarea:-webkit-autofill:hover, textarea:-webkit-autofill:focus, select:-webkit-autofill, select:-webkit-autofill:hover, select:-webkit-autofill:focus {
                -webkit-text-fill-color: var(--color-text); -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; transition: background-color 5000s ease-in-out 0s;
             } /* Autofill styling */
             html.dark input:-webkit-autofill, html.dark input:-webkit-autofill:hover, html.dark input:-webkit-autofill:focus, html.dark textarea:-webkit-autofill, html.dark textarea:-webkit-autofill:hover, html.dark textarea:-webkit-autofill:focus, html.dark select:-webkit-autofill, html.dark select:-webkit-autofill:hover, html.dark select:-webkit-autofill:focus {
                 -webkit-text-fill-color: var(--color-text); -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset;
             }
            select.form-input { @apply pr-10 bg-no-repeat appearance-none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 0.75rem center; background-size: 1.25em 1.25em; }
             html.dark select.form-input { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); }
            textarea.form-input { @apply min-h-[120px] resize-vertical; }
            .form-input-error { @apply !border-theme-accent ring-2 ring-theme-accent/50 focus:!border-theme-accent focus:!ring-theme-accent/50; }
            .form-error-message { @apply text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium; }

             .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current; }

             .footer-link { @apply text-gray-400 hover:text-white hover:underline text-sm transition-colors duration-200; }
             footer ul li a { @apply footer-link; }
             footer address a { @apply footer-link hover:text-white; }
        }

        @layer utilities {
            /* Animation Delays */
             .delay-100 { animation-delay: 0.1s; } .delay-200 { animation-delay: 0.2s; } .delay-300 { animation-delay: 0.3s; } .delay-400 { animation-delay: 0.4s; } .delay-500 { animation-delay: 0.5s; }

             /* Animation on Scroll Classes */
             .animate-on-scroll { opacity: 0; transition: opacity 0.7s ease-out, transform 0.7s ease-out; }
             .animate-on-scroll.fade-in-up { transform: translateY(30px); }
             .animate-on-scroll.fade-in-left { transform: translateX(-40px); }
             .animate-on-scroll.fade-in-right { transform: translateX(40px); }
             .animate-on-scroll.is-visible { opacity: 1; transform: translate(0, 0); }
        }

        /* Specific Section Styles */
         #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/85 dark:bg-theme-bg/80 backdrop-blur-lg z-50 shadow-sm transition-all duration-300 border-b border-theme-border/30; min-height: 70px; }
         #main-header.scrolled { @apply shadow-md bg-theme-surface/95 dark:bg-theme-bg/90 border-theme-border/50; }
         body { @apply pt-[70px]; }

          /* Navigation adjustments */
          #navbar ul li a { @apply text-theme-text-muted hover:text-theme-primary dark:hover:text-theme-primary font-medium py-2 relative transition duration-300 ease-in-out text-sm lg:text-base block lg:inline-block lg:py-0 px-3 lg:px-0; }
          #navbar ul li a::after { content: ''; @apply absolute bottom-[-4px] left-0 w-0 h-0.5 bg-theme-primary transition-all duration-300 ease-in-out rounded-full; }
          #navbar ul li a:hover::after, #navbar ul li a.active::after { @apply w-full; }
          #navbar ul li a.active { @apply text-theme-primary font-semibold; } /* Active link style */

          /* Mobile menu toggle */
          .menu-toggle span { @apply block w-6 h-0.5 bg-theme-text rounded-sm transition-all duration-300 ease-in-out origin-center; } /* Use theme text color */
          .menu-toggle.active span:nth-child(1) { @apply rotate-45 translate-y-[6px]; } /* Adjusted transform */
          .menu-toggle.active span:nth-child(2) { @apply opacity-0 scale-x-0; }
          .menu-toggle.active span:nth-child(3) { @apply -rotate-45 translate-y-[-6px]; }

          /* Mobile Navbar container */
          #navbar { @apply w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-theme-surface dark:bg-theme-surface lg:bg-transparent shadow-lg lg:shadow-none lg:border-none border-t border-theme-border transition-all duration-500 ease-in-out; }
          #navbar.open { @apply block; } /* JS will toggle max-height */


         /* Hero Section */
         #hero {
             background: linear-gradient(rgba(0,0,0, 0.6), rgba(0,0,0, 0.7)), url('https://via.placeholder.com/1920x1080.png/111827/e5e7eb?text=Community+Action') no-repeat center center/cover;
             @apply text-white min-h-[calc(100vh-70px)] flex items-center py-20 relative overflow-hidden;
         }
         .hero-text h1 { @apply !text-white mb-6 drop-shadow-lg leading-tight; } /* Override heading color */
         .hero-logo img { @apply drop-shadow-xl animate-pulse-slow; }
         .hero-scroll-indicator { @apply absolute bottom-8 left-1/2 -translate-x-1/2 z-10 hidden md:block; }
         .hero-scroll-indicator a { @apply text-white/70 hover:text-white text-3xl animate-bounce-subtle; }

         /* Focus Areas */
         .focus-item { @apply card card-hover border-t-4 border-theme-primary bg-theme-surface p-6 md:p-8 text-center flex flex-col items-center; } /* Center content */
         .focus-item .icon { @apply text-5xl text-theme-primary mb-5 inline-block transition-transform duration-300 group-hover:scale-110; } /* Use primary color */
         .focus-item h3 { @apply text-xl text-theme-text-heading mb-3 transition-colors duration-300 group-hover:text-theme-primary; } /* Use heading text color */
         .focus-item p { @apply text-sm text-theme-text-muted leading-relaxed flex-grow mb-4 text-center; } /* Center text */
         .focus-item .read-more-link { @apply block text-sm font-semibold text-theme-secondary mt-auto opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:underline pt-2; }


         /* Objectives Section */
         .objective-item { @apply bg-theme-surface/60 dark:bg-theme-surface/80 p-5 rounded-lg shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:border-theme-primary border-l-4 border-transparent flex items-start space-x-4; }
          .objective-item i { @apply text-theme-primary group-hover:text-theme-accent transition-colors duration-300 flex-shrink-0 w-6 text-center text-xl; } /* Adjusted icon size/color */
          .objective-item p { @apply text-base leading-snug mb-0; } /* Removed margin bottom */
          .objective-item p strong { @apply font-semibold text-theme-text-heading; }


         /* News Section */
         #news-section .news-card { @apply card card-hover flex flex-col; }
         #news-section .news-card img { @apply w-full h-48 object-cover; } /* Removed group hover on img */
         #news-section .news-card .news-content { @apply p-5 flex flex-col flex-grow; }
         #news-section .news-card .date { @apply text-xs text-theme-text-muted block mb-1.5; }
         #news-section .news-card h4 { @apply text-lg text-theme-text-heading group-hover:text-theme-primary mb-2 leading-snug transition-colors duration-200; }
         #news-section .news-card p { @apply text-sm text-theme-text-muted leading-relaxed mb-4 flex-grow; }
         #news-section .news-card .read-more-action { @apply mt-auto pt-3 border-t border-theme-border/50; }
         #news-section .news-card .read-more-action a { @apply !text-sm !py-1.5 !px-4 !border-theme-secondary !text-theme-secondary hover:!bg-theme-secondary/10; } /* Outline secondary */


         /* Volunteer/Donate Sections */
          #volunteer-section { @apply bg-gradient-to-br from-theme-secondary to-indigo-700 dark:from-theme-secondary dark:to-indigo-900 text-white; }
          #donate-section { @apply bg-gradient-to-br from-theme-primary to-emerald-700 dark:from-theme-primary dark:to-emerald-900 text-white; }
          #volunteer-section .section-title, #donate-section .section-title { @apply !text-white; }
          #volunteer-section .section-title::after, #donate-section .section-title::after { @apply !bg-white/70; }
          #volunteer-section .form-label, #donate-section label { @apply !text-indigo-100 dark:!text-violet-100; } /* Lighter label */
          #volunteer-section .form-input { @apply !bg-white/10 !border-indigo-300/50 !text-white placeholder:!text-indigo-200/70 focus:!bg-white/20 focus:!border-white; } /* Inputs for volunteer */


         /* Gallery */
         .gallery-item img { @apply transition-all duration-300 ease-in-out group-hover:scale-105 group-hover:brightness-110; }

         /* Associates */
         .associate-logo img { @apply filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-70 group-hover:opacity-100; }
          .associate-logo p { @apply text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors; }


         /* Contact Section */
          #contact .contact-info-item { @apply flex items-start gap-4 mb-5; }
          #contact .contact-info-item i { @apply text-xl text-theme-primary mt-1 w-6 text-center flex-shrink-0; }
          #contact .contact-info-item span { @apply font-semibold text-theme-text-heading block text-sm; }
          #contact .contact-info-item a, #contact .contact-info-item div > span:last-child { @apply text-theme-text-muted text-base; }
          #contact .social-icons a { @apply text-theme-text-muted text-2xl transition duration-300 hover:scale-110; }
          #contact .social-icons a:hover { /* Specific hover colors */
              color: #1877F2; /* Facebook */
          }
          #contact .social-icons a[href*="instagram"]:hover { color: #E1306C; }
          #contact .social-icons a[href*="twitter"]:hover { color: #1DA1F2; }
          #contact .social-icons a[href*="linkedin"]:hover { color: #0A66C2; }
          #contact .registration-info { @apply bg-theme-surface-alt dark:bg-theme-surface/50 p-4 rounded-md border border-theme-border text-xs text-theme-text-muted mt-8; }
          #contact .registration-info i { @apply text-theme-primary text-sm; }

         /* Footer */
          footer { @apply bg-gray-900 dark:bg-black text-gray-400 pt-16 pb-8 mt-0 border-t-4 border-theme-primary dark:border-theme-primary; }
          footer h4 { @apply text-lg font-semibold text-white mb-4 relative pb-2; }
          footer h4::after { @apply content-[''] absolute bottom-0 left-0 w-12 h-0.5 bg-theme-primary rounded-full; } /* Underline for footer headings */
          footer .footer-bottom { @apply border-t border-gray-700 pt-8 mt-12 text-center text-sm text-gray-500; }

         /* Back to Top */
         #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-theme-primary text-white shadow-lg hover:bg-opacity-85 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95; }
         #back-to-top.visible { @apply opacity-100 visible; } /* Visibility class */

         /* Modal Styles */
         #bank-details-modal { /* Basic modal styling */
            @apply fixed inset-0 bg-black/60 dark:bg-black/75 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm;
         }
         #bank-details-modal > div { /* Modal content box */
            @apply bg-theme-surface rounded-lg shadow-xl p-6 md:p-8 w-full max-w-md text-left relative transform transition-all duration-300 scale-95 opacity-0;
         }
         #bank-details-modal.flex > div { /* Show animation */
             @apply scale-100 opacity-100;
         }
         #bank-details-modal h3 { @apply !text-theme-primary !mt-0; }
         #bank-details-modal .close-button { @apply absolute top-3 right-3 text-theme-text-muted hover:text-theme-accent p-1 rounded-full transition-colors; }

    </style>

    <!-- Schema.org JSON-LD -->
    <!-- ... (Keep existing Schema.org script) ... -->
     <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "NGO",
      "name": "PAHAL NGO",
      "alternateName": "PAHAL",
      "url": "https://your-pahal-domain.com/", // CHANGE
      "logo": "https://your-pahal-domain.com/icon.webp", // CHANGE
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
<body class="bg-theme-bg text-theme-text font-sans">

<!-- Header -->
<!-- ... (Keep the enhanced <header> structure) ... -->
<header id="main-header" class="py-2 md:py-0">
    <div class="container mx-auto flex flex-wrap items-center justify-between">
         <!-- Logo -->
        <div class="logo flex-shrink-0 py-2">
             <a href="#hero" aria-label="PAHAL NGO Home" class="text-3xl md:text-4xl font-black text-theme-accent dark:text-red-400 font-heading leading-none flex items-center transition-opacity hover:opacity-80">
                <img src="icon.webp" alt="" class="h-9 w-9 mr-2 inline object-contain" aria-hidden="true"> <!-- Ensure icon.webp exists -->
                PAHAL
             </a>
             <p class="text-xs text-theme-text-muted italic ml-11 -mt-1.5 hidden sm:block">An Endeavour for a Better Tomorrow</p>
        </div>

        <!-- Mobile Menu Toggle -->
        <button id="mobile-menu-toggle" aria-label="Toggle Menu" aria-expanded="false" aria-controls="navbar" class="menu-toggle lg:hidden p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-theme-primary rounded">
            <span class="sr-only">Open main menu</span>
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- Navigation -->
        <nav id="navbar" aria-label="Main Navigation" class="navbar-container"> <!-- Add class for JS targeting -->
            <ul class="flex flex-col lg:flex-row lg:items-center lg:space-x-5 xl:space-x-6 py-4 lg:py-0 px-4 lg:px-0">
                <li><a href="#hero" class="nav-link active">Home</a></li>
                <li><a href="#profile" class="nav-link">Profile</a></li>
                <li><a href="#objectives" class="nav-link">Objectives</a></li>
                <li><a href="#areas-focus" class="nav-link">Focus Areas</a></li>
                <li><a href="#news-section" class="nav-link">News</a></li>
                <li><a href="#volunteer-section" class="nav-link">Get Involved</a></li>
                 <li><a href="blood-donation.php" class="nav-link">Blood Drive</a></li>
                 <li><a href="e-waste.php" class="nav-link">E-Waste</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
                 <li> <!-- Theme Toggle in Nav for Mobile -->
                     <button id="theme-toggle" type="button" title="Toggle theme" class="btn-icon text-theme-text-muted hover:text-theme-primary hover:bg-theme-primary/10 transition-colors duration-200 ml-2">
                         <i class="fas fa-moon text-lg" id="theme-toggle-dark-icon"></i>
                         <i class="fas fa-sun text-lg hidden" id="theme-toggle-light-icon"></i>
                     </button>
                </li>
            </ul>
        </nav>
    </div>
</header>

<main>
    <!-- Hero Section -->
    <!-- ... (Keep the enhanced Hero structure) ... -->
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
                 <a href="#volunteer-section" class="btn btn-primary text-base md:text-lg"><i class="fas fa-hands-helping"></i>Get Involved</a>
              </div>
            </div>
            <div class="hero-logo order-1 lg:order-2 flex-shrink-0 w-[180px] lg:w-auto animate-on-scroll fade-in-right delay-200">
                 <img src="icon.webp" alt="PAHAL NGO Large Logo Icon" class="mx-auto w-36 h-36 md:w-48 md:h-48 lg:w-60 lg:h-60 rounded-full shadow-2xl bg-white/20 p-2 backdrop-blur-sm">
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
    <!-- ... (Keep the enhanced Profile structure) ... -->
    <section id="profile" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto animate-on-scroll fade-in-up">
             <h2 class="section-title section-title-underline">Our Profile & Vision</h2>
             <div class="grid md:grid-cols-5 gap-12 items-center mt-16">
                 <div class="md:col-span-3 profile-text">
                    <h3 class="text-2xl mb-4 !text-theme-text-heading">Who We Are</h3>
                    <p class="mb-6 text-theme-text-muted text-lg">'PAHAL' (Initiative) stands as a testament to collective action. We are a dynamic, volunteer-led youth organization conceived by a confluence of inspired minds—Educationists, Doctors, Legal Professionals, Technologists, Entrepreneurs, and passionate Students—all driven by a singular vision: to catalyze perceptible, positive transformation within our social fabric.</p>
                     <blockquote class="border-l-4 border-theme-secondary dark:border-theme-secondary bg-theme-surface dark:bg-theme-surface-alt p-5 my-8 shadow-sm rounded-r-lg relative">
                        <i class="fas fa-quote-left text-theme-secondary text-2xl absolute -top-3 -left-3 opacity-30"></i>
                        <p class="italic font-semibold text-theme-secondary text-xl text-center">"PAHAL is an endeavour for a Better Tomorrow"</p>
                     </blockquote>
                    <h3 class="text-2xl mb-4 mt-10 !text-theme-text-heading">Our Core Vision</h3>
                     <p class="text-theme-text-muted text-lg">We aim to cultivate <strong class="text-theme-primary font-semibold">Holistic Personality Development</strong> by motivating active participation in <strong class="text-theme-primary font-semibold">humanitarian service</strong>. PAHAL endeavours to stimulate social consciousness, offering tangible platforms for individuals to engage <strong class="text-theme-primary font-semibold">creatively and constructively</strong> with global and local communities, thereby building a more compassionate and equitable world.</p>
                 </div>
                 <div class="md:col-span-2 profile-image animate-on-scroll fade-in-right delay-200">
                    <img src="https://via.placeholder.com/500x600.png/047857/f9fafb?text=PAHAL+Vision" alt="PAHAL NGO team engaging in a community activity" class="rounded-lg shadow-xl mx-auto w-full object-cover h-full max-h-[500px]">
                </div>
             </div>
        </div>
    </section>

    <!-- Objectives Section -->
    <!-- ... (Keep the enhanced Objectives structure, update classes) ... -->
    <section id="objectives" class="section-padding">
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Our Guiding Objectives</h2>
             <div class="max-w-6xl mx-auto grid md:grid-cols-2 lg:grid-cols-3 gap-6 mt-16">
                 <!-- Objective Item Structure -->
                 <div class="objective-item group animate-on-scroll fade-in-up">
                     <i class="fas fa-users"></i> <p>To collaborate genuinely <strong>with and among the people</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-100">
                     <i class="fas fa-people-carry"></i> <p>To engage in <strong>creative & constructive social action</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-200">
                     <i class="fas fa-lightbulb"></i> <p>To enhance knowledge of <strong>self & community realities</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-300">
                     <i class="fas fa-seedling"></i> <p>To apply scholarship for <strong>mitigating social problems</strong>.</p>
                 </div>
                 <div class="objective-item group animate-on-scroll fade-in-up delay-400">
                     <i class="fas fa-tools"></i> <p>To gain and apply skills in <strong>humanity development</strong>.</p>
                 </div>
                  <div class="objective-item group animate-on-scroll fade-in-up delay-500">
                     <i class="fas fa-recycle"></i> <p>To promote <strong>sustainable practices</strong> & awareness.</p>
                 </div>
            </div>
        </div>
    </section>

    <!-- Areas of Focus Section -->
    <!-- ... (Keep the enhanced Focus Areas structure, update classes) ... -->
    <section id="areas-focus" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Key Focus Areas</h2>
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 mt-16">
                 <!-- Health -->
                 <a href="blood-donation.php" title="Explore Health Initiatives" class="focus-item group animate-on-scroll fade-in-up card-hover">
                     <span class="icon"><i class="fas fa-heart-pulse"></i></span> <h3>Health & Wellness</h3>
                     <p>Prioritizing community well-being via awareness campaigns, blood drives, and promoting healthy lifestyles.</p>
                     <span class="read-more-link">Blood Donation Program <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>
                 <!-- Education -->
                 <div class="focus-item group animate-on-scroll fade-in-up delay-100 card-hover">
                     <span class="icon"><i class="fas fa-user-graduate"></i></span> <h3>Education & Skilling</h3>
                     <p>Empowering youth by fostering ethical foundations, essential life skills, and professional readiness.</p>
                     <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon <i class="fas fa-clock ml-1"></i></span>
                  </div>
                 <!-- Environment -->
                 <a href="e-waste.php" title="Learn about E-waste Recycling" class="focus-item group animate-on-scroll fade-in-up delay-200 card-hover">
                      <span class="icon"><i class="fas fa-leaf"></i></span> <h3>Environment</h3>
                      <p>Championing environmental stewardship through plantation drives, waste management, and e-waste recycling.</p>
                      <span class="read-more-link">E-Waste Program <i class="fas fa-arrow-right ml-1"></i></span>
                 </a>
                 <!-- Communication -->
                 <div class="focus-item group animate-on-scroll fade-in-up delay-300 card-hover">
                     <span class="icon"><i class="fas fa-comments"></i></span> <h3>Communication Skills</h3>
                     <p>Enhancing verbal, non-verbal, and presentation abilities in youth via interactive programs.</p>
                      <span class="read-more-link opacity-50 cursor-not-allowed">Details Soon <i class="fas fa-clock ml-1"></i></span>
                 </div>
             </div>
        </div>
    </section>

    <!-- How to Join / Get Involved Section -->
    <!-- ... (Keep the enhanced Volunteer structure, update classes) ... -->
    <section id="volunteer-section" class="section-padding text-white">
        <div class="container mx-auto">
             <h2 class="section-title !text-white section-title-underline after:!bg-theme-accent">Join the PAHAL Movement</h2>
            <div class="grid lg:grid-cols-2 gap-12 items-center mt-16">
                <!-- Info Text -->
                <div class="text-center lg:text-left animate-on-scroll fade-in-left">
                    <h3 class="text-3xl lg:text-4xl font-bold mb-4 text-white leading-snug">Make a Difference, Volunteer With Us</h3>
                    <p class="text-gray-200 dark:text-gray-300 max-w-3xl mx-auto lg:mx-0 mb-6 text-lg leading-relaxed">PAHAL welcomes passionate individuals, students, and organizations eager to contribute to community betterment. Your time, skills, and dedication are invaluable assets.</p>
                    <p class="text-gray-200 dark:text-gray-300 max-w-3xl mx-auto lg:mx-0 mb-8 text-lg leading-relaxed">Volunteering offers a rewarding experience: develop skills, network, and directly impact lives. Express your interest below!</p>
                     <div class="mt-10 flex flex-wrap justify-center lg:justify-start gap-4">
                         <a href="#contact" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-theme-secondary"><i class="fas fa-phone-alt"></i>Contact Directly</a>
                         <!-- <a href="volunteer-opportunities.php" class="btn !bg-white !text-theme-secondary hover:!bg-gray-100"><i class="fas fa-list-alt"></i>View Opportunities</a> -->
                     </div>
                 </div>
                 <!-- Volunteer Sign-up Form -->
                 <div class="panel !bg-black/20 dark:!bg-black/30 !border-theme-border/20 animate-on-scroll fade-in-right delay-100">
                     <h3 class="text-2xl mb-6 text-white font-semibold text-center">Register Your Volunteer Interest</h3>
                     <?= get_form_status_html('volunteer_form') ?>
                    <form id="volunteer-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#volunteer-section" method="POST" class="space-y-5">
                         <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                         <input type="hidden" name="form_id" value="volunteer_form">
                         <div class="honeypot-field" aria-hidden="true"><label for="website_url_volunteer">Do not fill</label><input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>
                         <div><label for="volunteer_name" class="form-label !text-gray-200 required">Full Name</label><input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="form-input !bg-white/5 !border-gray-400/50 !text-white placeholder:!text-gray-300/60 focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>><?= get_field_error_html('volunteer_form', 'volunteer_name') ?></div>
                         <div class="grid md:grid-cols-2 gap-5">
                            <div><label for="volunteer_email" class="form-label !text-gray-200">Email</label><input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="form-input !bg-white/5 !border-gray-400/50 !text-white placeholder:!text-gray-300/60 focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>><?= get_field_error_html('volunteer_form', 'volunteer_email') ?></div>
                            <div><label for="volunteer_phone" class="form-label !text-gray-200">Phone</label><input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="form-input !bg-white/5 !border-gray-400/50 !text-white placeholder:!text-gray-300/60 focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>><?= get_field_error_html('volunteer_form', 'volunteer_phone') ?></div>
                         </div>
                          <p class="text-xs text-gray-300 -mt-3" id="volunteer_contact_note">Provide Email or Phone.</p>
                         <div><label for="volunteer_area" class="form-label !text-gray-200 required">Area of Interest</label><select id="volunteer_area" name="volunteer_area" required class="form-input !bg-white/5 !border-gray-400/50 !text-white focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>><option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : ''?>>-- Select --</option><option value="Health" <?= $volunteer_form_area_value == 'Health' ? 'selected' : ''?>>Health</option><option value="Education" <?= $volunteer_form_area_value == 'Education' ? 'selected' : ''?>>Education</option><option value="Environment" <?= $volunteer_form_area_value == 'Environment' ? 'selected' : ''?>>Environment</option><option value="Communication" <?= $volunteer_form_area_value == 'Communication' ? 'selected' : ''?>>Communication</option><option value="Events" <?= $volunteer_form_area_value == 'Events' ? 'selected' : ''?>>Events</option><option value="Blood Drive" <?= $volunteer_form_area_value == 'Blood Drive' ? 'selected' : ''?>>Blood Drive</option><option value="E-Waste" <?= $volunteer_form_area_value == 'E-Waste' ? 'selected' : ''?>>E-Waste</option><option value="Admin" <?= $volunteer_form_area_value == 'Admin' ? 'selected' : ''?>>Admin Support</option><option value="Other" <?= $volunteer_form_area_value == 'Other' ? 'selected' : ''?>>Other</option></select><?= get_field_error_html('volunteer_form', 'volunteer_area') ?></div>
                         <div><label for="volunteer_availability" class="form-label !text-gray-200 required">Availability</label><input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="form-input !bg-white/5 !border-gray-400/50 !text-white placeholder:!text-gray-300/60 focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>><?= get_field_error_html('volunteer_form', 'volunteer_availability') ?></div>
                         <div><label for="volunteer_message" class="form-label !text-gray-200">Message (Optional)</label><textarea id="volunteer_message" name="volunteer_message" rows="3" class="form-input !bg-white/5 !border-gray-400/50 !text-white placeholder:!text-gray-300/60 focus:!bg-white/10 focus:!border-white <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Your motivation or skills..." <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea><?= get_field_error_html('volunteer_form', 'volunteer_message') ?></div>
                         <button type="submit" class="btn btn-accent w-full sm:w-auto"><span class="spinner hidden mr-2"></span><i class="fas fa-paper-plane"></i>Submit Volunteer Interest</button>
                    </form>
                 </div>
            </div>
        </div>
    </section>


    <!-- News & Events Section -->
    <!-- ... (Keep the enhanced News structure, update classes) ... -->
    <section id="news-section" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Latest News & Events</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-16">
                <?php if (!empty($news_items)): ?>
                    <?php foreach ($news_items as $index => $item): ?>
                    <div class="news-card group animate-on-scroll fade-in-up delay-<?= ($index * 100) ?>">
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="block aspect-[16/10] overflow-hidden" title="Read more about <?= htmlspecialchars($item['title']) ?>">
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="Image for <?= htmlspecialchars($item['title']) ?>" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                        </a>
                        <div class="news-content">
                             <span class="date"><i class="far fa-calendar-alt mr-1 opacity-70"></i><?= date('M j, Y', strtotime($item['date'])) ?></span>
                             <h4 class="my-2">
                                 <a href="<?= htmlspecialchars($item['link']) ?>" class="hover:underline group-hover:!text-theme-primary"><?= htmlspecialchars($item['title']) ?></a>
                             </h4>
                             <p><?= htmlspecialchars($item['excerpt']) ?></p>
                              <div class="read-more-action">
                                  <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-outline !text-sm !py-1 !px-3">Read More <i class="fas fa-arrow-right text-xs ml-1"></i></a>
                              </div>
                         </div>
                    </div>
                    <?php endforeach; ?>
                 <?php else: ?>
                     <p class="text-center text-theme-text-muted md:col-span-2 lg:col-span-3">No recent news items to display.</p>
                 <?php endif; ?>
            </div>
            <div class="text-center mt-12">
                <a href="/news-archive.php" class="btn btn-secondary"><i class="far fa-newspaper"></i>View News Archive</a>
            </div>
        </div>
    </section>

    <!-- Gallery Section -->
    <!-- ... (Keep the enhanced Gallery structure, update classes) ... -->
    <section id="gallery-section" class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Glimpses of Our Work</h2>
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4 mt-16">
                    <?php foreach ($gallery_images as $index => $image): ?>
                    <a href="<?= htmlspecialchars($image['src']) ?>" class="gallery-item block aspect-video rounded-lg overflow-hidden shadow-md group animate-on-scroll fade-in-up delay-<?= ($index * 50) ?>">
                         <img src="<?= htmlspecialchars($image['src']) ?>" alt="<?= htmlspecialchars($image['alt']) ?>" loading="lazy" class="w-full h-full object-cover transition-all duration-300 ease-in-out group-hover:scale-105 group-hover:brightness-110">
                     </a>
                    <?php endforeach; ?>
                </div>
                <p class="text-center mt-8 text-theme-text-muted italic">Click on images to view larger.</p>
            <?php else: ?>
                 <p class="text-center text-theme-text-muted">Gallery images are currently unavailable.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Associates Section -->
    <!-- ... (Keep the enhanced Associates structure, update classes) ... -->
    <section id="associates" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
            <h2 class="section-title section-title-underline">Our Valued Associates & Partners</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-theme-text-muted mb-16">Collaboration amplifies impact. We are deeply grateful for the support and partnership of these esteemed organizations.</p>
             <div class="flex flex-wrap justify-center items-center gap-x-10 md:gap-x-16 gap-y-8">
                <?php foreach ($associates as $index => $associate): ?>
                 <div class="associate-logo text-center group transform transition duration-300 hover:scale-110 animate-on-scroll fade-in-up delay-<?= ($index * 50) ?>">
                    <img src="<?= htmlspecialchars($associate['img']) ?>" alt="<?= htmlspecialchars($associate['name']) ?> Logo" class="max-h-16 md:max-h-20 w-auto mx-auto mb-2 filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-70 group-hover:opacity-100">
                    <p class="text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p>
                 </div>
                 <?php endforeach; ?>
            </div>
        </div>
    </section>

     <!-- Donation CTA Section -->
     <!-- ... (Keep the enhanced Donate structure, update classes) ... -->
     <section id="donate-section" class="section-padding text-center relative overflow-hidden"> <!-- Added relative overflow-hidden -->
         <!-- Background subtle pattern/effect -->
         <div class="absolute inset-0 bg-gradient-to-br from-theme-primary/5 to-transparent dark:from-theme-primary/10"></div>
         <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-theme-bg to-transparent z-0"></div> <!-- Fade edge -->

         <div class="container mx-auto relative z-10"> <!-- Content needs z-index -->
             <i class="fas fa-donate text-4xl text-white bg-theme-accent p-4 rounded-full shadow-lg mb-6 inline-block animate-bounce-subtle"></i>
             <h2 class="section-title !text-white section-title-underline after:!bg-white/70"><span class="drop-shadow-md">Support Our Initiatives</span></h2>
            <p class="text-gray-100 dark:text-gray-200 max-w-3xl mx-auto mb-8 text-lg leading-relaxed drop-shadow">Your generous contribution fuels our mission, enabling vital programs in health, education, and environmental protection within the Jalandhar community.</p>
            <p class="text-gray-200 dark:text-gray-300 bg-black/20 dark:bg-black/40 inline-block px-4 py-1.5 rounded-full text-sm font-semibold mb-10 backdrop-blur-sm border border-white/20">Donations are Tax Exempt under Sec 80G of the Income Tax Act.</p>
            <div class="space-y-4 sm:space-y-0 sm:space-x-6 flex flex-wrap justify-center items-center gap-4">
                 <a href="#contact" class="btn btn-secondary !bg-white !text-theme-primary hover:!bg-gray-100 shadow-xl"><i class="fas fa-info-circle"></i> Donation Inquiries</a>
                 <button type="button" class="btn btn-outline !border-white !text-white hover:!bg-white hover:!text-theme-primary shadow-xl" data-modal-target="bank-details-modal">
                     <i class="fas fa-university"></i>View Bank Details
                </button>
                 <!-- <a href="https://your-payment-gateway-link.com" target="_blank" rel="noopener noreferrer" class="btn btn-primary shadow-xl"><i class="fas fa-credit-card"></i>Donate Online Securely</a> -->
            </div>
        </div>
     </section>

    <!-- Contact Section -->
    <!-- ... (Keep the enhanced Contact structure, update classes) ... -->
    <section id="contact" class="section-padding bg-theme-surface-alt dark:bg-theme-surface/50">
        <div class="container mx-auto">
             <h2 class="section-title section-title-underline">Connect With Us</h2>
             <p class="text-center max-w-3xl mx-auto text-lg text-theme-text-muted mb-16">Whether you have questions, suggestions, partnership proposals, or just want to learn more, we encourage you to reach out. We're here to connect.</p>
             <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                 <!-- Contact Details & Map -->
                 <div class="lg:col-span-2 animate-on-scroll fade-in-left">
                     <h3 class="text-2xl mb-6 font-semibold !text-theme-text-heading">Contact Information</h3>
                     <div class="space-y-6 text-theme-text-muted text-base mb-10">
                         <!-- Address -->
                        <div class="contact-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div><span>Our Office:</span> 36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008, India</div>
                        </div>
                        <!-- Phones -->
                        <div class="contact-info-item">
                            <i class="fas fa-phone-alt"></i>
                            <div><span>Phone Lines:</span> <a href="tel:+911812672784">Office: +91 181-267-2784</a><br><a href="tel:+919855614230">Mobile: +91 98556-14230</a></div>
                        </div>
                        <!-- Email -->
                        <div class="contact-info-item">
                             <i class="fas fa-envelope"></i>
                             <div><span>Email Us:</span> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></div>
                        </div>
                     </div>

                    <!-- Social Media -->
                    <div class="mb-10 pt-8 border-t border-theme-border/50">
                         <h4 class="text-lg font-semibold text-theme-secondary mb-4">Follow Our Journey</h4>
                         <div class="flex space-x-5 social-icons">
                             <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" aria-label="PAHAL on Instagram" title="Instagram"><i class="fab fa-instagram"></i></a>
                             <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                             <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter"><i class="fab fa-twitter"></i></a>
                             <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                         </div>
                    </div>

                     <!-- Embedded Map -->
                     <div class="mb-10 pt-8 border-t border-theme-border/50">
                         <h4 class="text-lg font-semibold text-theme-secondary mb-4">Visit Us</h4>
                        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3407.758638397537!2d75.5988858150772!3d31.33949238143149!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391a5b4422dab0c5%3A0xe88f5c48cfc1a3d3!2sPahal%20NGO!5e0!3m2!1sen!2sin!4v1678886655444!5m2!1sen!2sin"
                             width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="PAHAL NGO Location Map" class="rounded-lg shadow-md border border-theme-border/50">
                         </iframe>
                     </div>

                     <!-- Reg Info -->
                    <div class="registration-info">
                         <h4 class="text-sm font-semibold text-theme-primary dark:text-theme-primary mb-2">Registration & Compliance</h4>
                        <p><i class="fas fa-certificate"></i> Societies Registration Act XXI, 1860 (Reg. No.: 737)</p>
                        <p><i class="fas fa-certificate"></i> Section 12-A, Income Tax Act, 1961</p>
                        <p><i class="fas fa-donate"></i> Section 80G Tax Exemption Certified</p>
                     </div>
                 </div>

                <!-- Contact Form -->
                <div class="lg:col-span-3 panel !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50 animate-on-scroll fade-in-right delay-100">
                    <h3 class="text-2xl mb-8 font-semibold !text-theme-text-heading text-center lg:text-left">Send Us a Message Directly</h3>
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                        <input type="hidden" name="form_id" value="contact_form">
                        <div class="honeypot-field" aria-hidden="true"><label for="website_url_contact">Do not fill</label><input type="text" id="website_url_contact" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>
                        <div><label for="contact_name" class="form-label required">Your Name</label><input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="<?= get_field_error_class('contact_form', 'name') ?>" aria-required="true" placeholder="e.g., Jane Doe" <?= get_aria_describedby('contact_form', 'name') ?>><?= get_field_error_html('contact_form', 'name') ?></div>
                        <div><label for="contact_email" class="form-label required">Your Email</label><input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="<?= get_field_error_class('contact_form', 'email') ?>" aria-required="true" placeholder="e.g., jane.doe@example.com" <?= get_aria_describedby('contact_form', 'email') ?>><?= get_field_error_html('contact_form', 'email') ?></div>
                        <div><label for="contact_message" class="form-label required">Your Message</label><textarea id="contact_message" name="message" rows="5" required class="<?= get_field_error_class('contact_form', 'message') ?>" aria-required="true" placeholder="Share your thoughts, questions, or feedback..." <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea><?= get_field_error_html('contact_form', 'message') ?></div>
                        <button type="submit" class="btn btn-primary w-full sm:w-auto" id="contact-submit-button">
                            <span class="button-text flex items-center"><i class="fas fa-paper-plane mr-2"></i>Send Message</span>
                            <span class="spinner hidden ml-2"></span>
                        </button>
                    </form>
                 </div>
            </div>
        </div>
    </section>

    <!-- Donation Modal -->
    <!-- ... (Keep the enhanced Modal structure) ... -->
     <div id="bank-details-modal" class="modal-container" aria-labelledby="modal-title" role="dialog" aria-modal="true">
      <div class="modal-box">
         <button type="button" class="close-button" aria-label="Close modal" data-modal-close="bank-details-modal">
           <i class="fas fa-times"></i>
         </button>
         <h3 id="modal-title" class="text-xl font-semibold text-theme-primary mb-4">Bank Transfer Details</h3>
        <p class="text-sm text-theme-text-muted mb-4">Use the following details for direct bank transfers. Please mention "Donation" in the transfer description.</p>
         <div class="space-y-2 text-sm bg-theme-surface-alt dark:bg-theme-surface/50 p-4 rounded-md border border-theme-border">
            <p><strong>Account Name:</strong> PAHAL (Regd.)</p>
            <p><strong>Account Number:</strong> [YOUR_BANK_ACCOUNT_NUMBER]</p> <!-- REPLACE -->
             <p><strong>Bank Name:</strong> [YOUR_BANK_NAME]</p> <!-- REPLACE -->
             <p><strong>Branch:</strong> [YOUR_BANK_BRANCH]</p> <!-- REPLACE -->
             <p><strong>IFSC Code:</strong> [YOUR_IFSC_CODE]</p> <!-- REPLACE -->
        </div>
        <p class="text-xs text-theme-text-muted mt-4">For donation queries or receipts, contact us. Thank you!</p>
      </div>
    </div>

</main>

<!-- Footer -->
<!-- ... (Keep the enhanced <footer> structure) ... -->
<footer class="bg-gray-900 dark:bg-black text-gray-400 pt-16 pb-8 mt-0 border-t-4 border-theme-primary dark:border-theme-primary">
    <div class="container mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12 text-center md:text-left">
            <!-- Footer About -->
            <div>
                <h4 class="footer-heading">About PAHAL</h4>
                <a href="#hero" class="inline-block mb-3">
                  <img src="icon.webp" alt="PAHAL Footer Icon" class="w-14 h-14 rounded-full bg-white p-1 shadow-md mx-auto md:mx-0">
                </a>
                <p class="footer-text">Jalandhar-based non-profit youth organization fostering holistic growth & community service since [Year].</p>
                 <p class="text-xs text-gray-500">Reg No: 737 | 80G & 12A Certified</p>
                 <div class="mt-4 flex justify-center md:justify-start space-x-4">
                     <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram" class="footer-social-icon"><i class="fab fa-instagram"></i></a>
                     <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook" class="footer-social-icon"><i class="fab fa-facebook-f"></i></a>
                     <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" aria-label="Twitter" title="Twitter" class="footer-social-icon"><i class="fab fa-twitter"></i></a>
                     <a href="https://www.linkedin.com/company/pahal-ngo/" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn" class="footer-social-icon"><i class="fab fa-linkedin-in"></i></a>
                 </div>
            </div>

             <!-- Footer Quick Links -->
             <div>
                 <h4 class="footer-heading">Explore</h4>
                 <ul class="space-y-2 text-sm columns-2 md:columns-1">
                     <li><a href="#profile">Profile</a></li>
                     <li><a href="#objectives">Objectives</a></li>
                     <li><a href="#areas-focus">Focus Areas</a></li>
                     <li><a href="#news-section">News</a></li>
                     <li><a href="blood-donation.php">Blood Drive</a></li>
                     <li><a href="e-waste.php">E-Waste</a></li>
                     <li><a href="#volunteer-section">Volunteer</a></li>
                     <li><a href="#donate-section">Donate</a></li>
                     <li><a href="#contact">Contact</a></li>
                     <li><a href="/privacy-policy.php">Privacy Policy</a></li>
                 </ul>
             </div>

             <!-- Footer Contact -->
             <div>
                 <h4 class="footer-heading">Reach Us</h4>
                 <address class="not-italic space-y-3 text-sm text-gray-300">
                     <p class="flex items-start"><i class="fas fa-map-marker-alt fa-fw mr-3 mt-1 text-theme-accent flex-shrink-0"></i> 36 New Vivekanand Park, Maqsudan, Jalandhar, Punjab - 144008</p>
                     <p class="flex items-center"><i class="fas fa-phone-alt fa-fw mr-3 text-theme-accent"></i> <a href="tel:+911812672784">181-267-2784</a></p>
                     <p class="flex items-center"><i class="fas fa-mobile-alt fa-fw mr-3 text-theme-accent"></i> <a href="tel:+919855614230">98556-14230</a></p>
                     <p class="flex items-start"><i class="fas fa-envelope fa-fw mr-3 mt-1 text-theme-accent"></i> <a href="mailto:engage@pahal-ngo.org" class="break-all">engage@pahal-ngo.org</a></p>
                 </address>
             </div>

             <!-- Footer Inspiration -->
             <div>
                  <h4 class="footer-heading">Our Inspiration</h4>
                 <blockquote class="text-sm italic text-gray-400 border-l-2 border-theme-accent pl-4">
                    "The best way to find yourself is to lose yourself in the service of others."
                     <cite class="block not-italic mt-1 text-xs text-gray-500">- Mahatma Gandhi</cite>
                </blockquote>
             </div>

        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <p>© <?= $current_year ?> PAHAL (Regd.), Jalandhar. All Rights Reserved. | An Endeavour for a Better Tomorrow.</p>
             <!-- <p class="mt-2 text-xs">Website by [Your Name/Company]</p> -->
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<!-- ... (Keep existing Back to Top button) ... -->
<button id="back-to-top" aria-label="Back to Top" title="Back to Top" class="back-to-top-button">
   <i class="fas fa-arrow-up"></i>
</button>


<!-- Simple Lightbox JS (after content) -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript (Theme, Menu, Scroll, etc.) -->
<!-- ... (Keep the enhanced <script> block for interactions) ... -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Elements ---
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
        let headerHeight = header ? header.offsetHeight : 70; // Initial guess

        // --- Theme Toggle ---
        const applyTheme = (theme) => {
            htmlElement.classList.toggle('dark', theme === 'dark');
            lightIcon?.classList.toggle('hidden', theme === 'dark');
            darkIcon?.classList.toggle('hidden', theme === 'light');
            localStorage.setItem('theme', theme);
        };
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
        applyTheme(initialTheme);
        themeToggleBtn?.addEventListener('click', () => {
            applyTheme(htmlElement.classList.contains('dark') ? 'light' : 'dark');
        });

        // --- Header & Layout Updates ---
        let scrollTimeout;
        const updateLayout = () => {
             if (!header) return;
             headerHeight = header.offsetHeight; // Recalculate on resize/scroll
             document.body.style.paddingTop = `${headerHeight}px`;
             header.classList.toggle('scrolled', window.scrollY > 50);
             backToTopButton?.classList.toggle('visible', window.scrollY > 300); // Use visibility class
        };
        updateLayout(); // Initial call
        window.addEventListener('resize', updateLayout);
        window.addEventListener('scroll', () => { // Debounce scroll listener slightly
             clearTimeout(scrollTimeout);
             scrollTimeout = setTimeout(updateLayout, 50);
        }, { passive: true });

        // --- Mobile Menu ---
        let isMobileMenuOpen = false;
        const toggleMobileMenu = (forceClose = false) => {
            if (!menuToggle || !navbar) return;
            const shouldOpen = !isMobileMenuOpen && !forceClose;
            menuToggle.setAttribute('aria-expanded', String(shouldOpen));
            menuToggle.classList.toggle('active', shouldOpen);
            navbar.classList.toggle('open', shouldOpen); // Toggle class for max-height transition
             if (shouldOpen) {
                 navbar.style.maxHeight = navbar.scrollHeight + "px";
             } else {
                 navbar.style.maxHeight = "0";
             }
            document.body.classList.toggle('overflow-hidden', shouldOpen && window.innerWidth < 1024); // Prevent body scroll on mobile only
             isMobileMenuOpen = shouldOpen;
         };
        menuToggle?.addEventListener('click', () => toggleMobileMenu());


        // --- Active Link Highlighting ---
        const setActiveLink = () => {
             let currentSectionId = '';
             const scrollThreshold = headerHeight + 100; // Adjust activation threshold

             sections.forEach(section => {
                 const sectionTop = section.offsetTop - scrollThreshold;
                 if (window.pageYOffset >= sectionTop) { // Check if scrolled past top
                      currentSectionId = '#' + section.getAttribute('id');
                 }
             });
             // Handle edge case for top of page
             if (window.pageYOffset < (sections[0]?.offsetTop - scrollThreshold || 300)) {
                  currentSectionId = '#hero';
             }

             navLinks.forEach(link => {
                 const isActive = link.getAttribute('href') === currentSectionId;
                 link.classList.toggle('active', isActive);
                 link.setAttribute('aria-current', isActive ? 'page' : null);
             });
        };
         // Debounce scroll listener for active link
         let activeLinkTimeout;
         window.addEventListener('scroll', () => {
             clearTimeout(activeLinkTimeout);
             activeLinkTimeout = setTimeout(setActiveLink, 100);
         }, { passive: true });
        setActiveLink(); // Initial check

        // --- Smooth Scrolling & Menu Close ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
             anchor.addEventListener('click', function (e) {
                 const targetId = this.getAttribute('href');
                 if (targetId.length <= 1) return;
                 const targetElement = document.querySelector(targetId);
                 if (targetElement) {
                     e.preventDefault();
                     if (isMobileMenuOpen && window.innerWidth < 1024) {
                         toggleMobileMenu(true); // Close mobile menu on link click
                     }
                     const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - headerHeight - 15; // Extra offset
                     window.scrollTo({ top: targetPosition, behavior: 'smooth' });
                     // Update hash manually after scroll (optional)
                     // setTimeout(() => history.pushState(null, '', targetId), 800); // Delay hash update
                 }
             });
        });

        // --- Back to Top ---
         backToTopButton?.addEventListener('click', () => {
             window.scrollTo({ top: 0, behavior: 'smooth' });
         });

        // --- Form Submission Indicator & Message Animation ---
         document.querySelectorAll('form[id$="-form-tag"]').forEach(form => { // Target forms ending with -form-tag
             const submitButton = form.querySelector('button[type="submit"]');
             const spinner = submitButton?.querySelector('.spinner');
             const buttonTextSpan = submitButton?.querySelector('.button-text'); // Assuming text is in a span

             form.addEventListener('submit', (e) => {
                 if (submitButton) {
                     submitButton.disabled = true;
                      if(buttonTextSpan) buttonTextSpan.textContent = 'Submitting...'; // Change text
                     spinner?.classList.remove('hidden');
                 }
                 // PHP redirect will handle enabling/disabling on page reload
             });

             // Animate status messages if they exist after redirect
             const formId = form.id.replace('-tag', ''); // Get original form ID
             const statusMessage = document.querySelector(`[data-form-message-id="${formId}"]`);
             if(statusMessage) {
                 setTimeout(() => {
                     statusMessage.style.opacity = '1';
                     statusMessage.style.transform = 'translateY(0)';
                 }, 50);
             }
         });


        // --- Gallery Lightbox ---
         try {
             if (typeof SimpleLightbox !== 'undefined') {
                 new SimpleLightbox('.gallery a', { captionDelay: 250, fadeSpeed: 200, animationSpeed: 200 });
             }
         } catch(e) { console.error("SimpleLightbox init failed:", e); }

        // --- Animation on Scroll ---
        const observerOptions = { root: null, rootMargin: '0px 0px -10% 0px', threshold: 0.1 }; // Trigger earlier
        const intersectionCallback = (entries, observer) => {
             entries.forEach(entry => {
                 if (entry.isIntersecting) {
                     entry.target.classList.add('is-visible');
                     observer.unobserve(entry.target);
                 }
             });
        };
         if ('IntersectionObserver' in window) {
             const observer = new IntersectionObserver(intersectionCallback, observerOptions);
             document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el));
         } else { // Fallback
             document.querySelectorAll('.animate-on-scroll').forEach(el => el.classList.add('is-visible'));
         }

        // --- Modal Handling ---
        const modalTriggers = document.querySelectorAll('[data-modal-target]');
        const modalClosers = document.querySelectorAll('[data-modal-close]');
        const modals = document.querySelectorAll('.modal-container'); // Target the outer container

        modalTriggers.forEach(button => {
             button.addEventListener('click', () => {
                 const modalId = button.getAttribute('data-modal-target');
                 const modal = document.getElementById(modalId);
                 if (modal) {
                    modal.classList.remove('hidden');
                     modal.classList.add('flex'); // Use flex for centering
                     // Trigger transition
                     setTimeout(() => modal.querySelector('.modal-box')?.classList.add('scale-100', 'opacity-100'), 20);
                    modal.querySelector('.modal-box [tabindex="-1"], .modal-box button, .modal-box a')?.focus();
                     document.body.style.overflow = 'hidden';
                 }
            });
         });

        const closeModal = (modal) => {
             if (modal) {
                 modal.querySelector('.modal-box')?.classList.remove('scale-100', 'opacity-100');
                 // Wait for animation before hiding
                 modal.addEventListener('transitionend', () => {
                      modal.classList.add('hidden');
                      modal.classList.remove('flex');
                      document.body.style.overflow = '';
                 }, { once: true });
                 // Safety hide if transitionend doesn't fire
                 setTimeout(() => {
                     modal.classList.add('hidden');
                     modal.classList.remove('flex');
                     document.body.style.overflow = '';
                 }, 350); // Match transition duration
            }
        }

         modalClosers.forEach(button => {
             button.addEventListener('click', () => {
                 const modalId = button.getAttribute('data-modal-close');
                 closeModal(document.getElementById(modalId));
             });
         });

         modals.forEach(modal => { // Close on overlay click
             modal.addEventListener('click', (event) => {
                 if (event.target === modal) { closeModal(modal); }
             });
         });
         document.addEventListener('keydown', (event) => { // Close with ESC
             if (event.key === 'Escape') {
                 document.querySelectorAll('.modal-container.flex').forEach(closeModal);
             }
         });

        console.log("PAHAL Main Page Enhanced JS Initialized.");
    });
</script>

</body>
</html>
