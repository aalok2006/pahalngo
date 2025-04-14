<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Enhanced Version: v2.0
// Features: PHPMailer, CSRF, Honeypot, Logging for Forms, Expanded Content, Dynamic Camps (Example)
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Dependency & Config ---
// Assume index.php structure (adjust path if needed)
$baseDir = __DIR__; // Or dirname(__DIR__) if files are in different folders
if (!file_exists($baseDir . '/vendor/autoload.php')) {
    die("Error: PHPMailer library not found. Please run 'composer install'.");
}
require $baseDir . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Include configuration constants (assuming they are defined in a central file or redeclared here)
// --- Re-declare Core Config (or include a config file) ---
define('USE_SMTP', true); // Use settings from index.php
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your_smtp_user@example.com');
define('SMTP_PASSWORD', 'your_smtp_password');
define('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
define('SMTP_FROM_EMAIL', 'noreply@your-pahal-domain.com');
define('SMTP_FROM_NAME', 'PAHAL NGO Blood Program');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('HONEYPOT_FIELD_NAME', 'website_url_blood');
define('ENABLE_LOGGING', true);
define('LOG_FILE_ERROR', $baseDir . '/logs/form_errors.log');
define('LOG_FILE_BLOOD_DONOR', $baseDir . '/logs/blood_donor_regs.log');
define('LOG_FILE_BLOOD_REQUEST', $baseDir . '/logs/blood_requests.log');

// --- Recipient Emails Specific to Blood Program ---
define('RECIPIENT_EMAIL_DONOR_REG', "bloodbank@your-pahal-domain.com");
define('RECIPIENT_EMAIL_BLOOD_REQUEST', "bloodrequests@your-pahal-domain.com");

// --- Helper Functions (Assume functions like log_message, generate_csrf_token, etc. are available, e.g., via included file or re-declared) ---
// For brevity, let's assume these are available. If not, copy them from index.php enhance code block.
// Ensure these functions are accessible:
// log_message(), generate_csrf_token(), validate_csrf_token(), sanitize_string(), sanitize_email(), validate_data(), send_email()
// Include or redeclare the necessary helper functions from the index.php example here...
// Example: function log_message(...) { ... }
//          function generate_csrf_token(...) { ... }
//          ... etc. ...

// --- Initialize Variables ---
$current_year = date('Y');
$page_title = "Blood Donation & Assistance - PAHAL NGO";
$page_description = "Learn about blood donation eligibility, register as a donor, find upcoming camps, or request blood assistance through PAHAL NGO in Jalandhar.";
$page_keywords = "blood donation, pahal ngo, jalandhar, donate blood, blood request, blood camp, save life, blood donor registration";

// Form state
$form_submissions = [];
$form_messages = []; // form_id => ['type' => 'success|error', 'text' => 'Message']
$form_errors = [];   // form_id => [field_name => 'Error text']
$csrf_token = generate_csrf_token(); // Generate initial token

// --- Dummy Data for Upcoming Camps (Replace with DB query in real app) ---
$upcoming_camps = [
    [
        'id' => 1,
        'date' => new DateTime('2024-11-15'), // Use DateTime objects for easier formatting/comparison
        'time' => '10:00 AM - 3:00 PM',
        'location' => 'PAHAL NGO Main Office, Maqsudan, Jalandhar',
        'organizer' => 'PAHAL & Local Hospital Partners',
        'notes' => 'Walk-ins welcome, pre-registration encouraged. Refreshments provided.'
    ],
    [
        'id' => 2,
        'date' => new DateTime('2024-12-10'),
        'time' => '9:00 AM - 1:00 PM',
        'location' => 'Community Centre, Model Town, Jalandhar',
        'organizer' => 'PAHAL Youth Wing',
        'notes' => 'Special drive focusing on Thalassemia awareness.'
    ],
    // Add more camps
];
// Filter out past camps
$upcoming_camps = array_filter($upcoming_camps, function($camp) {
    return $camp['date'] >= new DateTime('today');
});
// Sort by date
usort($upcoming_camps, function($a, $b) {
    return $a['date'] <=> $b['date'];
});

// --- Blood Type Information ---
$blood_types = [
    'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'
];
$blood_facts = [
    "One donation can save up to three lives.",
    "Blood cannot be manufactured – it only comes from generous donors.",
    "About 1 in 7 people entering a hospital need blood.",
    "The shelf life of donated blood is typically 42 days.",
    "Type O negative blood is the universal red cell donor type.",
    "Type AB positive plasma is the universal plasma donor type.",
    "Regular blood donation may help keep iron levels in check.",
];

// --- Form Processing Logic ---
// ------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_form_id = $_POST['form_id'] ?? null;

    // Anti-Spam & CSRF Checks (as in index.php)
    if (!empty($_POST[HONEYPOT_FIELD_NAME]) || !validate_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        log_message("[SPAM/CSRF DETECTED] Failed security check. Form ID: {$submitted_form_id}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        http_response_code(403);
        die("Security validation failed. Please refresh and try again.");
    }
     // Regenerate token later

    // --- Process Donor Registration Form ---
    if ($submitted_form_id === 'donor_registration_form') {
        $form_id = 'donor_registration_form';
        $form_errors[$form_id] = [];

        // Sanitize
        $donor_name = sanitize_string($_POST['donor_name'] ?? '');
        $donor_email = sanitize_email($_POST['donor_email'] ?? '');
        $donor_phone = sanitize_string($_POST['donor_phone'] ?? '');
        $donor_blood_group = sanitize_string($_POST['donor_blood_group'] ?? '');
        $donor_dob = sanitize_string($_POST['donor_dob'] ?? ''); // YYYY-MM-DD
        $donor_location = sanitize_string($_POST['donor_location'] ?? ''); // City/Area
        $donor_consent = isset($_POST['donor_consent']) && $_POST['donor_consent'] === 'yes';

        $form_submissions[$form_id] = [
            'donor_name' => $donor_name, 'donor_email' => $donor_email, 'donor_phone' => $donor_phone,
            'donor_blood_group' => $donor_blood_group, 'donor_dob' => $donor_dob, 'donor_location' => $donor_location,
            'donor_consent' => $donor_consent ? 'yes' : '' // Store consent value
        ];

        // Validate
        $rules = [
            'donor_name' => 'required|alpha_space|minLength:2|maxLength:100',
            'donor_email' => 'required|email|maxLength:255',
            'donor_phone' => 'required|phone|maxLength:20',
            'donor_blood_group' => 'required|in:' . implode(',', $blood_types), // Custom 'in' rule needed or check separately
            'donor_dob' => 'required|date:Y-m-d', // Custom 'date' rule needed or check separately
            'donor_location' => 'required|maxLength:150',
            'donor_consent' => 'required', // Checkbox validation: value must be 'yes'
        ];
         // --- Add custom validation checks here ---
         $validation_errors = validate_data($form_submissions[$form_id], $rules);

         // Custom: Blood group validation
         if (!in_array($donor_blood_group, $blood_types)) {
             $validation_errors['donor_blood_group'] = "Please select a valid blood group.";
         }
         // Custom: DOB and Age Check (Approximate check for 18-65)
         if (!empty($donor_dob) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $donor_dob)) {
            try {
                $birthDate = new DateTime($donor_dob);
                $today = new DateTime();
                $age = $today->diff($birthDate)->y;
                 if ($age < 18 || $age > 65) {
                    $validation_errors['donor_dob'] = "Donors must typically be between 18 and 65 years old.";
                }
            } catch (Exception $e) {
                 $validation_errors['donor_dob'] = "Invalid date format provided.";
            }
         } elseif(empty($validation_errors['donor_dob'])) { // Only add if no required/format error yet
              $validation_errors['donor_dob'] = "Date of birth is required in YYYY-MM-DD format.";
         }

         // Custom: Consent checkbox validation
        if (!$donor_consent) {
             $validation_errors['donor_consent'] = "You must consent to be contacted.";
         }

         $form_errors[$form_id] = $validation_errors;

        // Process if valid
        if (empty($validation_errors)) {
            $to = RECIPIENT_EMAIL_DONOR_REG;
            $subject = "New Blood Donor Registration: " . $donor_name;

            $body = "A potential blood donor has registered via the PAHAL website.\n\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Donor Details:\n";
            $body .= "-------------------------------------------------\n";
            $body .= "Name:        " . $donor_name . "\n";
            $body .= "DOB:         " . $donor_dob . " (Age Approx: {$age} years)\n"; // Include calculated age
            $body .= "Email:       " . $donor_email . "\n";
            $body .= "Phone:       " . $donor_phone . "\n";
            $body .= "Blood Group: " . $donor_blood_group . "\n";
            $body .= "Location:    " . $donor_location . "\n";
            $body .= "Consent Given: Yes\n";
             $body .= "IP Address:   " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
            $body .= "Timestamp:    " . date('Y-m-d H:i:s') . "\n";
            $body .= "-------------------------------------------------\n";
            $body .= "ACTION: Please verify eligibility and add to the donor database/contact list.\n";
            $body .= "-------------------------------------------------\n";


            $logContext = "[Donor Reg Form]";
             if (send_email($to, $subject, $body, $donor_email, $donor_name, $logContext)) {
                $form_messages[$form_id] = ['type' => 'success', 'text' => "Thank you, {$donor_name}! Your registration is received. We'll contact you regarding donation opportunities and eligibility."];
                log_message("{$logContext} Success. Name: {$donor_name}, Email: {$donor_email}, BG: {$donor_blood_group}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_DONOR);
                $form_submissions[$form_id] = []; // Clear form on success
             } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, {$donor_name}, there was an error processing your registration. Please try again or contact us."];
                log_message("{$logContext} FAILED Email Send. Name: {$donor_name}, Email: {$donor_email}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
        } else {
             $errorCount = count($validation_errors);
             $form_messages[$form_id] = ['type' => 'error', 'text' => "Please correct the {$errorCount} error(s) to complete registration."];
             log_message("[Donor Reg Form] Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
        }
         $_SESSION['scroll_to'] = '#donor-registration';
    }

    // --- Process Blood Request Form ---
    elseif ($submitted_form_id === 'blood_request_form') {
         $form_id = 'blood_request_form';
         $form_errors[$form_id] = [];

         // Sanitize
         $request_patient_name = sanitize_string($_POST['request_patient_name'] ?? '');
         $request_blood_group = sanitize_string($_POST['request_blood_group'] ?? '');
         $request_units = filter_input(INPUT_POST, 'request_units', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]); // Sanitize & validate integer
         $request_hospital = sanitize_string($_POST['request_hospital'] ?? '');
         $request_contact_person = sanitize_string($_POST['request_contact_person'] ?? '');
         $request_contact_phone = sanitize_string($_POST['request_contact_phone'] ?? '');
         $request_urgency = sanitize_string($_POST['request_urgency'] ?? ''); // e.g., Urgent, Within 24h, Within 3 days
         $request_message = sanitize_string($_POST['request_message'] ?? '');


         $form_submissions[$form_id] = [
             'request_patient_name' => $request_patient_name, 'request_blood_group' => $request_blood_group, 'request_units' => $request_units ?: '', // Store sanitized units or empty string
             'request_hospital' => $request_hospital, 'request_contact_person' => $request_contact_person, 'request_contact_phone' => $request_contact_phone,
             'request_urgency' => $request_urgency, 'request_message' => $request_message
         ];

        // Validate
         $rules = [
             'request_patient_name' => 'required|alpha_space|minLength:2|maxLength:100',
             'request_blood_group' => 'required|in:' . implode(',', $blood_types), // Custom check needed
             'request_units' => 'required|integer|min:1|max:20', // Custom rule or check needed
             'request_hospital' => 'required|maxLength:200',
             'request_contact_person' => 'required|alpha_space|minLength:2|maxLength:100',
             'request_contact_phone' => 'required|phone|maxLength:20',
             'request_urgency' => 'required|maxLength:50',
             'request_message' => 'maxLength:2000', // Optional message
         ];
         $validation_errors = validate_data($form_submissions[$form_id], $rules);

         // Custom validation: Units
         if ($request_units === false || $request_units === null) { // filter_input returns false on failure, null if not set
            $validation_errors['request_units'] = "Please enter a valid number of units (1-20).";
         }
          // Custom validation: Blood group
         if (!in_array($request_blood_group, $blood_types)) {
             $validation_errors['request_blood_group'] = "Please select a valid blood group.";
         }

         $form_errors[$form_id] = $validation_errors;


         // Process if valid
         if (empty($validation_errors)) {
             $to = RECIPIENT_EMAIL_BLOOD_REQUEST;
             $subject = "Urgent Blood Request: {$request_blood_group} for {$request_patient_name}"; // Informative subject

             $body = "A blood request has been submitted via the PAHAL website.\n\n";
             $body .= "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n";
             $body .= "          BLOOD REQUEST DETAILS - {$request_urgency}\n";
             $body .= "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n\n";
             $body .= "Patient Name:      " . $request_patient_name . "\n";
             $body .= "Blood Group Needed: " . $request_blood_group . "\n";
             $body .= "Units Required:    " . $request_units . "\n";
             $body .= "Urgency:           " . $request_urgency . "\n";
             $body .= "Hospital Name/Addr:" . $request_hospital . "\n\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Contact Information:\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Contact Person:    " . $request_contact_person . "\n";
             $body .= "Contact Phone:     " . $request_contact_phone . "\n\n";
             $body .= "-------------------------------------------------\n";
             $body .= "Additional Info Provided:\n";
             $body .= "-------------------------------------------------\n";
             $body .= (!empty($request_message) ? $request_message : "(None provided)") . "\n\n";
              $body .= "-------------------------------------------------\n";
             $body .= "Details Provided By:\n";
             $body .= "-------------------------------------------------\n";
              $body .= "IP Address:        " . ($_SERVER['REMOTE_ADDR'] ?? 'Not available') . "\n";
              $body .= "Timestamp:         " . date('Y-m-d H:i:s') . "\n";
             $body .= "-------------------------------------------------\n";
             $body .= "ACTION: Please verify the request and assist if possible by contacting donors or coordinating with blood banks.\n";
             $body .= "-------------------------------------------------\n";


             $logContext = "[Blood Req Form]";
             if (send_email($to, $subject, $body, '', $request_contact_person, $logContext)) { // No reply-to set directly, use requestor info
                 $form_messages[$form_id] = ['type' => 'success', 'text' => "Your blood request has been submitted. We understand the urgency and will do our best to assist. We or a potential donor may contact {$request_contact_person} soon."];
                 log_message("{$logContext} Success. Patient: {$request_patient_name}, BG: {$request_blood_group}, Units: {$request_units}, Contact: {$request_contact_person} ({$request_contact_phone}). IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_BLOOD_REQUEST);
                $form_submissions[$form_id] = []; // Clear form on success
             } else {
                $form_messages[$form_id] = ['type' => 'error', 'text' => "Sorry, there was an error submitting your blood request. Please try again or call us directly for urgent needs."];
                 log_message("{$logContext} FAILED Email Send. Patient: {$request_patient_name}, BG: {$request_blood_group}. Contact: {$request_contact_person}. IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
            }
         } else {
            $errorCount = count($validation_errors);
            $form_messages[$form_id] = ['type' => 'error', 'text' => "Please fix the {$errorCount} error(s) to submit your request."];
            log_message("{$logContext} Validation failed. Errors: " . json_encode($validation_errors) . ". IP: {$_SERVER['REMOTE_ADDR']}", LOG_FILE_ERROR);
         }
         $_SESSION['scroll_to'] = '#request-blood';
    }

    // --- Post-Processing & Redirect ---
     unset($_SESSION[CSRF_TOKEN_NAME]);
     $csrf_token = generate_csrf_token();
    $_SESSION['form_messages'] = $form_messages;
    $_SESSION['form_errors'] = $form_errors;
    $_SESSION['form_submissions'] = $form_submissions;

    $scrollTarget = $_SESSION['scroll_to'] ?? '';
    unset($_SESSION['scroll_to']);

    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . $scrollTarget);
    exit;

} else {
    // --- GET Request: Retrieve session data after redirect ---
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    $csrf_token = generate_csrf_token();
}

// --- Prepare Form Data for HTML ---
// Include functions from index.php enhance block or redeclare
// get_form_value(), get_form_status_html(), get_field_error_html(), get_field_error_class()
// Example re-declaration:
// function get_form_value(...) { global $form_submissions; ... }
// function get_form_status_html(...) { global $form_messages; ... }
// ... etc. ...

// Get form values for donor registration
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
$donor_reg_email_value = get_form_value('donor_registration_form', 'donor_email');
$donor_reg_phone_value = get_form_value('donor_registration_form', 'donor_phone');
$donor_reg_blood_group_value = get_form_value('donor_registration_form', 'donor_blood_group');
$donor_reg_dob_value = get_form_value('donor_registration_form', 'donor_dob');
$donor_reg_location_value = get_form_value('donor_registration_form', 'donor_location');
$donor_reg_consent_value = get_form_value('donor_registration_form', 'donor_consent') === 'yes';

// Get form values for blood request
$blood_req_patient_name_value = get_form_value('blood_request_form', 'request_patient_name');
$blood_req_blood_group_value = get_form_value('blood_request_form', 'request_blood_group');
$blood_req_units_value = get_form_value('blood_request_form', 'request_units');
$blood_req_hospital_value = get_form_value('blood_request_form', 'request_hospital');
$blood_req_contact_person_value = get_form_value('blood_request_form', 'request_contact_person');
$blood_req_contact_phone_value = get_form_value('blood_request_form', 'request_contact_phone');
$blood_req_urgency_value = get_form_value('blood_request_form', 'request_urgency');
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');

// Define theme colors (match index.php)
$primary_color = '#008000'; // Green
$accent_color = '#DC143C'; // Crimson Red
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
    <meta name="robots" content="index, follow"> <!-- SEO optimization -->
    <!-- OG Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>"/>
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="https://your-pahal-domain.com/blood-donation.php"/> <!-- CHANGE URL -->
    <meta property="og:image" content="https://your-pahal-domain.com/images/blood-donation-og.jpg"/> <!-- CHANGE Image URL -->

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="icon" href="/favicon.ico" type="image/x-icon"> <!-- Favicon -->

<script>
    // Basic Tailwind Config (Customize with your actual theme colors)
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '<?= $primary_color ?>',
            'primary-dark': '<?= $primary_dark_color ?>',
            accent: '<?= $accent_color ?>',
            'accent-dark': '<?= $accent_dark_color ?>',
            'bg-light': '<?= $light_bg_color ?>',
            'text-main': '<?= $dark_text_color ?>',
            // Additional colors for blood theme
            blood: '#A91E22', // A typical blood-red color
            'blood-light': '#D84347',
            'blood-dark': '#7F1619',
            info: '#1E90FF', // Dodger Blue for informational sections
            'info-light': '#ADD8E6', // Light Blue
          },
          fontFamily: {
            'sans': ['Open Sans', 'sans-serif'],
            'heading': ['Lato', 'sans-serif'],
          },
          container: {
              center: true,
              padding: '1rem',
               screens: {
                   sm: '640px', md: '768px', lg: '1024px', xl: '1140px', '2xl': '1280px'
                },
            },
          // Adding more subtle animations
           animation: {
                'fade-in': 'fadeIn 0.5s ease-out forwards',
                'slide-in-bottom': 'slideInBottom 0.6s ease-out forwards',
            },
           keyframes: {
               fadeIn: { '0%': { opacity: 0 }, '100%': { opacity: 1 } },
                slideInBottom: { '0%': { opacity: 0, transform: 'translateY(30px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } }
            }
        }
      }
    }
</script>
<style type="text/tailwindcss">
    /* Inherit styles from index.php or redefine */
    @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');

    @layer base {
        html { @apply scroll-smooth; }
        body { @apply font-sans text-text-main leading-relaxed bg-bg-light antialiased; }
        h1, h2, h3, h4, h5, h6 { @apply font-heading text-primary font-bold leading-tight mb-4 tracking-tight; }
        h1 { @apply text-4xl md:text-5xl; }
        h2 { @apply text-3xl md:text-4xl text-primary-dark mt-12; }
        h3 { @apply text-2xl md:text-3xl text-primary-dark; }
        h4 { @apply text-xl font-semibold mb-3; }
        p { @apply mb-4 text-base md:text-lg; }
        a { @apply text-accent hover:text-accent-dark transition-colors duration-200; }
        ul { @apply list-disc list-inside mb-4 pl-2 space-y-2; } /* Consistent list styling */
         ol { @apply list-decimal list-inside mb-4 pl-4 space-y-2; }

         /* Improve form element defaults */
        label { @apply block text-sm font-medium text-gray-700 mb-1; }
        input[type="text"], input[type="email"], input[type="tel"], input[type="date"], select, textarea {
             @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400
                    focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary
                    transition duration-150 ease-in-out sm:text-sm disabled:bg-gray-100;
        }
        select { @apply appearance-none pr-8; /* Custom select arrow needs background image or pseudo-element */ }
         textarea { @apply min-h-[100px]; }

        /* Accessibility focus styling */
         *:focus-visible { @apply outline-none ring-2 ring-offset-2 ring-accent; }
    }
    @layer components {
        .btn {
            @apply inline-block px-6 py-3 rounded-md font-semibold text-white shadow-md transition-all duration-300 ease-in-out;
            @apply bg-accent hover:bg-accent-dark focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transform hover:-translate-y-0.5;
        }
        .btn-secondary {
             @apply inline-block px-6 py-3 rounded-md font-semibold text-white shadow-md transition-all duration-300 ease-in-out;
            @apply bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transform hover:-translate-y-0.5;
        }
        .btn-outline {
             @apply inline-block px-5 py-2 rounded-md font-semibold border-2 transition-all duration-300 ease-in-out text-sm;
             @apply border-accent text-accent hover:bg-accent hover:text-white focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50;
        }
         .card {
             @apply bg-white p-6 rounded-lg shadow-lg overflow-hidden transition-shadow duration-300 hover:shadow-xl;
         }
          .section-padding { @apply py-16 md:py-24; }
          .form-section { @apply bg-white p-6 md:p-8 rounded-lg shadow-lg border-t-4 border-primary mt-12; }
    }
     @layer utilities {
         /* Hide honeypot */
        .honeypot-field { @apply absolute left-[-5000px]; }
         .animate-delay-100 { animation-delay: 0.1s; }
         .animate-delay-200 { animation-delay: 0.2s; }
         .animate-delay-300 { animation-delay: 0.3s; }
    }


    /* --- Page Specific Styles --- */
    #main-header { /* Assuming fixed header from index */
        @apply fixed top-0 left-0 w-full bg-white/95 backdrop-blur-sm z-50 shadow-sm transition-all duration-300 border-b border-gray-200;
        min-height: 70px;
         @apply py-2 md:py-0;
    }
    body { @apply pt-[70px]; /* Adjust if header height changes */ }

    /* Hero Specific */
    #hero-blood {
        @apply bg-gradient-to-br from-blood-light via-red-100 to-info-light text-center section-padding relative overflow-hidden;
         /* --- IMAGE SUGGESTION ---
            Add subtle background texture or image related to blood cells / abstract flow.
            background-image: url('path/to/subtle-blood-texture.svg');
            background-size: cover;
            background-blend-mode: overlay; /* Example blend */
         */
    }
     #hero-blood h1 {
        @apply text-4xl md:text-6xl font-extrabold text-blood-dark mb-4 drop-shadow-lg; /* Use dark blood red for contrast */
     }
     #hero-blood p.lead {
        @apply text-lg md:text-xl text-gray-700 font-medium max-w-3xl mx-auto mb-8 drop-shadow-sm;
    }
     #hero-blood .icon-drop {
        @apply text-6xl text-accent mb-4 animate-pulse;
    }

    /* Eligibility Icons */
     .eligibility-list li i.fa-check { @apply text-green-600; }
     .eligibility-list li i.fa-times { @apply text-red-600; }
     .eligibility-list li i.fa-info-circle { @apply text-blue-600; }

     /* Camps Section Styling */
     .camp-card { @apply bg-white p-5 rounded-lg shadow-md border-l-4 border-accent transition-all duration-300 hover:shadow-lg hover:border-primary hover:scale-[1.01]; }
     .camp-card .camp-date { @apply text-accent font-bold text-lg; }
     .camp-card .camp-location { @apply text-primary-dark font-semibold; }

     /* Form Field Error Highlighting */
    .form-input-error {
        @apply border-red-500 ring-1 ring-red-500 focus:border-red-500 focus:ring-red-500;
    }

     /* Facts Section */
     #blood-facts .fact-card {
        @apply bg-info/10 border border-info/30 p-4 rounded-md text-center text-info flex flex-col items-center justify-center;
     }
     #blood-facts .fact-icon { @apply text-3xl mb-2 text-info; }
     #blood-facts .fact-text { @apply text-sm font-semibold text-text-main; }

