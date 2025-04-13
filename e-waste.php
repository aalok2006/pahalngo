<?php
// Maybe include a common header/footer if you have one
$current_year = date('Y');
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Waste Collection & Recycling - PAHAL NGO</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" />

    <script>
        // Professional Tailwind Configuration
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                primary: { // Nature-inspired Green
                  light: '#66BB6A', // Lighter shade for hover/accents
                  DEFAULT: '#2E7D32', // Main green
                  dark: '#1B5E20'   // Darker shade for contrast/headings
                },
                secondary: '#F9FAFB', // Off-white for backgrounds
                accent: { // Action color - Vibrant Green or Blue
                  DEFAULT: '#2ECC71', // Example: Vibrant Green
                  // DEFAULT: '#3498DB', // Example: Clean Blue
                  dark: '#27AE60' // Darker accent for hover
                },
                neutral: {
                  light: '#F3F4F6', // Light grey
                  DEFAULT: '#6B7280', // Medium grey (text)
                  dark: '#374151'  // Dark grey (headings/strong text)
                }
              },
              fontFamily: {
                'sans': ['Open Sans', 'sans-serif'],
                'heading': ['Lato', 'sans-serif'],
              },
              container: {
                center: true,
                padding: {
                  DEFAULT: '1rem',
                  sm: '2rem',
                  lg: '4rem',
                  xl: '5rem',
                },
              },
            }
          }
        }
    </script>

    <style type="text/tailwindcss">
        @layer base {
            html { @apply scroll-smooth; }
            body { @apply font-sans text-neutral-dark leading-relaxed pt-[70px] bg-white; } /* Added padding-top */
            h1, h2, h3, h4, h5, h6 { @apply font-heading text-primary-dark font-bold leading-tight mb-4; }
            h1 { @apply text-4xl md:text-5xl lg:text-6xl; }
            h2 { @apply text-3xl md:text-4xl; }
            h3 { @apply text-2xl md:text-3xl text-primary; }
            p { @apply mb-5 text-base md:text-lg text-neutral-dark; }
            a { @apply text-accent hover:text-accent-dark transition duration-300; }
            ul { @apply list-none space-y-3 mb-6; }
            li { @apply flex items-start; }
            li::before { /* Custom bullet points using FontAwesome */
                content: '\f00c'; /* check icon */
                font-family: 'Font Awesome 6 Free';
                font-weight: 900;
                @apply text-primary mr-3 mt-1 text-sm flex-shrink-0;
            }
        }

        @layer components {
            .btn {
                @apply inline-block bg-accent text-white font-semibold py-3 px-8 rounded-full shadow-md hover:bg-accent-dark hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent focus:ring-opacity-50 transition duration-300 ease-in-out transform hover:-translate-y-0.5;
            }
            .btn-secondary {
                 @apply inline-block bg-transparent border-2 border-primary text-primary font-semibold py-3 px-8 rounded-full hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50 transition duration-300 ease-in-out;
            }
            .section-padding {
                @apply py-16 md:py-24;
            }
            .card {
                @apply bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300;
            }
        }

        /* Header Styles */
        #main-header {
            @apply fixed top-0 left-0 w-full bg-white z-50 shadow-md transition-all duration-300;
            min-height: 70px; /* Ensure consistent height */
        }
        #main-header .logo a {
            @apply text-3xl md:text-4xl font-black text-primary font-heading leading-none; /* Adjusted size and color */
        }
        #main-header nav a {
             @apply text-neutral-dark hover:text-primary font-semibold text-lg ml-6; /* Adjusted colors and spacing */
        }
        .hero-bg {
            /* --- IMAGE SUGGESTION ---
               Replace with a high-quality image relevant to e-waste/recycling.
               Examples:
               - Organized electronics ready for recycling
               - Hands holding a circuit board with a plant growing
               - Abstract green tech background
               - Community members dropping off e-waste
               Use unsplash.com, pexels.com for free options.
            */
           background-image: url('https://images.unsplash.com/photo-1611284446314-60a58ac0deb9?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1770&q=80');
           background-size: cover;
           background-position: center;
           background-attachment: fixed; /* Optional: Parallax effect */
        }
    </style>
