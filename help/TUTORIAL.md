# Arista Switch Management Platform - Tutorial

This tutorial will guide you through the essential features of the Arista Switch Management Platform step by step.

## Prerequisites

- Platform installed and configured
- Admin account created
- At least one Arista switch accessible on your network
- Switch has eAPI enabled

## Tutorial Sections

1. [Initial Setup](#initial-setup)
2. [Adding Your First Switch](#adding-your-first-switch)
3. [Viewing Switch Information](#viewing-switch-information)
4. [Configuring VLANs](#configuring-vlans)
5. [Managing Interfaces](#managing-interfaces)
6. [Editing Switch Configuration](#editing-switch-configuration)
7. [Creating User Accounts](#creating-user-accounts)
8. [Setting Permissions](#setting-permissions)
9. [Backing Up Configurations](#backing-up-configurations)
10. [Monitoring and Logs](#monitoring-and-logs)

---

## Initial Setup

### Step 1: Access the Platform

1. Open your web browser
2. Navigate to `http://localhost/arista/frontend/index.html` (or your server URL)
3. You should see the login page

### Step 2: First Login

1. Enter your admin credentials
2. Click **"Login"**
3. You'll be redirected to the dashboard

**What you'll see:**
- Empty switch list (if no switches added yet)
- Navigation menu on the left
- User information in the top right
- Quick action buttons

---

## Adding Your First Switch

### Step 1: Navigate to Add Switch

1. Click the **"Add Switch"** button in the top right
2. Or use the navigation menu → **Switches** → **Add Switch**

### Step 2: Fill in Switch Information

Fill in the required fields:

- **Hostname**: A friendly name (e.g., "Core-Switch-01")
- **IP Address**: Management IP (e.g., "192.168.1.10")
- **Username**: eAPI username (usually "admin")
- **Password**: eAPI password
- **Description**: Optional description (e.g., "Main core switch in datacenter")

### Step 3: Test Connection

1. Click **"Test Connection"** to verify connectivity
2. Wait for the connection test to complete
3. If successful, you'll see a green checkmark

### Step 4: Save the Switch

1. Click **"Add Switch"** to save
2. The switch will appear in your switch list
3. The system will automatically poll the switch for status

**Tips:**
- Ensure the switch has eAPI enabled before adding
- Use descriptive hostnames for easy identification
- Keep switch credentials secure

---

## Viewing Switch Information

### Step 1: Open Switch Details

1. Click on any switch name in the switch list
2. The switch details page will open

### Step 2: Explore Switch Information

The switch details page has multiple tabs:

#### General Tab
- Switch hostname and IP
- Model and serial number
- Uptime and status
- Software version
- Hardware information

#### Interfaces Tab
- List of all interfaces
- Interface status (Up/Down)
- Speed and duplex settings
- Description and VLAN assignments

#### VLANs Tab
- List of configured VLANs
- VLAN IDs and names
- VLAN interfaces

#### Configuration Tab
- Current running configuration
- Startup configuration
- Configuration backups

#### Logs Tab
- System logs
- Interface logs
- Configuration change logs

#### MAC Address Table Tab
- Learned MAC addresses
- Associated VLANs and interfaces
- Aging information

### Step 3: Refresh Switch Data

1. Click **"Poll Switch"** to refresh all information
2. Data updates automatically every few minutes
3. Manual refresh available at any time

---

## Configuring VLANs

### Creating a New VLAN

#### Step 1: Navigate to VLAN Management

1. Open switch details
2. Click on the **"VLANs"** tab
3. Or use navigation menu → **VLAN Management**

#### Step 2: Create VLAN

1. Click **"Create VLAN"** button
2. Enter VLAN details:
   - **VLAN ID**: Number between 1-4094 (e.g., 100)
   - **VLAN Name**: Descriptive name (e.g., "Sales-VLAN")
   - **Description**: Optional description
3. Click **"Create"**

#### Step 3: Verify VLAN Creation

1. The VLAN appears in the VLAN list
2. Status shows as "Active"
3. VLAN is now available for interface assignment

### Assigning VLANs to Interfaces

#### Step 1: Open Interface Management

1. Navigate to switch details
2. Click **"Interfaces"** tab
3. Find the interface you want to configure

#### Step 2: Configure Access Port

1. Click on an interface (e.g., "Ethernet1")
2. Set **Mode** to "Access"
3. Select **VLAN** (e.g., VLAN 100)
4. Click **"Save"**

#### Step 3: Configure Trunk Port

1. Click on an interface
2. Set **Mode** to "Trunk"
3. Select multiple VLANs to allow on trunk
4. Set native VLAN if needed
5. Click **"Save"**

**Important Notes:**
- Access ports carry traffic for a single VLAN
- Trunk ports carry traffic for multiple VLANs
- Native VLAN is used for untagged traffic on trunks

---

## Managing Interfaces

### Viewing Interface Status

1. Open switch details
2. Go to **"Interfaces"** tab
3. View interface list with:
   - Status indicators (green = up, red = down)
   - Speed and duplex
   - VLAN assignments
   - Description

### Configuring an Interface

#### Step 1: Select Interface

1. Click on an interface name
2. Interface details panel opens

#### Step 2: Configure Settings

Configure as needed:
- **Description**: Add description
- **Speed**: Set speed (auto, 10M, 100M, 1G, 10G)
- **Duplex**: Set duplex (auto, half, full)
- **Mode**: Access or Trunk
- **VLAN**: Assign VLAN(s)
- **Shutdown**: Enable/disable interface

#### Step 3: Apply Changes

1. Click **"Save"** to apply
2. Changes are sent to the switch
3. Interface status updates automatically

### Port Channel Management

#### Creating a Port Channel

1. Navigate to **"Port Channels"** tab
2. Click **"Create Port Channel"**
3. Enter details:
   - **Port Channel ID**: Number (e.g., 1)
   - **Mode**: LACP or Static
4. Add member interfaces
5. Click **"Create"**

#### Adding Members to Port Channel

1. Select a port channel
2. Click **"Add Members"**
3. Select interfaces to add
4. Click **"Add"**

---

## Editing Switch Configuration

### Step 1: Open Configuration Editor

1. Open switch details
2. Navigate to **"Configuration"** tab
3. Click **"Edit Configuration"**

### Step 2: Make Changes

1. Configuration editor opens with current config
2. Make your changes:
   - Add new commands
   - Modify existing commands
   - Remove commands
3. Syntax highlighting helps identify commands

### Step 3: Create Backup (Recommended)

1. Before saving, click **"Backup Configuration"**
2. Enter backup name/description
3. Backup is saved automatically

### Step 4: Save Configuration

1. Review your changes
2. Click **"Save Configuration"**
3. Configuration is applied to switch
4. Changes take effect immediately

### Step 5: View Configuration Diff

1. Click **"Compare with Backup"**
2. See differences between current and backup
3. Identify what changed

**Best Practices:**
- Always backup before making changes
- Test changes in non-production first
- Review diff before applying
- Document changes in backup description

---

## Creating User Accounts

### Step 1: Navigate to User Management

1. Click navigation menu
2. Select **"User Management"** (Admin only)
3. User list displays

### Step 2: Add New User

1. Click **"Add User"** button
2. Fill in user details:
   - **Username**: Unique username
   - **Password**: Strong password
   - **Confirm Password**: Re-enter password
   - **Role**: Select role:
     - **Admin**: Full access to all features
     - **Operator**: Can add/edit switches, limited delete
     - **Viewer**: Read-only access
   - **Email**: Optional email address
3. Click **"Create User"**

### Step 3: Verify User Creation

1. User appears in user list
2. Status shows as "Active"
3. User can now log in

**Role Permissions:**
- **Admin**: Full control, user management, all operations
- **Operator**: Switch management, configuration, no user management
- **Viewer**: Read-only, view switches and configurations

---

## Setting Permissions

### Step 1: Open Permission Management

1. Navigate to **"Permission Management"** (Admin only)
2. Select a user from the list

### Step 2: Grant Permissions

1. Select a switch from the list
2. Check permissions to grant:
   - **View**: Can view switch information
   - **Edit**: Can edit switch configurations
   - **Delete**: Can delete switch
3. Click **"Grant Permission"**

### Step 3: Revoke Permissions

1. View user's current permissions
2. Find permission to revoke
3. Click **"Revoke"** button
4. Permission is removed immediately

**Permission Scenarios:**
- **Full Access**: Grant all permissions on all switches
- **Read-Only**: Grant only View permission
- **Selective Access**: Grant permissions on specific switches only

---

## Backing Up Configurations

### Automatic Backups

The system can automatically backup configurations:
- Before configuration changes
- On a scheduled basis (if configured)
- Before firmware updates

### Manual Backup

#### Step 1: Open Configuration Tab

1. Navigate to switch details
2. Click **"Configuration"** tab

#### Step 2: Create Backup

1. Click **"Backup Configuration"**
2. Enter backup name (optional)
3. Add description (optional)
4. Click **"Create Backup"**

#### Step 3: View Backups

1. Backups list shows all backups
2. View backup details:
   - Date and time
   - Description
   - Size
3. Click backup to view contents

### Restoring from Backup

#### Step 1: Select Backup

1. Open backups list
2. Click on a backup to restore

#### Step 2: Review Backup

1. View backup contents
2. Compare with current configuration
3. Verify this is the correct backup

#### Step 3: Restore

1. Click **"Restore Configuration"**
2. Confirm restoration
3. Configuration is restored to switch
4. Switch may need to reload

**Important:**
- Restoring may cause network interruption
- Verify backup is correct before restoring
- Consider maintenance window for restoration

---

## Monitoring and Logs

### Viewing Real-time Logs

#### Step 1: Open Logs Viewer

1. Navigate to switch details
2. Click **"Logs"** tab
3. Logs display automatically

#### Step 2: Filter Logs

1. Use filter options:
   - **Log Level**: Info, Warning, Error
   - **Time Range**: Last hour, day, week
   - **Search**: Search for specific text
2. Logs update in real-time

#### Step 3: Export Logs

1. Click **"Export Logs"**
2. Select format (CSV, TXT)
3. Download logs file

### Monitoring Port Activity

#### Step 1: Open Port Activity

1. Navigate to switch details
2. Click **"Port Activity"** tab

#### Step 2: View Statistics

View real-time statistics:
- **Port Status**: Up/Down indicators
- **Traffic**: Bytes in/out
- **Errors**: Error counters
- **Utilization**: Port utilization percentage

#### Step 3: Refresh Data

1. Data updates automatically
2. Manual refresh available
3. Set auto-refresh interval

### MAC Address Table

#### Step 1: View MAC Table

1. Navigate to switch details
2. Click **"MAC Address Table"** tab
3. View learned MAC addresses

#### Step 2: Search MAC Addresses

1. Use search box
2. Filter by VLAN or interface
3. View MAC address details:
   - MAC address
   - VLAN
   - Interface
   - Age

---

## Advanced Features

### Time Settings

1. Navigate to switch details
2. Click **"Time Settings"** tab
3. Configure:
   - **NTP Servers**: Add NTP server addresses
   - **Time Zone**: Set timezone
   - **Manual Time**: Set time manually if needed
4. Click **"Save"**

### Restart Scheduler

1. Navigate to **"Restart Scheduler"**
2. Select a switch
3. Set restart time
4. Add description
5. Schedule restart

### Firmware Management

1. Navigate to **"Firmware Manager"**
2. Select a switch
3. **Upload**: Upload new firmware file
4. **Download**: Download current firmware
5. View firmware version information

---

## Tips and Best Practices

### General Tips

1. **Regular Backups**: Backup configurations regularly
2. **Document Changes**: Document all configuration changes
3. **Test First**: Test changes in non-production environment
4. **Monitor Logs**: Regularly review logs for issues
5. **User Permissions**: Follow principle of least privilege

### Security Tips

1. **Strong Passwords**: Use strong passwords for all accounts
2. **Regular Updates**: Keep switches and platform updated
3. **Access Control**: Limit access to authorized users only
4. **Audit Logs**: Regularly review audit logs
5. **Network Security**: Use firewalls and network segmentation

### Troubleshooting Tips

1. **Check Connectivity**: Verify switch connectivity first
2. **Review Logs**: Check logs for error messages
3. **Test Connection**: Use "Test Connection" feature
4. **Browser Console**: Check browser console for errors
5. **Switch Logs**: Review switch logs directly if needed

---

## Next Steps

Now that you've completed the tutorial:

1. **Explore Advanced Features**: Try port channels, firmware management
2. **Set Up Monitoring**: Configure alerts and monitoring
3. **Create User Accounts**: Add team members with appropriate roles
4. **Document Your Setup**: Document your network topology
5. **Regular Maintenance**: Set up regular backup schedules

## Getting Help

- Check the **Help** section in the application
- Review the main README.md file
- Check browser console for errors
- Review switch logs
- Contact your network administrator

---

**Happy Managing!** 🚀
