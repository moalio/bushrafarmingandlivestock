-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 26, 2026 at 02:21 AM
-- Server version: 10.11.19-MariaDB
-- PHP Version: 8.5.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bushrafarmingandlivestock`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Unique account number; identifies the account in every other table',
  `parentaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Parent group of this account; links to accounts.accountid, empty for a top-level group',
  `code` varchar(20) NOT NULL COMMENT 'Account code, for example 1.1; must be unique and sorts the chart of accounts',
  `accountname` varchar(150) NOT NULL COMMENT 'Name of the account; shown in the chart of accounts and in activity messages',
  `accounttype` enum('Assets','Liability','Expense','Equity','Revenue') NOT NULL COMMENT 'Kind of account; decides the tab of the chart of accounts and the reports it feeds',
  `status` enum('ON','OFF') NOT NULL DEFAULT 'ON' COMMENT 'Account state: ON can be used, OFF is deactivated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chart of accounts of Bushra Farming and Livestock';

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`accountid`, `parentaccountid`, `code`, `accountname`, `accounttype`, `status`) VALUES
(3, NULL, '1', 'Cash On Hand', 'Assets', 'ON'),
(4, 3, '1.1', 'Salam Bank', 'Assets', 'ON'),
(5, NULL, '3.1', 'Utility Expenses', 'Expense', 'ON'),
(6, 5, '3.1.1', 'Water Expenses', 'Expense', 'ON'),
(7, NULL, '2000', 'Accounts Payable', 'Liability', 'ON'),
(8, NULL, '4000', 'Sales Revenue', 'Revenue', 'ON'),
(9, NULL, '5000', 'Cost of Goods Sold', 'Expense', 'ON'),
(10, NULL, '1100', 'Accounts Receivable', 'Assets', 'ON'),
(11, NULL, '1200', 'Livestock', 'Assets', 'ON');

-- --------------------------------------------------------

--
-- Table structure for table `accounttransfers`
--

