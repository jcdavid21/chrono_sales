-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: May 07, 2026 at 02:51 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.0.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `chrono_sales_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `alert_logs`
--

CREATE TABLE `alert_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `rule_id` int(10) UNSIGNED DEFAULT NULL,
  `confidence_label` enum('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'LOW',
  `confidence_prob` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `triggered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `models_used` varchar(120) NOT NULL DEFAULT '',
  `alert_type` enum('surge','dip','stable') NOT NULL DEFAULT 'stable',
  `predicted_value` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alert_rules`
--

CREATE TABLE `alert_rules` (
  `rule_id` int(11) NOT NULL,
  `rule_name` varchar(80) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `metric` varchar(40) DEFAULT NULL,
  `condition` varchar(10) DEFAULT NULL,
  `threshold_value` decimal(12,2) DEFAULT NULL,
  `notify_email` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(60) DEFAULT NULL,
  `resource` varchar(60) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(120) NOT NULL,
  `city` varchar(80) DEFAULT NULL,
  `region` varchar(80) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`branch_id`, `branch_name`, `city`, `region`, `is_active`, `created_at`) VALUES
(1, 'Aqua Mineral Shop Rob Malate', NULL, NULL, 1, '2026-04-27 05:31:02'),
(2, 'Aqua Mineral Shop SM Fairview', NULL, NULL, 1, '2026-04-27 05:31:02'),
(3, 'Aqua Mineral Shop SM Seaside', NULL, NULL, 1, '2026-04-27 05:31:02'),
(4, 'Cebu Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(5, 'Cebu Botanifique Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(6, 'Centris Bota Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(7, 'Centris Elite Shop', NULL, NULL, 1, '2026-04-27 05:31:02'),
(8, 'Elite Perfection BSA', NULL, NULL, 1, '2026-04-27 05:31:02'),
(9, 'Elite Perfection Rob Cebu', NULL, NULL, 1, '2026-04-27 05:31:02'),
(10, 'Gateway Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(11, 'Iconique BGC', NULL, NULL, 1, '2026-04-27 05:31:02'),
(12, 'Iconique City Front Pampanga', NULL, NULL, 1, '2026-04-27 05:31:02'),
(13, 'Iconique Gateway 2', NULL, NULL, 1, '2026-04-27 05:31:02'),
(14, 'Iconique Parqal', NULL, NULL, 1, '2026-04-27 05:31:02'),
(15, 'Libran Office Elite', NULL, NULL, 1, '2026-04-27 05:31:02'),
(16, 'Megamall Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(17, 'Megamall Botanifique Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(18, 'Robinson\'s Galleria Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(19, 'Robinson\'s Malate Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(20, 'Robinson\'s Malate Botanifique Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(21, 'SM Clark Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(22, 'Sm Fairview Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(23, 'Sm Fairview Botanifique kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(24, 'Starmills Botanifique Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(25, 'Trinoma 2 Aqua Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02'),
(26, 'Vertis Botanifique Kiosk', NULL, NULL, 1, '2026-04-27 05:31:02');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_sales_summary`
--

CREATE TABLE `daily_sales_summary` (
  `summary_id` int(11) NOT NULL,
  `summary_date` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `total_revenue` decimal(14,2) DEFAULT NULL,
  `transaction_count` int(11) DEFAULT NULL,
  `avg_ticket` decimal(12,2) DEFAULT NULL,
  `total_discount` decimal(14,2) DEFAULT NULL,
  `total_vat` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discount_impact_scores`
--

CREATE TABLE `discount_impact_scores` (
  `score_id` int(11) NOT NULL,
  `discount_type_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `impact_coefficient` decimal(8,4) DEFAULT NULL,
  `top_features_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discount_types`
--

CREATE TABLE `discount_types` (
  `discount_type_id` int(11) NOT NULL,
  `type_name` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `discount_types`
--

INSERT INTO `discount_types` (`discount_type_id`, `type_name`) VALUES
(1, 'fixed'),
(2, 'percent');

-- --------------------------------------------------------

--
-- Table structure for table `forecast_predictions`
--

CREATE TABLE `forecast_predictions` (
  `prediction_id` int(11) NOT NULL,
  `run_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `forecast_date` date DEFAULT NULL,
  `predicted_revenue` decimal(14,2) DEFAULT NULL,
  `lower_bound` decimal(14,2) DEFAULT NULL,
  `upper_bound` decimal(14,2) DEFAULT NULL,
  `ci_50_lower` decimal(14,2) DEFAULT NULL,
  `ci_50_upper` decimal(14,2) DEFAULT NULL,
  `ci_80_lower` decimal(14,2) DEFAULT NULL,
  `ci_80_upper` decimal(14,2) DEFAULT NULL,
  `ci_95_lower` decimal(14,2) DEFAULT NULL,
  `ci_95_upper` decimal(14,2) DEFAULT NULL,
  `confidence_lower` decimal(14,2) DEFAULT NULL,
  `confidence_upper` decimal(14,2) DEFAULT NULL,
  `model_type` enum('lstm','xgb','rf','ensemble') NOT NULL DEFAULT 'lstm'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forecast_runs`
--

CREATE TABLE `forecast_runs` (
  `run_id` int(11) NOT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `model_used` varchar(40) DEFAULT NULL,
  `model_type` enum('lstm','xgb','rf') DEFAULT NULL,
  `task_type` varchar(60) DEFAULT NULL,
  `hyperparams_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hyperparams_json`)),
  `horizon_days` int(11) DEFAULT NULL,
  `mae` decimal(12,4) DEFAULT NULL,
  `rmse` decimal(12,4) DEFAULT NULL,
  `val_loss` decimal(10,6) DEFAULT NULL,
  `mape` decimal(8,4) DEFAULT NULL,
  `accuracy` decimal(8,6) DEFAULT NULL,
  `f1_score` decimal(8,6) DEFAULT NULL,
  `precision_score` decimal(8,6) DEFAULT NULL,
  `recall_score` decimal(8,6) DEFAULT NULL,
  `roc_auc` decimal(8,6) DEFAULT NULL,
  `feature_importance_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`feature_importance_json`)),
  `features_used_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_used_json`)),
  `rows_trained` int(11) DEFAULT NULL,
  `rows_tested` int(11) DEFAULT NULL,
  `is_deployed` tinyint(1) NOT NULL DEFAULT 0,
  `triggered_by` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `forecast_runs`
--

INSERT INTO `forecast_runs` (`run_id`, `run_at`, `branch_id`, `model_used`, `model_type`, `task_type`, `hyperparams_json`, `horizon_days`, `mae`, `rmse`, `val_loss`, `mape`, `accuracy`, `f1_score`, `precision_score`, `recall_score`, `roc_auc`, `feature_importance_json`, `features_used_json`, `rows_trained`, `rows_tested`, `is_deployed`, `triggered_by`) VALUES
(5, '2026-05-07 00:11:40', NULL, 'lstm', 'lstm', 'grand_total_forecast', '{\"sequence_length\": 60, \"lstm_units\": 64, \"dropout_rate\": 0.2, \"epochs\": 30, \"learning_rate\": 0.001, \"target_column\": \"grand_total\"}', NULL, 6.7654, 7.9678, 0.059359, 33.1506, NULL, NULL, NULL, NULL, NULL, '[]', '[\"dow\", \"hour_of_day\", \"final_discount\", \"vat\", \"branch_id\", \"overall_payment_method_id\", \"is_ok\"]', 1316, 329, 1, 'ml-training-page'),
(6, '2026-05-07 00:12:33', NULL, 'xgb', 'xgb', 'revenue_impact', '{\"n_estimators\": 200, \"max_depth\": 6, \"learning_rate\": 0.1, \"subsample\": 0.8, \"task_type\": \"revenue_impact\"}', NULL, NULL, NULL, NULL, NULL, 0.932600, 0.965100, 0.940800, 0.990700, 0.497500, '[[\"vat\", 0.17922], [\"branch_id\", 0.17673], [\"final_discount\", 0.16521], [\"grand_total\", 0.15894], [\"dow\", 0.13843], [\"hour_of_day\", 0.12733], [\"overall_payment_method_id\", 0.05414]]', '[\"dow\", \"hour_of_day\", \"grand_total\", \"final_discount\", \"vat\", \"branch_id\", \"overall_payment_method_id\"]', 1364, 341, 1, 'ml-training-page'),
(7, '2026-05-07 00:13:07', NULL, 'rf', 'rf', 'branch_health', '{\"n_estimators\": 150, \"max_depth\": 10, \"min_samples_split\": 2, \"task_type\": \"branch_health\", \"class_weight\": \"balanced\"}', NULL, NULL, NULL, NULL, NULL, 0.879800, 0.936000, 0.937500, 0.934600, 0.476000, '[[\"vat\", 0.18174], [\"grand_total\", 0.16639], [\"branch_id\", 0.16397], [\"hour_of_day\", 0.15965], [\"final_discount\", 0.14448], [\"dow\", 0.12215], [\"overall_payment_method_id\", 0.06163]]', '[\"dow\", \"hour_of_day\", \"grand_total\", \"final_discount\", \"vat\", \"branch_id\", \"overall_payment_method_id\"]', 1364, 341, 1, 'ml-training-page');

-- --------------------------------------------------------

--
-- Table structure for table `ml_model_status`
--

CREATE TABLE `ml_model_status` (
  `model_id` int(10) UNSIGNED NOT NULL,
  `model_name` varchar(60) NOT NULL,
  `task_type` varchar(60) NOT NULL DEFAULT '' COMMENT 'e.g. grand_total_forecast, churn_risk',
  `model_type` enum('lstm','xgb','rf') NOT NULL DEFAULT 'xgb',
  `run_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → forecast_runs.run_id',
  `key_metric` varchar(30) DEFAULT NULL COMMENT 'e.g. rmse, accuracy',
  `key_metric_value` decimal(12,6) DEFAULT NULL,
  `last_trained_at` datetime DEFAULT NULL,
  `accuracy` decimal(6,4) DEFAULT NULL,
  `f1_score` decimal(6,4) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ml_model_status`
--

INSERT INTO `ml_model_status` (`model_id`, `model_name`, `task_type`, `model_type`, `run_id`, `key_metric`, `key_metric_value`, `last_trained_at`, `accuracy`, `f1_score`, `is_active`) VALUES
(9, 'grand_total_forecast_lstm', 'grand_total_forecast', 'lstm', 5, 'rmse', 7.967800, '2026-05-07 08:11:49', NULL, NULL, 1),
(10, 'revenue_impact_xgb', 'revenue_impact', 'xgb', 6, 'accuracy', 0.932600, '2026-05-07 08:12:40', 0.9326, 0.9651, 1),
(11, 'branch_health_rf', 'branch_health', 'rf', 7, 'accuracy', 0.879800, '2026-05-07 08:13:11', 0.8798, 0.9360, 1);

-- --------------------------------------------------------

--
-- Table structure for table `payment_bank_transfer`
--

CREATE TABLE `payment_bank_transfer` (
  `bank_transfer_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `bank_name` varchar(60) DEFAULT NULL,
  `reference_number` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_card`
--

CREATE TABLE `payment_card` (
  `card_payment_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `approval_code` varchar(20) DEFAULT NULL,
  `card_amount` decimal(12,2) DEFAULT NULL,
  `last_4_digits` char(4) DEFAULT NULL,
  `terminal_type` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_cash`
--

CREATE TABLE `payment_cash` (
  `cash_payment_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `cash_received` decimal(12,2) DEFAULT NULL,
  `change_given` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_check`
--

CREATE TABLE `payment_check` (
  `check_payment_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `bank_name` varchar(60) DEFAULT NULL,
  `check_amount` decimal(12,2) DEFAULT NULL,
  `check_number` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_customer_deposit`
--

CREATE TABLE `payment_customer_deposit` (
  `deposit_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `series_id` varchar(20) DEFAULT NULL,
  `series_number` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `method_id` int(11) NOT NULL,
  `method_name` varchar(40) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`method_id`, `method_name`, `description`) VALUES
(1, 'BankTransfer', NULL),
(2, 'Card', NULL),
(3, 'Cash', NULL),
(4, 'Check', NULL),
(5, 'CustomerDeposit', NULL),
(6, 'MULTI', NULL),
(7, 'QR', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_multi_splits`
--

CREATE TABLE `payment_multi_splits` (
  `split_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `split_order` tinyint(4) DEFAULT NULL,
  `method_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_qr`
--

CREATE TABLE `payment_qr` (
  `qr_payment_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `qr_amount` decimal(12,2) DEFAULT NULL,
  `qr_app_name` varchar(40) DEFAULT NULL,
  `qr_reference` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `invoice_number` varchar(30) DEFAULT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `customer_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `discount_type_id` int(11) DEFAULT NULL,
  `discount_value` decimal(12,2) DEFAULT NULL,
  `total_treatment` decimal(12,2) DEFAULT NULL,
  `total_product` decimal(12,2) DEFAULT NULL,
  `final_discount` decimal(12,2) DEFAULT NULL,
  `vat` decimal(12,2) DEFAULT NULL,
  `grand_total` decimal(12,2) DEFAULT NULL,
  `anomaly_score` decimal(5,4) DEFAULT NULL,
  `anomaly_flag` tinyint(1) NOT NULL DEFAULT 0,
  `overall_payment_method_id` int(11) DEFAULT NULL,
  `transaction_status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('Admin','Analyst','Viewer') DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `full_name`, `role`, `password_hash`, `is_active`, `created_at`) VALUES
(1, 'admin@chrono.sales.com', 'Admin User', 'Admin', '$2y$10$tzzLSmUblYPNMs.ZBlznau4/EII79WuM1PckHjTPukUjePqxMjwmK', 1, '2026-04-27 05:36:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alert_logs`
--
ALTER TABLE `alert_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_triggered_at` (`triggered_at`);

--
-- Indexes for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD PRIMARY KEY (`rule_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_logged_at` (`logged_at`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`),
  ADD UNIQUE KEY `branch_name` (`branch_name`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `idx_full_name` (`full_name`);

--
-- Indexes for table `daily_sales_summary`
--
ALTER TABLE `daily_sales_summary`
  ADD PRIMARY KEY (`summary_id`),
  ADD KEY `idx_date` (`summary_date`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `discount_impact_scores`
--
ALTER TABLE `discount_impact_scores`
  ADD PRIMARY KEY (`score_id`),
  ADD KEY `idx_dis_period` (`discount_type_id`,`period_start`,`period_end`);

--
-- Indexes for table `discount_types`
--
ALTER TABLE `discount_types`
  ADD PRIMARY KEY (`discount_type_id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `forecast_predictions`
--
ALTER TABLE `forecast_predictions`
  ADD PRIMARY KEY (`prediction_id`),
  ADD KEY `idx_fdate` (`forecast_date`),
  ADD KEY `run_id` (`run_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  ADD PRIMARY KEY (`run_id`),
  ADD KEY `idx_run_at` (`run_at`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `idx_task_type` (`task_type`),
  ADD KEY `idx_model_type` (`model_type`),
  ADD KEY `idx_is_deployed` (`is_deployed`);

--
-- Indexes for table `ml_model_status`
--
ALTER TABLE `ml_model_status`
  ADD PRIMARY KEY (`model_id`),
  ADD UNIQUE KEY `uq_model_name` (`model_name`),
  ADD KEY `idx_task_type` (`task_type`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `fk_ms_run_id` (`run_id`);

--
-- Indexes for table `payment_bank_transfer`
--
ALTER TABLE `payment_bank_transfer`
  ADD PRIMARY KEY (`bank_transfer_id`),
  ADD KEY `payment_bank_transfer_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_card`
--
ALTER TABLE `payment_card`
  ADD PRIMARY KEY (`card_payment_id`),
  ADD KEY `payment_card_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_cash`
--
ALTER TABLE `payment_cash`
  ADD PRIMARY KEY (`cash_payment_id`),
  ADD KEY `payment_cash_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_check`
--
ALTER TABLE `payment_check`
  ADD PRIMARY KEY (`check_payment_id`),
  ADD KEY `payment_check_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_customer_deposit`
--
ALTER TABLE `payment_customer_deposit`
  ADD PRIMARY KEY (`deposit_id`),
  ADD KEY `payment_customer_deposit_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`method_id`),
  ADD UNIQUE KEY `method_name` (`method_name`);

--
-- Indexes for table `payment_multi_splits`
--
ALTER TABLE `payment_multi_splits`
  ADD PRIMARY KEY (`split_id`),
  ADD KEY `method_id` (`method_id`),
  ADD KEY `payment_multi_splits_ibfk_1` (`transaction_id`);

--
-- Indexes for table `payment_qr`
--
ALTER TABLE `payment_qr`
  ADD PRIMARY KEY (`qr_payment_id`),
  ADD KEY `payment_qr_ibfk_1` (`transaction_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_invoice` (`invoice_number`),
  ADD KEY `idx_date` (`transaction_date`),
  ADD KEY `idx_total` (`grand_total`),
  ADD KEY `idx_status` (`transaction_status`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `discount_type_id` (`discount_type_id`),
  ADD KEY `overall_payment_method_id` (`overall_payment_method_id`),
  ADD KEY `transactions_ibfk_1` (`customer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alert_logs`
--
ALTER TABLE `alert_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alert_rules`
--
ALTER TABLE `alert_rules`
  MODIFY `rule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3733;

--
-- AUTO_INCREMENT for table `daily_sales_summary`
--
ALTER TABLE `daily_sales_summary`
  MODIFY `summary_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discount_impact_scores`
--
ALTER TABLE `discount_impact_scores`
  MODIFY `score_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discount_types`
--
ALTER TABLE `discount_types`
  MODIFY `discount_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `forecast_predictions`
--
ALTER TABLE `forecast_predictions`
  MODIFY `prediction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  MODIFY `run_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `ml_model_status`
--
ALTER TABLE `ml_model_status`
  MODIFY `model_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `payment_bank_transfer`
--
ALTER TABLE `payment_bank_transfer`
  MODIFY `bank_transfer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `payment_card`
--
ALTER TABLE `payment_card`
  MODIFY `card_payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1315;

--
-- AUTO_INCREMENT for table `payment_cash`
--
ALTER TABLE `payment_cash`
  MODIFY `cash_payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1932;

--
-- AUTO_INCREMENT for table `payment_check`
--
ALTER TABLE `payment_check`
  MODIFY `check_payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_customer_deposit`
--
ALTER TABLE `payment_customer_deposit`
  MODIFY `deposit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `method_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `payment_multi_splits`
--
ALTER TABLE `payment_multi_splits`
  MODIFY `split_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=343;

--
-- AUTO_INCREMENT for table `payment_qr`
--
ALTER TABLE `payment_qr`
  MODIFY `qr_payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8845;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD CONSTRAINT `alert_rules_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `daily_sales_summary`
--
ALTER TABLE `daily_sales_summary`
  ADD CONSTRAINT `daily_sales_summary_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `forecast_predictions`
--
ALTER TABLE `forecast_predictions`
  ADD CONSTRAINT `forecast_predictions_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `forecast_runs` (`run_id`),
  ADD CONSTRAINT `forecast_predictions_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  ADD CONSTRAINT `forecast_runs_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `payment_bank_transfer`
--
ALTER TABLE `payment_bank_transfer`
  ADD CONSTRAINT `payment_bank_transfer_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`);

--
-- Constraints for table `payment_card`
--
ALTER TABLE `payment_card`
  ADD CONSTRAINT `payment_card_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_cash`
--
ALTER TABLE `payment_cash`
  ADD CONSTRAINT `payment_cash_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_check`
--
ALTER TABLE `payment_check`
  ADD CONSTRAINT `payment_check_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`);

--
-- Constraints for table `payment_customer_deposit`
--
ALTER TABLE `payment_customer_deposit`
  ADD CONSTRAINT `payment_customer_deposit_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`);

--
-- Constraints for table `payment_multi_splits`
--
ALTER TABLE `payment_multi_splits`
  ADD CONSTRAINT `payment_multi_splits_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`),
  ADD CONSTRAINT `payment_multi_splits_ibfk_2` FOREIGN KEY (`method_id`) REFERENCES `payment_methods` (`method_id`);

--
-- Constraints for table `payment_qr`
--
ALTER TABLE `payment_qr`
  ADD CONSTRAINT `payment_qr_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`discount_type_id`) REFERENCES `discount_types` (`discount_type_id`),
  ADD CONSTRAINT `transactions_ibfk_4` FOREIGN KEY (`overall_payment_method_id`) REFERENCES `payment_methods` (`method_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
