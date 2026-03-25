# PC POS & Inventory Management System

A complete Point of Sale (POS) and Inventory Management System built for PC components stores using PHP, MySQL, Bootstrap 5, and a modular architecture.

## Features

- **User Management**: Role-based access control with granular permissions
- **Product Management**: Categories, products, specifications (EAV pattern), multiple images
- **Inventory Management**: Stock movements, adjustments, low stock alerts
- **Point of Sale (POS)**: Complete sales transaction processing
- **Purchase Orders**: Manage supplier orders and receiving
- **Customer Management**: Customer database and transaction history
- **Reports & Analytics**: Sales reports, inventory reports, and more
- **Security**: CSRF protection, XSS prevention, password hashing, prepared statements

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.2 or higher
- Apache web server with mod_rewrite enabled
- XAMPP / WAMP / LAMP (or similar)

## Installation

### 1. Database Setup

1. Create a new MySQL database:
   ```sql
   CREATE DATABASE pc_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Import the database schema:
   ```bash
   mysql -u root -p pc_pos < database/schema.sql
   ```
   Or use phpMyAdmin to import `database/schema.sql`

### 2. Configuration

1. Edit `config/config.php` and update the following settings:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'pc_pos');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('APP_URL', 'http://localhost/pc_pos');
   ```

2. Create uploads directory:
   ```bash
   mkdir uploads
   chmod 755 uploads
   ```

### 3. Web Server Configuration

#### Apache (.htaccess)
The `.htaccess` file is already included. Ensure `mod_rewrite` is enabled.

#### Nginx
Add the following to your server block:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 4. Default Login Credentials

- **Username**: `admin`
- **Password**: `admin123`

**⚠️ IMPORTANT**: Change the default password immediately after first login!

## Project Structure

```
pc_pos/
├── assets/
│   ├── css/
│   │   └── style.css          # Custom styles
│   └── js/
│       └── main.js            # Main JavaScript
├── config/
│   ├── config.php             # Application configuration
│   └── database.php           # Database connection class
├── database/
│   └── schema.sql             # Database schema
├── includes/
│   ├── init.php               # Initialization file
│   ├── auth.php               # Authentication functions
│   ├── security.php           # Security functions
│   ├── csrf.php               # CSRF protection
│   └── functions.php          # Utility functions
├── modules/
│   ├── products.php           # Products module
│   ├── categories.php         # Categories module
│   ├── inventory.php          # Inventory module
│   ├── sales.php              # Sales/POS module
│   ├── purchase_orders.php    # Purchase orders module
│   ├── users.php              # Users module
│   ├── customers.php          # Customers module
│   └── suppliers.php          # Suppliers module
├── templates/
│   ├── header.php             # Header template
│   └── footer.php             # Footer template
├── uploads/                   # File uploads directory
├── index.php                  # Dashboard
├── login.php                  # Login page
├── logout.php                 # Logout handler
└── README.md                  # This file
```

## Database Schema

The system includes 20 core tables:

1. **users** - User accounts
2. **roles** - User roles
3. **permissions** - System permissions
4. **role_permissions** - Role-permission mapping
5. **user_logs** - Activity logs
6. **categories** - Product categories (hierarchical)
7. **suppliers** - Supplier information
8. **products** - Product master data
9. **product_specifications** - Product specs (EAV)
10. **product_images** - Product images
11. **stock_movements** - Inventory transactions
12. **customers** - Customer database
13. **transactions** - Sales transactions
14. **transaction_items** - Transaction line items
15. **payments** - Payment records
16. **purchase_orders** - Purchase order headers
17. **purchase_order_items** - PO line items
18. **returns** - Return transactions
19. **price_history** - Price change tracking
20. **inventory_adjustments** - Stock adjustments

## Security Features

- **Prepared Statements**: All database queries use PDO prepared statements
- **Password Hashing**: Bcrypt password hashing
- **CSRF Protection**: Token-based CSRF protection
- **XSS Prevention**: Input sanitization and output escaping
- **Session Security**: Secure session management
- **Rate Limiting**: Login attempt rate limiting
- **Role-Based Access Control**: Granular permission system

## Usage

### Adding a Product

1. Navigate to Products > Add Product
2. Fill in product details
3. Add specifications (CPU, RAM, Storage, etc.)
4. Upload product images
5. Set stock levels and pricing

### Processing a Sale

1. Navigate to Sales > POS
2. Scan or search for products
3. Add items to cart
4. Select customer (optional)
5. Process payment
6. Complete transaction

### Creating Purchase Order

1. Navigate to Purchase Orders > New PO
2. Select supplier
3. Add products and quantities
4. Submit PO
5. Receive items when delivered

## Development

### Adding a New Module

1. Create a new class in `modules/your_module.php`
2. Follow the existing module pattern
3. Include proper error handling
4. Add user activity logging
5. Create corresponding pages in root directory

### Customizing Styles

Edit `assets/css/style.css` or add custom CSS files and include them in page templates.

## Troubleshooting

### Database Connection Error
- Check database credentials in `config/config.php`
- Ensure MySQL service is running
- Verify database exists

### Permission Denied Errors
- Check file permissions on `uploads/` directory
- Ensure web server has write access

### Session Issues
- Check PHP session configuration
- Ensure session directory is writable

## License

This project is provided as-is for educational and commercial use.

## Support

For issues and questions, please refer to the code comments or create an issue in the repository.

