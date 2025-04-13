const express = require('express');
const dotenv = require('dotenv');
const cors = require('cors');
const path = require('path');
const connectDB = require('./config/db');
const contactRoutes = require('./routes/contactRoutes');

dotenv.config();
connectDB(); // Connect to MongoDB

const app = express();

// Middleware
app.use(cors({ origin: process.env.CLIENT_URL || '*' })); // Allow requests from frontend
app.use(express.json()); // To parse JSON request bodies

// Define API Routes
app.use('/api/contact', contactRoutes);

// --- Serve Static Assets in Production ---
if (process.env.NODE_ENV === 'production') {
    // Set static folder (point to the React build directory)
    app.use(express.static(path.join(__dirname, '../client/dist'))); // Adjust if using CRA (../client/build)

    // Serve index.html for any routes not handled by the API
    app.get('*', (req, res) => {
        res.sendFile(path.resolve(__dirname, '../client/dist', 'index.html')); // Adjust path
    });
} else {
    app.get('/', (req, res) => {
        res.send('API is running in development mode...');
    });
}
// --- End Production Setup ---


const PORT = process.env.PORT || 5001;

app.listen(PORT, () => console.log(`Server running in ${process.env.NODE_ENV} mode on port ${PORT}`));
