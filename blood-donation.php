<?php
// Maybe include a common header/footer if you have one
$current_year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Donation - PAHAL NGO</title>
    <!-- Include Tailwind CSS, Fonts, FontAwesome like in the main file -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" />
    <script>
        // Copy Tailwind config from main file if needed
        tailwind.config = { /* ... */ }
    </script>
    <style type="text/tailwindcss">
        /* Copy base styles, btn, section-title etc. from main file */
        body { @apply font-['Open_Sans'] text-gray-700 leading-relaxed pt-[70px]; /* Add padding-top for fixed header */ }
        h1, h2, h3, h4, h5, h6 { @apply font-['Lato'] text-primary font-bold leading-tight mb-3; }
        .section-title { /* ... */ }
        .btn { /* ... */ }
        /* Add any specific styles for this page */
    </style>
     <!-- Add header styles from main file -->
    <style type="text/tailwindcss">
        #main-header { @apply fixed top-0 left-0 w-full bg-white z-50 shadow-md transition-all duration-300; min-height: 70px; }
        /* Copy other relevant header styles */
    </style>
</head>
<body>
    <!-- You might want to include the same header here -->
    <header id="main-header" class="py-2 md:py-0">
       <div class="container mx-auto flex flex-wrap items-center justify-between">
           <div class="logo"><a href="index.php#hero" class="text-4xl font-black text-accent font-['Lato'] leading-none">PAHAL</a></div>
           <!-- Include nav if needed, or just a link back -->
           <nav><a href="index.php" class="text-primary hover:text-accent font-semibold">Back to Home</a></nav>
       </div>
    </header>

    <main>
        <section class="py-16 md:py-24">
            <div class="container mx-auto">
                <h1 class="text-4xl md:text-5xl text-center mb-12 text-primary">Blood Donation Initiatives</h1>

                <div class="prose lg:prose-xl max-w-4xl mx-auto text-gray-700">
                    <p>PAHAL is deeply committed to addressing the critical need for blood in our community. We regularly organize blood donation camps and awareness drives.</p>

                    <h2 class="text-primary">Why Donate Blood?</h2>
                    <p>Information about the importance of blood donation...</p>
                    <ul>
                        <li>Saves lives</li>
                        <li>Helps patients undergoing surgery or suffering from chronic illnesses</li>
                        <li>A single donation can help multiple people</li>
                    </ul>

                    <h2 class="text-primary">Our Blood Donation Camps</h2>
                    <p>Details about past and upcoming camps. We have organized over 530 camps and mobilized more than 58,000 units of blood.</p>
                    <!-- You could add a list or table of upcoming camps -->

                    <h2 class="text-primary">How You Can Help</h2>
                    <p>Information for potential donors: eligibility criteria, the donation process, what to expect.</p>
                    <p>Information for volunteers wanting to help organize camps.</p>

                    <h2 class="text-primary">Need Blood?</h2>
                    <p>While PAHAL focuses on organizing donations, if you are in urgent need, please contact the local blood banks directly. You can also reach out to us, and we will do our best to connect you with potential donors or resources.</p>
                    <p>Contact: [Specific Blood Donation Contact Info if available, otherwise general contact]</p>
                    <p>Phone: <a href="tel:+919855614230" class="text-accent hover:underline">+91 98556-14230</a></p>
                    <p>Email: <a href="mailto:engage@pahal-ngo.org" class="text-accent hover:underline">engage@pahal-ngo.org</a></p>

                    <div class="mt-10 text-center">
                        <a href="index.php#contact" class="btn">Contact Us for More Info</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- You might want to include the same footer here -->
     <footer class="bg-primary text-gray-200 pt-16 pb-8 mt-16">
        <div class="container mx-auto text-center text-sm text-gray-400">
             © <?= $current_year ?> PAHAL NGO. All Rights Reserved. | <a href="index.php" class="hover:text-white">Back to Main Site</a>
        </div>
    </footer>
     <!-- Include the main JS file if needed for header interactions -->
     <!-- <script src="your-main-script.js"></script> -->

</body>
</html>
