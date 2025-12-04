# Quick Reference Guide

Quick reference for common tasks in the Arista Switch Management Platform.

## Common Tasks

### Switch Operations

| Task | Steps |
|------|-------|
| **Add Switch** | Dashboard → Add Switch → Fill form → Test Connection → Save |
| **Edit Switch** | Switch List → Click Switch → Edit → Save |
| **Delete Switch** | Switch List → Delete Button → Confirm |
| **Poll Switch** | Switch List → Poll Button (or Switch Details → Poll) |
| **View Switch Details** | Switch List → Click Switch Name |

### VLANs → Select VLAN → Edit → Save |

### VLAN Operations

| Task | Steps |
|------|-------|
| **Create VLAN** | Switch Details → VLANs Tab → Create VLAN → Fill form → Save |
| **Edit VLAN** | Switch Details → VLANs Tab → Select VLAN → Edit → Save |
| **Delete VLAN** | Switch Details → VLANs Tab → Select VLAN → Delete → Confirm |
| **Assign VLAN to Port** | Switch Details → Interfaces Tab → Select Interface → Set Mode/VLAN → Save |

### Interface Operations

| Task | Steps |
|------|-------|
| **Configure Interface** | Switch Details → Interfaces Tab → Select Interface → Configure → Save |
| **Enable/Disable Port** | Switch Details → Interfaces Tab → Select Interface → Toggle Shutdown → Save |
| **Set Port Speed** | Switch Details → Interfaces Tab → Select Interface → Set Speed → Save |
| **Create Port Channel** | Switch Details → Port Channels Tab → Create → Add Members → Save |

### Configuration Operations

| Task | Steps |
|------|-------|
| **Edit Configuration** | Switch Details → Configuration Tab → Edit → Make Changes → Save |
| **Backup Configuration** | Switch Details → Configuration Tab → Backup → Name → Create |
| **Restore Configuration** | Switch Details → Configuration Tab → Select Backup → Restore → Confirm |
| **View Config Diff** | Switch Details → Configuration Tab → Compare with Backup |

### User Operations

| Task | Steps |
|------|-------|
| **Add User** | User Management → Add User → Fill form → Create |
| **Edit User** | User Management → Select User → Edit → Save |
| **Delete User** | User Management → Select User → Delete → Confirm |
| **Set Permissions** | Permission Management → Select User → Select Switch → Grant/Revoke |

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl + F` | Search/Filter |
| `Esc` | Close Modal/Dialog |
| `Enter` | Submit Form |
| `Tab` | Navigate Fields |

## Status Indicators

| Indicator | Meaning |
|-----------|---------|
| 🟢 Green | Online/Active/Up |
| 🔴 Red | Offline/Inactive/Down |
| 🟡 Yellow | Warning/Degraded |
| ⚪ Gray | Unknown/Unavailable |

## Common Error Messages

| Error | Solution |
|-------|----------|
| "Connection Failed" | Check switch IP, eAPI enabled, network connectivity |
| "Authentication Failed" | Verify username/password, check switch credentials |
| "Permission Denied" | Check user role and permissions |
| "Configuration Error" | Verify configuration syntax, check switch logs |
| "Session Expired" | Log in again, check session timeout settings |

## API Endpoints Quick Reference

### Authentication
- `POST /arista/api/auth/login.php` - Login
- `GET /arista/api/auth/session.php` - Check session
- `POST /arista/api/auth/logout.php` - Logout

### Switches
- `GET /arista/api/switches/list.php` - List switches
- `GET /arista/api/switches/get.php?id={id}` - Get switch details
- `POST /arista/api/switches/add.php` - Add switch
- `POST /arista/api/switches/update.php` - Update switch
- `POST /arista/api/switches/delete.php` - Delete switch
- `POST /arista/api/switches/poll.php` - Poll switch

### VLANs
- `GET /arista/api/switches/vlans/list.php?switch_id={id}` - List VLANs
- `POST /arista/api/switches/vlans/create.php` - Create VLAN
- `POST /arista/api/switches/vlans/update.php` - Update VLAN
- `POST /arista/api/switches/vlans/delete.php` - Delete VLAN

### Configuration
- `GET /arista/api/switches/config/get.php?switch_id={id}` - Get config
- `POST /arista/api/switches/config/save.php` - Save config
- `POST /arista/api/switches/config/backup.php` - Backup config
- `POST /arista/api/switches/config/restore.php` - Restore config

## User Roles

| Role | Permissions |
|------|-------------|
| **Admin** | Full access, user management, all operations |
| **Operator** | Switch management, configuration, no user management |
| **Viewer** | Read-only access, view switches and configurations |

## File Locations

| File/Directory | Purpose |
|----------------|---------|
| `frontend/index.html` | Main application entry point |
| `frontend/app.js` | Main Vue.js application |
| `frontend/components/` | Vue.js components |
| `api/config.php` | Application configuration |
| `api/classes/` | PHP classes (Database, Security, etc.) |
| `api/switches/` | Switch-related API endpoints |
| `.htaccess` | Apache rewrite rules |

## Troubleshooting Quick Fixes

### Switch Won't Connect
1. Verify IP address is correct
2. Check eAPI is enabled on switch
3. Test network connectivity (ping)
4. Verify credentials

### Page Won't Load
1. Clear browser cache (Ctrl+F5)
2. Check Apache is running
3. Verify `.htaccess` file exists
4. Check browser console for errors

### Configuration Won't Save
1. Check switch connectivity
2. Verify user permissions
3. Check configuration syntax
4. Review switch logs

### Session Expires Quickly
1. Increase `SESSION_LIFETIME` in `api/config.php`
2. Check server time synchronization
3. Clear browser cookies

## Support Contacts

- **Documentation**: See `README.md` and `TUTORIAL.md`
- **Help Section**: Application → Help menu
- **Issues**: GitHub Issues (if applicable)

---

**Last Updated**: 2024
