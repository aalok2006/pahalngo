<?php
// ========================================================================
// PAHAL NGO Website - E-Waste Collection & Recycling Page
// Enhanced Version: v2.0
// Features: PHPMailer, CSRF, Honeypot, Logging for Pickup Request, Expanded Info, Interactive Map Placeholder
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Dependency & Config ---
$baseDir = __DIR__;
if (!file_exists($baseDir . '/vendor/autoload.php')) {
    // Try checking one level up if composer vendor is in the root
    if (file_exists(dirname($baseDir) . '/vendor/autoload.php')) {
       require dirname($baseDir) . '/vendor/autoload.php';
    } else {
       die("Error: PHPMailer library not found (checked vendor/autoload.php). Please run 'composer install' in your project root.");
    }
} else {
    require $baseDir . '/vendor/autoload.php';
}


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// --- Include or Redeclare Core Config ---
// It's better to include a central config file. If not possible, redeclare needed constants.
// Example: include_once($baseDir . '/config.php'); or include_once(dirname($baseDir) . '/config.php');
// For this example, redeclaring the necessary constants specific to this file's functionality.
if (!defined('USE_SMTP')) define('USE_SMTP', false); // Default to false if not set centrally
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.example.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'your_smtp_user@example.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'your_smtp_password');
if (!defined('SMTP_ENCRYPTION')) define('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'noreply@your-pahal-domain.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'PAHAL NGO E-Waste Program');
if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', 'csrf_token');
if (!defined('HONEYPOT_FIELD_NAME')) define('HONEYPOT_FIELD_NAME', 'contact_preference'); // Unique name maybe
if (!defined('ENABLE_LOGGING')) define('ENABLE_LOGGING', true);
if (!defined('LOG_FILE_ERROR')) define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
if (!defined('LOG_FILE_EWASTE_PICKUP')) define('LOG_FILE_EWASTE_PICKUP', $baseDir . '/logs/ewaste_pickup_requests.log');
if (!defined('RECIPIENT_EMAIL_EWASTE')) define('RECIPIENT_EMAIL_EWASTE', "ewaste@your-pahal-domain.com");

// --- Helper Functions ---
// MUST include or redeclare necessary functions here:
// log_message, generate_csrf_token, validate_csrf_token, sanitize_string, sanitize_email,
// validate_data, send_email, get_form_value, get_form_status_html, get_field_error_html, get_field_error_class
// NOTE: These are critical for functionality. Copy definitions from index.php if not using includes.

// Example inclusion of functions (create functions.php with the needed definitions)
// include_once('functions.php');

