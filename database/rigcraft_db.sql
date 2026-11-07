-- =====================================================================
--  RigCraft — Hardware Intake & Public Verification Portal
--  Database schema + seed data (all prices in Philippine pesos, PHP ₱)
--
--  Import via phpMyAdmin (Import tab) or CLI, from the RigCraft folder:
--     C:\xampp\mysql\bin\mysql.exe -u root < database\rigcraft_db.sql
--  or with CodeIgniter, from the backend folder (the database must exist):
--     php spark migrate                    (creates the tables)
--     php spark db:seed RigCraftSeeder     (adds the sample data)
--
--  NOTE: This script DROPS and recreates the RigCraft tables, so
--        re-running it resets the database to the seed state.
--        Seed dates are relative to the day you import.
--
--  Seed logins (CHANGE THESE after first sign-in):
--     admin  / admin123   (Administrator)
--     marcus / tech1234   (Technician)
--     aisha  / tech1234   (Technician)
--
--  Demo shopper account (public store, not the console):
--     juan@example.com / customer123
-- =====================================================================

CREATE DATABASE IF NOT EXISTS rigcraft_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rigcraft_db;

-- Children first so foreign keys don't block the drops.
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS diagnostic_logs;
DROP TABLE IF EXISTS components;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------
--  users — staff accounts for the internal console
-- ---------------------------------------------------------------------
CREATE TABLE users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  components — one row per physical unit bought in for refurbishment
-- ---------------------------------------------------------------------
CREATE TABLE components (
  id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  serial_hash      VARCHAR(32)      NOT NULL COMMENT 'RigCraft public ID, e.g. RC-3070TI-8941 (encoded in the QR label)',
  serial_number    VARCHAR(64)      NOT NULL COMMENT 'Manufacturer S/N printed on the unit',
  model_name       VARCHAR(120)     NOT NULL,
  brand            VARCHAR(60)      NOT NULL,
  category         ENUM('GPU','CPU','RAM','Motherboard','PSU','Storage','Other') NOT NULL DEFAULT 'GPU',
  intake_date      DATE             NOT NULL DEFAULT (CURRENT_DATE),
  buyout_cost      DECIMAL(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'PHP (₱). Internal: what we paid. Never exposed publicly.',
  listing_price    DECIMAL(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'PHP (₱). Resale price.',
  retail_price     DECIMAL(10,2)    NULL COMMENT 'PHP (₱). Brand-new SRP, shown as "you save" on the shop.',
  status          ENUM('Intake','Testing','Certified','Sold','Rejected') NOT NULL DEFAULT 'Intake',
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

  CONSTRAINT fk_components_creator
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT chk_prices CHECK (buyout_cost >= 0 AND listing_price >= 0
                               AND (sold_price IS NULL OR sold_price >= 0)
                               AND (retail_price IS NULL OR retail_price >= 0)),
  -- A Sold unit must record what it sold for and when.
  CONSTRAINT chk_sold CHECK (status <> 'Sold' OR (sold_price IS NOT NULL AND sold_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  diagnostic_logs — one row per stress-test run (a unit may be
--  re-tested; the app always reads the most recent run)
-- ---------------------------------------------------------------------
CREATE TABLE diagnostic_logs (
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

  CONSTRAINT fk_logs_component
    FOREIGN KEY (component_id) REFERENCES components(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_logs_technician
    FOREIGN KEY (technician_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT chk_fan_pct CHECK (fan_speed_pct BETWEEN 0 AND 100),

  -- The 72.0 °C ceiling is enforced at the database level too:
  -- a run can only be PASSED if it stayed at or under 72.0 °C
  -- with zero memory errors. FAILED runs may record any value.
  CONSTRAINT chk_pass_rules CHECK (
    stress_status = 'FAILED'
    OR (peak_temp_celsius <= 72.0 AND memory_error_count = 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  customers — shopper accounts for the public store (separate from
--  staff users: a customer can never sign in to the console)
-- ---------------------------------------------------------------------
CREATE TABLE customers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  orders — one row per online checkout. Payment is collected on
--  pickup/delivery (cash or GCash); no card data is ever stored.
-- ---------------------------------------------------------------------
CREATE TABLE orders (
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

  CONSTRAINT fk_orders_customer
    FOREIGN KEY (customer_id) REFERENCES customers(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_order_total CHECK (total >= 0),
  CONSTRAINT chk_order_address CHECK (fulfillment <> 'Delivery' OR address IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  order_items — the units in an order. Name and price are copied at
--  checkout so the receipt survives later edits or a deleted unit.
-- ---------------------------------------------------------------------
CREATE TABLE order_items (
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

  CONSTRAINT fk_items_order
    FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_items_component
    FOREIGN KEY (component_id) REFERENCES components(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_item_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Seed data
-- =====================================================================

-- Passwords: admin123 / tech1234 (bcrypt)
INSERT INTO users (id, username, full_name, password_hash, role) VALUES
  (1, 'admin',  'System Administrator', '$2y$10$dTa/gpJ8ADbn7r3yeF/wvufo5oHS6LYRkOQtOxlY1rAqMjWYTEBOq', 'admin'),
  (2, 'marcus', 'Marcus Reyes',         '$2y$10$T9y6OAlPeDYsJCA973J94ekBAyy0XJongzgXA/8RVahcLHxKc8L6a', 'technician'),
  (3, 'aisha',  'Aisha Patel',          '$2y$10$T9y6OAlPeDYsJCA973J94ekBAyy0XJongzgXA/8RVahcLHxKc8L6a', 'technician');

-- Demo shopper. Password: customer123 (bcrypt)
INSERT INTO customers (id, email, full_name, phone, password_hash) VALUES
  (1, 'juan@example.com', 'Juan Dela Cruz', '0917 555 0123', '$2y$10$kraGUoW9tmxD6sdszFF1r.v0LMpfTX3hT8X8nfzvanNX.iRfgzGr2');

-- retail_price = illustrative brand-new SRP used for the shop's "you save" figures.
INSERT INTO components
  (id, serial_hash, serial_number, model_name, brand, category, intake_date,
   buyout_cost, listing_price, retail_price, status, warranty_months, sold_price, sold_at, notes, created_by)
VALUES
  -- Primary demo unit (verify.php falls back to this hash)
  (1,  'RC-3070TI-8941',    '2216K0471983',     'NVIDIA GeForce RTX 3070 Ti',          'NVIDIA',   'GPU', CURDATE() - INTERVAL 10 DAY, 13500.00, 19999.00, 32995.00, 'Certified', 6, NULL, NULL, 'Founders Edition. Minor scuff on backplate.', 2),
  (2,  'RC-5800X-3317',     '9HA2741P10388',    'AMD Ryzen 7 5800X',                   'AMD',      'CPU', CURDATE() - INTERVAL 6 DAY,   6000.00,  8995.00, 14500.00, 'Certified', 6, NULL, NULL, NULL, 3),
  (3,  'RC-6700XT-5502',    'SR6700XT2208431',  'AMD Radeon RX 6700 XT',               'Sapphire', 'GPU', CURDATE() - INTERVAL 4 DAY,   9500.00,     0.00, 21995.00, 'Rejected',  0, NULL, NULL, 'Artifacts under load. Likely VRAM fault — part out.', 2),
  (4,  'RC-3060-4410',      'MSI3060V12-55102', 'NVIDIA GeForce RTX 3060 12GB',        'MSI',      'GPU', CURDATE() - INTERVAL 52 DAY,  8500.00, 12999.00, 19995.00, 'Sold',      6, 12500.00, CURDATE() - INTERVAL 30 DAY, NULL, 3),
  (5,  'RC-12400F-7731',    'X2B41700F9921',    'Intel Core i5-12400F',                'Intel',    'CPU', CURDATE() - INTERVAL 45 DAY,  4200.00,  6495.00,  9495.00, 'Sold',      6,  6300.00, CURDATE() - INTERVAL 20 DAY, NULL, 2),
  (6,  'RC-6600-2089',      'MSI6600P2104477',  'AMD Radeon RX 6600',                  'MSI',      'GPU', CURDATE() - INTERVAL 38 DAY,  6000.00,  9499.00, 15995.00, 'Certified', 6, NULL, NULL, 'Failed first run on stock paste; passed after re-paste.', 3),
  (7,  'RC-2080SUPER-5126', 'ZT2080S19C0331',   'NVIDIA GeForce RTX 2080 Super',       'Zotac',    'GPU', CURDATE() - INTERVAL 33 DAY,  9000.00,     0.00, NULL,     'Rejected',  0, NULL, NULL, 'Hotspot runaway even after re-paste. Fan bearing noisy.', 2),
  (8,  'RC-5600X-9902',     '9JB4410K20017',    'AMD Ryzen 5 5600X',                   'AMD',      'CPU', CURDATE() - INTERVAL 28 DAY,  3800.00,  5995.00,  9995.00, 'Sold',      6,  5800.00, CURDATE() - INTERVAL 9 DAY,  NULL, 3),
  (9,  'RC-3080-6614',      'MSI3080GZT10G8812','NVIDIA GeForce RTX 3080 10GB',        'MSI',      'GPU', CURDATE() - INTERVAL 21 DAY, 19500.00, 27999.00, 44995.00, 'Certified', 6, NULL, NULL, 'Gaming Z Trio. Thermal pads replaced.', 2),
  (10, 'RC-32GB-3381',      'CMK32GX4M2E3200',  'Corsair Vengeance LPX 32GB DDR4-3200','Corsair',  'RAM', CURDATE() - INTERVAL 15 DAY,  2500.00,  3995.00,  6495.00, 'Certified', 6, NULL, NULL, '2 x 16GB kit.', 3),
  (11, 'RC-4070-1258',      'GB4070WF3OC7702',  'NVIDIA GeForce RTX 4070',             'Gigabyte', 'GPU', CURDATE() - INTERVAL 2 DAY,  24000.00, 32999.00, 38995.00, 'Testing',   6, NULL, NULL, 'Queued for stress test.', 2),
  (12, 'RC-1660SUPER-7740', 'EVGA1660S06G5521', 'NVIDIA GeForce GTX 1660 Super',       'EVGA',     'GPU', CURDATE() - INTERVAL 1 DAY,   3500.00,     0.00, NULL,     'Intake',    6, NULL, NULL, 'Just arrived. Needs cleaning.', 3);

INSERT INTO diagnostic_logs
  (component_id, technician_id, tested_by, test_duration_mins, peak_temp_celsius, fan_speed_pct,
   memory_error_count, thermal_paste_replaced, paste_brand, ultrasonic_cleaned,
   benchmark_tool, stress_status, test_date)
VALUES
  (1,  2, 'Marcus Reyes', 30, 68.4,  62, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 8 DAY),
  (2,  3, 'Aisha Patel',  30, 70.9,  74, 0, TRUE,  'Thermal Grizzly Kryonaut', FALSE, 'Prime95',       'PASSED', NOW() - INTERVAL 5 DAY),
  (3,  2, 'Marcus Reyes', 30, 81.7, 100, 3, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 3 DAY),
  (4,  3, 'Aisha Patel',  30, 66.1,  55, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 50 DAY),
  (5,  2, 'Marcus Reyes', 30, 64.0,  48, 0, TRUE,  'Noctua NT-H2',             FALSE, 'Cinebench R23', 'PASSED', NOW() - INTERVAL 44 DAY),
  (6,  3, 'Aisha Patel',  30, 74.5,  88, 0, FALSE, NULL,                       TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 37 DAY),
  (6,  3, 'Aisha Patel',  30, 69.8,  70, 0, TRUE,  'Arctic MX-6',              TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 35 DAY),
  (7,  2, 'Marcus Reyes', 30, 78.9, 100, 0, TRUE,  'Thermal Grizzly Kryonaut', TRUE,  'FurMark 2',     'FAILED', NOW() - INTERVAL 32 DAY),
  (8,  3, 'Aisha Patel',  30, 69.0,  70, 0, TRUE,  'Arctic MX-4',              FALSE, 'Prime95',       'PASSED', NOW() - INTERVAL 27 DAY),
  (9,  2, 'Marcus Reyes', 30, 71.6,  80, 0, TRUE,  'Honeywell PTM7950',        TRUE,  'FurMark 2',     'PASSED', NOW() - INTERVAL 20 DAY),
  (10, 3, 'Aisha Patel',  60, 47.5,   0, 0, FALSE, NULL,                       FALSE, 'MemTest86',     'PASSED', NOW() - INTERVAL 14 DAY);
