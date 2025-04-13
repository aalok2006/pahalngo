<?php
// --- PHP Contact Form Processing ---

// Initialize variables
$name = $email = $message = '';
$form_message = '';
$form_message_type = ''; // 'success' or 'error'
$errors = [];

// --- IMPORTANT: Configure Email Settings ---
// CHANGE THIS to the email address where you want to receive messages
$recipient_email = "your_email@example.com";
// CHANGE THIS potentially to an email address associated with your domain for better deliverability
$sender_email_header = "From: webmaster@your-render-app-domain.com";
// Note: The built-in mail() function might have deliverability issues on shared hosting like Render's free tier.
// Consider using SMTP with a library like PHPMailer or a transactional email service (SendGrid, Mailgun) for production.
// --- End Configuration ---

// Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Sanitize Inputs (Basic Security) ---
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING));

    // --- Validate Inputs ---
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if (empty($message)) {
        $errors[] = "Message is required.";
    }

    // --- If No Validation Errors, Proceed to Send Email ---
    if (empty($errors)) {
        $to = $recipient_email;
        $subject = "PAHAL Website Contact Form: " . $name; // Use the sanitized name

        $body = "You have received a new message from your website contact form.\n\n";
        $body .= "Here are the details:\n\n";
        $body .= "Name: " . $name . "\n\n";
        $body .= "Email: " . $email . "\n\n";
        $body .= "Message:\n" . $message . "\n";

        $headers = $sender_email_header . "\r\n";
        $headers .= "Reply-To: " . $email . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; // Specify content type and charset

        // Attempt to send the email
        if (@mail($to, $subject, $body, $headers)) { // Use @ to suppress errors if mail() fails, handle below
            $form_message = "Thank you! Your message has been sent successfully.";
            $form_message_type = 'success';
            // Clear form fields ONLY on success
            $name = $email = $message = '';
        } else {
            // Provide a more user-friendly error message
            // $errorInfo = error_get_last(); // Get the last error if mail() failed (useful for debugging)
            // error_log("Mail Error: " . ($errorInfo['message'] ?? 'Unknown mail error')); // Log error server-side
            $form_message = "Sorry, there was an error sending your message. Please check your server's mail configuration or contact support.";
            $form_message_type = 'error';
        }
    } else {
        // --- If Validation Errors Occurred ---
        $form_message = "Please fix the following errors:<br>" . implode('<br>', $errors);
        $form_message_type = 'error';
    }
}

// Prepare variables for embedding in HTML (ensure they exist and are escaped)
$current_year = date('Y');
$form_name_value = htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8');
$form_email_value = htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8');
$form_message_value = htmlspecialchars($message ?? '', ENT_QUOTES, 'UTF-8');