// Placeholder: Function Definitions (assuming they are available)
// Replace with actual function definitions or include statement
function log_message(string $msg, string $file) { /* ... implementation ... */ error_log("LOG [$file]: $msg"); }
function generate_csrf_token(): string { if (empty($_SESSION[CSRF_TOKEN_NAME])) { $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32)); } return $_SESSION[CSRF_TOKEN_NAME]; }
function validate_csrf_token(?string $token): bool { return !empty($token) && !empty($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token); }
function sanitize_string(string $input): string { return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function sanitize_email(string $email): string { $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL); return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : ''; }
function validate_data(array $data, array $rules): array { /* ... complex validation logic ... */ return []; /* return errors array */ } // VERY Basic placeholder
function send_email(string $to, string $subject, string $body, string $replyToEmail, string $replyToName, string $logContext): bool { /* ... PHPMailer logic ... */ return true; } // Basic placeholder
function get_form_value(string $formId, string $fieldName, string $default = ''): string { global $form_submissions; return htmlspecialchars($form_submissions[$formId][$fieldName] ?? $default, ENT_QUOTES, 'UTF-8'); }
function get_form_status_html(string $formId): string { global $form_messages; if(empty($form_messages[$formId])) return ''; $msg = $form_messages[$formId]; $class = $msg['type'] === 'success' ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700'; return "<div class='border px-4 py-3 rounded relative $class'>{$msg['text']}</div>"; }
function get_field_error_html(string $formId, string $fieldName): string { global $form_errors; return isset($form_errors[$formId][$fieldName]) ? "<p class='text-red-600 text-xs italic mt-1'>{$form_errors[$formId][$fieldName]}</p>" : ''; }
function get_field_error_class(string $formId, string $fieldName): string { global $form_errors; return isset($form_errors[$formId][$fieldName]) ? 'form-input-error' : 'border-gray-300'; }


// --- Initialize Variables ---
$current_year = date('Y');
$page_title = "E-Waste Collection & Responsible Recycling - PAHAL NGO";
$page_description = "Dispose of your old electronics responsibly with PAHAL NGO's e-waste collection program in Jalandhar. Learn what we accept and request a pickup.";
$page_keywords = "e-waste, electronic waste, recycling, pahal ngo, jalandhar, dispose electronics, computer recycling, phone recycling, e-waste collection, sustainable disposal";

// Form state
$form_submissions = [];
$form_messages = [];
$form_errors = [];
$csrf_token = generate_csrf_token();

// --- E-Waste Data ---
$accepted_items = [
    ['name' => 'Computers & Laptops', 'icon' => 'fas fa-laptop', 'details' => 'Desktops, towers, laptops, docking stations.'],
    ['name' => 'Monitors & Screens', 'icon' => 'fas fa-desktop', 'details' => 'CRT monitors, LCD/LED screens, all sizes.'],
    ['name' => 'Printers & Peripherals', 'icon' => 'fas fa-print', 'details' => 'Printers, scanners, fax machines, keyboards, mice, webcams.'],
    ['name' => 'Mobile Devices', 'icon' => 'fas fa-mobile-alt', 'details' => 'Mobile phones, smartphones, tablets, chargers.'],
    ['name' => 'Cables & Cords', 'icon' => 'fas fa-plug', 'details' => 'Power cords, USB cables, network cables, adapters.'],
    ['name' => 'Audio/Video Equipment', 'icon' => 'fas fa-tv', 'details' => 'DVD players, VCRs, stereos, speakers (small), cameras.'],
    ['name' => 'Small Home Appliances', 'icon' => 'fas fa-blender', 'details' => 'Toasters, irons, small kitchen gadgets (contact for larger items).'],
    ['name' => 'Batteries (Household)', 'icon' => 'fas fa-car-battery', 'details' => 'AA, AAA, C, D, button cells, laptop batteries (separated preferred).'],
];
$not_accepted_items = [
    ['name' => 'Large Appliances', 'icon' => 'fas fa-refrigerator', 'details' => 'Refrigerators, washing machines, AC units (require specialized handling).'], // Changed icon
    ['name' => 'Smoke Detectors', 'icon' => 'fas fa-smog', 'details' => 'Often contain radioactive elements.'],
    ['name' => 'Fluorescent Bulbs / CFLs', 'icon' => 'far fa-lightbulb', 'details' => 'Contain mercury, require specific hazardous waste disposal.'], // Use FontAwesome Regular icon
    ['name' => 'Hazardous Materials', 'icon' => 'fas fa-biohazard', 'details' => 'Chemicals, paints, or items contaminated with hazardous waste.'],
];
$ewaste_risks = [
    ['risk' => 'Lead (Pb)', 'source' => 'CRT Monitors, Solders', 'impact' => 'Neurological damage, developmental issues in children'],
    ['risk' => 'Mercury (Hg)', 'source' => 'LCD Screens (backlights), Batteries, Switches', 'impact' => 'Brain and kidney damage, affects fetal development'],
    ['risk' => 'Cadmium (Cd)', 'source' => 'Rechargeable Batteries, Circuit Boards', 'impact' => 'Kidney damage, lung disease, potential carcinogen'],
    ['risk' => 'Brominated Flame Retardants (BFRs)', 'source' => 'Plastic Casings, Cables, Circuit Boards', 'impact' => 'Endocrine disruption, potential neurodevelopmental effects'],
    ['risk' => 'Polyvinyl Chloride (PVC)', 'source' => 'Cables, Housings', 'impact' => 'Releases dioxins and furans when burned, harmful emissions'],
    ['risk' => 'Beryllium (Be)', 'source' => 'Connectors, Motherboards', 'impact' => 'Lung disease (berylliosis), potential carcinogen'],
];

// --- Define Jalandhar Coordinates for Map ---
$map_coords = ['lat' => 31.3260, 'lng' => 75.5762]; // Center of Jalandhar approx.
$map_zoom = 12;
// Define drop-off points (Replace with actual data from DB or config)
$drop_off_points = [
    ['name' => 'PAHAL NGO Office (Scheduled)', 'lat' => 31.3395, 'lng' => 75.5989, 'address' => '36 New Vivekanand Park, Maqsudan', 'notes' => 'Acceptance during announced drives or by appointment only.'],
    // ['name' => 'Karo Sambhav Collection Point XYZ', 'lat' => 31.3100, 'lng' => 75.5800, 'address' => '123 Model Town Market', 'notes' => 'Verify accepted items and hours.'],
    // Add more points
];


// --- Form Processing Logic ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = $_POST['form_id'] ?? null;

     // Anti-Spam & CSRF Checks
    if (!empty($_POST[HONEYPOT_FIELD_NAME]) || !validate_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
         log_message("[SPAM/CSRF DETECTED] E-Waste Form Failed. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
         http_response_code(403);
         die("Security validation failed. Refresh and try again.");
     }
     // Token valid, proceed. Regenerate token *after* processing.

     // --- Process E-Waste Pickup Request Form ---
     if ($submitted_form_id === 'ewaste_pickup_form') {
         $form_id = 'ewaste_pickup_form';
         $form_errors[$form_id] = [];

         // Sanitize
         $pickup_name = sanitize_string($_POST['pickup_name'] ?? '');
         $pickup_email = sanitize_email($_POST['pickup_email'] ?? '');
         $pickup_phone = sanitize_string($_POST['pickup_phone'] ?? '');
         $pickup_address = sanitize_string($_POST['pickup_address'] ?? '');
         $pickup_items_desc = sanitize_string($_POST['pickup_items_desc'] ?? '');
         $pickup_quantity_estimate = sanitize_string($_POST['pickup_quantity_estimate'] ?? ''); // e.g., 1 laptop, 5kg misc
         $pickup_preference = sanitize_string($_POST['pickup_preference'] ?? ''); // e.g., Drop-off, Request Pickup

         $form_submissions[$form_id] = [
             'pickup_name' => $pickup_name, 'pickup_email' => $pickup_email, 'pickup_phone' => $pickup_phone,
             'pickup_address' => $pickup_address, 'pickup_items_desc' => $pickup_items_desc, 'pickup_quantity_estimate' => $pickup_quantity_estimate,
             'pickup_preference' => $pickup_preference
         ];

         // Validate
        // Rules array (adjust 'phone' and add 'in' check if needed)
        $rules = [
            'pickup_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'pickup_email' => 'required_without:pickup_phone|email|maxLength:255', // Require email *or* phone
            'pickup_phone' => 'required_without:pickup_email|phone|maxLength:20',   // Replace 'phone' with custom validation logic
            'pickup_address' => 'required|maxLength:300',
            'pickup_items_desc' => 'required|minLength:10|maxLength:1000',
            'pickup_quantity_estimate' => 'required|maxLength:100',
            'pickup_preference' => 'required|in:Drop-off Info,Request Pickup', // Needs custom 'in' check
        ];
         // NOTE: Implement 'required_without' and 'phone' rule in your validate_data function.
         // Simplified validation here - assume basic rules checked
        $validation_errors = []; // Replace with output of validate_data($form_submissions[$form_id], $rules);

         // Example custom checks (replace with logic in validate_data ideally):
          if (empty($pickup_email) && empty($pickup_phone)) {
              $validation_errors['pickup_email'] = 'Either Email or Phone is required.';
              $validation_errors['pickup_phone'] = 'Either Email or Phone is required.';
          }
         // Basic phone check example (must enhance in validate_data)
         if (!empty($pickup_phone) && !preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $pickup_phone)) {
              $validation_errors['pickup_phone'] = 'Please enter a valid phone number.';
         }
         // Pickup preference check
          if (!in_array($pickup_preference, ['Drop-off Info', 'Request Pickup'])) {
              $validation_errors['pickup_preference'] = 'Please select your preference.';
          }

          $form_errors[$form_id] = $validation_errors;

         // Process if valid
         if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_EWASTE;
            $subject = "E-Waste Request ({$pickup_preference}): " . $pickup_name;

             $body = "An e-waste request has been submitted via the PAHAL website.\n\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Requester Details:\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Name:           " . $pickup_name . "\n";
             $body .= "Email:          " . $pickup_email . "\n";
             $body .= "Phone:          " . $pickup_phone . "\n";
             $body .= "Full Address:   " . $pickup_address . "\n\n";
             $body .= "-------------------------------------------------\n";
             $body .= "E-Waste Details:\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Item Description:\n" . $pickup_items_desc . "\n\n";
             $body .= "Quantity Estimate: " . $pickup_quantity_estimate . "\n";
             $body .= "Preference:       " . $pickup_preference . "\n\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Submitted By:\n";
             $body .= "-------------------------------------------------\n";
             $body .= "IP Address:      " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
             $body .= "Timestamp:       " . date('Y-m-d H:i:s') . "\n";
             $body .= "-------------------------------------------------\n";
             $body .= "ACTION REQUIRED:\n";
             if ($pickup_preference === 'Request Pickup') {
                $body .= "- Contact requester to coordinate pickup feasibility and schedule.\n";
             } else {
                $body .= "- Provide requester with current drop-off location details and timings.\n";
             }
             $body .= "-------------------------------------------------\n";

            $logContext = "[E-Waste Req Form]";
             if (send_email($to, $subject, $body, $pickup_email, $pickup_name, $logContext)) {
                 $message_text = ($pickup_preference === 'Request Pickup')
                    ? "Thank you, {$pickup_name}! Your pickup request is received. We will contact you within 2-3 business days to discuss feasibility and coordinate details."
                    : "Thank you, {$pickup_name}! We've received your inquiry. We will reply shortly via email ({$pickup_email}) or phone ({$pickup_phone}) with information about our current drop-off procedures or upcoming collection drives.";
                 $form_messages[$form_id] = ['type' => 'success', 'text' => $message_text];
                 log_message("{$logContext} Success. Name: {$pickup_name}, Pref: {$pickup_preference}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_EWASTE_PICKUP);
                $form_submissions[$form_id] = []; // Clear on success
             } else {
                 $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$pickup_name}, there was an error submitting your request. Please try again or contact us directly via phone."];
                log_message("{$logContext} FAILED Email Send. Name: {$pickup_name}, Pref: {$pickup_preference}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
         } else {
             $errorCount = count($validation_errors);
             $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) below to submit your request."];
             log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        }
        $_SESSION['scroll_to'] = '#pickup-request';
     }

     // --- Post-Processing & Redirect ---
     unset($_SESSION[CSRF_TOKEN_NAME]); // Clear used token
     $csrf_token = generate_csrf_token(); // Generate new one for the response page
    $_SESSION['form_messages'] = $form_messages;
    $_SESSION['form_errors'] = $form_errors;
    $_SESSION['form_submissions'] = $form_submissions; // Persist data on error

    $scrollTarget = $_SESSION['scroll_to'] ?? '';
    unset($_SESSION['scroll_to']); // Clear after reading

     header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget);
    exit;

 } else {
     // GET Request Handling
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
     if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    $csrf_token = generate_csrf_token(); // Ensure token exists for form render
 }

 // --- Prepare Form Data for HTML ---
 // Need: get_form_value(), get_form_status_html(), get_field_error_html(), get_field_error_class()
 $pickup_req_name_value = get_form_value('ewaste_pickup_form', 'pickup_name');
 $pickup_req_email_value = get_form_value('ewaste_pickup_form', 'pickup_email');
 $pickup_req_phone_value = get_form_value('ewaste_pickup_form', 'pickup_phone');
 $pickup_req_address_value = get_form_value('ewaste_pickup_form', 'pickup_address');
 $pickup_req_items_desc_value = get_form_value('ewaste_pickup_form', 'pickup_items_desc');
 $pickup_req_quantity_value = get_form_value('ewaste_pickup_form', 'pickup_quantity_estimate');
 $pickup_req_preference_value = get_form_value('ewaste_pickup_form', 'pickup_preference');

