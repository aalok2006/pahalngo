<?php
// ========================================================================
// PAHAL NGO Website - Main Page & Contact Form Processor
// Version: 3.5 (Advanced UI Animations & Visuals)
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
            switch ($rule) { // Simplified for brevity - assume full rules exist
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

/**
 * Generates form status HTML (success/error) with enhanced styling & animation.
 */
function get_form_status_html(string $formId): string {
    global $form_messages; if (empty($form_messages[$formId])) return '';
    $message = $form_messages[$formId]; $isSuccess = ($message['type'] === 'success');
    $baseClasses = 'form-message border px-4 py-3 rounded-lg relative mb-6 text-sm shadow-lg transition-all duration-500 transform opacity-0 translate-y-2 scale-95'; // Start smaller for animation
    $typeClasses = $isSuccess ? 'bg-green-100 border-green-500 text-green-900 dark:bg-green-900/40 dark:border-green-600 dark:text-green-100' : 'bg-red-100 border-red-500 text-red-900 dark:bg-red-900/40 dark:border-red-600 dark:text-red-100';
    $iconClass = $isSuccess ? 'fas fa-check-circle text-green-500 dark:text-green-400' : 'fas fa-exclamation-triangle text-red-500 dark:text-red-400';
    $title = $isSuccess ? 'Success!' : 'Error:';
    // data-form-message-id triggers JS animation
    return "<div class=\"{$baseClasses} {$typeClasses}\" role=\"alert\" data-form-message-id=\"{$formId}\"><strong class=\"font-bold flex items-center text-base\"><i class=\"{$iconClass} mr-2 text-xl animate-pulse\"></i>{$title}</strong> <span class=\"block sm:inline mt-1 ml-8\">" . htmlspecialchars($message['text']) . "</span></div>";
}

/**
 * Generates HTML for a field error message with accessibility link.
 */
function get_field_error_html(string $formId, string $fieldName): string {
    global $form_errors; $errorId = htmlspecialchars($formId . '_' . $fieldName . '_error');
    if (isset($form_errors[$formId][$fieldName])) { return '<p class="text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1 animate-fade-in"><i class="fas fa-exclamation-circle text-xs"></i>' . htmlspecialchars($form_errors[$formId][$fieldName]) . '</p>'; } return '';
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
$page_title = "PAHAL NGO Jalandhar | Empowering Communities, Inspiring Change";
$page_description = "'PAHAL' is a leading volunteer-driven youth NGO in Jalandhar, Punjab, fostering holistic development through impactful initiatives in health, education, environment, and communication skills.";
$page_keywords = "PAHAL, NGO, Jalandhar, Punjab, volunteer, youth organization, social work, community service, health camps, blood donation, education, environment, e-waste, communication skills, personality development, non-profit";

// --- Initialize Form State Variables ---
$form_submissions = []; $form_messages = []; $form_errors = [];
$csrf_token = generate_csrf_token();

// --- Form Processing Logic (POST Request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ... (Keep your existing, validated POST handling logic here) ...
    // This includes: Security Checks, Form Identification, Sanitization,
    // Validation, Email Sending, Logging, Setting Session Variables, Redirect.
} else {
    // --- GET Request: Retrieve session data ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); } else { $form_messages = []; }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); } else { $form_errors = []; }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); } else { $form_submissions = []; }
    $csrf_token = generate_csrf_token(); // Ensure token exists for GET
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
$news_items = [ /* ... */ ]; $gallery_images = [ /* ... */ ]; $associates = [ /* ... */ ];

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
    <script src="https://cdn.tailwindcss.com></script>

    <!-- Google Fonts (Poppins & Fira Code) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Fira+Code:wght@400&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Simple Lightbox CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.css">

    <script>
        // Tailwind Config (Enhanced with more colors/animations)
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'], heading: ['Poppins', 'sans-serif'], mono: ['Fira Code', 'monospace'],
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
                        'bounce-subtle': 'bounceSubtle 2s infinite ease-in-out', 'gradient-bg': 'gradientBg 15s ease infinite', // Animated gradient
                        'glow-pulse': 'glowPulse 2.5s infinite alternate ease-in-out', // Glow animation
                        'icon-bounce': 'iconBounce 0.6s ease-out', // For button icons
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInDown: { '0%': { opacity: '0', transform: 'translateY(-25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } }, // Increased distance
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(25px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideInLeft: { '0%': { opacity: '0', transform: 'translateX(-40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        slideInRight: { '0%': { opacity: '0', transform: 'translateX(40px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        formMessageIn: { '0%': { opacity: '0', transform: 'translateY(15px) scale(0.95)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
                        bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
                        gradientBg: { // Keyframes for animated gradient
                           '0%': { backgroundPosition: '0% 50%' }, '50%': { backgroundPosition: '100% 50%' }, '100%': { backgroundPosition: '0% 50%' },
                        },
                        glowPulse: { // Keyframes for glow effect
                            '0%': { boxShadow: '0 0 5px 0px var(--color-glow)' }, '100%': { boxShadow: '0 0 20px 5px var(--color-glow)' },
                        },
                        iconBounce: { // Keyframes for icon bounce on hover
                             '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-3px)' },
                        }
                    },
                    boxShadow: { // Keep existing + add focus shadow variable
                        'card': '0 5px 15px rgba(0, 0, 0, 0.07)', 'card-dark': '0 8px 25px rgba(0, 0, 0, 0.3)',
                        'input-focus': '0 0 0 3px var(--color-primary-focus)',
                    },
                    container: { // Keep previous container settings
                      center: true, padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' }, screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1280px' },
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Theme Variables - More Vibrant Palette */
        :root { /* Light Theme */
          --color-primary: #059669; /* Emerald 600 */ --color-primary-hover: #047857; /* Emerald 700 */ --color-primary-focus: rgba(5, 150, 105, 0.3);
          --color-secondary: #6366f1; /* Indigo 500 */ --color-secondary-hover: #4f46e5; /* Indigo 600 */
          --color-accent: #e11d48; /* Rose 600 */ --color-accent-hover: #be123c; /* Rose 700 */
          --color-success: #16a34a; /* Green 600 */ --color-warning: #f59e0b; /* Amber 500 */ --color-info: #0ea5e9; /* Sky 500 */
          --color-bg: #f8fafc; /* Slate 50 */
          --color-surface: #ffffff; --color-surface-alt: #f1f5f9; /* Slate 100 */
          --color-text: #1e293b; /* Slate 800 */ --color-text-muted: #64748b; /* Slate 500 */ --color-text-heading: #0f172a; /* Slate 900 */
          --color-border: #e2e8f0; /* Slate 200 */ --color-border-light: #f1f5f9; /* Slate 100 */
          --color-glow: rgba(5, 150, 105, 0.4); /* Primary glow color */
          --scrollbar-thumb: #cbd5e1; /* Slate 300 */ --scrollbar-track: #f1f5f9; /* Slate 100 */
          color-scheme: light;
        }

        html.dark {
          --color-primary: #34d399; /* Emerald 400 */ --color-primary-hover: #6ee7b7; /* Emerald 300 */ --color-primary-focus: rgba(52, 211, 153, 0.4);
          --color-secondary: #a78bfa; /* Violet 400 */ --color-secondary-hover: #c4b5fd; /* Violet 300 */
          --color-accent: #f87171; /* Red 400 */ --color-accent-hover: #fb7185; /* Rose 400 */
          --color-success: #4ade80; /* Green 400 */ --color-warning: #facc15; /* Yellow 400 */ --color-info: #38bdf8; /* Sky 400 */
          --color-bg: #0b1120; /* Darker Slate */
          --color-surface: #1e293b; /* Slate 800 */ --color-surface-alt: #334155; /* Slate 700 */
          --color-text: #cbd5e1; /* Slate 300 */ --color-text-muted: #94a3b8; /* Slate 400 */ --color-text-heading: #f1f5f9; /* Slate 100 */
          --color-border: #475569; /* Slate 600 */ --color-border-light: #334155; /* Slate 700 */
          --color-glow: rgba(52, 211, 153, 0.5);
          --scrollbar-thumb: #475569; /* Slate 600 */ --scrollbar-track: #1e293b; /* Slate 800 */
          color-scheme: dark;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 5px; }
        ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 5px; border: 2px solid var(--scrollbar-track); }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--scrollbar-thumb) 75%, white); }


        @layer base {
            html { @apply scroll-smooth antialiased; }
            body { @apply bg-theme-bg text-theme-text font-sans transition-colors duration-300 overflow-x-hidden; } /* Prevent horizontal scroll */
            h1, h2, h3, h4, h5, h6 { @apply font-heading font-semibold text-theme-text-heading tracking-tight leading-tight; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl font-extrabold; } /* Bolder H1 */
            h2 { @apply text-3xl md:text-4xl font-bold mb-4; }
            h3 { @apply text-xl md:text-2xl font-bold text-theme-primary mb-4 mt-5; }
            h4 { @apply text-lg font-semibold text-theme-secondary mb-2; }
            p { @apply mb-5 leading-relaxed text-base max-w-prose; }
            a { @apply text-theme-secondary hover:text-theme-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-secondary/50 rounded-sm; transition-colors duration-200; }
            a:not(.btn):not(.btn-secondary):not(.btn-outline):not(.nav-link):not(.footer-link) { /* More specific default link */
                 @apply underline decoration-theme-secondary/50 hover:decoration-theme-primary decoration-1 underline-offset-2;
            }
            hr { @apply border-theme-border/40 my-12; } /* Lighter border */
            blockquote { @apply border-l-4 border-theme-secondary bg-theme-surface-alt p-5 my-6 italic text-theme-text-muted shadow-inner rounded-r-md;}
            blockquote cite { @apply block not-italic mt-2 text-sm text-theme-text-muted/80;}
            address { @apply not-italic;}
            *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-offset-theme-surface ring-theme-primary/70 rounded-md; }
            .honeypot-field { @apply !absolute !-left-[9999px] !w-px !h-px !overflow-hidden !opacity-0; }
        }

        @layer components {
            .section-padding { @apply py-16 md:py-20 lg:py-24 px-4; }
            .section-title { @apply text-center mb-12 md:mb-16 text-theme-primary; }
            .section-title-underline::after { content: ''; @apply block w-24 h-1 bg-gradient-to-r from-theme-secondary to-theme-accent mx-auto mt-4 rounded-full opacity-80; } /* Gradient underline */

            /* Enhanced Buttons */
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

            /* Enhanced Cards */
            .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/60 overflow-hidden relative transition-all duration-300; }
            .card-hover { @apply hover:shadow-xl dark:hover:shadow-2xl hover:border-theme-primary/50 hover:scale-[1.03] z-10; } /* Enhanced hover */
            .card::after { /* Subtle glow on hover preparation */
                content: ''; @apply absolute inset-0 rounded-xl opacity-0 transition-opacity duration-300 pointer-events-none;
                box-shadow: 0 0 25px -5px var(--color-glow);
            }
            .card-hover:hover::after { @apply opacity-70; }

            /* Panel with Glassmorphism */
            .panel { @apply bg-theme-surface/75 dark:bg-theme-surface/70 backdrop-blur-xl border border-theme-border/40 rounded-2xl shadow-lg p-6 md:p-8; }

            /* Enhanced Forms */
            .form-label { @apply block mb-1.5 text-sm font-medium text-theme-text-muted; }
            .form-label.required::after { content: '*'; @apply text-theme-accent ml-0.5; }
            .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-bg dark:bg-theme-surface/60 border-theme-border placeholder-theme-text-muted/70 text-theme-text shadow-sm transition duration-200 ease-in-out focus:border-theme-primary focus:ring-2 focus:ring-theme-primary/50 focus:outline-none disabled:opacity-60; }
            /* Autofill styles (keep from previous) */
            input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus, textarea:-webkit-autofill, textarea:-webkit-autofill:hover, textarea:-webkit-autofill:focus, select:-webkit-autofill, select:-webkit-autofill:hover, select:-webkit-autofill:focus { -webkit-text-fill-color: var(--color-text); -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; transition: background-color 5000s ease-in-out 0s; }
            html.dark input:-webkit-autofill, html.dark input:-webkit-autofill:hover, html.dark input:-webkit-autofill:focus, html.dark textarea:-webkit-autofill, html.dark textarea:-webkit-autofill:hover, html.dark textarea:-webkit-autofill:focus, html.dark select:-webkit-autofill, html.dark select:-webkit-autofill:hover, html.dark select:-webkit-autofill:focus { -webkit-box-shadow: 0 0 0px 1000px var(--color-surface) inset; }
            select.form-input { /* Keep custom arrow */ }
            textarea.form-input { @apply min-h-[120px] resize-vertical; }
            .form-input-error { @apply !border-theme-accent ring-2 ring-theme-accent/50 focus:!border-theme-accent focus:!ring-theme-accent/50; }
            .form-error-message { @apply text-theme-accent dark:text-red-400 text-xs italic mt-1 font-medium flex items-center gap-1; } /* Error message style */
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
        }

        @layer utilities {
            /* Animation Delays */
             .delay-100 { animation-delay: 0.1s; } .delay-200 { animation-delay: 0.2s; } .delay-300 { animation-delay: 0.3s; } .delay-400 { animation-delay: 0.4s; } .delay-500 { animation-delay: 0.5s; } .delay-700 { animation-delay: 0.7s; }

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
             .animated-gradient-accent {
                 background: linear-gradient(-45deg, var(--color-accent), var(--color-warning), var(--color-secondary), var(--color-accent));
                 background-size: 400% 400%;
                 animation: gradientBg 20s ease infinite;
             }
        }

        /* Specific Section Styles */
         #main-header { @apply fixed top-0 left-0 w-full bg-theme-surface/85 dark:bg-theme-bg/80 backdrop-blur-xl z-50 shadow-sm transition-all duration-300 border-b border-theme-border/30; min-height: 70px; }
         #main-header.scrolled { @apply shadow-lg bg-theme-surface/95 dark:bg-theme-bg/90 border-theme-border/50; }
         body { @apply pt-[70px]; }

          /* Navigation */
          #navbar ul li a { @apply text-theme-text-muted hover:text-theme-primary dark:hover:text-theme-primary font-medium py-2 relative transition duration-300 ease-in-out text-sm lg:text-base block lg:inline-block lg:py-0 px-3 lg:px-2 xl:px-3; } /* Adjusted padding */
          #navbar ul li a::after { content: ''; @apply absolute bottom-[-5px] left-0 w-0 h-[3px] bg-gradient-to-r from-theme-secondary to-theme-primary opacity-0 transition-all duration-300 ease-out rounded-full group-hover:opacity-100 group-hover:w-full; } /* Animated underline */
          #navbar ul li a.active { @apply text-theme-primary font-semibold; }
          #navbar ul li a.active::after { @apply w-full opacity-100; }

          /* Mobile menu toggle */
          /* ... (Keep existing styles) ... */

          /* Mobile Navbar container */
          #navbar { @apply navbar-container w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-screen overflow-hidden lg:overflow-visible absolute lg:relative top-[70px] lg:top-auto left-0 bg-theme-surface dark:bg-theme-surface lg:bg-transparent shadow-xl lg:shadow-none lg:border-none border-t border-theme-border transition-all duration-500 ease-in-out; }
          #navbar.open { @apply block; } /* JS will toggle max-height */

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
         #news-section .news-card .news-content h4 a { @apply group-hover:!text-theme-secondary; } /* Change title color on hover */

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
          #volunteer-section .form-input { @apply !bg-white/10 !border-gray-400/40 !text-white placeholder:!text-gray-300/60 focus:!bg-white/20 focus:!border-white; }

         /* Gallery */
         .gallery-item img { @apply transition-all duration-400 ease-in-out group-hover:scale-105 group-hover:brightness-110 filter group-hover:contrast-110; } /* Add contrast */

         /* Associates */
         #associates { @apply bg-theme-surface-alt dark:bg-theme-surface/50; }
         .associate-logo img { @apply filter grayscale group-hover:grayscale-0 transition duration-300 ease-in-out opacity-75 group-hover:opacity-100; }
         .associate-logo p { @apply text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors; }

         /* Contact Section */
         #contact { @apply bg-gradient-to-b from-theme-surface-alt to-theme-bg dark:from-theme-surface/50 dark:to-theme-bg;}
         #contact .panel { @apply !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50; } /* Solid surface for form */
         #contact .contact-info-item i { @apply text-theme-primary; }
         #contact .registration-info { @apply bg-theme-surface dark:bg-theme-surface/80 p-4 rounded-md border border-theme-border text-xs text-theme-text-muted mt-8 shadow-inner;}

         /* Footer */
         footer { @apply bg-slate-900 dark:bg-black text-gray-400 pt-16 pb-8 mt-0 border-t-4 border-theme-primary dark:border-theme-primary; }

         /* Back to Top */
         #back-to-top { @apply fixed bottom-6 right-6 z-[60] p-3 rounded-full bg-theme-primary text-white shadow-lg hover:bg-theme-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-theme-primary opacity-0 invisible transition-all duration-300 hover:scale-110 active:scale-95; }
         #back-to-top.visible { @apply opacity-100 visible; }

         /* Modal Styles */
         #bank-details-modal { @apply fixed inset-0 bg-black/70 dark:bg-black/80 z-[100] hidden items-center justify-center p-4 backdrop-blur-md; } /* Stronger blur */
         #bank-details-modal > div { @apply bg-theme-surface rounded-lg shadow-xl p-6 md:p-8 w-full max-w-lg text-left relative transform transition-all duration-300 scale-95 opacity-0; } /* Wider modal */
         #bank-details-modal.flex > div { @apply scale-100 opacity-100; }
         #bank-details-modal h3 { @apply !text-theme-primary !mt-0 mb-5 border-b border-theme-border pb-3; } /* Title style */
         #bank-details-modal .close-button { @apply absolute top-4 right-4 text-theme-text-muted hover:text-theme-accent p-1 rounded-full transition-colors focus-visible:ring-theme-accent; }

    </style>
    <!-- Schema.org JSON-LD -->
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
     <section id="volunteer-section" class="section-padding animated-gradient-secondary text-white relative">
        <div class="absolute inset-0 bg-black/30 mix-blend-multiply z-0"></div> <!-- Darkening Overlay -->
        <div class="container mx-auto relative z-10">
             <h2 class="section-title !text-white section-title-underline after:!bg-theme-accent">Join the PAHAL Movement</h2>
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
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>"><input type="hidden" name="form_id" value="volunteer_form"><div class="honeypot-field" aria-hidden="true"><label for="website_url_volunteer">Keep Blank</label><input type="text" id="website_url_volunteer" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>
                        <!-- Form Fields (using updated classes) -->
                        <div><label for="volunteer_name" class="form-label !text-gray-200 required">Full Name</label><input type="text" id="volunteer_name" name="volunteer_name" required value="<?= $volunteer_form_name_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_name') ?>" placeholder="Your Name" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_name') ?>><?= get_field_error_html('volunteer_form', 'volunteer_name') ?></div>
                        <div class="grid md:grid-cols-2 gap-5">
                            <div><label for="volunteer_email" class="form-label !text-gray-200">Email</label><input type="email" id="volunteer_email" name="volunteer_email" value="<?= $volunteer_form_email_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_email') ?>" placeholder="your.email@example.com" <?= get_aria_describedby('volunteer_form', 'volunteer_email') ?>><?= get_field_error_html('volunteer_form', 'volunteer_email') ?></div>
                            <div><label for="volunteer_phone" class="form-label !text-gray-200">Phone</label><input type="tel" id="volunteer_phone" name="volunteer_phone" value="<?= $volunteer_form_phone_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_phone') ?>" placeholder="Your Phone" <?= get_aria_describedby('volunteer_form', 'volunteer_phone') ?>><?= get_field_error_html('volunteer_form', 'volunteer_phone') ?></div>
                        </div>
                        <p class="text-xs text-gray-300 -mt-3" id="volunteer_contact_note">Provide Email or Phone.</p>
                        <div><label for="volunteer_area" class="form-label !text-gray-200 required">Area of Interest</label><select id="volunteer_area" name="volunteer_area" required class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_area') ?>" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_area') ?>><option value="" disabled <?= empty($volunteer_form_area_value) ? 'selected' : ''?>>-- Select --</option><option value="Health" <?= $volunteer_form_area_value == 'Health' ? 'selected' : ''?>>Health</option><!-- Other options --><option value="Other" <?= $volunteer_form_area_value == 'Other' ? 'selected' : ''?>>Other</option></select><?= get_field_error_html('volunteer_form', 'volunteer_area') ?></div>
                        <div><label for="volunteer_availability" class="form-label !text-gray-200 required">Availability</label><input type="text" id="volunteer_availability" name="volunteer_availability" required value="<?= $volunteer_form_availability_value ?>" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_availability') ?>" placeholder="e.g., Weekends, Evenings" aria-required="true" <?= get_aria_describedby('volunteer_form', 'volunteer_availability') ?>><?= get_field_error_html('volunteer_form', 'volunteer_availability') ?></div>
                        <div><label for="volunteer_message" class="form-label !text-gray-200">Message (Optional)</label><textarea id="volunteer_message" name="volunteer_message" rows="3" class="form-input form-input-inverted <?= get_field_error_class('volunteer_form', 'volunteer_message') ?>" placeholder="Your motivation or skills..." <?= get_aria_describedby('volunteer_form', 'volunteer_message') ?>><?= $volunteer_form_message_value ?></textarea><?= get_field_error_html('volunteer_form', 'volunteer_message') ?></div>
                        <button type="submit" class="btn btn-accent w-full sm:w-auto"><span class="spinner hidden mr-2"></span><span class="button-text flex items-center"><i class="fas fa-paper-plane"></i>Submit Interest</span></button>
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
                             <img src="<?= htmlspecialchars($item['image']) ?>" alt="..." loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
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
            <div class="text-center mt-12"><a href="/news-archive.php" class="btn btn-secondary"><i class="far fa-newspaper"></i>View News Archive</a></div>
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
                    <p class="text-xs font-medium text-theme-text-muted group-hover:text-theme-primary transition-colors"><?= htmlspecialchars($associate['name']) ?></p>
                 </div>
                 <?php endforeach; ?>
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
                    <div class="mb-10 pt-8 border-t border-theme-border/50"><h4 class="text-lg font-semibold text-theme-secondary mb-4">Follow Our Journey</h4><div class="flex space-x-5 social-icons"><!-- Social Icons --></div></div>
                     <div class="mb-10 pt-8 border-t border-theme-border/50"><h4 class="text-lg font-semibold text-theme-secondary mb-4">Visit Us</h4><iframe src="..." width="100%" height="300" class="rounded-lg shadow-md border border-theme-border/50"></iframe></div>
                     <div class="registration-info"><h4 class="text-sm font-semibold text-theme-primary dark:text-theme-primary mb-2">Registration</h4><!-- Reg Details --></div>
                 </div>
                <!-- Contact Form -->
                <div class="lg:col-span-3 panel !bg-theme-surface dark:!bg-theme-surface !border-theme-border/50 animate-on-scroll fade-in-right delay-100">
                    <h3 class="text-2xl mb-8 font-semibold !text-theme-text-heading text-center lg:text-left">Send Us a Message</h3>
                    <?= get_form_status_html('contact_form') ?>
                    <form id="contact-form-tag" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-6">
                        <!-- Hidden fields -->
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>"><input type="hidden" name="form_id" value="contact_form"><div class="honeypot-field">...</div>
                        <!-- Form Fields -->
                        <div><label for="contact_name" class="form-label required">Name</label><input type="text" id="contact_name" name="name" required value="<?= $contact_form_name_value ?>" class="<?= get_field_error_class('contact_form', 'name') ?>" placeholder="e.g., Jane Doe" <?= get_aria_describedby('contact_form', 'name') ?>><?= get_field_error_html('contact_form', 'name') ?></div>
                        <div><label for="contact_email" class="form-label required">Email</label><input type="email" id="contact_email" name="email" required value="<?= $contact_form_email_value ?>" class="<?= get_field_error_class('contact_form', 'email') ?>" placeholder="e.g., jane.doe@example.com" <?= get_aria_describedby('contact_form', 'email') ?>><?= get_field_error_html('contact_form', 'email') ?></div>
                        <div><label for="contact_message" class="form-label required">Message</label><textarea id="contact_message" name="message" rows="5" required class="<?= get_field_error_class('contact_form', 'message') ?>" placeholder="Your thoughts..." <?= get_aria_describedby('contact_form', 'message') ?>><?= $contact_form_message_value ?></textarea><?= get_field_error_html('contact_form', 'message') ?></div>
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
   <i class="fas fa-arrow-up text-lg"></i> <!-- Slightly larger icon -->
