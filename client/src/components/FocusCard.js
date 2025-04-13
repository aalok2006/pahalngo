// Example FocusCard.js
import React from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
// Import specific icons needed, e.g., import { faHeartbeat, faLeaf } from '@fortawesome/free-solid-svg-icons';

const FocusCard = ({ icon, title, text, linkTo, isExternal = false }) => {
    const cardContent = (
        <>
            <span className="icon text-5xl text-accent mb-5 inline-block group-hover:scale-110 transition-transform">
                <FontAwesomeIcon icon={icon} />
            </span>
            <h3 className="text-xl text-primary mb-3 group-hover:text-accent-dark transition-colors">{title}</h3>
            <p className="text-sm text-gray-600 leading-relaxed">{text}</p>
        </>
    );

    const cardClasses = "focus-item block bg-white p-8 rounded-lg shadow-md text-center transition duration-300 ease-in-out hover:shadow-xl hover:-translate-y-2 no-underline group"; // Added 'block'

    if (linkTo) {
        if (isExternal) {
             return <a href={linkTo} target="_blank" rel="noopener noreferrer" className={cardClasses}>{cardContent}</a>;
        } else {
            return <RouterLink to={linkTo} className={cardClasses}>{cardContent}</RouterLink>;
        }
    } else {
         // Render as a div if no link is provided
        return <div className={cardClasses.replace('block', '')}>{cardContent}</div>; // Remove 'block' if it's just a div
    }
};

export default FocusCard;