// Theme colors (align with previous config)
$primary_color = '#2E7D32'; // Main green
$primary_dark_color = '#1B5E20';
$accent_color = '#FFA000'; // Amber/Yellow accent for environment/caution
$accent_dark_color = '#FF8F00';
$secondary_color = '#F9FAFB'; // Off-white bg
$neutral_dark_color = '#374151';
$neutral_medium_color = '#6B7280';


?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
     <meta name="robots" content="index, follow">
    <!-- OG Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
     <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
     <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/e-waste.php"/> <!-- CHANGE URL -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/ewaste-og.jpg"/> <!-- CHANGE Image URL -->

    <!-- Tailwind, Fonts, Icons -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="icon" href="/favicon.ico" type="image/x-icon"> <!-- Favicon -->
     <!-- Leaflet CSS (for Map Placeholder) -->
     <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>


<script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '<?= $primary_color ?>', // Nature Green
            'primary-dark': '<?= $primary_dark_color ?>',
             accent: '<?= $accent_color ?>', // Amber/Yellow
            'accent-dark': '<?= $accent_dark_color ?>',
            secondary: '<?= $secondary_color ?>', // Off-white BG
            neutral: {
              light: '#F3F4F6', DEFAULT: '<?= $neutral_medium_color ?>', dark: '<?= $neutral_dark_color ?>'
            },
             danger: '#DC2626', // Red for warnings
            'danger-light': '#FECACA',
             info: '#3B82F6', // Blue for informational sections
             'info-light': '#EFF6FF',
          },
          fontFamily: {
            'sans': ['Open Sans', 'sans-serif'], 'heading': ['Lato', 'sans-serif'],
          },
           container: {
               center: true, padding: '1rem', screens: { sm: '640px', md: '768px', lg: '1024px', xl: '1140px', '2xl': '1280px' }
            },
           animation: {
               'fade-in-scale': 'fadeInScale 0.6s ease-out forwards',
               'slide-up': 'slideUp 0.5s ease-out forwards',
               'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
            },
            keyframes: {
                fadeInScale: { '0%': { opacity: 0, transform: 'scale(0.95)' }, '100%': { opacity: 1, transform: 'scale(1)' } },
                 slideUp: { '0%': { opacity: 0, transform: 'translateY(20px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                 pulseGlow: {
                     '0%, 100%': { opacity: 1, boxShadow: '0 0 0 0 rgba(255, 160, 0, 0.7)' }, // Accent color with opacity
                     '50%': { opacity: 0.8, boxShadow: '0 0 10px 5px rgba(255, 160, 0, 0)' }
                 }
             }
        }
      }
    }
