<?php
// ========================================================================
// PAHAL NGO Website - Blood Donation & Request Page
// Version: 3.0 (UI/UX Enhancement)
// Features: Theme Toggle, Modern UI, Enhanced Animations & Feedback
// Backend: PHP mail(), CSRF, Honeypot, Logging
// ========================================================================

// Start session for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Configuration (Keep Existing) ---
// ... (RECIPIENT_EMAIL_*, SENDER_EMAIL_*, CSRF_TOKEN_NAME, HONEYPOT_FIELD_NAME, ENABLE_LOGGING, LOG_FILE_*) ...
// ... (Same as your provided code) ...
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

// --- Helper Functions (Keep Existing - Ensure they are included/defined) ---
// ... (log_message, generate_csrf_token, validate_csrf_token, sanitize_string, sanitize_email, validate_data, send_email, get_form_value) ...
// You MUST have these functions available from your previous code or include them here.
// For brevity, assuming they exist as you provided.

// --- UPDATED Helper Functions for Enhanced UI ---

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
     // Combine base classes with error class if applicable
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


// --- Initialize Page Variables ---
$current_year = date('Y');
$page_title = "Donate Blood & Request Assistance | PAHAL NGO Jalandhar"; // Slightly optimized
$page_description = "Register as a blood donor, find donation camps, or request urgent blood assistance through PAHAL NGO in Jalandhar. Your donation saves lives."; // Slightly more active
$page_keywords = "pahal ngo blood donation, jalandhar blood bank, donate blood jalandhar, blood request jalandhar, blood camp schedule, save life, volunteer donor"; // More specific

// --- Initialize Form State Variables ---
// (Same as before)
$form_submissions = [];
$form_messages = [];
$form_errors = [];
$csrf_token = generate_csrf_token();

// --- Dummy Data & Logic (Same as before) ---
// ... (Upcoming Camps & Blood Facts arrays) ...
$upcoming_camps = [ /* ... as before ... */ ];
$upcoming_camps = array_filter($upcoming_camps, fn($camp) => $camp['date'] >= new DateTime('today'));
usort($upcoming_camps, fn($a, $b) => $a['date'] <=> $b['date']);
$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$blood_facts = [ /* ... as before ... */ ];


// --- Form Processing Logic (Keep Existing - No Changes Needed Here) ---
// ... (The entire if ($_SERVER["REQUEST_METHOD"] == "POST") block remains the same) ...
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ... (Your existing POST handling logic including CSRF, Honeypot, Validation, Email Sending, Logging, Redirect) ...
    // Make sure this section correctly populates $form_messages, $form_errors, $form_submissions
    // and handles the redirect with $_SESSION variables as before.
} else {
    // --- GET Request: Retrieve session data ---
    // (Same as before)
    if (isset($_SESSION['form_messages'])) { $form_messages = $_SESSION['form_messages']; unset($_SESSION['form_messages']); }
    if (isset($_SESSION['form_errors'])) { $form_errors = $_SESSION['form_errors']; unset($_SESSION['form_errors']); }
    if (isset($_SESSION['form_submissions'])) { $form_submissions = $_SESSION['form_submissions']; unset($_SESSION['form_submissions']); }
    $csrf_token = generate_csrf_token(); // Ensure token exists
}

// --- Prepare Form Data for HTML (Keep Existing - No Changes Needed Here) ---
// ... (get_form_value calls for all form fields) ...
$donor_reg_name_value = get_form_value('donor_registration_form', 'donor_name');
// ... and so on for all other form fields ...
$blood_req_message_value = get_form_value('blood_request_form', 'request_message');
$donor_reg_consent_value = get_form_value('donor_registration_form', 'donor_consent') === 'yes';