// Generate status message HTML
$form_status_message_html = '';
if (!empty($form_message)) {
    $alert_classes = '';
    if ($form_message_type === 'success') {
        $alert_classes = 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative';
    } elseif ($form_message_type === 'error') {
        $alert_classes = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative';
    }
    $form_status_message_html = "<div class=\"mb-4 {$alert_classes}\" role=\"alert\">{$form_message}</div>";
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAHAL NGO - An Endeavour for a Better Tomorrow</title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script>
        // Optional: You can configure Tailwind further here if needed
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#008000', // Green
                        'primary-dark': '#006400', // Darker Green
                        accent: '#DC143C', // Crimson Red
                        'accent-dark': '#a5102f',
                        lightbg: '#f8f9fa',
                    },
                    fontFamily: {
                        // Example: Apply Lato and Open Sans if desired
                        // sans: ['Open Sans', 'sans-serif'],
                        // heading: ['Lato', 'sans-serif'],
                    },
                    container: {
                      center: true,
                      padding: '1rem', // Default padding
                      screens: {
                        sm: '640px',
                        md: '768px',
                        lg: '1024px',
                        xl: '1140px', // Match original container width
                        '2xl': '1140px',
                      },
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* Add custom base styles or component overrides here if needed */
        body {
            @apply font-['Open_Sans'] text-gray-700 leading-relaxed;
        }
        h1, h2, h3, h4, h5, h6 {
             @apply font-['Lato'] text-primary font-bold leading-tight mb-3;
        }
        .section-title {
            @apply text-3xl md:text-4xl text-center mb-12 relative pb-4;
        }
        .section-title::after {
            content: '';
            @apply absolute bottom-0 left-1/2 -translate-x-1/2 w-20 h-1 bg-accent rounded-full;
        }
        .btn {
            @apply inline-block bg-accent text-white py-3 px-7 rounded font-semibold font-['Open_Sans'] transition duration-300 ease-in-out hover:bg-accent-dark hover:-translate-y-0.5 shadow-md hover:shadow-lg cursor-pointer text-base;
        }
        .btn-secondary {
             @apply bg-white text-primary border-2 border-primary hover:bg-primary hover:text-white;
        }
        #main-header {
            @apply fixed top-0 left-0 w-full bg-white z-50 shadow-md transition-all duration-300;
            min-height: 70px; /* Ensure space even when content wraps */
        }
        #navbar ul li a {
            @apply text-primary font-semibold py-1 relative transition duration-300 ease-in-out;
        }
        #navbar ul li a::after {
            content: '';
            @apply absolute bottom-[-3px] left-0 w-0 h-0.5 bg-accent transition-all duration-300 ease-in-out;
        }
        #navbar ul li a:hover::after,
        #navbar ul li a.active::after {
            @apply w-full;
        }
        #navbar ul li a:hover,
        #navbar ul li a.active {
            @apply text-accent;
        }
        .menu-toggle span {
             @apply block w-6 h-0.5 bg-primary mb-1 rounded-sm transition-all duration-300 ease-in-out;
        }
        .menu-toggle.active span:nth-child(1) { @apply transform rotate-45 translate-y-[6px]; }
        .menu-toggle.active span:nth-child(2) { @apply opacity-0; }
        .menu-toggle.active span:nth-child(3) { @apply transform -rotate-45 translate-y-[-6px]; }

        /* Custom style for hero background */
        #hero {
             background: linear-gradient(rgba(0, 128, 0, 0.6), rgba(0, 100, 0, 0.7)), url('https://via.placeholder.com/1600x800.png?text=PAHAL+Community+Action') no-repeat center center/cover;
        }

        /* Style for focus item border top */
        .focus-item { @apply border-t-4 border-primary; }

        /* Style for linked focus items */
        a.focus-item { @apply no-underline; } /* Ensure links don't get underlined */

        /* Adjust form message styling if needed */
         .form-message { @apply text-sm; }

    </style>