</script>

<style type="text/tailwindcss">
     /* Base styles from index.php assumed or redefined */
     @layer base {
         html { @apply scroll-smooth; }
         body { @apply font-sans text-neutral-dark leading-relaxed bg-secondary pt-[70px] antialiased; }
         h1, h2, h3, h4, h5, h6 { @apply font-heading text-primary-dark font-bold leading-tight mb-4 tracking-tight; }
         h1 { @apply text-4xl md:text-5xl lg:text-6xl; }
         h2 { @apply text-3xl md:text-4xl text-primary-dark; }
         h3 { @apply text-2xl md:text-3xl text-primary; }
         p { @apply mb-5 text-base md:text-lg text-neutral; }
         a { @apply text-primary hover:text-primary-dark transition duration-300; } /* Green links */
         ul.checkmark-list { @apply list-none space-y-2 mb-6 pl-0; }
         ul.checkmark-list li { @apply flex items-start; }
         ul.checkmark-list li::before { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-green-500 mr-3 mt-1 text-sm flex-shrink-0; }
         ul.cross-list { @apply list-none space-y-2 mb-6 pl-0; } /* For 'not accepted' */
         ul.cross-list li { @apply flex items-start; }
         ul.cross-list li::before { content: '\f00d'; font-family: 'Font Awesome 6 Free'; font-weight: 900; @apply text-danger mr-3 mt-1 text-sm flex-shrink-0; }


        table { @apply w-full border-collapse text-left text-sm text-neutral; }
        thead { @apply bg-primary/10; }
        th { @apply border border-primary/20 px-4 py-2 font-semibold text-primary; }
        td { @apply border border-gray-300 px-4 py-2; }
        tbody tr:nth-child(odd) { @apply bg-white; }
        tbody tr:nth-child(even) { @apply bg-neutral-light; }
        tbody tr:hover { @apply bg-primary/5; }


        label { @apply block text-sm font-medium text-gray-700 mb-1; }
         label.required::after { content: ' *'; @apply text-red-500; } /* Indicate required fields */
        input[type="text"], input[type="email"], input[type="tel"], select, textarea {
             @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400
                    focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition sm:text-sm;
        }
        textarea { @apply min-h-[120px] resize-y; } /* Allow vertical resize */
        select { @apply appearance-none bg-white bg-no-repeat; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="%236B7280"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>'); background-position: right 0.5rem center; background-size: 1.5em 1.5em; }

         /* Accessibility focus */
         *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-accent; }
    }
     @layer components {
         .btn {
            @apply inline-flex items-center justify-center bg-primary text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-primary-dark hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed;
         }
         .btn i { @apply mr-2 -ml-1; } /* Adjust icon margin */
         .btn-secondary { /* Amber button */
            @apply inline-flex items-center justify-center bg-accent text-black font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:text-white hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed;
         }
         .btn-outline {
            @apply inline-flex items-center justify-center bg-transparent border-2 border-primary text-primary font-semibold py-2 px-6 rounded-full hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out;
         }
        .section-padding { @apply py-16 md:py-24; }
        .card { @apply bg-white p-6 rounded-lg shadow-md transition-shadow duration-300 hover:shadow-lg overflow-hidden; } /* Added overflow hidden */
         .form-section { @apply bg-white p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-accent mt-12; } /* Accent border */
         .section-title {
            @apply text-3xl md:text-4xl text-center mb-12 relative pb-4 text-primary-dark;
         }
        .section-title::after {
             content: ''; @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-24 h-1 bg-primary rounded-full;
         }
    }
     @layer utilities {
        .honeypot-field { @apply absolute left-[-5000px] w-px h-px overflow-hidden; }
        .animate-delay-100 { animation-delay: 0.1s; }
        .animate-delay-200 { animation-delay: 0.2s; }
        .animate-delay-300 { animation-delay: 0.3s; }
        /* Add more animation delays if needed */
    }

    /* Page Specific */
    #main-header { /* Assume fixed header */
        @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200;
         min-height: 70px; @apply py-2 md:py-0;
    }
     /* Hero Styles */
     .hero-ewaste {
       /* Use a relevant, high-quality background */
       background: linear-gradient(rgba(30, 90, 40, 0.7), rgba(0, 30, 10, 0.8)), url('https://images.unsplash.com/photo-1611284446314-60a58ac0deb9?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1770&q=80') no-repeat center center/cover;
       @apply text-white section-padding flex items-center justify-center text-center relative overflow-hidden min-h-[60vh] md:min-h-[70vh]; /* Ensure decent height */
    }
     .hero-ewaste h1 { @apply text-white drop-shadow-lg; }
     .hero-ewaste p { @apply text-gray-200 max-w-3xl mx-auto drop-shadow; }
     .hero-ewaste .btn-secondary { @apply !text-white; } /* Ensure white text on amber bg for hero */


     /* Item lists styling */
    .item-list-card { @apply bg-white p-4 rounded-lg shadow border border-gray-200 flex items-start space-x-4 transition-shadow hover:shadow-md; }
     .item-list-card i { @apply text-2xl w-8 h-8 flex items-center justify-center rounded-full flex-shrink-0 mt-1; } /* Rounded background */
    .item-accepted i { @apply text-primary bg-green-100; }
     .item-not-accepted i { @apply text-danger bg-red-100; }
    .item-accepted .item-name { @apply font-semibold text-primary-dark block; }
    .item-not-accepted .item-name { @apply font-semibold text-danger block; }
     .item-details { @apply text-xs text-neutral; }


     /* Environmental Risks Table */
     #risks-table { @apply overflow-x-auto rounded-lg shadow-md border border-gray-200; }
     #risks-table th { @apply whitespace-nowrap; }
     #risks-table td:first-child { @apply font-semibold text-primary-dark; }

    /* Map Container */
     #ewaste-map { @apply h-[400px] w-full rounded-lg shadow-md border border-gray-300 mt-6 z-0; } /* Ensure z-index */
     .leaflet-popup-content { @apply text-sm; } /* Customize popup style */
     .leaflet-control-zoom a { @apply !text-primary-dark; }

     /* Form Error */
     .form-input-error { @apply border-red-500 ring-1 ring-red-500 focus:border-red-500 focus:ring-red-500; }

     /* Partner logo */
     .partner-logo img {
        @apply max-h-16 w-auto filter grayscale hover:grayscale-0 transition duration-300 ease-in-out opacity-70 hover:opacity-100;
     }

