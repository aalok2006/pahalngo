import React from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import Header from './components/Layout/Header'; // Adjust path
import Footer from './components/Layout/Footer'; // Adjust path
import Home from './pages/Home';
import BloodDonation from './pages/BloodDonation';
import EWaste from './pages/EWaste';
import ScrollToTop from './components/Utils/ScrollToTop'; // Helper component

function App() {
    return (
        <Router>
             {/* ScrollToTop ensures navigation to new pages starts at the top */}
            <ScrollToTop />
            <div className="flex flex-col min-h-screen">
                <Header />
                <main className="flex-grow"> {/* pt-[70px] handled globally in index.css */}
                    <Routes>
                        <Route path="/" element={<Home />} />
                        <Route path="/blood-donation" element={<BloodDonation />} />
                        <Route path="/e-waste" element={<EWaste />} />
                        {/* Add a 404 route if needed */}
                    </Routes>
                </main>
                <Footer />
            </div>
        </Router>
    );
}

export default App;
