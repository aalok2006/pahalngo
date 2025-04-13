const express = require('express');
const { sendContactEmail } = require('../controllers/contactController');
const router = express.Router();

// @route   POST /api/contact
// @desc    Send contact form email
// @access  Public
router.post('/', sendContactEmail);

module.exports = router;
