-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 04, 2025 at 12:13 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `switchdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'info',
  `message` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `acknowledged` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) DEFAULT NULL,
  `target_type` varchar(64) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `csrf_tokens`
--

CREATE TABLE `csrf_tokens` (
  `id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `csrf_tokens`
--

INSERT INTO `csrf_tokens` (`id`, `token`, `user_id`, `created_at`, `expires_at`) VALUES
(1, 'a16c5b745ac71359eb2a4a048338bab31e68d8a8dac03bb1e7a08314f148f4dd', 1, '2025-12-03 17:13:08', '2025-12-03 23:43:08');

-- --------------------------------------------------------

--
-- Table structure for table `firmware_files`
--

CREATE TABLE `firmware_files` (
  `id` int(11) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `version` varchar(64) DEFAULT NULL,
  `model` varchar(128) DEFAULT NULL,
  `size` bigint(20) NOT NULL,
  `checksum_sha256` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `display_name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(64) DEFAULT 'general',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `display_name`, `description`, `category`, `created_at`) VALUES
(1, 'switches.view', 'View Switches', 'Can view the list of switches and their basic information', 'switches', '2025-11-07 18:37:23'),
(2, 'switches.create', 'Create Switches', 'Can add new switches to the system', 'switches', '2025-11-07 18:37:23'),
(3, 'switches.update', 'Update Switches', 'Can modify switch settings and credentials', 'switches', '2025-11-07 18:37:23'),
(4, 'switches.delete', 'Delete Switches', 'Can remove switches from the system', 'switches', '2025-11-07 18:37:23'),
(5, 'switches.poll', 'Poll Switches', 'Can poll switches for status updates', 'switches', '2025-11-07 18:37:23'),
(6, 'vlans.view', 'View VLANs', 'Can view VLAN configurations', 'vlans', '2025-11-07 18:37:23'),
(7, 'vlans.create', 'Create VLANs', 'Can create new VLANs on switches', 'vlans', '2025-11-07 18:37:23'),
(8, 'vlans.update', 'Update VLANs', 'Can modify VLAN settings', 'vlans', '2025-11-07 18:37:23'),
(9, 'vlans.delete', 'Delete VLANs', 'Can remove VLANs from switches', 'vlans', '2025-11-07 18:37:23'),
(10, 'vlans.sync', 'Sync VLANs', 'Can synchronize VLANs from switches', 'vlans', '2025-11-07 18:37:23'),
(11, 'interfaces.view', 'View Interfaces', 'Can view interface configurations and status', 'interfaces', '2025-11-07 18:37:23'),
(12, 'interfaces.configure', 'Configure Interfaces', 'Can modify interface settings (VLAN assignment, mode)', 'interfaces', '2025-11-07 18:37:23'),
(13, 'interfaces.sync', 'Sync Interfaces', 'Can synchronize interface data from switches', 'interfaces', '2025-11-07 18:37:23'),
(14, 'configs.view', 'View Configurations', 'Can view switch configuration files', 'configurations', '2025-11-07 18:37:23'),
(15, 'configs.backup', 'Create Backups', 'Can create configuration backups', 'configurations', '2025-11-07 18:37:23'),
(16, 'configs.restore', 'Restore Configurations', 'Can restore configurations from backups', 'configurations', '2025-11-07 18:37:23'),
(17, 'configs.compare', 'Compare Configurations', 'Can compare different configuration versions', 'configurations', '2025-11-07 18:37:23'),
(18, 'alerts.view', 'View Alerts', 'Can view system alerts and notifications', 'alerts', '2025-11-07 18:37:23'),
(19, 'alerts.acknowledge', 'Acknowledge Alerts', 'Can acknowledge and mark alerts as resolved', 'alerts', '2025-11-07 18:37:23'),
(20, 'alerts.delete', 'Delete Alerts', 'Can delete alerts from the system', 'alerts', '2025-11-07 18:37:24'),
(21, 'users.view', 'View Users', 'Can view user accounts and roles', 'users', '2025-11-07 18:37:24'),
(22, 'users.create', 'Create Users', 'Can create new user accounts', 'users', '2025-11-07 18:37:24'),
(23, 'users.update', 'Update Users', 'Can modify user accounts and settings', 'users', '2025-11-07 18:37:24'),
(24, 'users.delete', 'Delete Users', 'Can remove user accounts', 'users', '2025-11-07 18:37:24'),
(25, 'audit.view', 'View Audit Logs', 'Can view system audit logs and activity', 'audit', '2025-11-07 18:37:24'),
(26, 'permissions.manage', 'Manage Permissions', 'Can manage the permissions system', 'permissions', '2025-11-07 18:37:24'),
(27, 'permissions.grant', 'Grant Permissions', 'Can grant permissions to users', 'permissions', '2025-11-07 18:37:24'),
(28, 'permissions.revoke', 'Revoke Permissions', 'Can revoke permissions from users', 'permissions', '2025-11-07 18:37:24'),
(29, 'system.admin', 'System Administration', 'Full system administration access', 'system', '2025-11-07 18:37:24'),
(30, 'config.view', 'View Configuration', 'View current configuration text', 'config', '2025-11-09 14:53:16'),
(31, 'config.history', 'View Config History', 'View configuration history list', 'config', '2025-11-09 14:53:16'),
(32, 'config.download', 'Download Configuration', 'Download configuration as a file', 'config', '2025-11-09 14:53:16'),
(33, 'config.sync', 'Sync Running Config', 'Fetch running configuration from device and store', 'config', '2025-11-09 14:53:16'),
(34, 'config.upload', 'Upload Configuration', 'Upload configuration file into history', 'config', '2025-11-09 14:53:16'),
(35, 'config.edit', 'Edit Configuration', 'Edit configuration text in UI', 'config', '2025-11-09 14:53:16'),
(36, 'config.apply', 'Apply Configuration', 'Apply a configuration to the device via eAPI', 'config', '2025-11-09 14:53:16'),
(37, 'restart.view', 'View Scheduled Restarts', 'View scheduled restart tasks', 'restart', '2025-11-09 14:53:16'),
(38, 'restart.immediate', 'Immediate Restart', 'Initiate immediate device restart', 'restart', '2025-11-09 14:53:16'),
(39, 'restart.schedule', 'Schedule Restart', 'Schedule a future restart', 'restart', '2025-11-09 14:53:16'),
(40, 'restart.cancel', 'Cancel Scheduled Restart', 'Cancel a scheduled restart task', 'restart', '2025-11-09 14:53:16'),
(41, 'interfaces.edit', 'Edit Interfaces', 'Edit interface properties (description, speed, admin status)', 'interfaces', '2025-11-09 14:53:16'),
(42, 'interfaces.tag', 'Tag Interfaces', 'Add or edit custom tags on interfaces', 'interfaces', '2025-11-09 14:53:16'),
(43, 'interfaces.trunk', 'Configure Trunks', 'Set trunk mode, native VLAN, and allowed VLAN list', 'interfaces', '2025-11-09 14:53:16');

-- --------------------------------------------------------

--
-- Table structure for table `port_channels`
--

CREATE TABLE `port_channels` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `port_channel_name` varchar(32) NOT NULL,
  `port_channel_number` int(11) NOT NULL,
  `mode` enum('access','trunk','routed') DEFAULT 'trunk',
  `vlan_id` int(11) DEFAULT NULL,
  `native_vlan_id` int(11) DEFAULT NULL,
  `trunk_vlans` text DEFAULT NULL,
  `lacp_mode` enum('active','passive','on') DEFAULT 'active',
  `description` text DEFAULT NULL,
  `admin_status` enum('up','down','unknown') DEFAULT 'unknown',
  `oper_status` enum('up','down','unknown') DEFAULT 'unknown',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `port_channel_members`
--

CREATE TABLE `port_channel_members` (
  `id` int(11) NOT NULL,
  `port_channel_id` int(11) NOT NULL,
  `interface_name` varchar(32) NOT NULL,
  `admin_status` enum('up','down','unknown') DEFAULT 'unknown',
  `oper_status` enum('up','down','unknown') DEFAULT 'unknown',
  `lacp_state` varchar(32) DEFAULT NULL,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role` enum('admin','operator','viewer') NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role`, `permission_id`) VALUES
(23, 'admin', 1),
(24, 'admin', 2),
(25, 'admin', 3),
(26, 'admin', 4),
(27, 'admin', 5),
(28, 'admin', 6),
(29, 'admin', 7),
(30, 'admin', 8),
(31, 'admin', 9),
(32, 'admin', 10),
(33, 'admin', 11),
(34, 'admin', 12),
(35, 'admin', 13),
(36, 'admin', 14),
(37, 'admin', 15),
(38, 'admin', 16),
(39, 'admin', 17),
(40, 'admin', 18),
(41, 'admin', 19),
(42, 'admin', 20),
(43, 'admin', 21),
(44, 'admin', 22),
(45, 'admin', 23),
(46, 'admin', 24),
(47, 'admin', 25),
(48, 'admin', 26),
(49, 'admin', 27),
(50, 'admin', 28),
(51, 'admin', 29),
(58, 'admin', 30),
(55, 'admin', 31),
(53, 'admin', 32),
(56, 'admin', 33),
(57, 'admin', 34),
(54, 'admin', 35),
(52, 'admin', 36),
(65, 'admin', 37),
(63, 'admin', 38),
(64, 'admin', 39),
(62, 'admin', 40),
(59, 'admin', 41),
(60, 'admin', 42),
(61, 'admin', 43),
(6, 'operator', 1),
(7, 'operator', 2),
(8, 'operator', 3),
(9, 'operator', 5),
(10, 'operator', 6),
(11, 'operator', 7),
(12, 'operator', 8),
(13, 'operator', 10),
(14, 'operator', 11),
(15, 'operator', 12),
(16, 'operator', 13),
(17, 'operator', 14),
(18, 'operator', 15),
(19, 'operator', 16),
(20, 'operator', 17),
(21, 'operator', 18),
(22, 'operator', 19),
(70, 'operator', 30),
(68, 'operator', 31),
(67, 'operator', 32),
(69, 'operator', 33),
(71, 'operator', 37),
(1, 'viewer', 1),
(2, 'viewer', 6),
(3, 'viewer', 11),
(4, 'viewer', 14),
(5, 'viewer', 18),
(76, 'viewer', 30),
(75, 'viewer', 31),
(74, 'viewer', 32),
(77, 'viewer', 37);

-- --------------------------------------------------------

--
-- Table structure for table `scheduled_tasks`
--

CREATE TABLE `scheduled_tasks` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `task_type` enum('restart','backup','config_apply','other') DEFAULT 'other',
  `task_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`task_data`)),
  `scheduled_time` datetime NOT NULL,
  `status` enum('pending','running','completed','failed','cancelled') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `result` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switches`
--

CREATE TABLE `switches` (
  `id` int(11) NOT NULL,
  `hostname` varchar(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `model` varchar(64) DEFAULT NULL,
  `role` varchar(64) DEFAULT NULL,
  `firmware_version` varchar(64) DEFAULT NULL,
  `tags` text DEFAULT NULL,
  `status` enum('up','down','unknown') DEFAULT 'unknown',
  `environment_alert` tinyint(1) DEFAULT 0,
  `last_seen` datetime DEFAULT NULL,
  `last_polled` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switch_configs`
