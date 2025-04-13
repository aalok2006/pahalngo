import axios from 'axios';

const API_URL = (import.meta.env.VITE_API_BASE_URL || '/api') + '/contact'; // Vite

const send = async (formData) => {
    const response = await axios.post(API_URL, formData);
    return response.data; // Contains { msg: "..." }
};

export default { send };