</style>
</head>
<body class="bg-secondary">

<!-- Header -->
<header id="main-header">
   <div class="container mx-auto flex flex-wrap items-center justify-between">
        <div class="logo flex-shrink-0">
           <a href="index.php#hero" class="text-3xl font-black text-primary font-heading leading-none flex items-center">
             <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL
            </a>
       </div>
       <nav aria-label="Site Navigation">
            <a href="index.php" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">Home</a>
           <a href="index.php#contact" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">Contact</a>
            <a href="blood-donation.php" class="text-primary hover:text-primary-dark font-semibold px-3 py-2 transition-colors">Blood Donation</a>
       </nav>
   </div>
</header>

<main>

    <!-- Hero Section -->
    <section class="hero-ewaste animate-fade-in-scale">
        <div class="relative z-10 max-w-4xl mx-auto">
            <i class="fas fa-recycle text-6xl text-accent mb-4 animate-pulse-glow"></i>
             <h1 class="mb-4">Tackling E-Waste, Protecting Our Planet</h1>
            <p class="text-xl md:text-2xl mb-8">Join PAHAL's initiative for responsible electronic waste disposal in Jalandhar. Let's recycle today for a cleaner tomorrow.</p>
             <div class="flex flex-wrap justify-center gap-4">
                <a href="#why-recycle" class="btn"><i class="fas fa-leaf"></i> Why Recycle E-Waste?</a>
                 <a href="#pickup-request" class="btn btn-secondary"><i class="fas fa-shipping-fast"></i> Dispose Your E-Waste</a>
            </div>
        </div>
    </section>

     <!-- Why Recycle Section -->
    <section id="why-recycle" class="section-padding bg-white">
        <div class="container mx-auto">
             <h2 class="section-title text-center !mt-0">The Imperative of E-Waste Recycling</h2>
            <div class="grid md:grid-cols-3 gap-8 mt-12 text-center">
                 <div class="card animate-slide-up animate-delay-100 flex flex-col items-center">
                    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-4">
                       <i class="fas fa-shield-alt text-3xl text-primary"></i>
                    </div>
                    <h3 class="text-xl mb-2">Protect Ecosystems</h3>
                    <p class="text-sm">Prevent toxic heavy metals (Lead, Mercury, Cadmium) from contaminating soil, water, and air, safeguarding wildlife and human health.</p>
                </div>
                 <div class="card animate-slide-up animate-delay-200 flex flex-col items-center">
                     <div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mb-4">
                        <i class="fas fa-gem text-3xl text-accent"></i>
                    </div>
                    <h3 class="text-xl mb-2">Conserve Resources</h3>
                    <p class="text-sm">Recover valuable materials like gold, copper, and aluminum, reducing the demand for environmentally damaging mining operations.</p>
                </div>
                 <div class="card animate-slide-up animate-delay-300 flex flex-col items-center">
                      <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">
                         <i class="fas fa-burn text-3xl text-danger"></i>
                    </div>
                    <h3 class="text-xl mb-2">Reduce Harmful Emissions</h3>
                    <p class="text-sm">Proper recycling avoids incineration or landfilling practices that release toxic dioxins and greenhouse gases.</p>
                 </div>
            </div>
            <blockquote class="mt-12 bg-primary/5 border-l-4 border-primary p-6 text-center max-w-3xl mx-auto italic">
                <p class="text-primary-dark text-lg font-semibold">"Recycling e-waste isn't just responsible; it's essential for a sustainable future. PAHAL partners with certified recyclers like <a href='https://karosambhav.com/' target='_blank' rel='noopener noreferrer' class='underline font-bold'>Karo Sambhav</a> to ensure ethical processing."</p>
            </blockquote>
         </div>
    </section>


     <!-- What is E-Waste & Accepted Items -->
    <section id="what-we-accept" class="section-padding bg-neutral-light">
        <div class="container mx-auto">
             <h2 class="section-title text-center">Understanding E-Waste Categories</h2>
             <p class="text-center max-w-3xl mx-auto mb-12">PAHAL facilitates the collection of most common household and office electronics for proper disposal through authorized channels.</p>

             <div class="grid lg:grid-cols-2 gap-12">
                 <!-- Accepted Items -->
                <div class="animate-fade-in">
                     <h3 class="flex items-center text-primary text-2xl mb-6"><i class="fas fa-check-circle text-3xl mr-3 text-green-500"></i>Items We Help Recycle:</h3>
                     <div class="grid sm:grid-cols-2 gap-4">
                         <?php foreach ($accepted_items as $item): ?>
                         <div class="item-list-card item-accepted">
                             <i class="<?= $item['icon'] ?>"></i>
                             <div>
                                 <p class="item-name"><?= htmlspecialchars($item['name']) ?></p>
                                <p class="item-details"><?= htmlspecialchars($item['details']) ?></p>
                             </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                     <p class="text-xs text-neutral mt-4"><i class="fas fa-question-circle mr-1"></i> Unsure? Contact us below!</p>
                 </div>
                <!-- Not Accepted Items -->
                 <div class="animate-fade-in animate-delay-200">
                    <h3 class="flex items-center text-danger text-2xl mb-6"><i class="fas fa-times-circle text-3xl mr-3"></i>Items Requiring Special Handling (Not Collected by Us):</h3>
                    <div class="grid sm:grid-cols-2 gap-4">
                         <?php foreach ($not_accepted_items as $item): ?>
                         <div class="item-list-card item-not-accepted">
                             <i class="<?= $item['icon'] ?>"></i>
                            <div>
                                <p class="item-name"><?= htmlspecialchars($item['name']) ?></p>
                                <p class="item-details"><?= htmlspecialchars($item['details']) ?></p>
                             </div>
                         </div>
                         <?php endforeach; ?>
                     </div>
                    <p class="text-xs text-neutral mt-4"><i class="fas fa-info-circle mr-1"></i> Check with local municipal services for disposal options for these items.</p>
                </div>
             </div>
         </div>
     </section>

    <!-- Environmental Risks Table Section -->
    <section id="ewaste-risks" class="section-padding bg-white">
        <div class="container mx-auto">
            <h2 class="section-title text-center">The Hidden Dangers in Your Devices</h2>
            <p class="text-center max-w-4xl mx-auto mb-10">Improper disposal of electronics releases toxins that harm our environment and health. Understanding the risks highlights the need for responsible recycling.</p>

             <div id="risks-table" class="rounded-lg shadow-lg border border-gray-200 animate-fade-in-scale overflow-hidden">
                 <div class="overflow-x-auto">
                     <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-red-100">
                            <tr>
                                <th scope="col" class="!text-danger px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Hazardous Substance</th>
                                <th scope="col" class="!text-danger px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Common Sources</th>
                                <th scope="col" class="!text-danger px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Potential Impacts</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach($ewaste_risks as $index => $risk): ?>
                            <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-700"><?= htmlspecialchars($risk['risk']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($risk['source']) ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($risk['impact']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                 </div>
            </div>
             <p class="text-center text-xs text-neutral mt-6">Source: Adapted from reports by WHO, UNEP, and other environmental agencies.</p>
         </div>
    </section>

    <!-- How to Dispose / Request Pickup Section -->
    <section id="pickup-request" class="section-padding bg-primary/5">
        <div class="container mx-auto">
            <h2 class="section-title text-center"><i class="fas fa-shipping-fast mr-2"></i>Arrange E-Waste Disposal with PAHAL</h2>
             <p class="text-center max-w-3xl mx-auto mb-10 text-lg">We offer two primary ways to help you dispose of your e-waste: information on drop-off points/events, and coordination for larger quantity pickups (ideal for businesses, schools, or community drives).</p>

             <div class="form-section max-w-3xl mx-auto animate-slide-up">
                 <h3 class="text-2xl mb-6 text-center font-semibold">Submit Your E-Waste Request</h3>
                 <!-- Pickup Request Status Message -->
                 <?= get_form_status_html('ewaste_pickup_form') ?>

                 <form id="ewaste-pickup-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>#pickup-request" method="POST" class="space-y-6">
                     <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                     <input type="hidden" name="form_id" value="ewaste_pickup_form">
                     <div class="honeypot-field" aria-hidden="true">
                         <label for="contact_preference">Keep This Blank</label>
                         <input type="text" id="contact_preference" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off">
                     </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="pickup_name" class="required">Your Name / Organization Name:</label>
                            <input type="text" id="pickup_name" name="pickup_name" required value="<?= $pickup_req_name_value ?>" class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_name') ?>" placeholder="e.g., Anil Kumar or ABC School">
                             <?= get_field_error_html('ewaste_pickup_form', 'pickup_name') ?>
                         </div>
                        <div>
                             <label for="pickup_preference" class="required">Your Preference:</label>
                             <select id="pickup_preference" name="pickup_preference" required class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_preference') ?>">
                                <option value="" disabled <?= empty($pickup_req_preference_value) ? 'selected' : '' ?>>-- Select Option --</option>
                                <option value="Drop-off Info" <?= ($pickup_req_preference_value === 'Drop-off Info') ? 'selected' : '' ?>>Send me Drop-off Info / Event Details</option>
                                <option value="Request Pickup" <?= ($pickup_req_preference_value === 'Request Pickup') ? 'selected' : '' ?>>Request E-Waste Pickup (Larger quantities)</option>
                             </select>
                             <?= get_field_error_html('ewaste_pickup_form', 'pickup_preference') ?>
                        </div>
                     </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                             <label for="pickup_email">Your Email Address:</label>
                            <input type="email" id="pickup_email" name="pickup_email" value="<?= $pickup_req_email_value ?>" class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_email') ?>" placeholder="your.email@example.com">
                            <p class="text-xs text-gray-500 mt-1">Required if phone not provided.</p>
                             <?= get_field_error_html('ewaste_pickup_form', 'pickup_email') ?>
                         </div>
                        <div>
                             <label for="pickup_phone">Your Phone Number:</label>
                            <input type="tel" id="pickup_phone" name="pickup_phone" value="<?= $pickup_req_phone_value ?>" class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_phone') ?>" placeholder="e.g., 9876543210">
                            <p class="text-xs text-gray-500 mt-1">Required if email not provided.</p>
                             <?= get_field_error_html('ewaste_pickup_form', 'pickup_phone') ?>
                         </div>
                    </div>

                    <div>
                         <label for="pickup_address" class="required">Your Full Address (For Pickup / Location context):</label>
                         <textarea id="pickup_address" name="pickup_address" rows="3" required class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_address') ?>" placeholder="Enter full address, including street, area, city, and PIN code."><?= $pickup_req_address_value ?></textarea>
                         <?= get_field_error_html('ewaste_pickup_form', 'pickup_address') ?>
                     </div>

                     <div>
                         <label for="pickup_items_desc" class="required">Brief Description of E-Waste Items:</label>
                        <textarea id="pickup_items_desc" name="pickup_items_desc" rows="4" required class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_items_desc') ?>" placeholder="e.g., 2 old laptops, 1 CRT monitor, several keyboards, box of cables..."><?= $pickup_req_items_desc_value ?></textarea>
                        <?= get_field_error_html('ewaste_pickup_form', 'pickup_items_desc') ?>
                     </div>

                    <div>
                         <label for="pickup_quantity_estimate" class="required">Estimated Quantity:</label>
                         <input type="text" id="pickup_quantity_estimate" name="pickup_quantity_estimate" required value="<?= $pickup_req_quantity_value ?>" class="<?= get_field_error_class('ewaste_pickup_form', 'pickup_quantity_estimate') ?>" placeholder="e.g., Approx 10-15 Kgs, 3 large items, 1 car boot full...">
                         <?= get_field_error_html('ewaste_pickup_form', 'pickup_quantity_estimate') ?>
                    </div>


                    <div class="pt-5 text-center">
                        <button type="submit" class="btn w-full sm:w-auto">
                            <i class="fas fa-paper-plane"></i>Submit Request
                         </button>
                     </div>

                 </form>
            </div>
         </div>
     </section>

    <!-- Data Security Note -->
    <section class="section-padding bg-info-light">
        <div class="container mx-auto">
             <div class="bg-white p-8 rounded-lg shadow-md border-l-4 border-info flex flex-col md:flex-row items-center gap-6 animate-fade-in">
                <i class="fas fa-user-secret text-5xl text-info mb-4 md:mb-0 flex-shrink-0"></i>
                 <div>
                    <h3 class="text-info text-2xl !mt-0">Important: Data Security Before Disposal</h3>
                     <p class="text-neutral-dark text-base md:text-lg">Before handing over computers, laptops, phones, or any device containing personal data, please ensure you securely wipe or physically destroy the storage media (hard drives, SSDs, memory cards). PAHAL and its recycling partners are not responsible for data left on devices.</p>
                     <p class="text-xs text-neutral mt-2">Consider using data wiping software or consulting a professional if needed.</p>
                 </div>
             </div>
        </div>
    </section>


    <!-- Drop-off Locations Map -->
    <section id="dropoff-locations" class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title text-center">Find E-Waste Drop-Off Points</h2>
             <p class="text-center max-w-3xl mx-auto mb-10">While we encourage checking for announced collection drives first, here are some potential partner locations (availability and accepted items may vary - please call ahead if possible or await confirmation after form submission).</p>

             <div id="ewaste-map" class="z-0">
                 <!-- Map will be initialized here by Leaflet JS -->
             </div>
             <p class="text-center text-sm text-neutral mt-4">Map is indicative. Always confirm details before visiting a drop-off point.</p>

         </div>
     </section>

     <!-- Our Recycling Partners -->
    <section id="partners" class="section-padding bg-neutral-light">
         <div class="container mx-auto text-center">
             <h2 class="section-title !mt-0">Our Commitment & Partners</h2>
            <p class="max-w-3xl mx-auto mb-10">We collaborate with government-authorized and environmentally conscious recyclers to ensure your e-waste is processed safely and ethically, maximizing resource recovery and minimizing environmental harm.</p>
             <div class="flex flex-wrap justify-center items-center gap-x-12 gap-y-8">
                 <div class="partner-logo animate-fade-in">
                     <a href="https://karosambhav.com/" target="_blank" rel="noopener noreferrer" title="Karo Sambhav - Authorized E-Waste Recycler">
                        <img src="Karo_Logo-01.webp" alt="Karo Sambhav Logo" class="max-h-16">
                     </a>
                     <p class="text-xs mt-1 text-neutral">Karo Sambhav (Primary Partner)</p>
                </div>
                <!-- Add other partner logos if applicable -->
                 <div class="partner-logo animate-fade-in animate-delay-100">
                     <a href="#" title="Example Partner 2">
                        <img src="https://via.placeholder.com/150x60?text=Partner+Logo+2" alt="Partner 2 Logo">
                     </a>
                    <p class="text-xs mt-1 text-neutral">Another Recycler (Example)</p>
                </div>
                <div class="partner-logo animate-fade-in animate-delay-200">
                    <a href="https://cpcb.nic.in/" target="_blank" rel="noopener noreferrer" title="Central Pollution Control Board - Compliance Info">
                        <img src="https://via.placeholder.com/150x60/000080/FFFFFF?text=CPCB+India" alt="CPCB Logo Example">
                    </a>
                     <p class="text-xs mt-1 text-neutral">Compliance (CPCB Guidelines)</p>
                </div>
            </div>
         </div>
    </section>

    <!-- Contact Section -->
    <section id="contact-section" class="section-padding bg-white">
        <div class="container mx-auto text-center max-w-3xl">
            <h2 class="mb-6 !mt-0">Contact Us About E-Waste</h2>
             <p class="mb-8">For specific questions about our e-waste program, partnership inquiries, or clarification on accepted items not covered here, please reach out.</p>

            <div class="flex flex-col md:flex-row justify-center items-center space-y-4 md:space-y-0 md:space-x-8 mb-10 text-lg">
               <div class="flex items-center">
                   <i class="fas fa-phone-alt text-primary text-2xl mr-3"></i>
                   <a href="tel:+919855614230" class="text-neutral-dark hover:text-primary font-semibold">+91 98556-14230</a> <span class="text-sm ml-1 text-neutral">(Mention E-Waste)</span>
               </div>
                <div class="flex items-center">
                   <i class="fas fa-envelope text-primary text-2xl mr-3"></i>
                   <a href="mailto:ewaste@your-pahal-domain.com?subject=E-Waste Inquiry" class="text-neutral-dark hover:text-primary font-semibold break-all">ewaste@your-pahal-domain.com</a>
                </div>
            </div>

            <a href="index.php#contact" class="btn-outline">See All Contact Options</a>
        </div>
    </section>