</style>
</head>
<body class="bg-bg-light">
    <!-- Shared Header (Could be an include) -->
    <header id="main-header">
       <div class="container mx-auto px-4 flex flex-wrap items-center justify-between">
           <div class="logo flex-shrink-0">
               <a href="index.php#hero" class="text-3xl font-black text-accent font-heading leading-none flex items-center">
                 <img src="icon.webp" alt="PAHAL Icon" class="h-8 w-8 mr-2"> PAHAL
               </a>
           </div>
           <nav aria-label="Site Navigation">
                <a href="index.php" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">Home</a>
                <a href="index.php#contact" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">Contact</a>
                 <!-- Consider a dropdown for programs -->
                 <a href="e-waste.php" class="text-primary hover:text-accent font-semibold px-3 py-2 transition-colors">E-Waste</a>
           </nav>
       </div>
    </header>

<main>
    <!-- Hero Section -->
    <section id="hero-blood" class="animate-fade-in">
         <div class="container mx-auto relative z-10">
             <div class="icon-drop"><i class="fas fa-tint"></i></div> <!-- Droplet icon -->
             <h1>Donate Blood, Give the Gift of Life</h1>
             <p class="lead">Join PAHAL's mission to ensure a readily available and safe blood supply for our community. Your generosity can make a profound difference.</p>
             <div class="space-x-4 mt-10">
                 <a href="#donor-registration" class="btn btn-secondary text-lg"><i class="fas fa-user-plus mr-2"></i> Register as Donor</a>
                 <a href="#request-blood" class="btn text-lg"><i class="fas fa-ambulance mr-2"></i> Request Blood</a>
             </div>
         </div>
         <!-- Optional background elements -->
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
                                <li><i class="fas fa-calendar-alt mr-2 text-gray-500"></i> Are 18-65 years old (check specific camp limits).</li>
                                <li><i class="fas fa-weight-hanging mr-2 text-gray-500"></i> Weigh at least 50 kg (110 lbs).</li>
                                <li><i class="fas fa-heart mr-2 text-gray-500"></i> Are in good general health and feeling well.</li>
                                <li><i class="fas fa-tint mr-2 text-gray-500"></i> Meet hemoglobin level requirements (tested on site).</li>
                            </ul>
                        </div>
                         <div>
                            <h4 class="text-lg text-accent mb-2"><i class="fas fa-times mr-2 text-red-600"></i>Consult staff if you:</h4>
                             <ul class="text-text-main list-none pl-0 space-y-1 text-sm">
                                <li><i class="fas fa-pills mr-2 text-gray-500"></i> Are taking certain medications.</li>
                                <li><i class="fas fa-procedures mr-2 text-gray-500"></i> Have certain medical conditions (e.g., heart, blood pressure issues).</li>
                                <li><i class="fas fa-plane mr-2 text-gray-500"></i> Have recently traveled internationally.</li>
                                <li><i class="fas fa-calendar-minus mr-2 text-gray-500"></i> Donated whole blood recently (within ~3 months).</li>
                            </ul>
                         </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-6"><i class="fas fa-info-circle mr-1"></i>This is a guide only. Eligibility is confirmed at the donation site via a confidential screening.</p>
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
                 <!-- Donor Registration Status Message -->
                 <?= get_form_status_html('donor_registration_form') ?>

                 <form id="donor-registration-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>#donor-registration" method="POST" class="space-y-6">
                     <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                     <input type="hidden" name="form_id" value="donor_registration_form">
                     <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_donor">Keep Blank</label><input type="text" id="website_url_blood_donor" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>

                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                             <label for="donor_name" class="required">Full Name:</label>
                             <input type="text" id="donor_name" name="donor_name" required value="<?= $donor_reg_name_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_name') ?>" placeholder="e.g., Priya Sharma">
                             <?= get_field_error_html('donor_registration_form', 'donor_name') ?>
                         </div>
                         <div>
                              <label for="donor_dob" class="required">Date of Birth:</label>
                             <input type="date" id="donor_dob" name="donor_dob" required value="<?= $donor_reg_dob_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_dob') ?>" max="<?= date('Y-m-d') ?>"> <!-- Add max date -->
                              <p class="text-xs text-gray-500 mt-1">YYYY-MM-DD format. Must be 18-65 years.</p>
                             <?= get_field_error_html('donor_registration_form', 'donor_dob') ?>
                         </div>
                    </div>

                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                             <label for="donor_email" class="required">Email Address:</label>
                             <input type="email" id="donor_email" name="donor_email" required value="<?= $donor_reg_email_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_email') ?>" placeholder="e.g., priya.sharma@email.com">
                             <?= get_field_error_html('donor_registration_form', 'donor_email') ?>
                         </div>
                        <div>
                             <label for="donor_phone" class="required">Mobile Number:</label>
                             <input type="tel" id="donor_phone" name="donor_phone" required value="<?= $donor_reg_phone_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_phone') ?>" placeholder="e.g., 98xxxxxxxx">
                             <?= get_field_error_html('donor_registration_form', 'donor_phone') ?>
                         </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                             <label for="donor_blood_group" class="required">Blood Group:</label>
                             <select id="donor_blood_group" name="donor_blood_group" required aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_blood_group') ?>">
                                 <option value="" disabled <?= empty($donor_reg_blood_group_value) ? 'selected' : '' ?>>Select Your Blood Group</option>
                                 <?php foreach($blood_types as $type): ?>
                                 <option value="<?= $type ?>" <?= ($donor_reg_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                 <?php endforeach; ?>
                             </select>
                            <?= get_field_error_html('donor_registration_form', 'donor_blood_group') ?>
                         </div>
                        <div>
                             <label for="donor_location" class="required">Location (Area/City):</label>
                            <input type="text" id="donor_location" name="donor_location" required value="<?= $donor_reg_location_value ?>" aria-required="true" class="<?= get_field_error_class('donor_registration_form', 'donor_location') ?>" placeholder="e.g., Maqsudan, Jalandhar">
                            <?= get_field_error_html('donor_registration_form', 'donor_location') ?>
                        </div>
                     </div>

                     <div class="mt-6">
                         <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer">
                             <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?>
                                    class="h-5 w-5 text-primary rounded border-gray-300 focus:ring-primary">
                             <span class="text-sm text-gray-700">I consent to PAHAL contacting me regarding blood donation needs and camps based on the information provided. I understand this does not guarantee eligibility at the time of donation.</span>
                        </label>
                        <?= get_field_error_html('donor_registration_form', 'donor_consent') ?>
                     </div>


                    <div class="pt-5">
                        <button type="submit" class="btn btn-secondary w-full sm:w-auto flex items-center justify-center">
                             <i class="fas fa-check-circle mr-2"></i>Register Now
                         </button>
                     </div>

                 </form>
            </div>
         </div>
     </section>


    <!-- Blood Request Section -->
    <section id="request-blood" class="section-padding bg-accent/5">
         <div class="container mx-auto">
            <h2 class="section-title text-center text-accent"><i class="fas fa-first-aid mr-2"></i>Request Blood Assistance</h2>
             <p class="text-center max-w-3xl mx-auto mb-10 text-lg">If you or someone you know requires blood urgently or for a planned procedure, please submit a request. PAHAL will try to connect you with registered donors or guide you to local blood banks.</p>
             <p class="text-center max-w-3xl mx-auto mb-10 text-sm font-semibold text-red-700"><i class="fas fa-exclamation-triangle mr-1"></i>Disclaimer: PAHAL acts as a facilitator. We do not operate a blood bank directly. Availability depends on donor responses and blood bank stocks. For emergencies, please contact hospitals/blood banks directly first.</p>


             <div class="form-section max-w-3xl mx-auto !border-accent animate-fade-in">
                  <!-- Blood Request Status Message -->
                 <?= get_form_status_html('blood_request_form') ?>

                <form id="blood-request-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>#request-blood" method="POST" class="space-y-6">
                     <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_token ?>">
                    <input type="hidden" name="form_id" value="blood_request_form">
                     <div class="honeypot-field" aria-hidden="true"><label for="website_url_blood_req">Keep Blank</label><input type="text" id="website_url_blood_req" name="<?= HONEYPOT_FIELD_NAME ?>" tabindex="-1" autocomplete="off"></div>


                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                             <label for="request_patient_name" class="required">Patient's Full Name:</label>
                            <input type="text" id="request_patient_name" name="request_patient_name" required value="<?= $blood_req_patient_name_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_patient_name') ?>">
                            <?= get_field_error_html('blood_request_form', 'request_patient_name') ?>
                        </div>
                         <div>
                            <label for="request_blood_group" class="required">Blood Group Needed:</label>
                             <select id="request_blood_group" name="request_blood_group" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_blood_group') ?>">
                                 <option value="" disabled <?= empty($blood_req_blood_group_value) ? 'selected' : '' ?>>Select Blood Group</option>
                                <?php foreach($blood_types as $type): ?>
                                 <option value="<?= $type ?>" <?= ($blood_req_blood_group_value === $type) ? 'selected' : '' ?>><?= $type ?></option>
                                 <?php endforeach; ?>
                            </select>
                            <?= get_field_error_html('blood_request_form', 'request_blood_group') ?>
                        </div>
                     </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                            <label for="request_units" class="required">Units Required:</label>
                             <input type="number" id="request_units" name="request_units" required value="<?= $blood_req_units_value ?>" min="1" max="20" step="1" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_units') ?>" placeholder="e.g., 2">
                            <?= get_field_error_html('blood_request_form', 'request_units') ?>
                        </div>
                        <div>
                             <label for="request_urgency" class="required">Urgency:</label>
                            <select id="request_urgency" name="request_urgency" required aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_urgency') ?>">
                                 <option value="" disabled <?= empty($blood_req_urgency_value) ? 'selected' : '' ?>>Select Urgency Level</option>
                                 <option value="Emergency (Immediate)" <?= ($blood_req_urgency_value === 'Emergency (Immediate)') ? 'selected' : '' ?>>Emergency (Immediate)</option>
                                 <option value="Urgent (Within 24 Hours)" <?= ($blood_req_urgency_value === 'Urgent (Within 24 Hours)') ? 'selected' : '' ?>>Urgent (Within 24 Hours)</option>
                                 <option value="Within 2-3 Days" <?= ($blood_req_urgency_value === 'Within 2-3 Days') ? 'selected' : '' ?>>Within 2-3 Days</option>
                                 <option value="Planned (Within 1 Week)" <?= ($blood_req_urgency_value === 'Planned (Within 1 Week)') ? 'selected' : '' ?>>Planned (Within 1 Week)</option>
                            </select>
                             <?= get_field_error_html('blood_request_form', 'request_urgency') ?>
                         </div>
                    </div>

                     <div>
                         <label for="request_hospital" class="required">Hospital Name & Location:</label>
                        <input type="text" id="request_hospital" name="request_hospital" required value="<?= $blood_req_hospital_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_hospital') ?>" placeholder="e.g., Civil Hospital, Jalandhar">
                         <?= get_field_error_html('blood_request_form', 'request_hospital') ?>
                     </div>


                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                            <label for="request_contact_person" class="required">Contact Person:</label>
                            <input type="text" id="request_contact_person" name="request_contact_person" required value="<?= $blood_req_contact_person_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_person') ?>" placeholder="e.g., Attendant's Name">
                            <?= get_field_error_html('blood_request_form', 'request_contact_person') ?>
                         </div>
                         <div>
                            <label for="request_contact_phone" class="required">Contact Phone Number:</label>
                             <input type="tel" id="request_contact_phone" name="request_contact_phone" required value="<?= $blood_req_contact_phone_value ?>" aria-required="true" class="<?= get_field_error_class('blood_request_form', 'request_contact_phone') ?>">
                             <?= get_field_error_html('blood_request_form', 'request_contact_phone') ?>
                        </div>
                     </div>

                     <div>
                        <label for="request_message">Additional Information (Optional):</label>
                        <textarea id="request_message" name="request_message" rows="4" class="<?= get_field_error_class('blood_request_form', 'request_message') ?>" placeholder="e.g., Patient condition, doctor's name, specific instructions..."><?= $blood_req_message_value ?></textarea>
                         <?= get_field_error_html('blood_request_form', 'request_message') ?>
                     </div>

                     <div class="pt-5">
                        <button type="submit" class="btn w-full sm:w-auto flex items-center justify-center">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Request
                        </button>
                     </div>
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
                        <?php if (!empty($camp['notes'])): ?>
                         <p class="text-xs bg-primary/10 text-primary-dark p-2 rounded italic"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($camp['notes']) ?></p>
                         <?php endif; ?>
                          <!-- Optional: Add link to map or registration specific to camp -->
                          <!-- <a href="#" class="btn-outline mt-4 text-xs">More Details / Map</a> -->
                     </div>
                    <?php endforeach; ?>
                </div>
             <?php else: ?>
                 <div class="text-center bg-blue-50 p-8 rounded-lg border border-blue-200 max-w-2xl mx-auto shadow">
                    <i class="fas fa-info-circle text-4xl text-blue-500 mb-4"></i>
                    <h3 class="text-xl font-semibold text-blue-800 mb-2">No Camps Currently Scheduled</h3>
                    <p class="text-blue-700">Please check back soon for updates on future blood donation camps. You can also <a href="#donor-registration" class="font-bold underline hover:text-blue-900">register as a donor</a> to be notified.</p>
                 </div>
            <?php endif; ?>
        </div>
    </section>


     <!-- Facts & Figures Section -->
     <section id="blood-facts" class="section-padding bg-gray-100">
        <div class="container mx-auto">
            <h2 class="section-title text-center !mt-0">Did You Know? Blood Facts</h2>
             <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
                <?php foreach ($blood_facts as $index => $fact): ?>
                <div class="fact-card animate-fade-in animate-delay-<?= ($index + 1) * 100 ?>">
                     <!-- Use relevant icons -->
                     <i class="fas <?= $index % 4 == 0 ? 'fa-users' : ($index % 4 == 1 ? 'fa-hourglass-half' : ($index % 4 == 2 ? 'fa-universal-access' : 'fa-vial')) ?> fact-icon"></i>
                    <p class="fact-text"><?= htmlspecialchars($fact) ?></p>
                </div>
                <?php endforeach; ?>
                 <div class="fact-card bg-primary/10 border-primary/30 text-primary animate-fade-in animate-delay-<?= (count($blood_facts) + 1) * 100 ?>">
                     <i class="fas fa-plus-circle fact-icon !text-primary"></i>
                     <p class="fact-text !text-primary-dark">Your donation adds to this vital resource!</p>
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
                <p><strong class="text-primary-dark">Coordinator:</strong> [Insert Coordinator Name or Dept, e.g., Health Programs Lead]</p>
                <p>
                    <i class="fas fa-phone mr-2 text-primary"></i>
                    <strong class="text-primary-dark">Direct Line:</strong>
                    <a href="tel:+919855614230" class="hover:underline font-semibold text-accent ml-1">+91 98556-14230</a> (Specify Blood Program)
                 </p>
                 <p>
                    <i class="fas fa-envelope mr-2 text-primary"></i>
                    <strong class="text-primary-dark">Email:</strong>
                    <a href="mailto:bloodprogram@your-pahal-domain.com?subject=Blood%20Donation%20Inquiry" class="hover:underline font-semibold text-accent ml-1 break-all">bloodprogram@your-pahal-domain.com</a> <!-- Use dedicated email if possible -->
                </p>
             </div>
            <div class="mt-10">
                <a href="index.php#contact" class="btn btn-secondary"><i class="fas fa-address-book mr-2"></i>General PAHAL Contact Info</a>
             </div>
        </div>
    </section>

</main>

<!-- Footer (Could be an include) -->
<footer class="bg-primary-dark text-gray-300 pt-12 pb-8 mt-12">
    <div class="container mx-auto px-4 text-center">
         <div class="mb-4">
             <a href="index.php" class="text-2xl font-black text-white hover:text-gray-300 font-heading leading-none">PAHAL NGO</a>
             <p class="text-xs text-gray-400">Promoting Health and Well-being</p>
        </div>
        <nav class="mb-4 text-sm space-x-4">
             <a href="index.php" class="hover:text-white hover:underline">Home</a> |
             <a href="#donor-registration" class="hover:text-white hover:underline">Register Donor</a> |
             <a href="#request-blood" class="hover:text-white hover:underline">Request Blood</a> |
              <a href="#upcoming-camps" class="hover:text-white hover:underline">Camps</a> |
              <a href="index.php#contact" class="hover:text-white hover:underline">Contact</a>
        </nav>
         <p class="text-xs text-gray-500 mt-6">
            © <?= $current_year ?> PAHAL NGO. All Rights Reserved. <br class="sm:hidden">
            <a href="index.php#profile" class="hover:text-white hover:underline">About Us</a> | <a href="privacy-policy.php" class="hover:text-white hover:underline">Privacy Policy (Example)</a>
         </p>
   </div>
</footer>

<!-- JavaScript for form interactions, etc. -->
<script>
 document.addEventListener('DOMContentLoaded', () => {
    // Basic form interaction: Maybe add validation feedback logic, spinners on submit
    // Example: Show/hide elements based on selection

     // Age calculation hint or simple check on DOB change (optional enhancement)
     const dobInput = document.getElementById('donor_dob');
     if (dobInput) {
         dobInput.addEventListener('change', () => {
             try {
                 const birthDate = new Date(dobInput.value);
                 const today = new Date();
                 let age = today.getFullYear() - birthDate.getFullYear();
                 const m = today.getMonth() - birthDate.getMonth();
                 if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                     age--;
                 }
                 const ageHint = dobInput.parentElement.querySelector('.text-xs'); // Adjust selector if needed
                 if (ageHint) {
                     if (age >= 18 && age <= 65) {
                        ageHint.textContent = `Approx. age: ${age}. Looks good!`;
                        ageHint.style.color = 'green';
                    } else if (age > 0) {
                         ageHint.textContent = `Approx. age: ${age}. Note: Age must be 18-65 for donation.`;
                        ageHint.style.color = 'orange';
                    } else {
                         ageHint.textContent = 'YYYY-MM-DD format. Must be 18-65 years old.';
                         ageHint.style.color = ''; // Reset color
                    }
                 }
            } catch(e) { /* Handle potential errors silently */ }
         });
     }

      // Smooth scroll to sections from hero buttons (if needed, already handled in shared nav usually)
      document.querySelectorAll('a[href^="#"]').forEach(anchor => {
           // Add specific scroll logic if needed (can reuse from index.php script)
      });

      // Add any specific JS logic for this page here
       console.log("Blood Donation Page JS Loaded");


       // Handle Session Scroll Target (If the main JS isn't included or doesn't handle it globally)
       <?php if(isset($_SESSION['scroll_to_handled'])): unset($_SESSION['scroll_to_handled']); /* Indicate scroll happened */ ?>
           console.log('Scrolling based on session');
           const targetId = '<?= $_SESSION['scroll_to_handled'] ?? '' ?>';
           const targetElement = targetId ? document.querySelector(targetId) : null;
           if(targetElement) {
               const headerOffset = document.getElementById('main-header')?.offsetHeight ?? 70;
               const elementPosition = targetElement.getBoundingClientRect().top;
               const offsetPosition = elementPosition + window.pageYOffset - headerOffset - 20; // Add buffer
                window.scrollTo({ top: offsetPosition, behavior: 'smooth'});
           }
       <?php endif; ?>


 });

 </script>

</body>
</html>
