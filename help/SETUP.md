# Arista Switch Management Platform - Setup Guide

## What is This Application?

The **Arista Switch Management Platform** is a comprehensive web-based management system designed to centrally manage and monitor Arista network switches. This platform provides network administrators with an intuitive, modern interface to:

- **Manage Multiple Switches**: Add, configure, and monitor multiple Arista switches from a single web interface
- **Configure Network Settings**: Create and manage VLANs, configure interfaces, set up port channels, and manage network configurations
- **Monitor Network Health**: Real-time status monitoring, port activity tracking, and system log viewing
- **Manage Configurations**: Edit switch configurations, create backups, restore previous configurations, and synchronize settings across switches
- **Control Access**: User management with role-based permissions (Admin, Operator, Viewer) and granular access control per switch
- **Maintain Switches**: Firmware management, scheduled restarts, time synchronization, and maintenance operations

The platform communicates with Arista switches using the eAPI (eXtensible API) protocol, allowing for programmatic control and configuration without requiring direct console access to each switch.

### Testing & Compatibility Notes

**Tested Hardware:**
- This application has been tested with an **Arista 7280SE-64** switch

**Known Limitations:**
- **Firmware Update Feature**: The firmware update feature has not been tested due to lack of an active Arista support subscription. This feature may require additional testing or configuration.
- **EOS Version Testing**: Testing has been limited to the EOS version available on the test hardware. The application may work with other EOS versions, but comprehensive testing across different versions was not possible without an active support subscription.
- **Switch Model Compatibility**: While the application is designed to work with Arista switches that support eAPI, it has only been tested with the Arista 7280SE-64 model. Other switch models should work but may require additional testing.

**Recommendations:**
- Test the application with your specific switch models and EOS versions before deploying to production
- Verify firmware update functionality if you have an active support subscription
- Report any compatibility issues or unexpected behavior with different switch models or EOS versions

---

## Five-Step Setup Instructions

### Step 1: Install Files on Web Server

**For XAMPP (Windows):**
- Copy the `arista` folder to `C:\xampp\htdocs\`
- Ensure Apache and MySQL services are running in XAMPP Control Panel

**For Apache (Linux):**
- Copy the project to `/var/www/html/arista/` or your web root
- Ensure mod_rewrite is enabled:
  ```bash
  sudo a2enmod rewrite
  sudo systemctl restart apache2
  ```

### Step 2: Create Database and Import Schema

1. Create a MySQL/MariaDB database:
   ```sql
   CREATE DATABASE switchdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Import the database schema:
   ```bash
   mysql -u root -p switchdb < Exemption/add_permissions_tables.sql
   ```

### Step 3: Configure Database Connection

**⚠️ IMPORTANT**: You must configure your database connection settings before the application will work.

Edit the file `api/config.php` and locate the database configuration section (around line 14-18). Update it with your database credentials:

```php
// ============================================
// Database Configuration
// ============================================
define('DB_HOST', 'localhost');        // Your database host (usually 'localhost')
define('DB_NAME', 'switchdb');         // Your database name
define('DB_USER', 'your_username');    // Your MySQL username
define('DB_PASS', 'your_password');    // Your MySQL password
define('DB_CHARSET', 'utf8mb4');
```

**Replace the following values:**
- `your_username` - Your MySQL/MariaDB username (e.g., `root` for XAMPP)
- `your_password` - Your MySQL/MariaDB password (leave empty `''` for XAMPP default)

**Example for XAMPP (default installation):**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'switchdb');
define('DB_USER', 'root');
define('DB_PASS', '');  // Empty password for XAMPP default
```

### Step 4: Set File Permissions (Linux only)

If you're running on Linux, ensure the web server can access the files:
```bash
chmod -R 755 frontend/
chmod -R 755 api/
chmod -R 777 firmware/  # If firmware uploads are needed
```

**Note**: Windows/XAMPP users can skip this step.

### Step 5: Access the Application and Login

1. Open your web browser and navigate to:
   - **XAMPP**: `http://localhost/arista/`
   - **Linux**: `http://your-server-ip/arista/` or `http://localhost/arista/`

2. You will see the login page. Use the **default credentials**:
   - **Username**: `admin`
   - **Password**: `password`

3. After logging in, you can:
   - Add your Arista switches
   - Create additional user accounts
   - Configure permissions
   - Start managing your network switches

---

## Default Login Credentials

**Username**: `admin`  
**Password**: `password`

> **🔒 Security Warning**: Change the default admin password immediately after first login through the User Management interface. The default credentials should only be used for initial setup and testing.

---

## Next Steps After Setup

1. **Change Default Password**: Go to User Management → Edit admin user → Change password
2. **Add Your First Switch**: Click "Add Switch" and enter your switch details
3. **Configure Switch eAPI**: Ensure your switches have eAPI enabled (see Help section in application)
4. **Create Additional Users**: Add team members with appropriate roles
5. **Review Permissions**: Set up granular permissions for different users

---

## Troubleshooting

### Cannot Connect to Database
- Verify database credentials in `api/config.php` are correct
- Ensure MySQL/MariaDB service is running
- Check that the database `switchdb` exists
- Verify the database user has proper permissions

### Page Shows Errors or Blank Screen
- Check browser console (F12) for JavaScript errors
- Verify Apache mod_rewrite is enabled
- Clear browser cache (Ctrl+F5)
- Check Apache error logs

### Cannot Login
- Verify you're using the default credentials: `admin` / `password`
- Check that the database was imported correctly
- Ensure the `users` table exists in the database

### Switches Won't Connect
- Verify switch IP addresses are correct
- Ensure switches have eAPI enabled
- Check network connectivity (ping switch IP)
- Verify switch credentials are correct

---

## Additional Resources

- **Full Documentation**: See `README.md` for comprehensive documentation
- **Tutorial**: See `TUTORIAL.md` for step-by-step guides
- **Quick Reference**: See `QUICK_REFERENCE.md` for common tasks
- **Help Section**: Use the Help menu in the application for switch configuration guides

---

**Need Help?** Check the troubleshooting section in the main README.md or review the Help section within the application.
