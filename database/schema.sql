
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_attendees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_attendees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `resident_name` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `registered_at` datetime DEFAULT current_timestamp(),
  `sms_status` enum('sent','failed','pending') DEFAULT 'sent',
  `sms_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `agency_type` enum('ICDRRMO','BDRRMC') NOT NULL DEFAULT 'BDRRMC',
  `barangay_id` int(11) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `office_address` varchar(255) DEFAULT NULL,
  `coordinates_lat` decimal(10,6) DEFAULT NULL,
  `coordinates_lng` decimal(10,6) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `agencies_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `barangay_resource_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barangay_resource_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_code` varchar(50) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `category` enum('Relief Goods','Medical Supplies','Rescue Equipment','Emergency Supplies','Shelter & Sanitation') NOT NULL DEFAULT 'Relief Goods',
  `requested_quantity` int(11) NOT NULL,
  `approved_quantity` int(11) DEFAULT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'units',
  `urgency` enum('Immediate','High','Medium','Low') NOT NULL DEFAULT 'High',
  `target_purok` varchar(100) DEFAULT NULL,
  `purpose` text NOT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `review_remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_code` (`request_code`),
  KEY `barangay_id` (`barangay_id`),
  KEY `requested_by` (`requested_by`),
  KEY `resource_id` (`resource_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `barangay_resource_requests_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `barangay_resource_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `barangay_resource_requests_ibfk_3` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `barangay_resource_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `barangays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barangays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `city` varchar(100) DEFAULT 'Iligan City',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `population` int(11) DEFAULT NULL,
  `total_households` int(11) DEFAULT NULL,
  `risk_level` enum('Low','Moderate','High','Critical') DEFAULT NULL,
  `area_radius` int(11) DEFAULT NULL COMMENT 'Geographic coverage and hazard radius of the area in meters',
  `coordinates_lat` decimal(10,6) NOT NULL,
  `coordinates_lng` decimal(10,6) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `disaster_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disaster_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_code` varchar(50) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `purok_name` varchar(100) NOT NULL,
  `disaster_type` enum('Flood','Typhoon','Landslide','Earthquake','Fire','Storm Surge','Flash Flood') NOT NULL,
  `severity` enum('Low','Moderate','High','Critical') NOT NULL,
  `urgency` enum('Immediate','High','Medium','Low') NOT NULL,
  `affected_families` int(11) NOT NULL DEFAULT 0,
  `affected_individuals` int(11) NOT NULL DEFAULT 0,
  `displaced_families` int(11) NOT NULL DEFAULT 0,
  `casualties_count` int(11) NOT NULL DEFAULT 0,
  `injuries_count` int(11) NOT NULL DEFAULT 0,
  `missing_count` int(11) NOT NULL DEFAULT 0,
  `requested_assistance` text NOT NULL,
  `situation_overview` text DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `status` enum('Submitted','Ongoing','Under Review','Recommendation Ready','Approved','Allocated','Dispatched','Completed','Rejected') NOT NULL DEFAULT 'Ongoing',
  `submitted_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_code` (`tracking_code`),
  KEY `barangay_id` (`barangay_id`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `disaster_requests_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disaster_requests_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evacuation_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evacuation_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `location_address` varchar(255) NOT NULL,
  `center_type` enum('School Gym','Barangay Multi-Purpose Hall','Civic Center','Church / Chapel','Designated Open Ground') NOT NULL,
  `capacity_individuals` int(11) NOT NULL,
  `capacity_families` int(11) NOT NULL,
  `current_evacuees_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('Standby','Open / Active','At Capacity','Closed') DEFAULT 'Standby',
  `accessibility` enum('Accessible (All Vehicles)','High-Clearance / 4x4 Only','Foot Access Only','Temporarily Inaccessible') DEFAULT 'Accessible (All Vehicles)',
  `has_potable_water` tinyint(1) DEFAULT 1,
  `has_electricity` tinyint(1) DEFAULT 1,
  `has_medical_station` tinyint(1) DEFAULT 1,
  `contact_officer` varchar(100) NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `coordinates_lat` decimal(10,6) NOT NULL,
  `coordinates_lng` decimal(10,6) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `evacuation_areas_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evacuation_center_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evacuation_center_resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evacuation_area_id` int(11) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `resource_name` varchar(150) NOT NULL,
  `category` enum('Relief Goods','Medical Supplies','Rescue Equipment','Emergency Supplies','Shelter & Sanitation') NOT NULL DEFAULT 'Relief Goods',
  `unit` varchar(50) NOT NULL DEFAULT 'units',
  `quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('Available','In Use','Depleted','Reserved') DEFAULT 'Available',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `evacuation_area_id` (`evacuation_area_id`),
  CONSTRAINT `evacuation_center_resources_ibfk_1` FOREIGN KEY (`evacuation_area_id`) REFERENCES `evacuation_areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hazard_type_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hazard_type_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notif_user` (`notification_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notification_reads_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_role` enum('all','icdrrmo','barangay_head','responder','resident') NOT NULL DEFAULT 'all',
  `target_barangay_id` int(11) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `alert_level` enum('informational','warning','urgent') NOT NULL DEFAULT 'informational',
  `related_module` varchar(50) DEFAULT 'system',
  `related_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `target_barangay_id` (`target_barangay_id`),
  KEY `target_user_id` (`target_user_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`target_barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preparedness_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preparedness_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `activity_type` enum('Disaster Drill','First Aid / Rescuer Training','Community Seminar','IEC Campaign','Contingency Planning','Hazard Mapping Workshop') NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `venue` varchar(150) NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `assigned_personnel` varchar(150) NOT NULL,
  `target_participants` int(11) NOT NULL DEFAULT 50,
  `actual_participants` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `status` enum('Scheduled','Ongoing','Completed','Cancelled') DEFAULT 'Scheduled',
  `evaluation_summary` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `preparedness_activities_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `preparedness_activities_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purok_cluster_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purok_cluster_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_brgy` (`barangay_id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `puroks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `puroks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `hazard_types` varchar(255) DEFAULT 'Flood, Strong Winds',
  `risk_level` enum('Low','Moderate','High','Critical') DEFAULT 'Moderate',
  `households` int(11) DEFAULT 0,
  `population` int(11) DEFAULT 0,
  `coordinates_lat` decimal(10,6) NOT NULL,
  `coordinates_lng` decimal(10,6) NOT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `puroks_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recommendations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recommendation_code` varchar(50) NOT NULL,
  `disaster_request_id` int(11) NOT NULL,
  `priority` enum('Low','Medium','High','Critical') NOT NULL,
  `confidence_score` decimal(5,2) NOT NULL DEFAULT 85.00,
  `decision_path` text NOT NULL,
  `recommendation_reason` text NOT NULL,
  `status` enum('Pending Review','Approved','Rejected','Modified') DEFAULT 'Pending Review',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `recommendation_code` (`recommendation_code`),
  KEY `disaster_request_id` (`disaster_request_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `recommendations_ibfk_1` FOREIGN KEY (`disaster_request_id`) REFERENCES `disaster_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recommendations_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recommended_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recommended_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recommendation_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `recommended_quantity` int(11) NOT NULL,
  `approved_quantity` int(11) DEFAULT NULL,
  `allocated_quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('Pending','Approved','Allocated','Rejected') DEFAULT 'Pending',
  PRIMARY KEY (`id`),
  KEY `recommendation_id` (`recommendation_id`),
  KEY `resource_id` (`resource_id`),
  CONSTRAINT `recommended_items_ibfk_1` FOREIGN KEY (`recommendation_id`) REFERENCES `recommendations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recommended_items_ibfk_2` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `residents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `residents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `gender` enum('Male','Female','Other') DEFAULT 'Male',
  `age` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `barangay_id` int(11) NOT NULL,
  `purok` varchar(100) NOT NULL,
  `status` enum('active','inactive','pending','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `residents_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resource_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resource_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `transaction_type` enum('Restock','Allocation','Dispatched','Returned','Damaged','Adjusted','New Product') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT 'manual',
  `reference_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resource_id` (`resource_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `resource_transactions_ibfk_1` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resource_transactions_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` enum('Relief Goods','Medical Supplies','Rescue Equipment','Emergency Supplies','Shelter & Sanitation') NOT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `description` text DEFAULT NULL,
  `unit` varchar(30) NOT NULL,
  `total_quantity` int(11) NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `in_use_quantity` int(11) NOT NULL DEFAULT 0,
  `damaged_quantity` int(11) NOT NULL DEFAULT 0,
  `min_threshold` int(11) NOT NULL DEFAULT 50,
  `storage_location` varchar(150) NOT NULL DEFAULT 'Central ICDRRMO Depot',
  `batch_number` varchar(50) DEFAULT NULL COMMENT 'Batch/Lot number from supplier',
  `supplier_donor` varchar(150) DEFAULT NULL COMMENT 'Source: OCD, DSWD, NGO donor name, LGU procurement',
  `date_acquired` date DEFAULT NULL COMMENT 'Date the resource was received/procured',
  `expiry_date` date DEFAULT NULL COMMENT 'Expiration date for perishable items',
  `is_perishable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether the item is perishable (food, medicine)',
  `brand` varchar(100) DEFAULT NULL COMMENT 'Brand name or manufacturer',
  `weight_per_unit` varchar(50) DEFAULT NULL COMMENT 'Weight per unit e.g. 5kg, 500g',
  `cost_per_unit` decimal(10,2) DEFAULT NULL COMMENT 'Cost per unit in PHP for valuation',
  `item_condition` enum('New','Good','Fair','Poor','Expired') NOT NULL DEFAULT 'New' COMMENT 'Current physical condition',
  `barangay_id` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `responder_field_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `responder_field_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `responder_id` int(11) NOT NULL,
  `disaster_request_id` int(11) DEFAULT NULL,
  `barangay_id` int(11) NOT NULL,
  `purok` varchar(100) NOT NULL,
  `operational_status` enum('Available','Responding','On Site','Completed','Standby') NOT NULL DEFAULT 'Available',
  `condition_overview` text NOT NULL,
  `casualties` int(11) NOT NULL DEFAULT 0,
  `injuries` int(11) NOT NULL DEFAULT 0,
  `rescued_individuals` int(11) NOT NULL DEFAULT 0,
  `resources_utilized` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `responder_id` (`responder_id`),
  KEY `disaster_request_id` (`disaster_request_id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `responder_field_updates_ibfk_1` FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `responder_field_updates_ibfk_2` FOREIGN KEY (`disaster_request_id`) REFERENCES `disaster_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `responder_field_updates_ibfk_3` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(30) NOT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `message` text NOT NULL,
  `status` varchar(30) DEFAULT 'sent',
  `api_response` text DEFAULT NULL,
  `reference_module` varchar(50) DEFAULT 'event',
  `reference_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `phone` (`phone`),
  KEY `reference_id` (`reference_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `details` text NOT NULL,
  `ip_address` varchar(50) DEFAULT '127.0.0.1',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `gender` enum('Male','Female','Other') DEFAULT 'Male',
  `age` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `role` enum('icdrrmo','barangay_head','responder','resident') NOT NULL,
  `agency_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `availability` enum('Available','On Duty','Responding','Standby','Off Duty') NOT NULL DEFAULT 'Available',
  `barangay_id` int(11) DEFAULT NULL,
  `purok` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive','pending','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `barangay_id` (`barangay_id`),
  KEY `fk_users_agency` (`agency_id`),
  CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

