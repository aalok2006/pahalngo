// Example ContactForm.js snippet
import React, { useState } from 'react';
import axios from 'axios'; // Or use a service file

const ContactForm = () => {
    const [formData, setFormData] = useState({ name: '', email: '', message: '' });
    const [status, setStatus] = useState({ loading: false, error: null, successMsg: null });

    const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api'; // Vite env var

    const handleChange = (e) => {
        setFormData({ ...formData, [e.target.name]: e.target.value });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setStatus({ loading: true, error: null, successMsg: null });
        try {
            // Ideally use a service: await contactService.send(formData);
            const res = await axios.post(`${apiBaseUrl}/contact`, formData);
            setStatus({ loading: false, error: null, successMsg: res.data.msg });
            setFormData({ name: '', email: '', message: '' }); // Clear form on success
        } catch (err) {
            const errorMsg = err.response?.data?.msg || 'An unexpected error occurred.';
            setStatus({ loading: false, error: errorMsg, successMsg: null });
            console.error("Form Submit Error:", err.response || err);
        }
    };

    return (
        <div className="lg:col-span-3 bg-gray-50 p-6 sm:p-8 md:p-10 rounded-lg shadow-lg border-t-4 border-primary">
            <h3 className="text-2xl mb-6">Send Us a Message</h3>

            {/* Status Messages */}
            {status.loading && <div className="mb-4 text-blue-600">Sending...</div>}
            {status.error && <div className="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">{status.error}</div>}
            {status.successMsg && <div className="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">{status.successMsg}</div>}

            <form id="contact-form" onSubmit={handleSubmit} className="space-y-5">
                 {/* Input fields with value={formData.name} and onChange={handleChange} */}
                 <div>
                     <label htmlFor="name" className="block mb-2 text-sm font-medium text-primary">Name:</label>
                     <input type="text" id="name" name="name" required value={formData.name} onChange={handleChange} className="..." disabled={status.loading} />
                 </div>
                  <div>
                     <label htmlFor="email" className="block mb-2 text-sm font-medium text-primary">Email:</label>
                     <input type="email" id="email" name="email" required value={formData.email} onChange={handleChange} className="..." disabled={status.loading} />
                 </div>
                 <div>
                     <label htmlFor="message" className="block mb-2 text-sm font-medium text-primary">Message:</label>
                     <textarea id="message" name="message" rows="5" required value={formData.message} onChange={handleChange} className="..." disabled={status.loading}></textarea>
                 </div>
                 <button type="submit" className="btn w-full sm:w-auto" disabled={status.loading}>
                    {status.loading ? 'Sending...' : 'Send Message'}
                 </button>
            </form>
        </div>
    );
};
export default ContactForm;