</main>

<!-- Footer -->
<footer class="bg-primary-dark text-gray-300 pt-12 pb-8 mt-12">
     <div class="container mx-auto text-center px-4">
         <div class="mb-4">
             <a href="index.php" class="text-2xl font-black text-white hover:text-gray-300 font-heading leading-none">PAHAL NGO</a>
             <p class="text-xs text-gray-400">Driving Sustainable Solutions</p>
         </div>
         <nav class="mb-4 text-sm space-x-4">
              <a href="index.php" class="hover:text-white hover:underline">Home</a> |
             <a href="#why-recycle" class="hover:text-white hover:underline">Why Recycle</a> |
             <a href="#what-we-accept" class="hover:text-white hover:underline">What We Accept</a> |
              <a href="#pickup-request" class="hover:text-white hover:underline">Dispose E-Waste</a> |
              <a href="#dropoff-locations" class="hover:text-white hover:underline">Locations</a> |
              <a href="index.php#contact" class="hover:text-white hover:underline">Contact</a>
        </nav>
        <p class="text-xs text-gray-500 mt-6">
            © <?= $current_year ?> PAHAL NGO. All Rights Reserved. | Promoting responsible environmental practices. <br class="sm:hidden">
             <a href="index.php" class="hover:text-white hover:underline">Main Site</a> | <a href="privacy.php" class="hover:text-white hover:underline">Privacy Policy (Example)</a>
        </p>
        <!-- Optional: Add social media icons relevant to environmental focus if any -->
    </div>