</button>

<!-- Simple Lightbox JS -->
<script src="https://cdn.jsdelivr.net/npm/simplelightbox@2.14.2/dist/simple-lightbox.min.js"></script>

<!-- Main JavaScript -->
<script>
    // --- Keep the existing JS from the previous enhancement ---
    // This includes: Theme Toggle, Mobile Menu, Active Link Highlighting,
    // Smooth Scroll, Back to Top, Form Submission Spinner (basic),
    // Gallery Lightbox Init, Animation on Scroll (Intersection Observer), Modal Handling.
    // No major changes needed here unless specific new interactions were added.
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
        let headerHeight = header?.offsetHeight ?? 70;

        // --- Theme Toggle ---
        const applyTheme = (theme) => { /* ... keep existing ... */ };
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = storedTheme ? storedTheme : (prefersDark ? 'dark' : 'light');
        applyTheme(initialTheme);
        themeToggleBtn?.addEventListener('click', () => { applyTheme(htmlElement.classList.contains('dark') ? 'light' : 'dark'); });

        // --- Header & Layout ---
        let scrollTimeout;
        const updateLayout = () => { /* ... keep existing ... */ };
        updateLayout(); window.addEventListener('resize', updateLayout); window.addEventListener('scroll', () => { clearTimeout(scrollTimeout); scrollTimeout = setTimeout(updateLayout, 50); }, { passive: true });

        // --- Mobile Menu ---
        let isMobileMenuOpen = false;
        const toggleMobileMenu = (forceClose = false) => { /* ... keep existing ... */ };
        menuToggle?.addEventListener('click', () => toggleMobileMenu());

        // --- Active Link ---
        const setActiveLink = () => { /* ... keep existing ... */ };
        let activeLinkTimeout; window.addEventListener('scroll', () => { clearTimeout(activeLinkTimeout); activeLinkTimeout = setTimeout(setActiveLink, 100); }, { passive: true }); setActiveLink();

        // --- Smooth Scroll & Menu Close ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => { anchor.addEventListener('click', function (e) { /* ... keep existing ... */ }); });

        // --- Back to Top ---
        backToTopButton?.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });

        // --- Form Submission & Messages ---
        document.querySelectorAll('form[id$="-form-tag"]').forEach(form => {
             const submitButton = form.querySelector('button[type="submit"]');
             const spinner = submitButton?.querySelector('.spinner');
             const buttonTextSpan = submitButton?.querySelector('.button-text');
             form.addEventListener('submit', (e) => {
                 if (submitButton) { submitButton.disabled = true; buttonTextSpan?.classList.add('opacity-70'); spinner?.classList.remove('hidden'); }
             });
             const formId = form.id.replace('-tag', '');
             const statusMessage = document.querySelector(`[data-form-message-id="${formId}"]`);
             if(statusMessage) { setTimeout(() => { statusMessage.style.opacity = '1'; statusMessage.style.transform = 'translateY(0) scale(1)'; }, 50); }
        });

        // --- Gallery Lightbox ---
        try { if (typeof SimpleLightbox !== 'undefined') { new SimpleLightbox('.gallery a', { captionDelay: 250, fadeSpeed: 200, animationSpeed: 200 }); } } catch(e) { console.error("Lightbox init failed:", e); }

        // --- Animation on Scroll ---
        const observerOptions = { root: null, rootMargin: '0px 0px -10% 0px', threshold: 0.1 };
        const intersectionCallback = (entries, observer) => { /* ... keep existing ... */ };
        if ('IntersectionObserver' in window) { const observer = new IntersectionObserver(intersectionCallback, observerOptions); document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el)); }
        else { document.querySelectorAll('.animate-on-scroll').forEach(el => el.classList.add('is-visible')); }

        // --- Modal Handling ---
        const modalTriggers = document.querySelectorAll('[data-modal-target]');
        const modalClosers = document.querySelectorAll('[data-modal-close]');
        const modals = document.querySelectorAll('.modal-container');
        const closeModal = (modal) => { /* ... keep existing ... */ };
        modalTriggers.forEach(button => { button.addEventListener('click', () => { /* ... keep existing ... */ }); });
        modalClosers.forEach(button => { button.addEventListener('click', () => { /* ... keep existing ... */ }); });
        modals.forEach(modal => { modal.addEventListener('click', (event) => { /* ... keep existing ... */ }); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { document.querySelectorAll('.modal-container.flex').forEach(closeModal); } });

        console.log("PAHAL Advanced UI Initialized.");
    });
</script>

</body>
</html>
