<?php
// Maybe include a common header/footer if you have one
$current_year = date('Y');
?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Waste Collection & Recycling - PAHAL NGO</title>
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
             <h1 class="text-4xl md:text-5xl text-center mb-12 text-primary">E-Waste Collection & Recycling</h1>

            <div class="prose lg:prose-xl max-w-4xl mx-auto text-gray-700">
                <p>Electronic waste (e-waste) is a growing environmental concern. PAHAL is actively involved in promoting responsible e-waste disposal and recycling in partnership with organizations like Karo Sambhav.</p>

                <h2 class="text-primary">What is E-Waste?</h2>
                <p>Information about what constitutes e-waste (computers, phones, TVs, batteries, chargers, etc.) and the dangers of improper disposal.</p>

                <h2 class="text-primary">Our E-Waste Collection Program</h2>
                <p>Details about how PAHAL facilitates e-waste collection. Mention collection drives, drop-off points (if any), or partnerships.</p>
                <!-- List accepted items -->
                <h3 class="text-primary-dark">Accepted Items:</h3>
                <ul>
                    <li>Computers and Laptops</li>
                    <li>Mobile Phones and Tablets</li>
                    <li>Printers and Scanners</li>
                    <li>Keyboards, Mice, Cables</li>
                    <li>Batteries (specific types if applicable)</li>
                    <li>Other small electronics (contact us to confirm)</li>
                </ul>

                 <h2 class="text-primary">How to Dispose of Your E-Waste</h2>
                 <p>Provide clear instructions for individuals and businesses.</p>
                 <p><strong>Option 1: Collection Drives:</strong> Keep an eye on our announcements for upcoming e-waste collection events in Jalandhar.</p>
                 <p><strong>Option 2: Contact Us:</strong> For bulk e-waste or inquiries about drop-off possibilities, please get in touch.</p>


                <h2 class="text-primary">Contact for E-Waste Disposal / Selling</h2>
                <p>PAHAL primarily facilitates collection for responsible recycling through certified partners. While we don't typically "buy" e-waste directly from individuals in small quantities, our partners handle the recycling process. For larger quantities or business disposal, please contact us to discuss potential arrangements or be connected with appropriate recyclers.</p>
                <p>Contact Person/Dept: [E-Waste Program Coordinator, if applicable]</p>
                <p>Phone: <a href="tel:+919855614230" class="text-accent hover:underline">+91 98556-14230</a> (General Inquiry)</p>
                <p>Email: <a href="mailto:engage@pahal-ngo.org" class="text-accent hover:underline">engage@pahal-ngo.org</a> (Subject: E-Waste Inquiry)</p>

                <div class="mt-10 text-center">
                    <a href="index.php#contact" class="btn">General Inquiries</a>
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
