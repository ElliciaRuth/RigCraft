<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RigCraft schema (same as database/rigcraft_db.sql, without the sample data).
 *
 *   php spark migrate
 *
 * Uses CREATE TABLE IF NOT EXISTS, so running it against a database that was
 * imported from the SQL file simply records the migration and changes nothing.
 * Raw SQL keeps the CHECK constraints (e.g. the 72.0 °C pass rule), which the
 * Forge builder can't express.
 */
class CreateRigCraftSchema extends Migration
{
    public function up(): void
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->db->query("CREATE TABLE IF NOT EXISTS users (
          id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          username       VARCHAR(30)   NOT NULL,
          full_name      VARCHAR(80)   NOT NULL,
          password_hash  VARCHAR(255)  NOT NULL COMMENT 'PHP password_hash() (bcrypt)',
          role           ENUM('admin','technician') NOT NULL DEFAULT 'technician',
          is_active      BOOLEAN       NOT NULL DEFAULT TRUE,
          last_login_at  DATETIME      NULL,
          created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_username (username)
        ) {$opts}");

        $this->db->query("CREATE TABLE IF NOT EXISTS components (
          id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
          serial_hash      VARCHAR(32)      NOT NULL COMMENT 'RigCraft public ID, e.g. RC-3070TI-8941 (encoded in the QR label)',
          serial_number    VARCHAR(64)      NOT NULL COMMENT 'Manufacturer S/N printed on the unit',
          model_name       VARCHAR(120)     NOT NULL,
          brand            VARCHAR(60)      NOT NULL,
          category         ENUM('GPU','CPU','RAM','Motherboard','PSU','Storage','Other') NOT NULL DEFAULT 'GPU',
          intake_date      DATE             NOT NULL DEFAULT (CURRENT_DATE),
          buyout_cost      DECIMAL(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'PHP (₱). Internal: what we paid. Never exposed publicly.',
          listing_price    DECIMAL(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'PHP (₱). Resale price.',
          retail_price     DECIMAL(10,2)    NULL COMMENT 'PHP (₱). Brand-new SRP, shown as \"you save\" on the shop.',
          status           ENUM('Intake','Testing','Certified','Sold','Rejected') NOT NULL DEFAULT 'Intake',
          warranty_months  TINYINT UNSIGNED NOT NULL DEFAULT 6,
          sold_price       DECIMAL(10,2)    NULL COMMENT 'PHP (₱). Actual sale price.',
          sold_at          DATE             NULL COMMENT 'Sale date — buyer warranty starts here',
          notes            VARCHAR(1000)    NULL,
          created_by       INT UNSIGNED     NULL,
          created_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_serial_hash (serial_hash),
          KEY idx_serial_number (serial_number),
          KEY idx_status (status),
          KEY idx_intake_date (intake_date),
          CONSTRAINT fk_components_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
          CONSTRAINT chk_prices CHECK (buyout_cost >= 0 AND listing_price >= 0
                                       AND (sold_price IS NULL OR sold_price >= 0)
                                       AND (retail_price IS NULL OR retail_price >= 0)),
          CONSTRAINT chk_sold CHECK (status <> 'Sold' OR (sold_price IS NOT NULL AND sold_at IS NOT NULL))
        ) {$opts}");

        $this->db->query("CREATE TABLE IF NOT EXISTS diagnostic_logs (
          id                      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
          component_id            INT UNSIGNED      NOT NULL,
          technician_id           INT UNSIGNED      NULL,
          tested_by               VARCHAR(80)       NOT NULL COMMENT 'Technician name at the time of the test',
          test_duration_mins      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
          peak_temp_celsius       DECIMAL(4,1)      NOT NULL,
          fan_speed_pct           TINYINT UNSIGNED  NOT NULL,
          memory_error_count      INT UNSIGNED      NOT NULL DEFAULT 0,
          thermal_paste_replaced  BOOLEAN           NOT NULL DEFAULT FALSE,
          paste_brand             VARCHAR(60)       NULL,
          ultrasonic_cleaned      BOOLEAN           NOT NULL DEFAULT FALSE COMMENT 'Ultrasonic PCB cleaning performed',
          benchmark_tool          VARCHAR(60)       NOT NULL DEFAULT 'FurMark 2',
          stress_status           ENUM('PASSED','FAILED') NOT NULL,
          test_date               DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_component_date (component_id, test_date),
          CONSTRAINT fk_logs_component  FOREIGN KEY (component_id)  REFERENCES components(id) ON DELETE CASCADE  ON UPDATE CASCADE,
          CONSTRAINT fk_logs_technician FOREIGN KEY (technician_id) REFERENCES users(id)      ON DELETE SET NULL ON UPDATE CASCADE,
          CONSTRAINT chk_fan_pct CHECK (fan_speed_pct BETWEEN 0 AND 100),
          -- A run can only be PASSED at or under 72.0 °C with zero memory errors.
          CONSTRAINT chk_pass_rules CHECK (stress_status = 'FAILED' OR (peak_temp_celsius <= 72.0 AND memory_error_count = 0))
        ) {$opts}");

        $this->db->query("CREATE TABLE IF NOT EXISTS customers (
          id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          email          VARCHAR(190)  NOT NULL COMMENT 'Sign-in name, stored lower-case',
          full_name      VARCHAR(80)   NOT NULL,
          phone          VARCHAR(20)   NOT NULL,
          password_hash  VARCHAR(255)  NOT NULL COMMENT 'PHP password_hash() (bcrypt)',
          is_active      BOOLEAN       NOT NULL DEFAULT TRUE,
          last_login_at  DATETIME      NULL,
          created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_customer_email (email)
        ) {$opts}");

        $this->db->query("CREATE TABLE IF NOT EXISTS orders (
          id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          order_no        VARCHAR(20)   NOT NULL COMMENT 'Public reference, e.g. RCO-261108-4821',
          customer_id     INT UNSIGNED  NOT NULL,
          status          ENUM('Placed','Completed','Cancelled') NOT NULL DEFAULT 'Placed',
          fulfillment     ENUM('Pickup','Delivery') NOT NULL DEFAULT 'Pickup',
          payment_method  ENUM('Cash','GCash')      NOT NULL DEFAULT 'Cash',
          contact_name    VARCHAR(80)   NOT NULL,
          contact_phone   VARCHAR(20)   NOT NULL,
          address         VARCHAR(300)  NULL COMMENT 'Required for Delivery',
          notes           VARCHAR(500)  NULL,
          total           DECIMAL(10,2) NOT NULL COMMENT 'PHP (₱)',
          created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_order_no (order_no),
          KEY idx_order_customer (customer_id, created_at),
          KEY idx_order_status (status),
          CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
          CONSTRAINT chk_order_total CHECK (total >= 0),
          CONSTRAINT chk_order_address CHECK (fulfillment <> 'Delivery' OR address IS NOT NULL)
        ) {$opts}");

        $this->db->query("CREATE TABLE IF NOT EXISTS order_items (
          id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          order_id      INT UNSIGNED  NOT NULL,
          component_id  INT UNSIGNED  NULL,
          serial_hash   VARCHAR(32)   NOT NULL,
          model_name    VARCHAR(120)  NOT NULL,
          brand         VARCHAR(60)   NOT NULL,
          category      VARCHAR(20)   NOT NULL,
          price         DECIMAL(10,2) NOT NULL COMMENT 'PHP (₱) at checkout',
          PRIMARY KEY (id),
          KEY idx_item_order (order_id),
          KEY idx_item_component (component_id),
          CONSTRAINT fk_items_order     FOREIGN KEY (order_id)     REFERENCES orders(id)     ON DELETE CASCADE  ON UPDATE CASCADE,
          CONSTRAINT fk_items_component FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE SET NULL ON UPDATE CASCADE,
          CONSTRAINT chk_item_price CHECK (price >= 0)
        ) {$opts}");
    }

    public function down(): void
    {
        // Children first so foreign keys don't block the drops.
        foreach (['order_items', 'orders', 'customers', 'diagnostic_logs', 'components', 'users'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
