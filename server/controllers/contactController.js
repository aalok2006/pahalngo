const nodemailer = require('nodemailer');
require('dotenv').config();
// const ContactSubmission = require('../models/ContactSubmission'); // Optional: if saving

const sendContactEmail = async (req, res) => {
    const { name, email, message } = req.body;

    // Basic Validation
    if (!name || !email || !message) {
        return res.status(400).json({ msg: 'Please enter all fields' });
    }
    // Add email format validation if needed server-side too

    // --- Configure Nodemailer ---
    // Use environment variables securely
    const transporter = nodemailer.createTransport({
        service: 'gmail', // Or your email provider (SendGrid, Mailgun etc.)
        auth: {
            user: process.env.SENDER_EMAIL_USER,
            pass: process.env.SENDER_EMAIL_PASS, // Use App Password for Gmail
        },
    });

    const mailOptions = {
        from: process.env.SENDER_EMAIL_HEADER_FROM || `"PAHAL Website" <${process.env.SENDER_EMAIL_USER}>`,
        replyTo: email, // Set the validated sender email as Reply-To
        to: process.env.RECIPIENT_EMAIL,
        subject: `PAHAL Website Contact Form: ${name}`,
        text: `You have received a new message:\n\nName: ${name}\nEmail: ${email}\n\nMessage:\n${message}`,
        // html: `<p>You have received a new message:</p>...` // Optional HTML version
    };

    try {
        // --- Optional: Save to DB ---
        // const newSubmission = new ContactSubmission({ name, email, message });
        // await newSubmission.save();

        // --- Send Email ---
        await transporter.sendMail(mailOptions);
        console.log('Message sent successfully');
        res.status(200).json({ msg: 'Thank you! Your message has been sent successfully.' });

    } catch (error) {
        console.error('Error sending email:', error);
        // console.error('Nodemailer error details:', error.response || error.message); // More detailed logging
        res.status(500).json({ msg: 'Sorry, there was an error sending your message. Please try again later.' });
    }
};

module.exports = { sendContactEmail };