?>
<!DOCTYPE html>
<!-- Add class="dark" for default dark mode -->
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#1a202c"> <!-- Dark theme color -->
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
        hr { @apply border-theme-border my-8; }
    }

    @layer components {
        .section-padding { @apply py-16 md:py-24 px-4; }
        .section-title { @apply text-center mb-12 md:mb-16; }
        .btn { @apply inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-theme-surface transition-all duration-200 transform hover:-translate-y-1 disabled:opacity-50 disabled:cursor-not-allowed; }
        .btn-primary { @apply text-white bg-theme-primary hover:bg-opacity-90 focus:ring-theme-primary; }
        .btn-secondary { @apply text-white bg-theme-secondary hover:bg-opacity-90 focus:ring-theme-secondary; }
        .btn-accent { @apply text-white bg-theme-accent hover:bg-opacity-90 focus:ring-theme-accent; }
        .btn-outline { @apply text-theme-primary border-theme-primary bg-transparent hover:bg-theme-primary/10 focus:ring-theme-primary; }
        .btn-icon { @apply p-2 rounded-full; } /* For icon-only buttons like theme toggle */

        /* Enhanced Card Style */
        .card { @apply bg-theme-surface p-6 md:p-8 rounded-xl shadow-card dark:shadow-card-dark border border-theme-border/50 overflow-hidden relative transition-all duration-300; }
        .card:hover { @apply shadow-lg dark:shadow-xl transform scale-[1.02]; }

        /* Glassmorphism Panel Style */
        .panel { @apply bg-theme-surface/70 dark:bg-theme-surface/60 backdrop-blur-lg border border-theme-border/30 rounded-2xl shadow-lg p-6 md:p-8; }

        /* Form Input Styling */
        .form-input { @apply block w-full px-4 py-2.5 rounded-lg border bg-theme-surface/50 border-theme-border placeholder-theme-text-muted text-theme-text shadow-sm transition duration-150 ease-in-out focus:border-theme-primary focus:ring focus:ring-theme-primary focus:ring-opacity-50 focus:outline-none disabled:opacity-60; }
        label { @apply block text-sm font-medium text-theme-text-muted mb-1.5; }
        label.required::after { content: '*'; @apply text-theme-accent ml-1; }
        select.form-input { @apply pr-10; } /* Space for dropdown arrow */
        textarea.form-input { @apply min-h-[100px]; }
        .form-input-error { @apply border-theme-accent ring-1 ring-theme-accent focus:border-theme-accent focus:ring-theme-accent; }
        .form-section { @apply card border-l-4 border-theme-primary mt-8; } /* Default border */
        #request-blood .form-section { @apply !border-theme-accent; } /* Specific border for request */

        .honeypot-field { @apply !absolute !-left-[5000px]; } /* Improved hiding */

        /* Spinner for Buttons */
        .spinner { @apply inline-block animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-current mr-2; }
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
    body { @apply pt-[70px]; }
    /* Hero Specific */
    #hero-blood { @apply bg-gradient-to-br from-red-50 dark:from-gray-900 via-red-100 dark:via-red-900/20 to-sky-100 dark:to-sky-900/20 text-center section-padding relative overflow-hidden; }
     #hero-blood h1 { @apply text-4xl md:text-6xl font-extrabold text-theme-accent dark:text-red-400 mb-4 drop-shadow-lg; }
     #hero-blood p.lead { @apply text-lg md:text-xl text-gray-700 dark:text-gray-300 font-medium max-w-3xl mx-auto mb-8 drop-shadow-sm; }
     #hero-blood .icon-drop { @apply text-6xl text-theme-accent mb-4 animate-pulse; }
     #hero-blood .cta-buttons { @apply flex flex-col sm:flex-row items-center justify-center gap-4 mt-10; }

     /* Eligibility Icons */
     .eligibility-list li i { @apply text-lg w-5 text-center; }
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
    <section class="section-padding">
        <div class="container mx-auto">
            <h2 class="section-title">Understanding Blood Donation</h2>
            <div class="grid md:grid-cols-2 gap-10 lg:gap-12 mt-12">
                <!-- Why Donate? -->
                <div class="card animate-slide-in-bottom animation-delay-100">
                     <h3 class="!mt-0 flex items-center gap-3"><i class="fas fa-heartbeat text-3xl text-theme-accent"></i>Why Your Donation Matters</h3>
                     <p class="text-theme-text-muted">Blood is a critical resource, constantly needed for surgeries, accident victims, cancer patients, and individuals with blood disorders. It cannot be artificially created.</p>
                     <ul class="text-theme-text list-none pl-0 space-y-3 mt-4">
                         <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1"></i> Directly saves lives in emergencies and medical treatments.</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1"></i> Supports patients undergoing long-term therapies (e.g., chemotherapy).</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1"></i> Crucial component for maternal care during childbirth.</li>
                        <li class="flex items-start"><i class="fas fa-check-circle mr-3 text-theme-success mt-1"></i> Represents a vital act of community solidarity and support.</li>
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
                                <li class="flex items-center gap-2"><i class="fas fa-calendar-alt"></i> Are 18-65 years old (check limits).</li>
                                <li class="flex items-center gap-2"><i class="fas fa-weight-hanging"></i> Weigh ≥ 50 kg (110 lbs).</li>
                                <li class="flex items-center gap-2"><i class="fas fa-heart"></i> Are in good general health.</li>
                                <li class="flex items-center gap-2"><i class="fas fa-tint"></i> Meet hemoglobin levels (tested).</li>
                            </ul>
                        </div>
                         <div>
                            <h4 class="text-lg text-theme-warning mb-2 flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i>Consult staff if you:</h4>
                             <ul class="text-theme-text-muted list-none pl-0 space-y-1.5 text-sm">
                                <li class="flex items-center gap-2"><i class="fas fa-pills"></i> Take certain medications.</li>
                                <li class="flex items-center gap-2"><i class="fas fa-procedures"></i> Have specific medical conditions.</li>
                                <li class="flex items-center gap-2"><i class="fas fa-plane"></i> Traveled internationally recently.</li>
                                <li class="flex items-center gap-2"><i class="fas fa-calendar-minus"></i> Donated recently (~3 months).</li>
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
                     <div class="mt-6 pt-2"> <label for="donor_consent" class="flex items-center space-x-3 cursor-pointer p-3 rounded-md hover:bg-theme-primary/10 transition-colors"> <input type="checkbox" id="donor_consent" name="donor_consent" value="yes" required aria-required="true" <?= $donor_reg_consent_value ? 'checked' : '' ?> class="h-5 w-5 text-theme-primary rounded border-theme-border focus:ring-theme-primary form-checkbox" <?= get_aria_describedby('donor_registration_form', 'donor_consent') ?>> <span class="text-sm text-theme-text-muted dark:text-theme-text">I consent to PAHAL contacting me for donation needs/camps & understand this is not eligibility confirmation.</span> </label> <?= get_field_error_html('donor_registration_form', 'donor_consent') ?> </div>
                     <div class="pt-5 text-center md:text-left"> <button type="submit" class="btn btn-secondary w-full sm:w-auto"> <span class="spinner hidden mr-2"></span> {/* Spinner placeholder */} <i class="fas fa-check-circle mr-2"></i>Register Now </button> </div>
                 </form>
            </div>
         </div>
     </section>

     <hr class="border-theme-border/50">

    <!-- Blood Request Section -->
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
<script>
 document.addEventListener('DOMContentLoaded', () => {
    // --- Theme Toggle ---
    const themeToggleBtn = document.getElementById('theme-toggle');
    const darkIcon = document.getElementById('theme-toggle-dark-icon');
    const lightIcon = document.getElementById('theme-toggle-light-icon');
    const htmlElement = document.documentElement;

    // Apply theme on initial load
    if (localStorage.getItem('theme') === 'light' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: light)').matches)) {
        htmlElement.classList.remove('dark');
        lightIcon.classList.remove('hidden');
        darkIcon.classList.add('hidden');
    } else {
        htmlElement.classList.add('dark');
        darkIcon.classList.remove('hidden');
        lightIcon.classList.add('hidden');
    }

    themeToggleBtn.addEventListener('click', () => {
        htmlElement.classList.toggle('dark');
        const isDarkMode = htmlElement.classList.contains('dark');
        localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
        darkIcon.classList.toggle('hidden', !isDarkMode);
        lightIcon.classList.toggle('hidden', isDarkMode);
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

       // --- Basic Client-Side Spinner Handling (Example) ---
       // Needs integration with actual form submission handling if not using PHP redirect
       // const forms = document.querySelectorAll('form[id$="-form-tag"]'); // Select forms by suffix
       // forms.forEach(form => {
       //     form.addEventListener('submit', (event) => {
       //         const submitButton = form.querySelector('button[type="submit"]');
       //         if (submitButton) {
       //             const spinner = submitButton.querySelector('.spinner');
       //             submitButton.disabled = true;
       //             if (spinner) spinner.classList.remove('hidden');
       //             // If validation fails client-side OR after PHP redirect,
       //             // you'd need logic to re-enable the button and hide spinner.
       //         }
       //     });
       // });

       console.log("Enhanced Blood Donation Page JS Loaded");
 });
 </script>

</body>
</html>