--

CREATE TABLE `switch_configs` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `config_text` text NOT NULL,
  `config_hash` varchar(64) NOT NULL,
  `backup_type` enum('manual','scheduled','before_change') DEFAULT 'manual',
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `auto_backup_of_task_id` int(11) DEFAULT NULL,
  `config_changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_changes`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switch_credentials`
--

CREATE TABLE `switch_credentials` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_encrypted` text NOT NULL,
  `port` int(11) DEFAULT 443,
  `use_https` tinyint(1) DEFAULT 1,
  `timeout` int(11) DEFAULT 10,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switch_interfaces`
--

CREATE TABLE `switch_interfaces` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `interface_name` varchar(32) NOT NULL,
  `admin_status` enum('up','down','unknown') DEFAULT 'unknown',
  `oper_status` enum('up','down','unknown') DEFAULT 'unknown',
  `vlan_id` int(11) DEFAULT NULL,
  `native_vlan_id` int(11) DEFAULT NULL,
  `trunk_vlans` text DEFAULT NULL,
  `mode` enum('access','trunk','routed','unknown') DEFAULT 'unknown',
  `description` text DEFAULT NULL,
  `speed` varchar(32) DEFAULT NULL,
  `last_synced` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switch_vlans`
--

CREATE TABLE `switch_vlans` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `vlan_id` int(11) NOT NULL,
  `name` varchar(64) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','operator','viewer') DEFAULT 'viewer',
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `last_login`) VALUES
(1, 'admin', '$2y$10$RatJLTTFcf2vWor05YBEmOqvVyeZnE7zd.0lfvsoZJUscoxwbOFa.', 'admin', '2025-12-03 17:08:28');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted_by` int(11) NOT NULL,
  `granted_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `permission_id`, `granted_by`, `granted_at`, `expires_at`) VALUES