</footer>

 <!-- Leaflet JS (Place after Leaflet CSS) -->
 <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- Main JavaScript -->
<script>
 document.addEventListener('DOMContentLoaded', () => {
    console.log("E-Waste Page JS Loaded");

    // --- Initialize Leaflet Map ---
    const mapElement = document.getElementById('ewaste-map');
     if (mapElement && typeof L !== 'undefined') {
        const map = L.map(mapElement).setView([<?= $map_coords['lat'] ?>, <?= $map_coords['lng'] ?>], <?= $map_zoom ?>);

        // Add Tile Layer (OpenStreetMap is free)
         L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
             maxZoom: 19,
             attribution: '© <a href="http://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
         }).addTo(map);

        // Define a custom icon (optional)
        /*
         const ewasteIcon = L.icon({
            iconUrl: '/path/to/ewaste-marker-icon.png', // Create an icon
            iconSize: [32, 37], // size of the icon
            iconAnchor: [16, 37], // point of the icon which will correspond to marker's location
            popupAnchor: [0, -37] // point from which the popup should open relative to the iconAnchor
        });
        */

        // Add Markers for Drop-off Points
        const dropOffPoints = <?= json_encode($drop_off_points) ?>; // Get points from PHP

         if(dropOffPoints && dropOffPoints.length > 0) {
            dropOffPoints.forEach(point => {
                 if (point.lat && point.lng) {
                    const marker = L.marker([point.lat, point.lng], {
                         // icon: ewasteIcon // Use custom icon if defined
                         title: point.name // Tooltip on hover
                     }).addTo(map);

                     // Add Popup Info
                    let popupContent = `<b>${point.name}</b><br>${point.address}`;
                    if (point.notes) {
                         popupContent += `<br><small><i>${point.notes}</i></small>`;
                     }
                    marker.bindPopup(popupContent);
                }
            });
        } else {
             // Optional: Add a default marker or message if no points defined
             L.marker([<?= $map_coords['lat'] ?>, <?= $map_coords['lng'] ?>])
                 .addTo(map)
                 .bindPopup("E-Waste drop-off details available upon request or during collection drives.").openPopup();
        }

         // Handle map resize issues if needed (e.g., inside tabs/accordions)
         // setTimeout(() => map.invalidateSize(), 500);

     } else if(mapElement) {
         mapElement.innerHTML = '<p class="p-4 text-center text-red-600 bg-red-100 rounded">Error loading map. Please ensure you are connected to the internet.</p>';
     }

     // --- Add other page-specific JS interactions ---
     // Example: Toggle details on item cards? Animate scroll effects?

      // Handle Form Submission Indicator (optional, if using JS validation before PHP submit)
       const pickupForm = document.getElementById('ewaste-pickup-form');
       if (pickupForm) {
           pickupForm.addEventListener('submit', (e) => {
              // Add JS validation here if needed before showing spinner
              // if(!isValid) { e.preventDefault(); return; }

              const submitButton = pickupForm.querySelector('button[type="submit"]');
               if (submitButton) {
                   submitButton.disabled = true;
                  submitButton.innerHTML = '<i class="fas fa-spinner animate-spin mr-2"></i>Submitting...';
              }
           });
      }


 }); // End DOMContentLoaded
</script>


</body>
</html>