</head>
<body class="bg-secondary">

    <!-- Header -->
    <header id="main-header" class="py-2">
       <div class="container mx-auto flex flex-wrap items-center justify-between">
           <div class="logo">
               <a href="index.php#hero">PAHAL</a>
            </div>
           <nav>
               <a href="index.php">Back to Home</a>
               <!-- Add other nav links if needed -->
           </nav>
       </div>
    </header>

    <main>

        <!-- Hero Section -->
        <section class="hero-bg relative text-white section-padding flex items-center justify-center text-center">
            <div class="absolute inset-0 bg-black opacity-50"></div> <!-- Dark Overlay for text contrast -->
            <div class="relative z-10 max-w-4xl mx-auto">
                <h1 class="text-4xl md:text-6xl font-bold mb-4 text-white drop-shadow-lg">Responsible E-Waste Recycling</h1>
                <p class="text-xl md:text-2xl mb-8 text-gray-200 drop-shadow-md">Join PAHAL in creating a cleaner environment by properly disposing of your electronic waste.</p>
                <a href="#how-to-dispose" class="btn">Find Out How</a>
            </div>
        </section>

        <!-- Introduction Section -->
        <section class="section-padding bg-white">
            <div class="container mx-auto text-center max-w-4xl">
                <h2 class="mb-6">The Growing Challenge of E-Waste</h2>
                <p class="text-lg">Electronic waste (e-waste) is one of the fastest-growing waste streams globally. Improper disposal poses significant environmental and health risks. PAHAL is committed to tackling this challenge through responsible collection and recycling programs, often in partnership with certified organizations like Karo Sambhav.</p>
                <p class="text-lg font-semibold text-primary">Let's work together for a sustainable future.</p>
            </div>
        </section>

        <!-- What is E-Waste Section -->
        <section class="section-padding bg-neutral-light">
            <div class="container mx-auto grid md:grid-cols-2 gap-12 items-center">
                <div>
                    <!-- --- IMAGE SUGGESTION ---
                         Replace with an image illustrating common e-waste items
                         (e.g., pile of phones, old computers, tangled cables)
                         Placeholder: https://via.placeholder.com/600x400/cccccc/969696?text=E-Waste+Items
                    -->
                    <img src="https://via.placeholder.com/600x400/cccccc/969696?text=E-Waste+Items" alt="Examples of E-Waste Items" class="rounded-lg shadow-md mb-6 md:mb-0 w-full">
                </div>
                <div>
                    <h2 class="mb-4">What Qualifies as E-Waste?</h2>
                    <p>E-waste includes most items with a plug, battery, or cord that are nearing the end of their useful life. Common examples include:</p>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
                        <li><i class="fas fa-laptop text-primary mr-3 mt-1 text-xl"></i> Computers & Laptops</li>
                        <li><i class="fas fa-mobile-alt text-primary mr-3 mt-1 text-xl"></i> Phones & Tablets</li>
                        <li><i class="fas fa-tv text-primary mr-3 mt-1 text-xl"></i> Televisions & Monitors</li>
                        <li><i class="fas fa-print text-primary mr-3 mt-1 text-xl"></i> Printers & Scanners</li>
                        <li><i class="fas fa-keyboard text-primary mr-3 mt-1 text-xl"></i> Keyboards & Mice</li>
                        <li><i class="fas fa-plug text-primary mr-3 mt-1 text-xl"></i> Cables & Chargers</li>
                        <li><i class="fas fa-battery-half text-primary mr-3 mt-1 text-xl"></i> Batteries (check types)</li>
                        <li><i class="fas fa-headphones text-primary mr-3 mt-1 text-xl"></i> Small Appliances</li>
                    </ul>
                     <p class="mt-4">Improper disposal releases harmful toxins like lead, mercury, and cadmium into the environment. Recycling recovers valuable materials and prevents pollution.</p>
                </div>
            </div>
        </section>

        <!-- Our Program & Accepted Items Section -->
        <section class="section-padding bg-white">
            <div class="container mx-auto">
                <h2 class="text-center mb-12">Our E-Waste Collection Initiative</h2>
                <div class="grid md:grid-cols-2 gap-10">
                    <!-- Program Details Card -->
                    <div class="card flex flex-col">
                         <i class="fas fa-recycle text-4xl text-primary mb-4 self-start"></i>
                         <h3 class="mb-4">How We Help</h3>
                         <p>PAHAL facilitates the responsible collection of e-waste in the community. We partner with certified recyclers to ensure that your discarded electronics are processed safely and sustainably.</p>
                         <p>Our primary methods include:</p>
                         <ul class="mt-4 flex-grow">
                            <li><i class="fas fa-calendar-alt text-primary mr-3 mt-1"></i> Organizing periodic collection drives (stay tuned for announcements!).</li>
                            <li><i class="fas fa-map-marker-alt text-primary mr-3 mt-1"></i> Providing information on designated drop-off points when available.</li>
                            <li><i class="fas fa-hands-helping text-primary mr-3 mt-1"></i> Coordinating with businesses for larger volume pickups.</li>
                         </ul>
                         <div class="mt-auto pt-4">
                            <a href="#contact-section" class="text-accent font-semibold hover:underline">Questions? Contact Us <i class="fas fa-arrow-right ml-1 text-sm"></i></a>
                         </div>
                    </div>

                    <!-- Accepted Items Card -->
                    <div class="card">
                        <i class="fas fa-check-circle text-4xl text-primary mb-4"></i>
                        <h3 class="mb-4">What We Accept</h3>
                        <p>We generally accept a wide range of common household and office electronics:</p>
                        <ul>
                            <li>Computers (Desktops, Laptops) & Peripherals (Keyboards, Mice)</li>
                            <li>Mobile Phones, Tablets, and Chargers</li>
                            <li>Printers, Scanners, Fax Machines</li>
                            <li>Wires, Cables, and Power Adapters</li>
                            <li>Small home appliances (e.g., radios, VCRs - contact first for larger items)</li>
                            <li>Batteries (please check specific types accepted at drives)</li>
                        </ul>
                        <p class="text-sm text-neutral mt-4"><i class="fas fa-info-circle mr-1"></i> If you have an item not listed or are unsure, please contact us before bringing it.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- How to Dispose Section -->
        <section id="how-to-dispose" class="section-padding bg-primary-dark text-white">
            <div class="container mx-auto">
                <h2 class="text-center mb-12 text-white">How to Dispose of Your E-Waste with PAHAL</h2>
                <div class="grid md:grid-cols-2 gap-8 text-center md:text-left">
                    <!-- Option 1 -->
                    <div class="bg-primary p-6 rounded-lg shadow-lg">
                         <h3 class="text-white mb-3"><span class="bg-accent text-white rounded-full px-3 py-1 mr-2">1</span> Attend Collection Drives</h3>
                         <p class="text-gray-200">Keep an eye on our website and social media channels for announcements about upcoming e-waste collection events in and around Jalandhar. These are great opportunities for convenient drop-offs.</p>
                         <div class="mt-4">
                             <a href="index.php#news-events" class="btn-secondary bg-white text-primary hover:bg-gray-200">See Events <i class="fas fa-calendar-check ml-2"></i></a>
                         </div>
                    </div>
                     <!-- Option 2 -->
                     <div class="bg-primary p-6 rounded-lg shadow-lg">
                         <h3 class="text-white mb-3"><span class="bg-accent text-white rounded-full px-3 py-1 mr-2">2</span> Contact Us Directly</h3>
                         <p class="text-gray-200">For larger quantities (e.g., from businesses, schools, or community groups) or if you need specific guidance, please reach out to us to discuss potential arrangements or connect you with our recycling partners.</p>
                          <div class="mt-4">
                             <a href="#contact-section" class="btn-secondary bg-white text-primary hover:bg-gray-200">Get In Touch <i class="fas fa-phone-alt ml-2"></i></a>
                         </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact-section" class="section-padding bg-white">
            <div class="container mx-auto text-center max-w-3xl">
                <h2 class="mb-6">Contact Us About E-Waste</h2>
                <p class="mb-8">Have questions about our e-waste program, accepted items, collection schedules, or corporate partnerships? We're here to help!</p>
                <p class="mb-8">PAHAL primarily facilitates collection for responsible recycling through certified partners. While we don't typically "buy" e-waste directly from individuals in small quantities, our partners handle the recycling process. For larger quantities or business disposal, please contact us.</p>

                <div class="flex flex-col md:flex-row justify-center items-center space-y-4 md:space-y-0 md:space-x-8 mb-10 text-lg">
                   <div class="flex items-center">
                       <i class="fas fa-phone-alt text-primary text-2xl mr-3"></i>
                       <a href="tel:+919855614230" class="text-neutral-dark hover:text-primary font-semibold">+91 98556-14230</a>
                   </div>
                    <div class="flex items-center">
                       <i class="fas fa-envelope text-primary text-2xl mr-3"></i>
                       <a href="mailto:engage@pahal-ngo.org?subject=E-Waste Inquiry" class="text-neutral-dark hover:text-primary font-semibold">engage@pahal-ngo.org</a>
                    </div>
                </div>

                <a href="index.php#contact" class="btn">More Contact Options</a>
            </div>
        </section>

    </main>

    <!-- Footer -->
    <footer class="bg-primary-dark text-gray-300 pt-12 pb-8">
         <div class="container mx-auto text-center">
            <!-- Maybe add logo or quick links here -->
             <p class="text-sm text-gray-400">
                © <?= $current_year ?> PAHAL NGO. All Rights Reserved. | <a href="index.php" class="hover:text-white underline">Back to Main Site</a>
                <!-- Add links like Privacy Policy if applicable -->
            </p>
            <!-- Optional: Add social media icons -->
             <div class="mt-4 space-x-4">
                <a href="#" class="text-gray-400 hover:text-white" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="text-gray-400 hover:text-white" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" class="text-gray-400 hover:text-white" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
    </footer>

 <!-- Include the main JS file if needed for header interactions -->
 <!-- <script src="your-main-script.js"></script> -->

</body>
</html>
