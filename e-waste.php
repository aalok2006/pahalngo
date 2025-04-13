<?php
// Maybe include a common header/footer if you have one
// Example: include_once('partials/header.php');
$current_year = date('Y');

// Define theme colors (adjust these to match your actual theme)
$primary_color = '#0D47A1'; // Example: Deep Blue
$accent_color = '#D32F2F'; // Example: Red (fitting for blood donation)
$secondary_color = '#1976D2'; // Example: Medium Blue
$text_color = '#333333'; // Dark Gray for text
$bg_light = '#F4F8FB'; // Very light blue/gray for backgrounds
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PAHAL NGO's Blood Donation Program. Find information on donating blood, eligibility, requesting blood, and upcoming donation camps in Jalandhar.">
    <title>Blood Donation & Receiving - PAHAL NGO</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" />
    <!-- Favicon (replace with your actual favicon) -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <script>
        // Basic Tailwind Config (Customize with your actual theme colors)
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                primary: '<?php echo $primary_color; ?>', // Deep Blue
                accent: '<?php echo $accent_color; ?>',   // Red
                secondary: '<?php echo $secondary_color; ?>', // Medium Blue
                'primary-dark': '#0A3A82', // Darker Blue
                'accent-dark': '#B71C1C',   // Darker Red
                'bg-light': '<?php echo $bg_light; ?>',
                'text-main': '<?php echo $text_color; ?>',
              },
              fontFamily: {
                'sans': ['Open Sans', 'sans-serif'],
                'heading': ['Lato', 'sans-serif'],
              }
            }
          }
        }
    </script>
    <style type="text/tailwindcss">
        body {
            @apply font-sans text-text-main leading-relaxed pt-[70px] antialiased; /* Add padding-top for fixed header */
        }
        h1, h2, h3, h4, h5, h6 {
            @apply font-heading text-primary font-bold leading-tight mb-4;
        }
        h1 { @apply text-4xl md:text-5xl; }
        h2 { @apply text-3xl md:text-4xl mt-10; }
        h3 { @apply text-2xl md:text-3xl mt-6; }
        p { @apply mb-4 text-base md:text-lg; }
        a { @apply text-secondary hover:text-accent transition-colors duration-200; }
        ul { @apply list-disc list-inside mb-4 pl-4; }
        li { @apply mb-2; }

        .section-title {
            @apply text-center text-4xl md:text-5xl font-bold text-primary mb-12;
        }
        .btn {
            @apply inline-block px-6 py-3 rounded-md font-semibold text-white shadow-md transition-all duration-300 ease-in-out;
            @apply bg-accent hover:bg-accent-dark focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50;
        }
        .btn-secondary {
             @apply inline-block px-6 py-3 rounded-md font-semibold text-white shadow-md transition-all duration-300 ease-in-out;
            @apply bg-secondary hover:bg-primary focus:outline-none focus:ring-2 focus:ring-secondary focus:ring-opacity-50;
        }
        .btn-outline {
            @apply inline-block px-6 py-3 rounded-md font-semibold border-2 transition-all duration-300 ease-in-out;
            @apply border-accent text-accent hover:bg-accent hover:text-white focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50;
        }
        .card {
             @apply bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300;
        }

        /* Fixed Header Style */
        #main-header {
            @apply fixed top-0 left-0 w-full bg-white z-50 shadow-md transition-all duration-300;
            min-height: 70px;
        }
        .logo a { @apply text-3xl font-black text-accent font-heading leading-none; }

        /* Hero Section Specific Styles */
        #hero-blood {
            @apply bg-gradient-to-r from-red-100 via-white to-blue-100 text-center py-20 md:py-32 px-4;
            /* background-image: url('placeholder-hero-blood-donation.jpg'); /* Optional: Add a background image */
            /* background-size: cover; */
            /* background-position: center; */
        }
        #hero-blood h1 {
             @apply text-4xl md:text-6xl font-extrabold text-accent mb-4 drop-shadow-md;
        }
         #hero-blood p {
             @apply text-lg md:text-xl text-primary max-w-3xl mx-auto mb-8;
        }

        /* Icon Styling */
        .icon-feature {
            @apply text-accent text-3xl mb-3;
        }
    </style>