CREATE TABLE `accounttransfers` (
  `transferid` int(10) UNSIGNED NOT NULL COMMENT 'Unique transfer number; identifies the transfer in every other table',
  `fromaccountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money left from; links to accounts.accountid, its balance goes down',
  `toaccountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money went into; links to accounts.accountid, its balance goes up; always different from the from account',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount moved; always above 0 and never above the balance of the from account at that moment',
  `transferdate` date NOT NULL COMMENT 'Date the money was moved',
  `description` varchar(255) NOT NULL COMMENT 'Why the money was moved'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activitylogs`
--

CREATE TABLE `activitylogs` (
  `activityid` int(10) UNSIGNED NOT NULL COMMENT 'Unique activity row number',
  `userid` int(10) UNSIGNED DEFAULT NULL COMMENT 'User who did the action; NULL when unknown (no login yet)',
  `invoicenumber` varchar(30) DEFAULT NULL COMMENT 'Reference of the record the action was done on, for example INV2, CUST-1, EMP-5; NULL when the action has no reference',
  `action` varchar(50) NOT NULL COMMENT 'Add, Update or Delete',
  `description` varchar(255) NOT NULL COMMENT 'What happened, shown in the activity list',
  `activitydate` datetime NOT NULL COMMENT 'When the action happened'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Activity history of the system';

--
-- Dumping data for table `activitylogs`
--

INSERT INTO `activitylogs` (`activityid`, `userid`, `invoicenumber`, `action`, `description`, `activitydate`) VALUES
(1, NULL, NULL, 'Update', 'User updated: Administrator', '2026-11-17 15:11:54'),
(2, NULL, NULL, 'Update', 'User updated: Abdisalam Khalif', '2026-11-17 15:12:17'),
(3, 1, NULL, 'Login Failed', 'Sign-in failed: wrong password (IP ::1)', '2026-11-17 15:23:48'),
(4, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-17 15:23:52'),
(5, NULL, NULL, 'Add', 'Product added: CALAF', '2026-11-17 15:24:34'),
(6, NULL, NULL, 'Add', 'Livestock added: A9', '2026-11-17 15:25:07'),
(7, NULL, NULL, 'Update', 'Livestock updated: A9', '2026-11-17 15:25:17'),
(8, NULL, NULL, 'Update', 'Livestock updated: A9', '2026-11-17 15:25:35'),
(9, NULL, NULL, 'Add', 'Customer added: MOHAMED ALI ABDI', '2026-11-17 15:26:05'),
(10, NULL, NULL, 'Update', 'Product updated: CALAF', '2026-11-17 15:27:32'),
(11, NULL, NULL, 'Update', 'Livestock updated: A9', '2026-11-17 16:00:42'),
(12, NULL, NULL, 'Add', 'Account added: 1 Cash On Hand', '2026-11-17 16:02:05'),
(13, NULL, NULL, 'Add', 'Account added: 1.1 Salam Bank', '2026-11-17 16:02:22'),
(14, NULL, NULL, 'Delete', 'Account deleted: 1.1 Salam Bank', '2026-11-17 16:11:47'),
(15, NULL, NULL, 'Delete', 'Account deleted: 1 Cash On Hand', '2026-11-17 16:11:50'),
(16, NULL, NULL, 'Add', 'Supplier added: ABDISALAM', '2026-11-17 17:19:58'),
(17, NULL, NULL, 'Update', 'Supplier updated: ABDISALAM', '2026-11-17 17:20:16'),
(18, NULL, NULL, 'Add', 'Account added: 1 Cash On Hand', '2026-11-17 17:20:57'),
(19, NULL, NULL, 'Add', 'Account added: 1.1 Salam Bank', '2026-11-17 17:21:25'),
(20, NULL, NULL, 'Add', 'Purchase added: #1 ABDISALAM', '2026-11-17 17:22:36'),
(21, NULL, NULL, 'Update', 'Purchase updated: #1 ABDISALAM', '2026-11-17 17:57:39'),
(22, NULL, NULL, 'Add', 'Invoice added: #1 MOHAMED ALI ABDI', '2026-11-17 17:59:18'),
(23, NULL, NULL, 'Update', 'Invoice updated: #1 MOHAMED ALI ABDI', '2026-11-17 18:00:56'),
(24, NULL, NULL, 'Add', 'Livestock added: F12', '2026-11-17 18:40:56'),
(25, NULL, NULL, 'Update', 'Livestock updated: F12', '2026-11-17 18:47:14'),
(26, NULL, NULL, 'Update', 'Livestock updated: A9', '2026-11-17 18:53:25'),
(27, NULL, NULL, 'Add', 'Livestock added: D2', '2026-11-17 19:01:45'),
(28, NULL, NULL, 'Update', 'Livestock updated: D2', '2026-11-17 19:02:11'),
(29, NULL, NULL, 'Update', 'Livestock updated: F12', '2026-11-17 19:02:26'),
(30, NULL, NULL, 'Update', 'Livestock updated: D2', '2026-11-17 19:02:33'),
(31, NULL, NULL, 'Add', 'Purchase added: #2 ABDISALAM', '2026-11-17 19:15:04'),
(32, NULL, NULL, 'Add', 'Purchase added: #3 ABDISALAM', '2026-11-17 20:05:28'),
(33, NULL, NULL, 'Add', 'Purchase added: #4 ABDISALAM', '2026-11-17 20:17:44'),
(34, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-18 09:38:31'),
(35, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-18 11:35:29'),
(36, NULL, NULL, 'Add', 'Sale added: #2 MOHAMED ALI ABDI', '2026-11-18 12:32:38'),
(37, NULL, NULL, 'Add', 'Sale added: #3 MOHAMED ALI ABDI', '2026-11-18 12:34:35'),
(38, NULL, NULL, 'Delete', 'Sale deleted: #3 MOHAMED ALI ABDI', '2026-11-18 13:37:20'),
(39, NULL, NULL, 'Delete', 'Sale deleted: #2 MOHAMED ALI ABDI', '2026-11-18 13:37:25'),
(40, NULL, NULL, 'Add', 'Return added: #1 for invoice #1 MOHAMED ALI ABDI', '2026-11-18 14:55:00'),
(41, NULL, NULL, 'Add', 'Refund added: #1 for invoice #1 MOHAMED ALI ABDI', '2026-11-18 14:55:28'),
(42, NULL, NULL, 'Add', 'Sale added: #4 Walk-in', '2026-11-18 16:07:47'),
(43, NULL, NULL, 'Add', 'Quotation added: #1 MOHAMED ALI ABDI', '2026-11-18 16:10:53'),
(44, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-18 19:21:29'),
(45, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-19 09:44:55'),
(46, NULL, NULL, 'Add', 'Sale added: #5 Walk-in', '2026-11-19 12:39:39'),
(47, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-19 13:02:47'),
(48, NULL, NULL, 'Update', 'Sale updated: #4 Walk-in', '2026-11-19 13:10:35'),
(49, NULL, NULL, 'Update', 'Sale updated: #4 MOHAMED ALI ABDI', '2026-11-19 13:11:02'),
(50, NULL, NULL, 'Add', 'Purchase added: #5 ABDISALAM', '2026-11-19 13:24:19'),
(51, NULL, NULL, 'Add', 'Sale added: #6 MOHAMED ALI ABDI', '2026-11-19 13:24:57'),
(52, NULL, NULL, 'Delete', 'Livestock stage deleted: Active', '2026-11-19 13:30:37'),
(53, NULL, NULL, 'Add', 'Supplier added: MOHAMED ALI ABDI', '2026-11-19 14:12:32'),
(54, NULL, NULL, 'Add', 'Livestock type added: LO', '2026-11-19 17:21:34'),
(55, NULL, NULL, 'Add', 'Livestock stage added: iibin', '2026-11-19 17:21:50'),
(56, NULL, NULL, 'Add', 'Livestock added: Lo', '2026-11-19 17:22:03'),
(57, NULL, NULL, 'Add', 'Purchase added: #6 ABDISALAM', '2026-11-19 17:22:11'),
(58, NULL, NULL, 'Delete', 'Quotation deleted: #1 MOHAMED ALI ABDI', '2026-11-19 17:33:13'),
(59, NULL, NULL, 'Add', 'Quotation added: #2 Walk-in', '2026-11-19 17:33:56'),
(60, NULL, NULL, 'Update', 'Quotation updated: #2 MOHAMED ALI ABDI', '2026-11-19 17:34:04'),
(61, NULL, NULL, 'Add', 'Customer added: ABDISALAM KHALIF', '2026-11-19 17:40:13'),
(62, NULL, NULL, 'Add', 'Quotation added: #3 ABDISALAM KHALIF', '2026-11-19 17:40:19'),
(63, NULL, NULL, 'Update', 'Customer updated: ABDISALAM KHALIF', '2026-11-19 17:40:57'),
(64, NULL, NULL, 'Update', 'Quotation updated: #3 ABDISALAM KHALIF', '2026-11-19 17:45:09'),
(65, NULL, NULL, 'Update', 'Quotation updated: #3 ABDISALAM KHALIF', '2026-11-19 17:45:32'),
(66, NULL, NULL, 'Add', 'Sale added: #9 ABDISALAM KHALIF', '2026-11-20 08:29:15'),
(67, NULL, NULL, 'Add', 'Sale added: #10 ABDISALAM KHALIF', '2026-11-20 08:44:01'),
(68, NULL, NULL, 'Add', 'Purchase added: #7 MOHAMED ALI ABDI', '2026-11-20 10:17:00'),
(69, NULL, NULL, 'Add', 'Sale added: #11 ABDISALAM KHALIF', '2026-11-20 10:18:46'),
(70, NULL, NULL, 'Add', 'Shareholder added: ABDISALAM KHALIF', '2026-11-20 14:14:39'),
(71, NULL, NULL, 'Add', 'Deposit added: #1 ABDISALAM KHALIF 500.00', '2026-11-20 15:04:42'),
(72, NULL, NULL, 'Add', 'Deposit refund added: #1 ABDISALAM KHALIF 500.00', '2026-11-20 15:05:14'),
(73, NULL, NULL, 'Add', 'Payment added: #1 for purchase #5 ABDISALAM', '2026-11-20 15:16:32'),
(74, NULL, NULL, 'Add', 'Receipt added: #4 for invoice #11 ABDISALAM KHALIF', '2026-11-20 15:16:50'),
(75, NULL, NULL, 'Add', 'Account added: 3.1 Utility Expenses', '2026-11-20 15:26:17'),
(76, NULL, NULL, 'Add', 'Account added: 3.1.1 Water Expenses', '2026-11-20 15:26:41'),
(77, NULL, NULL, 'Add', 'Employee added: ABDISALAM KHALIF', '2026-11-20 16:26:09'),
(78, NULL, NULL, 'Add', 'Payroll bill added: 200.00 (1 employee) - NOV', '2026-11-20 16:57:35'),
(79, NULL, NULL, 'Payment', 'Payroll payment added: 200.00 (1 employee) - NOV', '2026-11-20 16:57:51'),
(80, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-25 17:40:45'),
(81, NULL, NULL, 'Add', 'Customer added: MOHAMED ALI ABDI', '2026-11-25 17:42:03'),
(82, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-25 18:24:58'),
(83, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-25 23:15:24'),
(84, NULL, NULL, 'Add', 'Livestock added: Y3', '2026-11-26 02:32:25'),
(85, 1, NULL, 'Login', 'User signed in (IP ::1)', '2026-11-26 03:10:43'),
(86, NULL, NULL, 'Add', 'Livestock added: Z3', '2026-11-26 03:21:24'),
(87, NULL, NULL, 'Add', 'Livestock added: Z3', '2026-11-26 03:41:55');

-- --------------------------------------------------------

--
-- Table structure for table `assetadjustments`
--

CREATE TABLE `assetadjustments` (
  `adjustmentid` int(10) UNSIGNED NOT NULL COMMENT 'Unique adjustment number',
  `assetid` int(10) UNSIGNED NOT NULL COMMENT 'Asset that lost value; links to assets.assetid; an asset with adjustments cannot be deleted',
  `adjustmenttype` enum('Depreciation','Supply Adjustment') NOT NULL COMMENT 'Depreciation (only for Equipment) or Supply Adjustment (only for Supply); the server checks that it matches the type of the asset',
  `amount` decimal(11,2) NOT NULL COMMENT 'Value taken off the asset; always above 0; the sum of the adjustments of an asset never goes above the amount of the asset',
  `adjustmentdate` date NOT NULL COMMENT 'Date of the adjustment',
  `description` varchar(255) NOT NULL COMMENT 'Why the value was lowered'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `assetid` int(10) UNSIGNED NOT NULL COMMENT 'Unique asset number; identifies the asset in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Asset name, for example a tractor or a feed stock; not unique because two assets can share a name',
  `assettype` enum('Equipment','Supply') NOT NULL COMMENT 'Equipment (tractors, tools, vehicles) is lowered by Depreciation; Supply (feed, medicine, materials in stock) is lowered by Supply Adjustment',
  `amount` decimal(11,2) NOT NULL COMMENT 'Value of the asset when it was registered; always above 0; the remaining value is this amount minus the sum of its adjustments and is never stored',
  `assetdate` date NOT NULL COMMENT 'Date the asset was acquired'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companyprofile`
--

CREATE TABLE `companyprofile` (
  `companyprofileid` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Always 1: the farm has one company profile row',
  `companyname` varchar(150) NOT NULL COMMENT 'Company name; shown on printed reports and pages',
  `phone` varchar(50) DEFAULT NULL COMMENT 'Company phone number; empty when unknown',
  `email` varchar(150) DEFAULT NULL COMMENT 'Company email address; empty when unknown',
  `address` varchar(255) DEFAULT NULL COMMENT 'Company address; empty when unknown',
  `logofile` varchar(255) DEFAULT NULL COMMENT 'Path of the logo image saved in uploads/company/, relative to the site root; empty when no logo'
) ;

--
-- Dumping data for table `companyprofile`
--

INSERT INTO `companyprofile` (`companyprofileid`, `companyname`, `phone`, `email`, `address`, `logofile`) VALUES
(1, 'Bushra Farming and Livestock', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customerdepositrefunds`
--

CREATE TABLE `customerdepositrefunds` (
  `depositrefundid` int(10) UNSIGNED NOT NULL COMMENT 'Unique deposit refund number; identifies the refund in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer who got the deposit back; links to customers.customerid',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money left from; links to accounts.accountid',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount of deposit given back in cash; always above 0',
  `refunddate` date NOT NULL COMMENT 'Date the deposit was given back',
  `note` varchar(255) DEFAULT NULL COMMENT 'Optional note about the refund'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customerdepositrefunds`
--

INSERT INTO `customerdepositrefunds` (`depositrefundid`, `customerid`, `accountid`, `amount`, `refunddate`, `note`) VALUES
(1, 3, 4, 500.00, '2026-11-20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customerdeposits`
--

CREATE TABLE `customerdeposits` (
  `depositid` int(10) UNSIGNED NOT NULL COMMENT 'Unique deposit number; identifies the deposit in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer who paid the money in advance; links to customers.customerid',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money went into; links to accounts.accountid',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount the customer paid in advance; always above 0',
  `depositdate` date NOT NULL COMMENT 'Date the customer paid the deposit',
  `note` varchar(255) DEFAULT NULL COMMENT 'Optional note about the deposit'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customerdeposits`
--

INSERT INTO `customerdeposits` (`depositid`, `customerid`, `accountid`, `amount`, `depositdate`, `note`) VALUES
(1, 3, 4, 500.00, '2026-11-20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customerdepositusages`
--

CREATE TABLE `customerdepositusages` (
  `usageid` int(10) UNSIGNED NOT NULL COMMENT 'Unique usage number; identifies the usage in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer whose deposit was used; links to customers.customerid',
  `invoiceid` int(10) UNSIGNED NOT NULL COMMENT 'Invoice paid with the deposit; links to invoices.invoiceid, the usage is deleted with the invoice',
  `amount` decimal(11,2) NOT NULL COMMENT 'Deposit amount used on the invoice; always above 0',
  `usagedate` date NOT NULL COMMENT 'Date the deposit was used',
  `note` varchar(255) DEFAULT NULL COMMENT 'Optional note about the usage'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Unique customer number; identifies the customer in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Customer name; not unique because two people can share a name',
  `primarycontact` varchar(50) DEFAULT NULL COMMENT 'Main phone number or contact of the customer; empty when unknown',
  `address` varchar(255) DEFAULT NULL COMMENT 'Address of the customer; empty when unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customers of Bushra Farming and Livestock';

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customerid`, `name`, `primarycontact`, `address`) VALUES
(1, 'MOHAMED ALI ABDI', '610701116', 'MOGADISHU'),
(2, 'Walk-in', NULL, NULL),
(3, 'ABDISALAM KHALIF', '615963760', 'DAYNIILE'),
(4, 'MOHAMED ALI ABDI', '619909020', 'HOLLAND');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employeeid` int(10) UNSIGNED NOT NULL COMMENT 'Unique employee number; identifies the employee in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Employee name; not unique because two people can share a name',
  `contact` varchar(50) DEFAULT NULL COMMENT 'Phone number or contact of the employee; empty when unknown',
  `address` varchar(255) DEFAULT NULL COMMENT 'Address of the employee; empty when unknown',
  `identityfile` varchar(255) DEFAULT NULL COMMENT 'Path of the identity document file (PDF or image) saved in uploads/employees/; empty when no file',
  `salary` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly salary of the employee; 0.00 means no salary; can be used together with the commission',
  `commissionpercent` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Commission percentage of the employee (0.00 to 100.00); 0.00 means no commission; can be used together with the salary',
  `grantorname` varchar(150) DEFAULT NULL COMMENT 'Name of the grantor of the employee; empty when none',
  `grantorcontact` varchar(50) DEFAULT NULL COMMENT 'Phone number or contact of the grantor; empty when unknown',
  `grantorrelationship` varchar(100) DEFAULT NULL COMMENT 'Relationship of the grantor to the employee, for example father, brother, friend; empty when unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Employees of Bushra Farming and Livestock';

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employeeid`, `name`, `contact`, `address`, `identityfile`, `salary`, `commissionpercent`, `grantorname`, `grantorcontact`, `grantorrelationship`) VALUES
(1, 'ABDISALAM KHALIF', '615909020', 'DAYNILE', 'uploads/employees/5c79eee29499675c8e72f370f2ebfda0.png', 200.00, 1.00, 'MOHAMED ALI ABDI', '610701116', 'WALAAL');

-- --------------------------------------------------------

--
-- Table structure for table `equity`
--

CREATE TABLE `equity` (
  `equityid` int(10) UNSIGNED NOT NULL COMMENT 'Unique equity number; identifies the capital or drawing record in every other table',
  `shareholderid` int(10) UNSIGNED NOT NULL COMMENT 'Shareholder who put the money in or took it out; links to shareholders.shareholderid; a shareholder with equity cannot be deleted',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money went into (Capital, its balance goes up) or left from (Drawing, its balance goes down); links to accounts.accountid',
  `equitytype` enum('Capital','Drawing') NOT NULL COMMENT 'Capital is money put into the business; Drawing is money taken out of it',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount of the movement; always above 0',
  `equitydate` date NOT NULL COMMENT 'Date the money was put in or taken out',
  `description` varchar(255) DEFAULT NULL COMMENT 'Optional note about the movement; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `expenseid` int(10) UNSIGNED NOT NULL COMMENT 'Unique expense number; identifies the expense in every other table',
  `payee` varchar(150) NOT NULL COMMENT 'Person or company that received the money; free text, not a supplier of a purchase',
  `expenseaccountid` int(10) UNSIGNED NOT NULL COMMENT 'Expense sub-account that says what the money was spent on; links to accounts.accountid',
  `paidfromaccountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money left from; links to accounts.accountid',
  `description` varchar(255) NOT NULL COMMENT 'Why the money was spent',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount spent; always above 0',
  `expensedate` date NOT NULL COMMENT 'Date the money was spent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoiceitems`
--

CREATE TABLE `invoiceitems` (
  `invoiceitemid` int(10) UNSIGNED NOT NULL COMMENT 'Unique invoice line number; identifies the line in every other table',
  `invoiceid` int(10) UNSIGNED NOT NULL COMMENT 'Invoice this line belongs to; links to invoices.invoiceid, lines are deleted with the invoice',
  `itemtype` enum('Livestock','Product') NOT NULL COMMENT 'Kind of item sold: Livestock or Product; decides which of livestockid and productid is filled',
  `livestockid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Livestock item sold; links to livestock.livestockid, filled only when the item type is Livestock',
  `productid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Product sold; links to products.productid, filled only when the item type is Product',
  `quantity` int(10) UNSIGNED NOT NULL COMMENT 'Animals for livestock, sales units for products; whole number greater than 0; exactly what this line took out of current stock, so edit and delete can put it back',
  `priceeach` decimal(9,2) NOT NULL COMMENT 'Price each: price of one animal or one sales unit; 0 or more',
  `costeach` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Cost each snapshot: cost of one animal or sales unit when the line was saved (for livestock a copy of livestock.costeach), used for profit reports',
  `inventoryaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Inventory account credited with the cost of this line',
  `linetotal` decimal(11,2) NOT NULL COMMENT 'Quantity times selling price; calculated by the server'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Invoice lines of Bushra Farming and Livestock';

--
-- Dumping data for table `invoiceitems`
--

INSERT INTO `invoiceitems` (`invoiceitemid`, `invoiceid`, `itemtype`, `livestockid`, `productid`, `quantity`, `priceeach`, `costeach`, `inventoryaccountid`, `linetotal`) VALUES
(2, 1, 'Livestock', 1, NULL, 50, 4.00, 0.00, 11, 200.00),
(6, 5, 'Livestock', 2, NULL, 1, 90.00, 0.00, 11, 90.00),
(8, 4, 'Livestock', 1, NULL, 2, 90.00, 29.00, 11, 180.00),
(9, 6, 'Livestock', 3, NULL, 1, 90.00, 0.00, 11, 90.00),
(10, 9, 'Livestock', 2, NULL, 2, 100.00, 0.00, 11, 200.00),
(11, 10, 'Livestock', 1, NULL, 7, 90.00, 29.00, 11, 630.00),
(12, 11, 'Livestock', 1, NULL, 40, 90.00, 29.00, 11, 3600.00);

--
-- Triggers `invoiceitems`
--
DELIMITER $$
CREATE TRIGGER `trginvoiceitemsbi` BEFORE INSERT ON `invoiceitems` FOR EACH ROW BEGIN
  IF NOT ((NEW.`itemtype` = 'Livestock' AND NEW.`livestockid` IS NOT NULL AND NEW.`productid` IS NULL)
       OR (NEW.`itemtype` = 'Product' AND NEW.`productid` IS NOT NULL AND NEW.`livestockid` IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exactly one of livestockid and productid must be filled, matching itemtype';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trginvoiceitemsbu` BEFORE UPDATE ON `invoiceitems` FOR EACH ROW BEGIN
  IF NOT ((NEW.`itemtype` = 'Livestock' AND NEW.`livestockid` IS NOT NULL AND NEW.`productid` IS NULL)
       OR (NEW.`itemtype` = 'Product' AND NEW.`productid` IS NOT NULL AND NEW.`livestockid` IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exactly one of livestockid and productid must be filled, matching itemtype';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoiceid` int(10) UNSIGNED NOT NULL COMMENT 'Unique invoice number; identifies the invoice in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer the goods were sold to; links to customers.customerid',
  `invoicedate` date NOT NULL COMMENT 'Date of the invoice; also the date of the receipt written with it',
  `reference` varchar(50) DEFAULT NULL COMMENT 'Customer order or reference number; empty when unknown',
  `totalamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Total of the invoice; sum of the line totals, calculated by the server',
  `paidamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Money received with the invoice; never above the total amount',
  `receivedinaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Asset account the money was received into (Receipt to); links to accounts.accountid, required when the paid amount is above 0',
  `revenueaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Revenue account credited with the total',
  `receivableaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Asset account debited with the unpaid part',
  `cogsaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Expense account debited with the cost of the animals sold',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the invoice; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoiceid`, `customerid`, `invoicedate`, `reference`, `totalamount`, `paidamount`, `receivedinaccountid`, `revenueaccountid`, `receivableaccountid`, `cogsaccountid`, `note`) VALUES
(1, 1, '2026-11-17', '610508800', 200.00, 200.00, 4, 8, NULL, 9, NULL),
(4, 1, '2026-11-18', NULL, 180.00, 150.00, 4, 8, 10, 9, NULL),
(5, 2, '2026-11-19', NULL, 90.00, 90.00, 4, 8, NULL, 9, NULL),
(6, 1, '2026-11-19', NULL, 90.00, 0.00, NULL, 8, 10, 9, NULL),
(9, 3, '2026-11-20', '677', 200.00, 0.00, NULL, 8, 10, 9, NULL),
(10, 3, '2026-11-20', NULL, 630.00, 0.00, NULL, 8, 10, 9, NULL),
(11, 3, '2026-11-20', NULL, 3600.00, 3600.00, 4, 8, NULL, 9, NULL);

--
-- Triggers `invoices`
--
DELIMITER $$
CREATE TRIGGER `trginvoicesbi` BEFORE INSERT ON `invoices` FOR EACH ROW BEGIN
  IF NEW.`paidamount` > 0 AND NEW.`receivedinaccountid` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receivedinaccountid is required when paidamount is above 0';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trginvoicesbu` BEFORE UPDATE ON `invoices` FOR EACH ROW BEGIN
  IF NEW.`paidamount` > 0 AND NEW.`receivedinaccountid` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'receivedinaccountid is required when paidamount is above 0';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `journalentries`
--

CREATE TABLE `journalentries` (
  `journalentryid` int(10) UNSIGNED NOT NULL,
  `entrydate` date NOT NULL,
  `sourcetype` varchar(20) NOT NULL COMMENT 'purchase | sale | salereturn | salerefund',
  `sourceid` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) NOT NULL,
  `totalamount` decimal(11,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journallines`
--

CREATE TABLE `journallines` (
  `journallineid` int(10) UNSIGNED NOT NULL,
  `journalentryid` int(10) UNSIGNED NOT NULL,
  `accountid` int(10) UNSIGNED NOT NULL,
  `debit` decimal(11,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(11,2) NOT NULL DEFAULT 0.00,
  `skipbalance` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = cash already counted by receipts/payments/refunds'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `livestock`
--

CREATE TABLE `livestock` (
  `livestockid` int(10) UNSIGNED NOT NULL COMMENT 'Unique livestock number; identifies the livestock item in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Name of the livestock item; must be unique and is shown in lists and activity messages',
  `type` varchar(100) NOT NULL COMMENT 'Kind of animal, for example Cattle, Goat or Sheep; used to group the livestock',
  `currentstock` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of animals on hand right now; set by Add and Edit (0 to 9,999,999)',
  `costeach` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Cost each: cost of one animal (0.00 to 9,999,999.99); set by Add and Edit and by the latest purchase; used for profit reports',
  `priceeach` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Price each: price of one animal (0.00 to 9,999,999.99); default price when sold; set by Add and Edit and by the latest purchase',
  `stage` varchar(50) NOT NULL DEFAULT 'Active' COMMENT 'Stage of the livestock item, for example Active or Inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Livestock of Bushra Farming and Livestock';

--
-- Dumping data for table `livestock`
--

INSERT INTO `livestock` (`livestockid`, `name`, `type`, `currentstock`, `costeach`, `priceeach`, `stage`) VALUES
(1, 'A9', 'GEEL', 6, 29.00, 90.00, 'DIYAAR U AH IN LA GADO'),
(2, 'F12', 'WAN', 59, 0.00, 0.00, 'Nafaqayn ah'),
(3, 'D2', 'Dibi', 34, 17.65, 0.00, 'Dashtay'),
(4, 'Lo', 'LO', 40, 12.50, 30.00, 'iibin'),
(5, 'Y3', 'GEEL', 0, 0.00, 0.00, 'Nafaqayn ah'),
(6, 'Z3', 'GEEL', 0, 0.00, 0.00, 'Nafaqayn ah'),
(7, 'Z3', 'WAN', 0, 0.00, 0.00, 'Nafaqayn ah');

-- --------------------------------------------------------

--
-- Table structure for table `livestockstages`
--

CREATE TABLE `livestockstages` (
  `livestockstageid` int(10) UNSIGNED NOT NULL COMMENT 'Primary key of the livestock stage',
  `name` varchar(50) NOT NULL COMMENT 'Stage name, stored as text in livestock.stage (unique, case-insensitive)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saved list of livestock stages (feeds the Stage select and its validation)';

--
-- Dumping data for table `livestockstages`
--

INSERT INTO `livestockstages` (`livestockstageid`, `name`) VALUES
(1, 'Dashtay'),
(2, 'DIYAAR U AH IN LA GADO'),
(9, 'iibin'),
(3, 'Nafaqayn ah');

-- --------------------------------------------------------

--
-- Table structure for table `livestocktypes`
--

CREATE TABLE `livestocktypes` (
  `livestocktypeid` int(10) UNSIGNED NOT NULL COMMENT 'Primary key of the livestock type',
  `name` varchar(100) NOT NULL COMMENT 'Type name, stored as text in livestock.type (unique, case-insensitive)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saved list of livestock types (feeds the Type select and its validation)';

--
-- Dumping data for table `livestocktypes`
--

INSERT INTO `livestocktypes` (`livestocktypeid`, `name`) VALUES
(1, 'Dibi'),
(2, 'GEEL'),
(5, 'LO'),
(3, 'WAN');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `paymentid` int(10) UNSIGNED NOT NULL COMMENT 'Unique payment number; identifies the payment in every other table',
  `supplierid` int(10) UNSIGNED NOT NULL COMMENT 'Supplier who received the money; links to suppliers.supplierid',
  `purchaseid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Purchase this payment pays; links to purchases.purchaseid, empty for a payment without a purchase (advance); the payment is kept when its purchase is deleted',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money left from; links to accounts.accountid',
  `amount` decimal(11,2) NOT NULL COMMENT 'Amount paid to the supplier; always above 0',
  `paymentdate` date NOT NULL COMMENT 'Date the money was paid to the supplier',
  `note` varchar(255) DEFAULT NULL COMMENT 'Optional note about the payment; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`paymentid`, `supplierid`, `purchaseid`, `accountid`, `amount`, `paymentdate`, `note`) VALUES
(1, 1, 5, 4, 5.00, '2026-11-20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payrollbillitems`
--

CREATE TABLE `payrollbillitems` (
  `payrollbillitemid` int(10) UNSIGNED NOT NULL COMMENT 'Unique bill line number; identifies the line in every other table',
  `payrollbillid` int(10) UNSIGNED NOT NULL COMMENT 'Bill this line belongs to; links to payrollbills.payrollbillid, lines are deleted with the bill',
  `employeeid` int(10) UNSIGNED NOT NULL COMMENT 'Employee charged on this line; links to employees.employeeid; an employee appears only once per bill',
  `amount` decimal(14,2) NOT NULL COMMENT 'Salary charged to the employee on this bill; always above 0',
  `amountpaid` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Money paid on this line so far; always recomputed from the sum of its payments, never incremented',
  `status` enum('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID' COMMENT 'Line state: PAID when the payments cover the amount, otherwise UNPAID (partly paid lines stay UNPAID)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll bill lines (one per employee) of Bushra Farming and Livestock';

--
-- Dumping data for table `payrollbillitems`
--

INSERT INTO `payrollbillitems` (`payrollbillitemid`, `payrollbillid`, `employeeid`, `amount`, `amountpaid`, `status`) VALUES
(1, 1, 1, 200.00, 200.00, 'PAID');

-- --------------------------------------------------------

--
-- Table structure for table `payrollbills`
--

CREATE TABLE `payrollbills` (
  `payrollbillid` int(10) UNSIGNED NOT NULL COMMENT 'Unique payroll bill number; identifies the bill in every other table',
  `memo` varchar(255) NOT NULL COMMENT 'Description of the bill, for example November 2026 salaries',
  `billdate` date NOT NULL COMMENT 'Date of the bill'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll bills (header) of Bushra Farming and Livestock';

--
-- Dumping data for table `payrollbills`
--

INSERT INTO `payrollbills` (`payrollbillid`, `memo`, `billdate`) VALUES
(1, 'NOV', '2026-11-20');

-- --------------------------------------------------------

--
-- Table structure for table `payrollpayments`
--

CREATE TABLE `payrollpayments` (
  `payrollpaymentid` int(10) UNSIGNED NOT NULL COMMENT 'Unique payroll payment number; identifies the payment in every other table',
  `payrollbillitemid` int(10) UNSIGNED NOT NULL COMMENT 'Bill line this payment pays; links to payrollbillitems.payrollbillitemid',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money left from; links to accounts.accountid',
  `amount` decimal(14,2) NOT NULL COMMENT 'Amount paid to the employee; always above 0 and never above the balance of the line',
  `paymentdate` date NOT NULL COMMENT 'Date the salary was paid',
  `description` varchar(255) DEFAULT NULL COMMENT 'Memo of the payment, written by the Payrolls page'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll payments of Bushra Farming and Livestock';

--
-- Dumping data for table `payrollpayments`
--

INSERT INTO `payrollpayments` (`payrollpaymentid`, `payrollbillitemid`, `accountid`, `amount`, `paymentdate`, `description`) VALUES
(1, 1, 4, 200.00, '2026-11-20', 'NOV');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permissionid` int(10) UNSIGNED NOT NULL COMMENT 'Unique permission row number; identifies one granted permission',
  `userid` int(10) UNSIGNED NOT NULL COMMENT 'User who holds the permission; links to users.userid',
  `menuid` smallint(5) UNSIGNED NOT NULL COMMENT 'Menu number from the permission catalog; groups the permission by sidebar menu',
  `submenuid` smallint(5) UNSIGNED NOT NULL COMMENT 'Submenu number inside the menu (1 for menus without submenus); selects the exact page allowed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pages each user is allowed to open';

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permissionid`, `userid`, `menuid`, `submenuid`) VALUES
(1, 1, 1, 1),
(2, 1, 2, 1),
(3, 1, 2, 2),
(4, 1, 3, 1),
(5, 1, 4, 1),
(6, 1, 5, 1),
(7, 1, 6, 1),
(8, 1, 6, 2),
(9, 1, 6, 3),
(10, 1, 6, 4),
(11, 1, 7, 1),
(12, 1, 7, 2),
(13, 1, 7, 3),
(14, 1, 7, 4),
(15, 1, 7, 5),
(16, 1, 7, 6),
(17, 1, 7, 7),
(18, 1, 7, 8),
(19, 1, 8, 1),
(20, 1, 8, 2),
(21, 1, 8, 3),
(22, 1, 8, 4),
(23, 1, 8, 5),
(24, 1, 8, 6),
(25, 1, 8, 7),
(26, 1, 8, 8),
(27, 1, 8, 9),
(28, 1, 8, 10),
(29, 1, 8, 11),
(30, 1, 8, 12),
(31, 1, 8, 13),
(32, 1, 8, 14),
(33, 1, 8, 15),
(34, 1, 8, 16),
(35, 1, 8, 17),
(36, 1, 8, 18),
(37, 1, 8, 19),
(38, 1, 8, 20),
(39, 1, 9, 1),
(40, 1, 10, 1),
(41, 1, 10, 2);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `productid` int(10) UNSIGNED NOT NULL COMMENT 'Unique product number; identifies the product in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Name of the product; must be unique and is shown in lists and activity messages',
  `unitcost` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Cost of one sales unit of the product (maximum 9,999,999.99)',
  `purchaseunit` varchar(50) NOT NULL COMMENT 'Unit the product is bought in, for example Carton',
  `salesunit` varchar(50) NOT NULL COMMENT 'Unit the product is sold in, for example Piece; stock is kept in this unit',
  `conversionfactor` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Number of sales units in one purchase unit; must be greater than 0',
  `wholesaleprice` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Wholesale price of one sales unit; never above the retail price',
  `retailprice` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Retail price of one sales unit; never below the wholesale price',
  `currentstock` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity on hand in sales units; set by Add and Edit (0 to 9,999,999)',
  `expiredate` date DEFAULT NULL COMMENT 'Date the product expires; empty when the product does not expire'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Products sold by Bushra Farming and Livestock';

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`productid`, `name`, `unitcost`, `purchaseunit`, `salesunit`, `conversionfactor`, `wholesaleprice`, `retailprice`, `currentstock`, `expiredate`) VALUES
(1, 'CALAF', 23.00, 'TAN', 'QIYAAS', 1.0000, 0.00, 0.00, 15, '2026-11-27');

-- --------------------------------------------------------

--
-- Table structure for table `purchaseitems`
--

CREATE TABLE `purchaseitems` (
  `purchaseitemid` int(10) UNSIGNED NOT NULL COMMENT 'Unique purchase line number; identifies the line in every other table',
  `purchaseid` int(10) UNSIGNED NOT NULL COMMENT 'Purchase this line belongs to; links to purchases.purchaseid, lines are deleted with the purchase',
  `itemtype` enum('Livestock','Product') NOT NULL COMMENT 'Kind of item bought: Livestock or Product; decides which of livestockid and productid is filled',
  `livestockid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Livestock item bought; links to livestock.livestockid, filled only when the item type is Livestock',
  `productid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Product bought; links to products.productid, filled only when the item type is Product',
  `quantity` decimal(10,4) NOT NULL COMMENT 'Quantity bought; in the purchase unit for products, head count for livestock; greater than 0',
  `costeach` decimal(9,2) NOT NULL COMMENT 'Cost each: cost of one purchase unit (one animal for livestock) = purchase cost divided by quantity, rounded half up to 2 decimals; calculated by the server',
  `priceeach` decimal(9,2) NOT NULL DEFAULT 0.00 COMMENT 'Price each: price of one animal typed in this purchase; 0.00 for old rows and product lines',
  `purchasecost` decimal(11,2) NOT NULL COMMENT 'Purchase cost: total paid for the whole line (all animals or units); 0 or more; checked by the server',
  `stockadded` int(10) UNSIGNED NOT NULL COMMENT 'Sales units or animals this line added to current stock; kept so edit and delete can reverse exactly that amount',
  `debitaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Inventory account debited by this line; links to accounts.accountid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchaseitems`
--

INSERT INTO `purchaseitems` (`purchaseitemid`, `purchaseid`, `itemtype`, `livestockid`, `productid`, `quantity`, `costeach`, `priceeach`, `purchasecost`, `stockadded`, `debitaccountid`) VALUES
(2, 1, 'Livestock', 1, NULL, 60.0000, 30.00, 0.00, 1800.00, 60, 11),
(3, 2, 'Product', NULL, 1, 5.0000, 9.00, 0.00, 45.00, 5, NULL),
(4, 3, 'Livestock', 2, NULL, 59.0000, 90.00, 0.00, 5310.00, 59, 11),
(5, 4, 'Product', NULL, 1, 10.0000, 23.00, 0.00, 230.00, 10, NULL),
(6, 5, 'Livestock', 1, NULL, 4.0000, 20.00, 0.00, 80.00, 4, 11),
(7, 6, 'Livestock', 4, NULL, 40.0000, 12.50, 30.00, 500.00, 40, 11),
(8, 7, 'Livestock', 3, NULL, 34.0000, 17.65, 0.00, 600.00, 34, 11);

--
-- Triggers `purchaseitems`
--
DELIMITER $$
CREATE TRIGGER `trgpurchaseitemsbi` BEFORE INSERT ON `purchaseitems` FOR EACH ROW BEGIN
  IF NOT ((NEW.`itemtype` = 'Livestock' AND NEW.`livestockid` IS NOT NULL AND NEW.`productid` IS NULL)
       OR (NEW.`itemtype` = 'Product' AND NEW.`productid` IS NOT NULL AND NEW.`livestockid` IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exactly one of livestockid and productid must be filled, matching itemtype';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trgpurchaseitemsbu` BEFORE UPDATE ON `purchaseitems` FOR EACH ROW BEGIN
  IF NOT ((NEW.`itemtype` = 'Livestock' AND NEW.`livestockid` IS NOT NULL AND NEW.`productid` IS NULL)
       OR (NEW.`itemtype` = 'Product' AND NEW.`productid` IS NOT NULL AND NEW.`livestockid` IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exactly one of livestockid and productid must be filled, matching itemtype';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `purchaseitems_bak`
--

CREATE TABLE `purchaseitems_bak` (
  `purchaseitemid` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unique purchase line number; identifies the line in every other table',
  `purchaseid` int(10) UNSIGNED NOT NULL COMMENT 'Purchase this line belongs to; links to purchases.purchaseid, lines are deleted with the purchase',
  `itemtype` enum('Livestock','Product') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Kind of item bought: Livestock or Product; decides which of livestockid and productid is filled',
  `livestockid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Livestock item bought; links to livestock.livestockid, filled only when the item type is Livestock',
  `productid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Product bought; links to products.productid, filled only when the item type is Product',
  `quantity` decimal(10,4) NOT NULL COMMENT 'Quantity bought; in the purchase unit for products, head count for livestock; greater than 0',
  `unitprice` decimal(9,2) NOT NULL COMMENT 'Price of one purchase unit (one animal for livestock); 0 or more',
  `linetotal` decimal(11,2) NOT NULL COMMENT 'Quantity times unit price, rounded to 2 decimals; calculated by the server',
  `stockadded` int(10) UNSIGNED NOT NULL COMMENT 'Sales units or animals this line added to current stock; kept so edit and delete can reverse exactly that amount'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchaseitems_bak`
--

INSERT INTO `purchaseitems_bak` (`purchaseitemid`, `purchaseid`, `itemtype`, `livestockid`, `productid`, `quantity`, `unitprice`, `linetotal`, `stockadded`) VALUES
(2, 1, 'Livestock', 1, NULL, 60.0000, 30.00, 1800.00, 60),
(3, 2, 'Product', NULL, 1, 5.0000, 9.00, 45.00, 5),
(4, 3, 'Livestock', 2, NULL, 59.0000, 90.00, 5310.00, 59),
(5, 4, 'Product', NULL, 1, 10.0000, 23.00, 230.00, 10),
(6, 5, 'Livestock', 1, NULL, 4.0000, 20.00, 80.00, 4);

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `purchaseid` int(10) UNSIGNED NOT NULL COMMENT 'Unique purchase number; identifies the purchase in every other table',
  `supplierid` int(10) UNSIGNED NOT NULL COMMENT 'Supplier the goods were bought from; links to suppliers.supplierid',
  `purchasedate` date NOT NULL COMMENT 'Date of the purchase; also the date of the payment made with it',
  `reference` varchar(50) DEFAULT NULL COMMENT 'Supplier invoice number; empty when unknown',
  `totalamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Total of the purchase; sum of the line totals, calculated by the server',
  `paidamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount paid at purchase time; never above the total amount',
  `paidfromaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Asset account the payment came from; links to accounts.accountid, required when the paid amount is above 0',
  `payableaccountid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Liability account credited with the unpaid part; links to accounts.accountid',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the purchase; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`purchaseid`, `supplierid`, `purchasedate`, `reference`, `totalamount`, `paidamount`, `paidfromaccountid`, `payableaccountid`, `note`) VALUES
(1, 1, '2026-11-17', NULL, 1800.00, 1800.00, 4, NULL, NULL),
(2, 1, '2026-11-17', NULL, 45.00, 0.00, NULL, 7, NULL),
(3, 1, '2026-11-17', 'A3245', 5310.00, 0.00, NULL, 7, 'Haloo Boobsiyo Lacagtooda'),
(4, 1, '2026-11-17', '33', 230.00, 0.00, NULL, 7, NULL),
(5, 1, '2026-11-19', '33', 80.00, 75.00, 4, 7, NULL),
(6, 1, '2026-11-19', '610508800', 500.00, 300.00, 4, 7, NULL),
(7, 2, '2026-11-20', NULL, 600.00, 400.00, 4, 7, NULL);

--
-- Triggers `purchases`
--
DELIMITER $$
CREATE TRIGGER `trgpurchasesbi` BEFORE INSERT ON `purchases` FOR EACH ROW BEGIN
  IF NEW.`paidamount` > 0 AND NEW.`paidfromaccountid` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paidfromaccountid is required when paidamount is above 0';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trgpurchasesbu` BEFORE UPDATE ON `purchases` FOR EACH ROW BEGIN
  IF NEW.`paidamount` > 0 AND NEW.`paidfromaccountid` IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paidfromaccountid is required when paidamount is above 0';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `quotationitems`
--

CREATE TABLE `quotationitems` (
  `quotationitemid` int(10) UNSIGNED NOT NULL COMMENT 'Unique quotation line number; identifies the line in every other table',
  `quotationid` int(10) UNSIGNED NOT NULL COMMENT 'Quotation this line belongs to; links to quotations.quotationid, lines are deleted with the quotation',
  `livestockid` int(10) UNSIGNED NOT NULL COMMENT 'Livestock item quoted; links to livestock.livestockid; a quotation never changes stock',
  `quantity` int(10) UNSIGNED NOT NULL COMMENT 'Number of animals quoted; whole number greater than 0',
  `priceeach` decimal(9,2) NOT NULL COMMENT 'Price each: quoted price of one animal; 0 or more',
  `linetotal` decimal(11,2) NOT NULL COMMENT 'Quantity times selling price; calculated by the server'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Quotation lines of Bushra Farming and Livestock';

--
-- Dumping data for table `quotationitems`
--

INSERT INTO `quotationitems` (`quotationitemid`, `quotationid`, `livestockid`, `quantity`, `priceeach`, `linetotal`) VALUES
(3, 2, 3, 3, 20.00, 60.00),
(6, 3, 2, 2, 90.00, 180.00);

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `quotationid` int(10) UNSIGNED NOT NULL COMMENT 'Unique quotation number; identifies the quotation in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer the quotation was made for; links to customers.customerid',
  `quotationdate` date NOT NULL COMMENT 'Date the quotation was made',
  `validuntil` date DEFAULT NULL COMMENT 'Last day the quoted prices are valid; empty when open; never before the quotation date',
  `reference` varchar(50) DEFAULT NULL COMMENT 'Customer request or reference number; empty when unknown',
  `totalamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Total of the quotation; sum of the line totals, calculated by the server',
  `status` enum('Draft','Sent','Accepted','Rejected','Expired') NOT NULL DEFAULT 'Draft' COMMENT 'Quotation state: Draft, Sent, Accepted, Rejected or Expired',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the quotation; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Quotations of Bushra Farming and Livestock';

--
-- Dumping data for table `quotations`
--

INSERT INTO `quotations` (`quotationid`, `customerid`, `quotationdate`, `validuntil`, `reference`, `totalamount`, `status`, `note`) VALUES
(2, 1, '2026-11-19', NULL, NULL, 60.00, 'Draft', NULL),
(3, 3, '2026-11-19', '2026-12-05', 'Waxaan Rabaa 5 Dec 2026', 180.00, 'Sent', 'Waa La ii Shiilaa Maqaarka iyo lugaha uma baahni');

-- --------------------------------------------------------

--
-- Table structure for table `receipts`
--

CREATE TABLE `receipts` (
  `receiptid` int(10) UNSIGNED NOT NULL COMMENT 'Unique receipt number; identifies the receipt in every other table',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer the money was received from; links to customers.customerid',
  `invoiceid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Invoice this receipt pays; links to invoices.invoiceid, the receipt is deleted with the invoice; empty for a receipt without an invoice',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Asset account the money was received into; links to accounts.accountid',
  `amount` decimal(11,2) NOT NULL COMMENT 'Money received; added to the balance of the account',
  `receiptdate` date NOT NULL COMMENT 'Date the money was received; for an automatic receipt it is the invoice date',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the receipt; the text Received with invoice marks the receipt written automatically with an invoice'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Receipts of Bushra Farming and Livestock';

--
-- Dumping data for table `receipts`
--

INSERT INTO `receipts` (`receiptid`, `customerid`, `invoiceid`, `accountid`, `amount`, `receiptdate`, `note`) VALUES
(1, 1, 1, 4, 200.00, '2026-11-17', 'Received with invoice'),
(2, 1, 4, 4, 150.00, '2026-11-18', 'Received with invoice'),
(3, 2, 5, 4, 90.00, '2026-11-19', 'Received with invoice'),
(4, 3, 11, 4, 3600.00, '2026-11-20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `salerefunds`
--

CREATE TABLE `salerefunds` (
  `refundid` int(10) UNSIGNED NOT NULL COMMENT 'Unique refund number; identifies the refund in every other table',
  `invoiceid` int(10) UNSIGNED NOT NULL COMMENT 'Invoice the money is refunded for; links to invoices.invoiceid',
  `returnid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Return this refund belongs to; links to salereturns.returnid, empty for a refund without a return',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer the money goes back to; links to customers.customerid',
  `accountid` int(10) UNSIGNED NOT NULL COMMENT 'Assets account the money goes out of; links to accounts.accountid, subtracted from the balance of the account',
  `amount` decimal(11,2) NOT NULL COMMENT 'Money refunded; greater than 0, never above the paid amount not yet refunded',
  `refunddate` date NOT NULL COMMENT 'Date the money was refunded; never before the invoice date',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the refund; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sale refunds of Bushra Farming and Livestock';

--
-- Dumping data for table `salerefunds`
--

INSERT INTO `salerefunds` (`refundid`, `invoiceid`, `returnid`, `customerid`, `accountid`, `amount`, `refunddate`, `note`) VALUES
(1, 1, NULL, 1, 4, 10.00, '2026-11-18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `salereturnitems`
--

CREATE TABLE `salereturnitems` (
  `returnitemid` int(10) UNSIGNED NOT NULL COMMENT 'Unique return line number; identifies the line in every other table',
  `returnid` int(10) UNSIGNED NOT NULL COMMENT 'Return this line belongs to; links to salereturns.returnid, lines are deleted with the return',
  `invoiceitemid` int(10) UNSIGNED NOT NULL COMMENT 'Invoice line the animals were sold on; links to invoiceitems.invoiceitemid',
  `livestockid` int(10) UNSIGNED NOT NULL COMMENT 'Livestock item returned; links to livestock.livestockid',
  `quantity` int(10) UNSIGNED NOT NULL COMMENT 'Animals returned, greater than 0; exactly what was put back into livestock.currentstock',
  `priceeach` decimal(9,2) NOT NULL COMMENT 'Price each: price of one animal on the original invoice line',
  `linetotal` decimal(11,2) NOT NULL COMMENT 'Quantity times selling price; calculated by the server'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sale return lines of Bushra Farming and Livestock';

--
-- Dumping data for table `salereturnitems`
--

INSERT INTO `salereturnitems` (`returnitemid`, `returnid`, `invoiceitemid`, `livestockid`, `quantity`, `priceeach`, `linetotal`) VALUES
(1, 1, 2, 1, 1, 4.00, 4.00);

-- --------------------------------------------------------

--
-- Table structure for table `salereturns`
--

CREATE TABLE `salereturns` (
  `returnid` int(10) UNSIGNED NOT NULL COMMENT 'Unique return number; identifies the return in every other table',
  `invoiceid` int(10) UNSIGNED NOT NULL COMMENT 'Invoice the animals were returned from; links to invoices.invoiceid',
  `customerid` int(10) UNSIGNED NOT NULL COMMENT 'Customer who returned the animals; links to customers.customerid, same customer as the invoice',
  `returndate` date NOT NULL COMMENT 'Date the animals were returned; never before the invoice date',
  `totalamount` decimal(11,2) NOT NULL DEFAULT 0.00 COMMENT 'Value of the animals returned (sum of the line totals); calculated by the server, a return does not move money',
  `note` varchar(255) DEFAULT NULL COMMENT 'Free note about the return; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sale returns of Bushra Farming and Livestock';

--
-- Dumping data for table `salereturns`
--

INSERT INTO `salereturns` (`returnid`, `invoiceid`, `customerid`, `returndate`, `totalamount`, `note`) VALUES
(1, 1, 1, '2026-11-18', 4.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sentsms`
--

CREATE TABLE `sentsms` (
  `smsid` int(10) UNSIGNED NOT NULL COMMENT 'Unique SMS batch number',
  `message` varchar(600) NOT NULL COMMENT 'Text sent to every recipient of the batch',
  `provider` varchar(20) NOT NULL COMMENT 'Provider used for the batch (Hormuud)',
  `recipientcount` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recipients of the batch (valid and invalid phones)',
  `successcount` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Messages the provider accepted',
  `failedcount` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Messages that failed (provider error or invalid phone)',
  `userid` int(10) UNSIGNED DEFAULT NULL COMMENT 'User who sent the batch; links to users.userid; empty when unknown (no login yet)',
  `sentat` datetime NOT NULL COMMENT 'When the batch was sent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sent SMS batches of Bushra Farming and Livestock (audit trail)';

-- --------------------------------------------------------

--
-- Table structure for table `sentsmsrecipients`
--

CREATE TABLE `sentsmsrecipients` (
  `recipientid` int(10) UNSIGNED NOT NULL COMMENT 'Unique recipient row number',
  `smsid` int(10) UNSIGNED NOT NULL COMMENT 'Batch this row belongs to; links to sentsms.smsid',
  `customerid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Customer the message was meant for; links to customers.customerid; empty when the customer was deleted',
  `phone` varchar(30) NOT NULL COMMENT 'Phone number as used for the send (normalised)',
  `status` enum('Sent','Failed') NOT NULL COMMENT 'Result for this phone',
  `errornote` varchar(255) DEFAULT NULL COMMENT 'Short reason when the status is Failed; never holds a secret'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recipients of the sent SMS batches of Bushra Farming and Livestock';

-- --------------------------------------------------------

--
-- Table structure for table `shareholders`
--

CREATE TABLE `shareholders` (
  `shareholderid` int(10) UNSIGNED NOT NULL COMMENT 'Unique shareholder number; identifies the shareholder in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Shareholder name; not unique because two people can share a name',
  `contact` varchar(50) DEFAULT NULL COMMENT 'Phone number or contact of the shareholder; empty when unknown',
  `address` varchar(255) DEFAULT NULL COMMENT 'Address of the shareholder; empty when unknown',
  `agreementfile` varchar(255) DEFAULT NULL COMMENT 'Path of the notary agreement file (PDF or image) saved in uploads/shareholders/; empty when no file',
  `beneficiaryname` varchar(150) DEFAULT NULL COMMENT 'Name of the beneficiary of the shareholder; empty when none',
  `beneficiarycontact` varchar(50) DEFAULT NULL COMMENT 'Phone number or contact of the beneficiary; empty when unknown',
  `beneficiaryrelationship` varchar(100) DEFAULT NULL COMMENT 'Relationship of the beneficiary to the shareholder, for example son, wife, brother; empty when unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shareholders of Bushra Farming and Livestock';

--
-- Dumping data for table `shareholders`
--

INSERT INTO `shareholders` (`shareholderid`, `name`, `contact`, `address`, `agreementfile`, `beneficiaryname`, `beneficiarycontact`, `beneficiaryrelationship`) VALUES
(1, 'ABDISALAM KHALIF', '615909090', 'DAYNIILE', 'uploads/shareholders/f3ae031dce7ebd178a566db75045892c.pdf', 'MOHAMED ALI ABDI', '615101010', 'WALAAL');

-- --------------------------------------------------------

--
-- Table structure for table `shareholdershares`
--

CREATE TABLE `shareholdershares` (
  `shareid` int(10) UNSIGNED NOT NULL COMMENT 'Unique share row number; identifies the ownership percentage row',
  `shareholderid` int(10) UNSIGNED DEFAULT NULL COMMENT 'Shareholder who owns this percentage; links to shareholders.shareholderid; empty when the row belongs to a group; a shareholder with a share cannot be deleted; a shareholder has at most one share row',
  `groupname` varchar(150) DEFAULT NULL COMMENT 'Name of the group of people sharing this percentage; empty when the row belongs to one shareholder; a group name is used once',
  `sharepercent` decimal(5,2) NOT NULL COMMENT 'Percentage of the business owned, from 0.01 to 100.00; the sum of all rows never goes above 100.00',
  `note` varchar(255) DEFAULT NULL COMMENT 'Optional note, for example who is in the group; empty when none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `smsgateway`
--

CREATE TABLE `smsgateway` (
  `smsgatewayid` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Always 1: the farm has one SMS gateway row',
  `username` varchar(150) NOT NULL COMMENT 'Hormuud username',
  `password` varchar(600) NOT NULL COMMENT 'Hormuud password, encrypted (AES-256-GCM, base64); never shown'
) ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplierid` int(10) UNSIGNED NOT NULL COMMENT 'Unique supplier number; identifies the supplier in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Supplier name; not unique because two suppliers can share a name',
  `contact` varchar(50) DEFAULT NULL COMMENT 'Contact of the supplier; empty when unknown',
  `address` varchar(255) DEFAULT NULL COMMENT 'Address of the supplier; empty when unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Suppliers of Bushra Farming and Livestock';

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplierid`, `name`, `contact`, `address`) VALUES
(1, 'ABDISALAM', '0615000000', 'MOGADISHU'),
(2, 'MOHAMED ALI ABDI', '615909020', 'MOGADISHU');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userid` int(10) UNSIGNED NOT NULL COMMENT 'Unique user number; identifies the user in every other table',
  `name` varchar(150) NOT NULL COMMENT 'Full name of the user; shown in lists and activity messages',
  `username` varchar(150) NOT NULL COMMENT 'Login name of the user; must be unique and is used to sign in',
  `password` varchar(255) NOT NULL COMMENT 'Password hash (password_hash output, never plain text); used to verify the login',
  `status` enum('ON','OFF') NOT NULL DEFAULT 'ON' COMMENT 'Account state: ON can sign in, OFF is deactivated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System users of Bushra Farming and Livestock';

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`userid`, `name`, `username`, `password`, `status`) VALUES
(1, 'Abdisalam Khalif', 'Abdisalam', '$2y$12$DLzVCCpGEwXwtNyXZAwhf.EBmKSlCqITrhTK3bPTqkXeRgtU.h38i', 'ON');

-- --------------------------------------------------------

--
-- Table structure for table `vat`
--

CREATE TABLE `vat` (
  `vatid` int(10) UNSIGNED NOT NULL COMMENT 'Unique VAT rate number',
  `vatrate` decimal(5,2) NOT NULL COMMENT 'VAT percentage, from 0.00 to 100.00; each rate exists once'
) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`accountid`),
  ADD UNIQUE KEY `uqaccountscode` (`code`),
  ADD KEY `idxaccountsparentaccountid` (`parentaccountid`);

--
-- Indexes for table `accounttransfers`
--
ALTER TABLE `accounttransfers`
  ADD PRIMARY KEY (`transferid`),
  ADD KEY `idxaccounttransfersfromaccountid` (`fromaccountid`),
  ADD KEY `idxaccounttransferstoaccountid` (`toaccountid`),
  ADD KEY `idxaccounttransferstransferdate` (`transferdate`);

--
-- Indexes for table `activitylogs`
--
ALTER TABLE `activitylogs`
  ADD PRIMARY KEY (`activityid`),
  ADD KEY `idxactivitylogsuserid` (`userid`),
  ADD KEY `idxactivitylogsinvoicenumber` (`invoicenumber`);

--
-- Indexes for table `assetadjustments`
--
ALTER TABLE `assetadjustments`
  ADD PRIMARY KEY (`adjustmentid`),
  ADD KEY `idxassetadjustmentsassetid` (`assetid`),
  ADD KEY `idxassetadjustmentsadjustmenttype` (`adjustmenttype`),
  ADD KEY `idxassetadjustmentsadjustmentdate` (`adjustmentdate`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`assetid`),
  ADD KEY `idxassetsname` (`name`),
  ADD KEY `idxassetsassettype` (`assettype`),
  ADD KEY `idxassetsassetdate` (`assetdate`);

--
-- Indexes for table `companyprofile`
--
ALTER TABLE `companyprofile`
  ADD PRIMARY KEY (`companyprofileid`);

--
-- Indexes for table `customerdepositrefunds`
--
ALTER TABLE `customerdepositrefunds`
  ADD PRIMARY KEY (`depositrefundid`),
  ADD KEY `idxcustomerdepositrefundscustomerid` (`customerid`),
  ADD KEY `idxcustomerdepositrefundsaccountid` (`accountid`),
  ADD KEY `idxcustomerdepositrefundsrefunddate` (`refunddate`);

--
-- Indexes for table `customerdeposits`
--
ALTER TABLE `customerdeposits`
  ADD PRIMARY KEY (`depositid`),
  ADD KEY `idxcustomerdepositscustomerid` (`customerid`),
  ADD KEY `idxcustomerdepositsaccountid` (`accountid`),
  ADD KEY `idxcustomerdepositsdepositdate` (`depositdate`);

--
-- Indexes for table `customerdepositusages`
--
ALTER TABLE `customerdepositusages`
  ADD PRIMARY KEY (`usageid`),
  ADD KEY `idxcustomerdepositusagescustomerid` (`customerid`),
  ADD KEY `idxcustomerdepositusagesinvoiceid` (`invoiceid`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customerid`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employeeid`),
  ADD KEY `idxemployeesname` (`name`);

--
-- Indexes for table `equity`
--
ALTER TABLE `equity`
  ADD PRIMARY KEY (`equityid`),
  ADD KEY `idxequityshareholderid` (`shareholderid`),
  ADD KEY `idxequityaccountid` (`accountid`),
  ADD KEY `idxequityequitytype` (`equitytype`),
  ADD KEY `idxequityequitydate` (`equitydate`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`expenseid`),
  ADD KEY `idxexpensespayee` (`payee`),
  ADD KEY `idxexpensesexpenseaccountid` (`expenseaccountid`),
  ADD KEY `idxexpensespaidfromaccountid` (`paidfromaccountid`),
  ADD KEY `idxexpensesexpensedate` (`expensedate`);

--
-- Indexes for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  ADD PRIMARY KEY (`invoiceitemid`),
  ADD KEY `idxinvoiceitemsinvoiceid` (`invoiceid`),
  ADD KEY `idxinvoiceitemslivestockid` (`livestockid`),
  ADD KEY `idxinvoiceitemsproductid` (`productid`),
  ADD KEY `idxinvoiceitemsinventoryaccount` (`inventoryaccountid`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoiceid`),
  ADD KEY `idxinvoicescustomerid` (`customerid`),
  ADD KEY `idxinvoicesinvoicedate` (`invoicedate`),
  ADD KEY `idxinvoicesreceivedinaccountid` (`receivedinaccountid`),
  ADD KEY `idxinvoicesrevenueaccount` (`revenueaccountid`),
  ADD KEY `idxinvoicesreceivableaccount` (`receivableaccountid`),
  ADD KEY `idxinvoicescogsaccount` (`cogsaccountid`);

--
-- Indexes for table `journalentries`
--
ALTER TABLE `journalentries`
  ADD PRIMARY KEY (`journalentryid`),
  ADD KEY `idxjournalentriessource` (`sourcetype`,`sourceid`),
  ADD KEY `idxjournalentriesdate` (`entrydate`);

--
-- Indexes for table `journallines`
--
ALTER TABLE `journallines`
  ADD PRIMARY KEY (`journallineid`),
  ADD KEY `idxjournallinesentry` (`journalentryid`),
  ADD KEY `idxjournallinesaccount` (`accountid`);

--
-- Indexes for table `livestock`
--
ALTER TABLE `livestock`
  ADD PRIMARY KEY (`livestockid`),
  ADD UNIQUE KEY `uqlivestocknametypestage` (`name`,`type`,`stage`),
  ADD KEY `idxlivestockname` (`name`);

--
-- Indexes for table `livestockstages`
--
ALTER TABLE `livestockstages`
  ADD PRIMARY KEY (`livestockstageid`),
  ADD UNIQUE KEY `uqlivestockstagesname` (`name`);

--
-- Indexes for table `livestocktypes`
--
ALTER TABLE `livestocktypes`
  ADD PRIMARY KEY (`livestocktypeid`),
  ADD UNIQUE KEY `uqlivestocktypesname` (`name`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`paymentid`),
  ADD KEY `idxpaymentssupplierid` (`supplierid`),
  ADD KEY `idxpaymentspurchaseid` (`purchaseid`),
  ADD KEY `idxpaymentsaccountid` (`accountid`),
  ADD KEY `idxpaymentspaymentdate` (`paymentdate`);

--
-- Indexes for table `payrollbillitems`
--
ALTER TABLE `payrollbillitems`
  ADD PRIMARY KEY (`payrollbillitemid`),
  ADD UNIQUE KEY `uqpayrollbillitemsbillemployee` (`payrollbillid`,`employeeid`),
  ADD KEY `idxpayrollbillitemspayrollbillid` (`payrollbillid`),
  ADD KEY `idxpayrollbillitemsemployeeid` (`employeeid`);

--
-- Indexes for table `payrollbills`
--
ALTER TABLE `payrollbills`
  ADD PRIMARY KEY (`payrollbillid`);

--
-- Indexes for table `payrollpayments`
--
ALTER TABLE `payrollpayments`
  ADD PRIMARY KEY (`payrollpaymentid`),
  ADD KEY `idxpayrollpaymentspayrollbillitemid` (`payrollbillitemid`),
  ADD KEY `idxpayrollpaymentsaccountid` (`accountid`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permissionid`),
  ADD UNIQUE KEY `uqpermissionsuserpage` (`userid`,`menuid`,`submenuid`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`productid`),
  ADD UNIQUE KEY `uqproductsname` (`name`);

--
-- Indexes for table `purchaseitems`
--
ALTER TABLE `purchaseitems`
  ADD PRIMARY KEY (`purchaseitemid`),
  ADD KEY `idxpurchaseitemspurchaseid` (`purchaseid`),
  ADD KEY `idxpurchaseitemslivestockid` (`livestockid`),
  ADD KEY `idxpurchaseitemsproductid` (`productid`),
  ADD KEY `idxpurchaseitemsdebitaccount` (`debitaccountid`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`purchaseid`),
  ADD KEY `idxpurchasessupplierid` (`supplierid`),
  ADD KEY `idxpurchasespurchasedate` (`purchasedate`),
  ADD KEY `idxpurchasespaidfromaccountid` (`paidfromaccountid`),
  ADD KEY `idxpurchasespayableaccount` (`payableaccountid`);

--
-- Indexes for table `quotationitems`
--
ALTER TABLE `quotationitems`
  ADD PRIMARY KEY (`quotationitemid`),
  ADD KEY `idxquotationitemsquotationid` (`quotationid`),
  ADD KEY `idxquotationitemslivestockid` (`livestockid`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`quotationid`),
  ADD KEY `idxquotationscustomerid` (`customerid`),
  ADD KEY `idxquotationsquotationdate` (`quotationdate`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`receiptid`),
  ADD KEY `idxreceiptscustomerid` (`customerid`),
  ADD KEY `idxreceiptsinvoiceid` (`invoiceid`),
  ADD KEY `idxreceiptsaccountid` (`accountid`);

--
-- Indexes for table `salerefunds`
--
ALTER TABLE `salerefunds`
  ADD PRIMARY KEY (`refundid`),
  ADD KEY `idxsalerefundsinvoiceid` (`invoiceid`),
  ADD KEY `idxsalerefundsreturnid` (`returnid`),
  ADD KEY `idxsalerefundscustomerid` (`customerid`),
  ADD KEY `idxsalerefundsaccountid` (`accountid`);

--
-- Indexes for table `salereturnitems`
--
ALTER TABLE `salereturnitems`
  ADD PRIMARY KEY (`returnitemid`),
  ADD KEY `idxsalereturnitemsreturnid` (`returnid`),
  ADD KEY `idxsalereturnitemsinvoiceitemid` (`invoiceitemid`),
  ADD KEY `idxsalereturnitemslivestockid` (`livestockid`);

--
-- Indexes for table `salereturns`
--
ALTER TABLE `salereturns`
  ADD PRIMARY KEY (`returnid`),
  ADD KEY `idxsalereturnsinvoiceid` (`invoiceid`),
  ADD KEY `idxsalereturnscustomerid` (`customerid`),
  ADD KEY `idxsalereturnsreturndate` (`returndate`);

--
-- Indexes for table `sentsms`
--
ALTER TABLE `sentsms`
  ADD PRIMARY KEY (`smsid`),
  ADD KEY `idxsentsmsuserid` (`userid`),
  ADD KEY `idxsentsmssentat` (`sentat`);

--
-- Indexes for table `sentsmsrecipients`
--
ALTER TABLE `sentsmsrecipients`
  ADD PRIMARY KEY (`recipientid`),
  ADD KEY `idxsentsmsrecipientssmsid` (`smsid`),
  ADD KEY `idxsentsmsrecipientscustomerid` (`customerid`);

--
-- Indexes for table `shareholders`
--
ALTER TABLE `shareholders`
  ADD PRIMARY KEY (`shareholderid`),
  ADD KEY `idxshareholdersname` (`name`);

--
-- Indexes for table `shareholdershares`
--
ALTER TABLE `shareholdershares`
  ADD PRIMARY KEY (`shareid`),
  ADD UNIQUE KEY `uqshareholdersharesshareholderid` (`shareholderid`),
  ADD UNIQUE KEY `uqshareholdersharesgroupname` (`groupname`);

--
-- Indexes for table `smsgateway`
--
ALTER TABLE `smsgateway`
  ADD PRIMARY KEY (`smsgatewayid`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplierid`),
  ADD KEY `idxsuppliersname` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userid`),
  ADD UNIQUE KEY `uqusersusername` (`username`);

--
-- Indexes for table `vat`
--
ALTER TABLE `vat`
  ADD PRIMARY KEY (`vatid`),
  ADD UNIQUE KEY `uqvatvatrate` (`vatrate`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `accountid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique account number; identifies the account in every other table', AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `accounttransfers`
--
ALTER TABLE `accounttransfers`
  MODIFY `transferid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique transfer number; identifies the transfer in every other table';

--
-- AUTO_INCREMENT for table `activitylogs`
--
ALTER TABLE `activitylogs`
  MODIFY `activityid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique activity row number', AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `assetadjustments`
--
ALTER TABLE `assetadjustments`
  MODIFY `adjustmentid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique adjustment number';

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `assetid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique asset number; identifies the asset in every other table';

--
-- AUTO_INCREMENT for table `customerdepositrefunds`
--
ALTER TABLE `customerdepositrefunds`
  MODIFY `depositrefundid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique deposit refund number; identifies the refund in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customerdeposits`
--
ALTER TABLE `customerdeposits`
  MODIFY `depositid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique deposit number; identifies the deposit in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customerdepositusages`
--
ALTER TABLE `customerdepositusages`
  MODIFY `usageid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique usage number; identifies the usage in every other table';

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customerid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique customer number; identifies the customer in every other table', AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employeeid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique employee number; identifies the employee in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `equity`
--
ALTER TABLE `equity`
  MODIFY `equityid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique equity number; identifies the capital or drawing record in every other table';

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `expenseid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique expense number; identifies the expense in every other table';

--
-- AUTO_INCREMENT for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  MODIFY `invoiceitemid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique invoice line number; identifies the line in every other table', AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoiceid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique invoice number; identifies the invoice in every other table', AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `journalentries`
--
ALTER TABLE `journalentries`
  MODIFY `journalentryid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journallines`
--
ALTER TABLE `journallines`
  MODIFY `journallineid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `livestock`
--
ALTER TABLE `livestock`
  MODIFY `livestockid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique livestock number; identifies the livestock item in every other table', AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `livestockstages`
--
ALTER TABLE `livestockstages`
  MODIFY `livestockstageid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key of the livestock stage', AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `livestocktypes`
--
ALTER TABLE `livestocktypes`
  MODIFY `livestocktypeid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key of the livestock type', AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `paymentid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique payment number; identifies the payment in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payrollbillitems`
--
ALTER TABLE `payrollbillitems`
  MODIFY `payrollbillitemid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique bill line number; identifies the line in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payrollbills`
--
ALTER TABLE `payrollbills`
  MODIFY `payrollbillid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique payroll bill number; identifies the bill in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payrollpayments`
--
ALTER TABLE `payrollpayments`
  MODIFY `payrollpaymentid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique payroll payment number; identifies the payment in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permissionid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique permission row number; identifies one granted permission', AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `productid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique product number; identifies the product in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchaseitems`
--
ALTER TABLE `purchaseitems`
  MODIFY `purchaseitemid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique purchase line number; identifies the line in every other table', AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `purchaseid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique purchase number; identifies the purchase in every other table', AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `quotationitems`
--
ALTER TABLE `quotationitems`
  MODIFY `quotationitemid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique quotation line number; identifies the line in every other table', AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotationid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique quotation number; identifies the quotation in every other table', AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `receiptid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique receipt number; identifies the receipt in every other table', AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `salerefunds`
--
ALTER TABLE `salerefunds`
  MODIFY `refundid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique refund number; identifies the refund in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `salereturnitems`
--
ALTER TABLE `salereturnitems`
  MODIFY `returnitemid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique return line number; identifies the line in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `salereturns`
--
ALTER TABLE `salereturns`
  MODIFY `returnid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique return number; identifies the return in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sentsms`
--
ALTER TABLE `sentsms`
  MODIFY `smsid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique SMS batch number';

--
-- AUTO_INCREMENT for table `sentsmsrecipients`
--
ALTER TABLE `sentsmsrecipients`
  MODIFY `recipientid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique recipient row number';

--
-- AUTO_INCREMENT for table `shareholders`
--
ALTER TABLE `shareholders`
  MODIFY `shareholderid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique shareholder number; identifies the shareholder in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `shareholdershares`
--
ALTER TABLE `shareholdershares`
  MODIFY `shareid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique share row number; identifies the ownership percentage row';

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplierid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique supplier number; identifies the supplier in every other table', AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique user number; identifies the user in every other table', AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vat`
--
ALTER TABLE `vat`
  MODIFY `vatid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique VAT rate number';

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fkaccountsparentaccountid` FOREIGN KEY (`parentaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE;

--
-- Constraints for table `accounttransfers`
--
ALTER TABLE `accounttransfers`
  ADD CONSTRAINT `fkaccounttransfersfromaccountid` FOREIGN KEY (`fromaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkaccounttransferstoaccountid` FOREIGN KEY (`toaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE;

--
-- Constraints for table `activitylogs`
--
ALTER TABLE `activitylogs`
  ADD CONSTRAINT `fkactivitylogsuserid` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON UPDATE CASCADE;

--
-- Constraints for table `assetadjustments`
--
ALTER TABLE `assetadjustments`
  ADD CONSTRAINT `fkassetadjustmentsassetid` FOREIGN KEY (`assetid`) REFERENCES `assets` (`assetid`) ON UPDATE CASCADE;

--
-- Constraints for table `customerdepositrefunds`
--
ALTER TABLE `customerdepositrefunds`
  ADD CONSTRAINT `fkcustomerdepositrefundsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkcustomerdepositrefundscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE;

--
-- Constraints for table `customerdeposits`
--
ALTER TABLE `customerdeposits`
  ADD CONSTRAINT `fkcustomerdepositsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkcustomerdepositscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE;

--
-- Constraints for table `customerdepositusages`
--
ALTER TABLE `customerdepositusages`
  ADD CONSTRAINT `fkcustomerdepositusagescustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkcustomerdepositusagesinvoiceid` FOREIGN KEY (`invoiceid`) REFERENCES `invoices` (`invoiceid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `equity`
--
ALTER TABLE `equity`
  ADD CONSTRAINT `fkequityaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkequityshareholderid` FOREIGN KEY (`shareholderid`) REFERENCES `shareholders` (`shareholderid`) ON UPDATE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fkexpensesexpenseaccountid` FOREIGN KEY (`expenseaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkexpensespaidfromaccountid` FOREIGN KEY (`paidfromaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE;

--
-- Constraints for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  ADD CONSTRAINT `fkinvoiceitemsinventoryaccount` FOREIGN KEY (`inventoryaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoiceitemsinvoiceid` FOREIGN KEY (`invoiceid`) REFERENCES `invoices` (`invoiceid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoiceitemslivestockid` FOREIGN KEY (`livestockid`) REFERENCES `livestock` (`livestockid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoiceitemsproductid` FOREIGN KEY (`productid`) REFERENCES `products` (`productid`) ON UPDATE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fkinvoicescogsaccount` FOREIGN KEY (`cogsaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoicescustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoicesreceivableaccount` FOREIGN KEY (`receivableaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoicesreceivedinaccountid` FOREIGN KEY (`receivedinaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkinvoicesrevenueaccount` FOREIGN KEY (`revenueaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE;

--
-- Constraints for table `journallines`
--
ALTER TABLE `journallines`
  ADD CONSTRAINT `fkjournallinesaccount` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkjournallinesentry` FOREIGN KEY (`journalentryid`) REFERENCES `journalentries` (`journalentryid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fkpaymentsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpaymentspurchaseid` FOREIGN KEY (`purchaseid`) REFERENCES `purchases` (`purchaseid`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpaymentssupplierid` FOREIGN KEY (`supplierid`) REFERENCES `suppliers` (`supplierid`) ON UPDATE CASCADE;

--
-- Constraints for table `payrollbillitems`
--
ALTER TABLE `payrollbillitems`
  ADD CONSTRAINT `fkpayrollbillitemsemployeeid` FOREIGN KEY (`employeeid`) REFERENCES `employees` (`employeeid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpayrollbillitemspayrollbillid` FOREIGN KEY (`payrollbillid`) REFERENCES `payrollbills` (`payrollbillid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payrollpayments`
--
ALTER TABLE `payrollpayments`
  ADD CONSTRAINT `fkpayrollpaymentsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpayrollpaymentspayrollbillitemid` FOREIGN KEY (`payrollbillitemid`) REFERENCES `payrollbillitems` (`payrollbillitemid`) ON UPDATE CASCADE;

--
-- Constraints for table `permissions`
--
ALTER TABLE `permissions`
  ADD CONSTRAINT `fkpermissionsuserid` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchaseitems`
--
ALTER TABLE `purchaseitems`
  ADD CONSTRAINT `fkpurchaseitemsdebitaccount` FOREIGN KEY (`debitaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpurchaseitemslivestockid` FOREIGN KEY (`livestockid`) REFERENCES `livestock` (`livestockid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpurchaseitemsproductid` FOREIGN KEY (`productid`) REFERENCES `products` (`productid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpurchaseitemspurchaseid` FOREIGN KEY (`purchaseid`) REFERENCES `purchases` (`purchaseid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fkpurchasespaidfromaccountid` FOREIGN KEY (`paidfromaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpurchasespayableaccount` FOREIGN KEY (`payableaccountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkpurchasessupplierid` FOREIGN KEY (`supplierid`) REFERENCES `suppliers` (`supplierid`) ON UPDATE CASCADE;

--
-- Constraints for table `quotationitems`
--
ALTER TABLE `quotationitems`
  ADD CONSTRAINT `fkquotationitemslivestockid` FOREIGN KEY (`livestockid`) REFERENCES `livestock` (`livestockid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkquotationitemsquotationid` FOREIGN KEY (`quotationid`) REFERENCES `quotations` (`quotationid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `quotations`
--
ALTER TABLE `quotations`
  ADD CONSTRAINT `fkquotationscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE;

--
-- Constraints for table `receipts`
--
ALTER TABLE `receipts`
  ADD CONSTRAINT `fkreceiptsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkreceiptscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fkreceiptsinvoiceid` FOREIGN KEY (`invoiceid`) REFERENCES `invoices` (`invoiceid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `salerefunds`
--
ALTER TABLE `salerefunds`
  ADD CONSTRAINT `fksalerefundsaccountid` FOREIGN KEY (`accountid`) REFERENCES `accounts` (`accountid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalerefundscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalerefundsinvoiceid` FOREIGN KEY (`invoiceid`) REFERENCES `invoices` (`invoiceid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalerefundsreturnid` FOREIGN KEY (`returnid`) REFERENCES `salereturns` (`returnid`) ON UPDATE CASCADE;

--
-- Constraints for table `salereturnitems`
--
ALTER TABLE `salereturnitems`
  ADD CONSTRAINT `fksalereturnitemsinvoiceitemid` FOREIGN KEY (`invoiceitemid`) REFERENCES `invoiceitems` (`invoiceitemid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalereturnitemslivestockid` FOREIGN KEY (`livestockid`) REFERENCES `livestock` (`livestockid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalereturnitemsreturnid` FOREIGN KEY (`returnid`) REFERENCES `salereturns` (`returnid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `salereturns`
--
ALTER TABLE `salereturns`
  ADD CONSTRAINT `fksalereturnscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fksalereturnsinvoiceid` FOREIGN KEY (`invoiceid`) REFERENCES `invoices` (`invoiceid`) ON UPDATE CASCADE;

--
-- Constraints for table `sentsms`
--
ALTER TABLE `sentsms`
  ADD CONSTRAINT `fksentsmsuserid` FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON UPDATE CASCADE;

--
-- Constraints for table `sentsmsrecipients`
--
ALTER TABLE `sentsmsrecipients`
  ADD CONSTRAINT `fksentsmsrecipientscustomerid` FOREIGN KEY (`customerid`) REFERENCES `customers` (`customerid`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fksentsmsrecipientssmsid` FOREIGN KEY (`smsid`) REFERENCES `sentsms` (`smsid`) ON UPDATE CASCADE;

--
-- Constraints for table `shareholdershares`
--
ALTER TABLE `shareholdershares`
  ADD CONSTRAINT `fkshareholdersharesshareholderid` FOREIGN KEY (`shareholderid`) REFERENCES `shareholders` (`shareholderid`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