(1, 1, 22, 1, '2025-11-07 18:48:13', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alerts_switch_severity` (`switch_id`,`severity`),
  ADD KEY `idx_alerts_acknowledged_time` (`acknowledged`,`timestamp`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_log_user_time` (`user_id`,`timestamp`),
  ADD KEY `idx_audit_log_target_time` (`target_type`,`target_id`,`timestamp`);

--
-- Indexes for table `csrf_tokens`
--
ALTER TABLE `csrf_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `firmware_files`
--
ALTER TABLE `firmware_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_stored_filename` (`stored_filename`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_version` (`version`),
  ADD KEY `idx_model` (`model`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `port_channels`
--
ALTER TABLE `port_channels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_switch_portchannel` (`switch_id`,`port_channel_name`),
  ADD KEY `idx_switch_id` (`switch_id`),
  ADD KEY `idx_port_channel_number` (`port_channel_number`);

--
-- Indexes for table `port_channel_members`
--
ALTER TABLE `port_channel_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_portchannel_interface` (`port_channel_id`,`interface_name`),
  ADD KEY `idx_port_channel_id` (`port_channel_id`),
  ADD KEY `idx_interface_name` (`interface_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role`,`permission_id`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_permission_id` (`permission_id`);

--
-- Indexes for table `scheduled_tasks`
--
ALTER TABLE `scheduled_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_scheduled_time` (`scheduled_time`),
  ADD KEY `idx_switch_id` (`switch_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_task_type` (`task_type`);

--
-- Indexes for table `switches`
--
ALTER TABLE `switches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_last_seen` (`last_seen`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_switches_status_polled` (`status`,`last_polled`),
  ADD KEY `idx_environment_alert` (`environment_alert`);

--
-- Indexes for table `switch_configs`
--
ALTER TABLE `switch_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_switch_id` (`switch_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_backup_type` (`backup_type`),
  ADD KEY `idx_switch_configs_task_id` (`auto_backup_of_task_id`);

--
-- Indexes for table `switch_credentials`
--
ALTER TABLE `switch_credentials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `switch_id` (`switch_id`),
  ADD KEY `idx_switch_id` (`switch_id`);

--
-- Indexes for table `switch_interfaces`
--
ALTER TABLE `switch_interfaces`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_switch_interfaces_switch_id` (`switch_id`),
  ADD KEY `idx_admin_status` (`admin_status`),
  ADD KEY `idx_oper_status` (`oper_status`);

--
-- Indexes for table `switch_vlans`
--
ALTER TABLE `switch_vlans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_switch_vlans_switch_id` (`switch_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_permission` (`user_id`,`permission_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_permission_id` (`permission_id`),
  ADD KEY `idx_granted_by` (`granted_by`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `csrf_tokens`
--
ALTER TABLE `csrf_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `firmware_files`
--
ALTER TABLE `firmware_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `port_channels`
--
ALTER TABLE `port_channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `port_channel_members`
--
ALTER TABLE `port_channel_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `scheduled_tasks`
--
ALTER TABLE `scheduled_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `switches`
--
ALTER TABLE `switches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `switch_configs`
--
ALTER TABLE `switch_configs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `switch_credentials`
--
ALTER TABLE `switch_credentials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `switch_interfaces`
--
ALTER TABLE `switch_interfaces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1806;

--
-- AUTO_INCREMENT for table `switch_vlans`
--
ALTER TABLE `switch_vlans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches_old` (`id`);

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `csrf_tokens`
--
ALTER TABLE `csrf_tokens`
  ADD CONSTRAINT `csrf_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `firmware_files`
--
ALTER TABLE `firmware_files`
  ADD CONSTRAINT `firmware_files_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `port_channels`
--
ALTER TABLE `port_channels`
  ADD CONSTRAINT `port_channels_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `port_channel_members`
--
ALTER TABLE `port_channel_members`
  ADD CONSTRAINT `port_channel_members_ibfk_1` FOREIGN KEY (`port_channel_id`) REFERENCES `port_channels` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `scheduled_tasks`
--
ALTER TABLE `scheduled_tasks`
  ADD CONSTRAINT `scheduled_tasks_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `scheduled_tasks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `switch_configs`
--
ALTER TABLE `switch_configs`
  ADD CONSTRAINT `switch_configs_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `switch_configs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `switch_configs_ibfk_3` FOREIGN KEY (`auto_backup_of_task_id`) REFERENCES `scheduled_tasks` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `switch_credentials`
--
ALTER TABLE `switch_credentials`
  ADD CONSTRAINT `switch_credentials_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `switch_interfaces`
--
ALTER TABLE `switch_interfaces`
  ADD CONSTRAINT `fk_switch_interfaces_switch_id` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `switch_vlans`
--
ALTER TABLE `switch_vlans`
  ADD CONSTRAINT `fk_switch_vlans_switch_id` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