</head>
<body class="bg-bg-light">
    <!-- Header -->
     <header id="main-header" class="py-2 md:py-0">
       <div class="container mx-auto px-4 flex flex-wrap items-center justify-between">
           <div class="logo">
             <!-- Optional: Add PAHAL Logo Image -->
             <!-- <img src="path/to/pahal-logo.png" alt="PAHAL NGO Logo" class="h-12 inline-block mr-2"> -->
             <a href="index.php" class="text-3xl font-black text-accent font-['Lato'] leading-none">PAHAL</a>
           </div>
           <nav>
            <!-- Add more nav links if needed -->
            <a href="index.php#contact" class="text-primary hover:text-accent font-semibold px-3 py-2">Contact</a>
            <a href="index.php" class="text-primary hover:text-accent font-semibold px-3 py-2">Home</a>
           </nav>
       </div>
    </header>

    <main>
        <!-- Hero Section -->
        <section id="hero-blood">
             <div class="container mx-auto">
                 <i class="fas fa-heartbeat text-6xl text-accent mb-4"></i>
                 <h1>Donate Blood, Save Lives</h1>
                 <p class="font-semibold">Be a hero in someone's story. Your single donation can help save up to three lives. Join PAHAL's mission to ensure a stable blood supply for those in need in our community.</p>
                 <div class="space-x-4">
                     <a href="#donate-info" class="btn">Become a Donor</a>
                     <a href="#request-info" class="btn-secondary">Request Blood</a>
                 </div>
             </div>
         </section>

        <!-- Main Content Section -->
        <section class="py-16 md:py-24">
            <div class="container mx-auto px-4">

                <div class="grid md:grid-cols-2 gap-12 mb-16 items-start">
                    <!-- Why Donate? -->
                    <div class="card">
                         <h2 class="text-secondary !mt-0"><i class="fas fa-question-circle mr-2 text-secondary"></i>Why Donate Blood?</h2>
                         <p>Blood is essential for surgeries, cancer treatment, chronic illnesses, and traumatic injuries. It cannot be manufactured; it can only come from generous donors like you.</p>
                         <ul class="text-text-main">
                            <li><i class="fas fa-ambulance mr-2 text-accent"></i> Saves lives during emergencies and surgeries.</li>
                            <li><i class="fas fa-child mr-2 text-accent"></i> Supports patients battling cancer, anemia, and blood disorders.</li>
                            <li><i class="fas fa-hospital-user mr-2 text-accent"></i> Essential for complex medical procedures.</li>
                            <li><i class="fas fa-hand-holding-heart mr-2 text-accent"></i> A vital contribution to community health and well-being.</li>
                         </ul>
                         <p class="font-semibold text-primary">Your donation is a lifeline.</p>
                    </div>

                    <!-- Eligibility -->
                    <div class="card">
                        <h2 class="text-secondary !mt-0"><i class="fas fa-user-check mr-2 text-secondary"></i>Who Can Donate?</h2>
                        <p>To ensure the safety of both donors and recipients, certain requirements must be met.</p>
                        <h3 class="text-primary-dark text-xl !mt-4 !mb-2">General Eligibility:</h3>
                        <ul class="text-text-main">
                            <li><i class="fas fa-calendar-alt mr-2 text-green-600"></i> Age: 18-65 years (consult if older/younger).</li>
                            <li><i class="fas fa-weight mr-2 text-green-600"></i> Weight: Minimum 50 kg.</li>
                            <li><i class="fas fa-heartbeat mr-2 text-green-600"></i> Good general health.</li>
                            <li><i class="fas fa-clock mr-2 text-green-600"></i> Minimum interval between donations (usually 3 months for whole blood).</li>
                        </ul>
                         <h3 class="text-primary-dark text-xl !mt-4 !mb-2">You may NOT be eligible if you:</h3>
                         <ul class="text-text-main">
                            <li><i class="fas fa-times-circle mr-2 text-red-600"></i> Have certain medical conditions (heart disease, specific infections, etc.).</li>
                            <li><i class="fas fa-syringe mr-2 text-red-600"></i> Are taking certain medications.</li>
                            <li><i class="fas fa-plane-departure mr-2 text-red-600"></i> Have recently traveled to certain areas.</li>
                            <li><i class="fas fa-tint-slash mr-2 text-red-600"></i> Have low hemoglobin levels.</li>
                         </ul>
                         <p class="text-sm text-gray-600 mt-4">This is a general guide. Please contact us or consult medical staff at the donation site for specific eligibility questions.</p>
                    </div>
                </div>

                <!-- How to Donate Section -->
                <div id="donate-info" class="mb-16 text-center bg-white p-8 rounded-lg shadow-md">
                    <h2 class="section-title !mb-6">Become a Blood Donor</h2>
                     <p class="max-w-3xl mx-auto mb-8">Ready to make a difference? Donating blood is a simple, safe, and rewarding process. Here’s how you can get involved:</p>
                     <div class="grid md:grid-cols-3 gap-8 text-left">
                         <div class="text-center">
                            <div class="icon-feature"><i class="fas fa-calendar-check"></i></div>
                            <h3 class="text-xl text-primary">1. Check Eligibility & Prepare</h3>
                            <p>Review the eligibility criteria. Ensure you are well-hydrated and have eaten before donating.</p>
                         </div>
                         <div class="text-center">
                            <div class="icon-feature"><i class="fas fa-clipboard-list"></i></div>
                            <h3 class="text-xl text-primary">2. Register / Find a Camp</h3>
                            <p>Find an upcoming PAHAL blood donation camp (see below) or contact us to register your interest for future drives.</p>
                            <!-- Placeholder for a link to a registration form page -->
                            <!-- <a href="/blood-donor-registration.php" class="btn-outline mt-4">Register Online</a> -->
                         </div>
                           <div class="text-center">
                            <div class="icon-feature"><i class="fas fa-medkit"></i></div>
                            <h3 class="text-xl text-primary">3. The Donation Process</h3>
                            <p>Includes a mini-health check, the actual donation (takes ~10-15 mins), and a short rest with refreshments.</p>
                         </div>
                     </div>
                     <div class="mt-10">
                        <a href="#contact-info" class="btn">Contact Us to Donate</a>
                     </div>
                </div>


                <!-- Request Blood Section -->
                <div id="request-info" class="mb-16 card">
                     <h2 class="text-secondary !mt-0"><i class="fas fa-first-aid mr-2 text-secondary"></i>Need Blood?</h2>
                     <p>If you or a loved one requires blood, PAHAL is here to help connect you with potential donors or resources. Please understand that availability depends on current blood stock and donor participation.</p>
                     <h3 class="text-primary-dark text-xl !mt-4 !mb-2">How to Request:</h3>
                     <ol class="list-decimal list-inside mb-4 pl-4 text-text-main">
                         <li>Contact PAHAL directly via phone or email (see below).</li>
                         <li>Provide necessary details: Patient's name, hospital, blood group needed, quantity, urgency, and contact person.</li>
                         <li>We will do our best to assist by checking our donor network or guiding you to relevant blood banks.</li>
                     </ol>
                     <p class="font-semibold">Please contact us as early as possible for requests.</p>
                      <div class="mt-6 text-center md:text-left">
                        <a href="#contact-info" class="btn-secondary">Contact for Blood Request</a>
                    </div>
                </div>

                 <!-- Upcoming Camps Section -->
                <div id="camps-info" class="mb-16 bg-primary text-white p-8 rounded-lg shadow-md">
                     <h2 class="text-white !mt-0 text-center"><i class="fas fa-hospital mr-2"></i>Upcoming Blood Donation Camps</h2>
                     <div class="text-center text-lg mb-6">
                         <!-- Example Content - Replace with dynamic data if possible -->
                         <p class="mb-4">Join us at our next camp and be a lifesaver!</p>

                         <div class="bg-white text-primary p-4 rounded shadow max-w-lg mx-auto mb-4">
                             <p class="font-bold text-xl">Next Camp:</p>
                             <p><i class="fas fa-calendar-alt mr-1"></i> Date: [Insert Next Camp Date Here, e.g., October 26, 2024]</p>
                             <p><i class="fas fa-clock mr-1"></i> Time: [Insert Time Here, e.g., 9:00 AM - 2:00 PM]</p>
                             <p><i class="fas fa-map-marker-alt mr-1"></i> Location: [Insert Location Here, e.g., PAHAL Office, Jalandhar]</p>
                         </div>

                         <p class="mt-4">Keep an eye on this section or follow our social media for updates on future camps.</p>
                         <!-- If no camps are scheduled -->
                         <!-- <p>No camps scheduled currently. Please check back soon or contact us to register your interest.</p> -->
                     </div>
                </div>

                <!-- Contact Information -->
                <div id="contact-info" class="text-center max-w-2xl mx-auto">
                     <h2 class="section-title !mb-6">Contact Us</h2>
                     <p>For any inquiries regarding blood donation, requests, or our programs, please reach out:</p>
                     <div class="card !shadow-none !bg-transparent">
                         <p class="font-semibold text-primary">Blood Donation Program Coordinator:</p>
                         <p><i class="fas fa-user mr-2 text-secondary"></i>[Coordinator Name/Department, e.g., Volunteer Coordination]</p>
                         <p><i class="fas fa-phone mr-2 text-secondary"></i>Phone: <a href="tel:+919855614230" class="hover:underline text-accent font-semibold">+91 98556-14230</a> (Specify 'Blood Donation Inquiry')</p>
                         <p><i class="fas fa-envelope mr-2 text-secondary"></i>Email: <a href="mailto:engage@pahal-ngo.org?subject=Blood%20Donation%20Inquiry" class="hover:underline text-accent font-semibold">engage@pahal-ngo.org</a> (Subject: Blood Donation Inquiry)</p>
                         <p class="mt-4"><i class="fas fa-map-marker-alt mr-2 text-secondary"></i>Visit Us: [Your NGO's Full Address, Jalandhar]</p>
                     </div>
                     <div class="mt-8">
                         <a href="index.php#contact" class="btn">General Inquiries</a>
                     </div>
                </div>

            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-primary text-gray-200 pt-12 pb-8 mt-16">
        <div class="container mx-auto px-4 text-center">
            <!-- Optional: Add logo/social links here -->
             <div class="mb-4">
                 <a href="index.php" class="text-2xl font-black text-white hover:text-gray-300 font-['Lato'] leading-none">PAHAL</a>
            </div>
            <nav class="mb-4">
                 <a href="index.php" class="text-gray-300 hover:text-white px-3">Home</a> |
                 <a href="#donate-info" class="text-gray-300 hover:text-white px-3">Donate Blood</a> |
                 <a href="#request-info" class="text-gray-300 hover:text-white px-3">Request Blood</a> |
                 <a href="index.php#contact" class="text-gray-300 hover:text-white px-3">Contact</a>
            </nav>
            <p class="text-sm text-gray-400">
                 © <?= $current_year ?> PAHAL NGO. All Rights Reserved. Promoting Health and Well-being in the Community.
            </p>
       </div>
    </footer>

    <!-- Optional: Include your main JS file if needed for header effects, etc. -->
    <!-- <script src="your-main-script.js"></script> -->
</body>
</html>
