📦 ItemTrack

ItemTrack is a PHP-based web application designed to help organizations, schools, or businesses manage and track their items efficiently. It supports adding, editing, deleting, and tracking items, including user-based item tracking and notifications.
##
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)

##
🚀 Features

🔹 Item Management

Add, edit, and delete items (add_item.php, edit_item.php, delete_item.php)

Track item status, location, and quantity (items.php)

View detailed item information and history (track_item.php, update_location.php)

🔹 User Management

User registration and login (register.php, login.php, logout.php)

Password creation and reset (create_password.php)

User profile management (user_profile.php)

Dashboard for admins and users (dashboard.php, user_dashboard.php)

🔹 Tracking & Notifications

Item tracking by users (user_tracking.php)

Notifications system for item updates or movements (notifications.php)

User-specific cart and order management (user_cart.php, user_orders.php, test_cart.php)

🔹 Frontend/Backend

Responsive design with CSS (css/style.css)

Interactive elements with JavaScript (js/main.js)

PHP for backend logic and MySQL for database storage

##🏗️ Project Structur
```
ItemTrack/
│
├── css/
│   └── style.css                  # Main stylesheet
│
├── database/
│   └── inventory_system.sql       # Database schema
│
├── includes/
│   └── header.php                 # Header file included in pages
│
├── js/
│   └── main.js                    # Main JavaScript file
│
├── add_item.php                   # Add new items
├── config.php                     # Database connection
├── create_password.php            # Password creation
├── dashboard.php                  # Admin dashboard
├── delete_item.php                # Delete items
├── edit_item.php                  # Edit items
├── index.php                      # Landing page
├── items.php                      # View all items
├── login.php                      # User login
├── logout.php                     # Logout handler
├── notifications.php              # Notifications system
├── register.php                   # User registration
├── test_cart.php                  # Cart testing
├── track_item.php                 # Track item details
├── update_location.php            # Update item location
├── user_cart.php                  # User shopping cart
├── user_dashboard.php             # User dashboard
├── user_orders.php                # User order history
├── user_profile.php               # User profile
├── user_tracking.php              # User item tracking
└── README.md                      # Project documentation