</head>
<body class="bg-white text-gray-700 font-sans leading-relaxed">

    <!-- Header -->
    <header id="main-header" class="py-2 md:py-0">
        <div class="container mx-auto flex flex-wrap items-center justify-between">
             <!-- Logo -->
            <div class="logo">
                <a href="#hero" class="text-4xl font-black text-accent font-['Lato'] leading-none">PAHAL</a>
            </div>

            <!-- Mobile Menu Toggle -->
            <button id="mobile-menu" class="menu-toggle lg:hidden p-2 focus:outline-none">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <!-- Navigation -->
            <nav id="navbar" class="w-full lg:w-auto lg:flex hidden max-h-0 lg:max-h-full overflow-hidden transition-all duration-500 ease-in-out lg:overflow-visible absolute lg:relative top-[65px] lg:top-0 left-0 bg-white lg:bg-transparent shadow-md lg:shadow-none">
                <ul class="flex flex-col lg:flex-row lg:items-center lg:space-x-7 py-4 lg:py-0 px-4 lg:px-0">
                    <li><a href="#hero" class="block lg:inline-block py-2 lg:py-0 active">Home</a></li>
                    <li><a href="#profile" class="block lg:inline-block py-2 lg:py-0">Profile</a></li>
                    <li><a href="#objectives" class="block lg:inline-block py-2 lg:py-0">Objectives</a></li>
                    <li><a href="#areas-focus" class="block lg:inline-block py-2 lg:py-0">Areas of Focus</a></li>
                    <li><a href="#how-to-join" class="block lg:inline-block py-2 lg:py-0">How to Join</a></li>
                    <li><a href="#associates" class="block lg:inline-block py-2 lg:py-0">Associates</a></li>
                    <li><a href="#contact" class="block lg:inline-block py-2 lg:py-0">Contact</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <!-- Hero Section -->
        <section id="hero" class="text-white min-h-[85vh] flex items-center pt-[70px] relative">
            <div class="container mx-auto flex flex-col lg:flex-row items-center justify-between gap-10 text-center lg:text-right">
                <div class="hero-logo order-1 lg:order-none flex-shrink-0 w-[150px] lg:w-auto">
                    <img src="icon.webp" alt="PAHAL NGO Logo" class="mx-auto">
                </div>
                <div class="hero-text flex-1 lg:pl-5 order-2 lg:order-none flex flex-col items-center justify-center text-center">
                  <h2 class="text-4xl lg:text-5xl font-black text-white mb-5 drop-shadow-md font-['Lato']">
                    Dreams & Dedication are a Powerful Combination
                  </h2>
                  <p class="text-lg lg:text-xl mb-10 max-w-2xl mx-auto text-gray-100">
                    PAHAL is an endeavour for a Better Tomorrow. Join us in creating perceptible change.
                  </p>
                  <a href="#profile" class="btn">Discover More</a>
                </div>

            </div>
        </section>

        <!-- Profile Section -->
        <section id="profile" class="py-16 md:py-24 bg-lightbg">
            <div class="container mx-auto">
                 <h2 class="section-title">Our Profile & Aim</h2>
                 <div class="grid md:grid-cols-2 gap-12 items-center">
                     <div class="profile-text md:order-1">
                        <h3 class="text-2xl text-primary-dark mb-4">Who We Are</h3>
                        <p class="mb-6 text-gray-600">'PAHAL' is a voluntary youth organization promoted by like-minded Educationists, Doctors, Legal Experts, Technocrats, Dynamic Entrepreneurs, Enthusiastic Students & youth to bring a perceptible change in the present social set-up.</p>
                        <span class="block italic font-semibold text-primary text-lg mb-6 border-l-4 border-accent pl-4">'PAHAL is an endeavour for a Better Tomorrow'</span>
                        <h3 class="text-2xl text-primary-dark mb-4">Our Aim</h3>
                        <p class="text-gray-600">The overall aim of 'PAHAL' organisation is to build Holistic Personality by motivating everyone for service to humanity while living one's life. At PAHAL, we attempt to arouse the social conscience of people and provide them with an opportunity to work with the people around the world Creatively & Constructively.</p>
                     </div>
                     <div class="profile-image md:order-2">
                         <img src="https://via.placeholder.com/500x350.png?text=PAHAL+Team+or+Activity" alt="PAHAL NGO Activity" class="rounded-lg shadow-lg mx-auto">
                     </div>
                 </div>
            </div>
        </section>

        <!-- Objectives Section -->
        <section id="objectives" class="py-16 md:py-24">
            <div class="container mx-auto">
                 <h2 class="section-title">Our Objectives</h2>
                <ul class="max-w-4xl mx-auto space-y-4">
                     <li class="bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:translate-x-2 flex items-start">
                         <i class="fas fa-users fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center"></i>
                         <span>To work with & among the people.</span>
                     </li>
                     <li class="bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:translate-x-2 flex items-start">
                         <i class="fas fa-hands-helping fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center"></i>
                         <span>To engage in creative & constructive social action & inculcate the sense of dignity of labour.</span>
                     </li>
                     <li class="bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:translate-x-2 flex items-start">
                         <i class="fas fa-lightbulb fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center"></i>
                         <span>To enhance one's knowledge about own self & the community through a confrontation with the realities of social life.</span>
                     </li>
                     <li class="bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:translate-x-2 flex items-start">
                         <i class="fas fa-graduation-cap fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center"></i>
                         <span>To put one's scholarship to practical use by mitigating at least some of the social problems.</span>
                     </li>
                     <li class="bg-lightbg p-5 md:p-6 border-l-4 border-primary rounded-md shadow-sm transition duration-300 ease-in-out hover:shadow-md hover:translate-x-2 flex items-start">
                         <i class="fas fa-cogs fa-fw text-primary text-xl mr-4 mt-1 w-5 text-center"></i>
                         <span>To gain skills in humanity development programmes & to put them into practice.</span>
                     </li>
                </ul>
            </div>
        </section>

        <!-- Areas of Focus Section -->
        <section id="areas-focus" class="py-16 md:py-24 bg-lightbg">
            <div class="container mx-auto">
                <h2 class="section-title">Areas of Focus</h2>
                 <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">

                     <!-- Health Card - Now Clickable -->
                     <a href="blood-donation.php" title="Learn more about our Blood Donation initiatives"
                        class="focus-item block bg-white p-8 rounded-lg shadow-md text-center transition duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2 no-underline group">
                         <span class="icon text-5xl text-accent mb-5 inline-block group-hover:scale-110 transition-transform"><i class="fas fa-heartbeat"></i></span>
                         <h3 class="text-xl text-primary mb-3 group-hover:text-accent-dark transition-colors">Health</h3>
                         <p class="text-sm text-gray-600 leading-relaxed">Health is the topmost priority. We create awareness through observation of various days and are active in blood donation drives. Click to learn more.</p>
                     </a>

                      <div class="focus-item bg-white p-8 rounded-lg shadow-md text-center transition duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2">
                         <span class="icon text-5xl text-accent mb-5 inline-block"><i class="fas fa-book-open-reader"></i></span>
                         <h3 class="text-xl text-primary mb-3">Education</h3>
                         <p class="text-sm text-gray-600 leading-relaxed">A vast field requiring a professional approach. We focus on creating unemployment awareness and enhancing Ethical, Life Skills & Professional Education abilities for youth.</p>
                     </div>

                      <!-- Environment Card - Now Clickable -->
                     <a href="e-waste.php" title="Learn more about our E-waste collection program"
                        class="focus-item block bg-white p-8 rounded-lg shadow-md text-center transition duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2 no-underline group">
                          <span class="icon text-5xl text-accent mb-5 inline-block group-hover:scale-110 transition-transform"><i class="fas fa-leaf"></i></span>
                          <h3 class="text-xl text-primary mb-3 group-hover:text-accent-dark transition-colors">Environment</h3>
                          <p class="text-sm text-gray-600 leading-relaxed">We are determined to increase green cover and run programs for waste management, including e-waste recycling. Click for e-waste collection details.</p>
                     </a>

                      <div class="focus-item bg-white p-8 rounded-lg shadow-md text-center transition duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2">
                         <span class="icon text-5xl text-accent mb-5 inline-block"><i class="fas fa-comments"></i></span>
                         <h3 class="text-xl text-primary mb-3">Communication</h3>
                         <p class="text-sm text-gray-600 leading-relaxed">Good communication skills are a necessity. We help youth improve verbal, physical, and non-verbal skills through continuous programs.</p>
                     </div>
                 </div>
            </div>
        </section>

         <!-- How to Join Section -->
         <section id="how-to-join" class="py-16 md:py-24 bg-primary text-white">
            <div class="container mx-auto text-center">
                <h2 class="section-title !text-white after:!bg-white">How to Join PAHAL</h2>
                <p class="text-gray-100 max-w-3xl mx-auto mb-4 text-lg leading-relaxed">PAHAL is a voluntary organisation and it is open for everybody at individual, institutional or organisational level. It is our objective to benefit the society at large. Apart from that, a formal membership can benefit you in numerous ways.</p>
                <p class="text-gray-100 max-w-3xl mx-auto text-lg leading-relaxed">Students or individuals can join PAHAL by filling a form on the website or you can drop in at the office any time! Institutions and other organisations may email their membership proposal at the email IDs mentioned. Visit Website for more details.</p>
                <div class="mt-10 space-y-4 sm:space-y-0 sm:space-x-4">
                     <a href="#contact" class="btn btn-secondary !border-white hover:!bg-gray-100 hover:!text-primary">Contact Us to Join</a>
                     <a href="http://www.pahal-ngo.org" target="_blank" rel="noopener noreferrer" class="btn">Visit Website</a>
                 </div>
            </div>
        </section>

        <!-- Associates Section -->
        <section id="associates" class="py-16 md:py-24 bg-lightbg">
            <div class="container mx-auto">
                <h2 class="section-title">Our Associates</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-8 items-center mt-10">
                    <!-- Logos... -->
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                        <img src="naco.webp" alt="NACO Logo" class="max-h-16 w-auto mx-auto mb-2">
                        <p class="text-sm font-semibold text-gray-600">NACO</p>
                    </div>
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                        <img src="microsoft.webp" alt="Microsoft Logo" class="max-h-16 w-auto mx-auto mb-2">
                        <p class="text-sm font-semibold text-gray-600">Microsoft</p>
                    </div>
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                        <img src="Karo_Logo-01.webp" alt="Karo Sambhav Logo" class="max-h-16 w-auto mx-auto mb-2">
                        <p class="text-sm font-semibold text-gray-600">Karo Sambhav</p>
                    </div>
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                        <img src="psacs.webp" alt="PSACS Logo" class="max-h-16 w-auto mx-auto mb-2">
                        <p class="text-sm font-semibold text-gray-600">PSACS</p>
                    </div>
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                        <img src="nabard.webp" alt="NABARD Logo" class="max-h-16 w-auto mx-auto mb-2">
                        <p class="text-sm font-semibold text-gray-600">NABARD</p>
                    </div>
                    <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                         <img src="punjab-gov.png" alt="Govt of Punjab Logo" class="max-h-16 w-auto mx-auto mb-2">
                         <p class="text-sm font-semibold text-gray-600">Govt. Punjab</p>
                     </div>
                     <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                         <img src="ramsan.png" alt="Ramsan Logo" class="max-h-16 w-auto mx-auto mb-2">
                         <p class="text-sm font-semibold text-gray-600">Ramsan</p>
                     </div>
                     <div class="associate-logo text-center transition duration-300 ease-in-out transform hover:scale-105 opacity-80 hover:opacity-100">
                         <img src="image.png" alt="Apollo Tyres Logo" class="max-h-16 w-auto mx-auto mb-2">
                         <p class="text-sm font-semibold text-gray-600">Apollo Tyres</p>
                     </div>
                     <!-- Add more logos as needed -->
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact" class="py-16 md:py-24">
            <div class="container mx-auto">
                 <h2 class="section-title">Contact Us</h2>
                <div class="grid lg:grid-cols-5 gap-10 lg:gap-16 items-start">
                    <!-- Contact Details -->
                    <div class="lg:col-span-2">
                         <h3 class="text-2xl mb-6">Get In Touch</h3>
                         <div class="space-y-4 text-gray-600">
                             <p class="flex items-start">
                                 <i class="fas fa-map-marker-alt fa-fw text-primary text-lg mr-3 mt-1 w-5 text-center flex-shrink-0"></i>
                                 <span>36 New Vivekanand Park, Maqsudan,<br>Jalandhar, Punjab - 144008 (India)</span>
                             </p>
                             <p class="flex items-start">
                                 <i class="fas fa-phone-alt fa-fw text-primary text-lg mr-3 mt-1 w-5 text-center flex-shrink-0"></i>
                                 <a href="tel:+911812672784" class="hover:text-accent">+91 181-267-2784</a>
                             </p>
                             <p class="flex items-start">
                                 <i class="fas fa-mobile-alt fa-fw text-primary text-lg mr-3 mt-1 w-5 text-center flex-shrink-0"></i>
                                 <a href="tel:+919855614230" class="hover:text-accent">+91 98556-14230</a>
                             </p>
                             <p class="flex items-start">
                                 <i class="fas fa-envelope fa-fw text-primary text-lg mr-3 mt-1 w-5 text-center flex-shrink-0"></i>
                                 <a href="mailto:engage@pahal-ngo.org" class="hover:text-accent">engage@pahal-ngo.org</a>
                             </p>
                         </div>

                         <div class="mt-8">
                             <h4 class="text-lg font-semibold text-primary mb-3">Follow Us</h4>
                             <div class="flex space-x-4">
                                 <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" title="Instagram" class="text-primary text-2xl transition duration-300 hover:text-accent hover:scale-110"><i class="fab fa-instagram"></i></a>
                                 <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" title="Facebook" class="text-primary text-2xl transition duration-300 hover:text-accent hover:scale-110"><i class="fab fa-facebook-f"></i></a>
                                 <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" title="Twitter" class="text-primary text-2xl transition duration-300 hover:text-accent hover:scale-110"><i class="fab fa-twitter"></i></a>
                             </div>
                         </div>

                         <div class="registration-info mt-8 pt-6 border-t border-gray-200 text-sm text-gray-500">
                             <h4 class="text-base font-semibold text-primary mb-2">Registration Details:</h4>
                             <p>Registration No.: 737 (Societies Registration Act)</p>
                             <p>Exempted under Section 80G (Vide No. CIT/JL-I/Trust/93/2011-12/2582)</p>
                             <p>Registered under Section 12-A of Income Tax Act</p>
                         </div>
                     </div>

                    <!-- Contact Form -->
                    <div class="lg:col-span-3 bg-gray-50 p-6 sm:p-8 md:p-10 rounded-lg shadow-lg border-t-4 border-primary">
                        <h3 class="text-2xl mb-6">Send Us a Message</h3>

                        <!-- PHP Status Message Area -->
                        <?= $form_status_message_html ?>

                        <form id="contact-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>#contact" method="POST" class="space-y-5">
                            <div>
                                <label for="name" class="block mb-2 text-sm font-medium text-primary">Name:</label>
                                <input type="text" id="name" name="name" required value="<?= $form_name_value ?>" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-dark focus:border-primary-dark block w-full p-3 transition duration-300 ease-in-out">
                            </div>
                            <div>
                                <label for="email" class="block mb-2 text-sm font-medium text-primary">Email:</label>
                                <input type="email" id="email" name="email" required value="<?= $form_email_value ?>" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-dark focus:border-primary-dark block w-full p-3 transition duration-300 ease-in-out">
                            </div>
                            <div>
                                <label for="message" class="block mb-2 text-sm font-medium text-primary">Message:</label>
                                <textarea id="message" name="message" rows="5" required class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-dark focus:border-primary-dark block w-full p-3 transition duration-300 ease-in-out resize-vertical"><?= $form_message_value ?></textarea>
                            </div>
                            <button type="submit" class="btn w-full sm:w-auto">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-primary text-gray-200 pt-16 pb-8 mt-16">
        <div class="container mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-10 text-center md:text-left">
                <!-- Footer About -->
                <div>
                    <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-10 after:h-0.5 after:bg-gray-400">About PAHAL</h4>
                    <p class="text-sm mb-3 leading-relaxed">A voluntary youth organization dedicated to holistic personality development and service to humanity through creative and constructive social action in Jalandhar.</p>
                     <p class="text-sm">Reg No: 737 | 80G & 12A Registered</p>
                </div>

                 <!-- Footer Quick Links -->
                 <div>
                     <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-10 after:h-0.5 after:bg-gray-400">Quick Links</h4>
                     <ul class="space-y-2 text-sm">
                        <li><a href="#profile" class="hover:text-white hover:underline">Profile</a></li>
                        <li><a href="#objectives" class="hover:text-white hover:underline">Objectives</a></li>
                        <li><a href="#areas-focus" class="hover:text-white hover:underline">Areas of Focus</a></li>
                        <li><a href="#how-to-join" class="hover:text-white hover:underline">How to Join</a></li>
                        <li><a href="#associates" class="hover:text-white hover:underline">Associates</a></li>
                        <li><a href="#contact" class="hover:text-white hover:underline">Contact Us</a></li>
                        <!-- Add links to new pages if desired -->
                        <li><a href="blood-donation.php" class="hover:text-white hover:underline">Blood Donation</a></li>
                        <li><a href="e-waste.php" class="hover:text-white hover:underline">E-Waste Program</a></li>
                     </ul>
                 </div>

                 <!-- Footer Contact -->
                 <div>
                     <h4 class="text-lg font-semibold text-white mb-4 relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 md:after:left-0 after:-translate-x-1/2 md:after:translate-x-0 after:w-10 after:h-0.5 after:bg-gray-400">Contact Info</h4>
                     <address class="not-italic space-y-2 text-sm">
                         <p><i class="fas fa-map-marker-alt fa-fw mr-2"></i> Maqsudan, Jalandhar, Punjab</p>
                         <p><i class="fas fa-phone-alt fa-fw mr-2"></i> <a href="tel:+911812672784" class="hover:text-white hover:underline">181-267-2784</a></p>
                         <p><i class="fas fa-mobile-alt fa-fw mr-2"></i> <a href="tel:+919855614230" class="hover:text-white hover:underline">98556-14230</a></p>
                         <p><i class="fas fa-envelope fa-fw mr-2"></i> <a href="mailto:engage@pahal-ngo.org" class="hover:text-white hover:underline">engage@pahal-ngo.org</a></p>
                     </address>
                     <div class="mt-4 flex justify-center md:justify-start space-x-3">
                         <a href="https://www.instagram.com/pahalasadi/" target="_blank" rel="noopener noreferrer" title="Instagram" class="text-xl transition duration-300 hover:text-white hover:scale-110"><i class="fab fa-instagram"></i></a>
                         <a href="https://www.facebook.com/PahalNgoJalandhar/" target="_blank" rel="noopener noreferrer" title="Facebook" class="text-xl transition duration-300 hover:text-white hover:scale-110"><i class="fab fa-facebook-f"></i></a>
                         <a href="https://twitter.com/PahalNGO1" target="_blank" rel="noopener noreferrer" title="Twitter" class="text-xl transition duration-300 hover:text-white hover:scale-110"><i class="fab fa-twitter"></i></a>
                     </div>
                 </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom border-t border-gray-600 pt-6 mt-8 text-center text-sm text-gray-400">
                © <?= $current_year ?> PAHAL NGO. All Rights Reserved. | An Endeavour for a Better Tomorrow.
            </div>
        </div>
    </footer>

    <!-- JavaScript (Mobile Menu, Smooth Scroll, Active Link) -->
    <script>
        // Script remains the same as before...
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('mobile-menu');
            const navbar = document.getElementById('navbar');
            const navLinks = document.querySelectorAll('#navbar a');
            const header = document.getElementById('main-header');
            let headerHeight = header ? header.offsetHeight : 70;

            function updateHeaderHeight() {
                headerHeight = header ? header.offsetHeight : 70;
                document.body.style.paddingTop = `${headerHeight}px`;
                const hero = document.getElementById('hero');
                 // No need to adjust hero padding top if it uses min-height and flex centering
            }

             window.addEventListener('resize', updateHeaderHeight);
             window.addEventListener('load', updateHeaderHeight);
             updateHeaderHeight(); // Initial call

            // Mobile Menu Toggle
            if (menuToggle && navbar) {
                menuToggle.addEventListener('click', () => {
                    const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
                    menuToggle.setAttribute('aria-expanded', !isExpanded);
                    menuToggle.classList.toggle('active');
                    navbar.classList.toggle('hidden');
                    updateHeaderHeight();
                });
            }

            // Function to set active link based on scroll position
            function setActiveLink() {
                let scrollPosition = window.pageYOffset;
                let currentSectionId = '';
                const offset = headerHeight + 50; // Offset for activation

                document.querySelectorAll('main section[id]').forEach(section => {
                    const sectionTop = section.offsetTop - offset;
                    const sectionBottom = sectionTop + section.offsetHeight;
                    const sectionId = '#' + section.getAttribute('id');

                    // Find the corresponding link
                    const link = document.querySelector(`#navbar a[href="${sectionId}"]`);

                    if (scrollPosition >= sectionTop && scrollPosition < sectionBottom && link) {
                        currentSectionId = sectionId;
                    }
                });

                 // Handle edge case for top of page (Hero)
                if (currentSectionId === '' && scrollPosition < (document.getElementById('profile')?.offsetTop - offset || 500) ) {
                   currentSectionId = '#hero';
                }

                 navLinks.forEach(link => {
                     link.classList.remove('active');
                     if (link.getAttribute('href') === currentSectionId) {
                         link.classList.add('active');
                     }
                 });
            }

            // Smooth Scrolling & Close Mobile Menu on Link Click
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const targetId = this.getAttribute('href');

                    // Check if it's an internal anchor link starting with #
                    if (targetId && targetId.startsWith('#')) {
                        e.preventDefault(); // Prevent default jump for internal links
                        const targetElement = document.querySelector(targetId);

                        if (targetElement) {
                            // Close mobile menu if open
                            if (navbar.classList.contains('hidden') === false && window.innerWidth < 1024) {
                                menuToggle.click();
                            }

                            const elementPosition = targetElement.getBoundingClientRect().top;
                            const offsetPosition = elementPosition + window.pageYOffset - headerHeight - 5;

                            window.scrollTo({
                                top: offsetPosition,
                                behavior: 'smooth'
                            });

                            // Set active class immediately
                            navLinks.forEach(lnk => lnk.classList.remove('active'));
                            this.classList.add('active');
                         }
                    } else {
                        // Let the browser handle external links or links to other pages normally
                        // If mobile menu is open, you might still want to close it
                         if (navbar.classList.contains('hidden') === false && window.innerWidth < 1024) {
                            menuToggle.click();
                        }
                    }
                });
            });

            // Set active link on scroll and initial load
            window.addEventListener('scroll', setActiveLink);
            window.addEventListener('load', () => {
                setTimeout(setActiveLink, 100);
                updateHeaderHeight(); // Re-check header height after load
            });
        });
    </script>

</body>
</html>
