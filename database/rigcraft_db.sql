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

-- ---------------------------------------------------------------------
--  Extended catalogue: 30 more certified units (CPUs, GPUs, RAM,
--  motherboards, SSDs). Generated from backend/app/Database/Seeds/
--  CatalogData.php — on an existing database run instead:
--     php spark db:seed CatalogSeeder
-- ---------------------------------------------------------------------
INSERT INTO components
  (serial_hash, serial_number, model_name, brand, category, intake_date,
   buyout_cost, listing_price, retail_price, status, warranty_months, notes, created_by)
VALUES
  ('RC-SU650-4417', '2M1820058713', 'ADATA Ultimate SU650 512GB', 'ADATA', 'Storage', CURDATE() - INTERVAL 34 DAY, 800.00, 1250.00, 1995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-3400G-6052', '9JA2731S90269', 'AMD Ryzen 5 3400G', 'AMD', 'CPU', CURDATE() - INTERVAL 31 DAY, 2800.00, 3995.00, 6995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-5500-3381', '9MB4207R40115', 'AMD Ryzen 5 5500', 'AMD', 'CPU', CURDATE() - INTERVAL 29 DAY, 2400.00, 3495.00, 5295.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-7500F-7714', '9PC3318T70482', 'AMD Ryzen 5 7500F', 'AMD', 'CPU', CURDATE() - INTERVAL 12 DAY, 5200.00, 6995.00, 8995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-5700X-2268', '9HB2915P60631', 'AMD Ryzen 7 5700X', 'AMD', 'CPU', CURDATE() - INTERVAL 18 DAY, 5000.00, 6995.00, 9995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-7600-5830', 'AR7600CLW2311047', 'ASRock Radeon RX 7600 Challenger White 8GB', 'ASRock', 'GPU', CURDATE() - INTERVAL 16 DAY, 8800.00, 11995.00, 16995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-7600XT-9147', 'AR7600XTC2402218', 'ASRock Radeon RX 7600 XT Challenger 16GB OC', 'ASRock', 'GPU', CURDATE() - INTERVAL 9 DAY, 12000.00, 15995.00, 21995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-B450M-1609', 'BB1M90100377', 'ASRock B450M Steel Legend', 'ASRock', 'Motherboard', CURDATE() - INTERVAL 40 DAY, 2300.00, 3495.00, 5995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-B550M-4482', 'BH2N10301925', 'ASRock B550M Pro RS', 'ASRock', 'Motherboard', CURDATE() - INTERVAL 22 DAY, 3500.00, 4995.00, 7495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-7600-3075', 'R9YVCM019284', 'ASUS Dual Radeon RX 7600 V2 OC 8GB', 'ASUS', 'GPU', CURDATE() - INTERVAL 14 DAY, 8500.00, 11495.00, 16495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-3060TI-8821', 'CF3060TIA21330592', 'Colorful iGame GeForce RTX 3060 Ti Advanced OC', 'Colorful', 'GPU', CURDATE() - INTERVAL 37 DAY, 11000.00, 14995.00, 24995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-4070SUPER-1953', 'CF4070SUW24080317', 'Colorful iGame GeForce RTX 4070 SUPER Ultra W OC', 'Colorful', 'GPU', CURDATE() - INTERVAL 7 DAY, 26000.00, 33995.00, 42995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-32GB-6640', 'F5-6000J3238F16GX2', 'G.Skill Flare X5 32GB DDR5-6000', 'G.Skill', 'RAM', CURDATE() - INTERVAL 11 DAY, 3500.00, 4995.00, 6995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-16GB-2905', 'F4-3200C16D-16GVKB', 'G.Skill Ripjaws V 16GB DDR4-3200', 'G.Skill', 'RAM', CURDATE() - INTERVAL 26 DAY, 1200.00, 1795.00, 2795.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-A520M-7381', 'SN21384013066', 'Gigabyte A520M DS3H AC', 'Gigabyte', 'Motherboard', CURDATE() - INTERVAL 30 DAY, 2000.00, 2995.00, 4695.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-B550M-5126', 'SN23157020481', 'Gigabyte B550M K', 'Gigabyte', 'Motherboard', CURDATE() - INTERVAL 19 DAY, 2800.00, 3995.00, 5995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-1650-8034', 'SN20234059917', 'Gigabyte GeForce GTX 1650 Mini ITX OC 4G', 'Gigabyte', 'GPU', CURDATE() - INTERVAL 44 DAY, 3500.00, 4995.00, 9495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-4060-4708', 'SN24026011583', 'Gigabyte GeForce RTX 4060 Windforce OC 8G', 'Gigabyte', 'GPU', CURDATE() - INTERVAL 8 DAY, 10500.00, 13995.00, 18495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-14400F-3359', 'X335J503', 'Intel Core i5-14400F', 'Intel', 'CPU', CURDATE() - INTERVAL 6 DAY, 6500.00, 8495.00, 11495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-16GB-9512', 'KF432C16BBK2-16', 'Kingston Fury Beast 16GB DDR4-3200', 'Kingston', 'RAM', CURDATE() - INTERVAL 24 DAY, 1250.00, 1895.00, 2895.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-NM620-6273', 'NLD512G220510244', 'Lexar NM620 512GB NVMe M.2', 'Lexar', 'Storage', CURDATE() - INTERVAL 20 DAY, 1000.00, 1595.00, 2495.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-B450M-2491', '602-7C52-030B2304', 'MSI B450M-A PRO MAX II', 'MSI', 'Motherboard', CURDATE() - INTERVAL 27 DAY, 1900.00, 2795.00, 4295.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-3050-7166', 'BJ240300418', 'Palit GeForce RTX 3050 StormX 6GB', 'Palit', 'GPU', CURDATE() - INTERVAL 15 DAY, 5500.00, 7495.00, 10995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-500GB-3847', 'S6PWNJ0T402871', 'Samsung 870 EVO 500GB', 'Samsung', 'Storage', CURDATE() - INTERVAL 33 DAY, 1700.00, 2495.00, 3995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-990-5590', 'S73VNJ0W118342', 'Samsung 990 PRO 1TB NVMe M.2', 'Samsung', 'Storage', CURDATE() - INTERVAL 10 DAY, 4600.00, 6295.00, 8995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-7800XT-2736', 'SP7800XTP23120086', 'Sapphire Pure Radeon RX 7800 XT 16GB', 'Sapphire', 'GPU', CURDATE() - INTERVAL 13 DAY, 21000.00, 26995.00, 33995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-512GB-8259', 'TM11-1902190350001', 'TeamGroup MP33 512GB NVMe M.2', 'TeamGroup', 'Storage', CURDATE() - INTERVAL 36 DAY, 900.00, 1395.00, 2195.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-16GB-1328', 'TF3D416G3200HC16F', 'TeamGroup T-Force Delta RGB 16GB DDR4-3200', 'TeamGroup', 'RAM', CURDATE() - INTERVAL 17 DAY, 1450.00, 2095.00, 3195.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus')),
  ('RC-16GB-6981', 'TLZGD416G3200HC16F', 'TeamGroup T-Force Vulcan Z 16GB DDR4-3200', 'TeamGroup', 'RAM', CURDATE() - INTERVAL 23 DAY, 1150.00, 1695.00, 2595.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'aisha')),
  ('RC-7800XT-4013', 'XFX7800XTQ319A0942', 'XFX Speedster QICK 319 Radeon RX 7800 XT 16GB', 'XFX', 'GPU', CURDATE() - INTERVAL 5 DAY, 19500.00, 25495.00, 31995.00, 'Certified', 6, NULL, (SELECT id FROM users WHERE username = 'marcus'));

INSERT INTO diagnostic_logs
  (component_id, technician_id, tested_by, test_duration_mins, peak_temp_celsius, fan_speed_pct,
   memory_error_count, thermal_paste_replaced, paste_brand, ultrasonic_cleaned,
   benchmark_tool, stress_status, test_date)
SELECT c.id, u.id, u.full_name, t.mins, t.peak, t.fan, 0, t.paste_rep, t.paste, t.ultra, t.tool, 'PASSED', NOW() - INTERVAL t.days DAY
FROM (
  SELECT 'RC-SU650-4417' AS hash, 'aisha' AS tech, 30 AS mins, 46.2 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'OCCT' AS tool, 33 AS days
  UNION ALL
  SELECT 'RC-3400G-6052' AS hash, 'marcus' AS tech, 30 AS mins, 64.6 AS peak, 66 AS fan, TRUE AS paste_rep, 'Arctic MX-4' AS paste, FALSE AS ultra, 'Prime95' AS tool, 30 AS days
  UNION ALL
  SELECT 'RC-5500-3381' AS hash, 'aisha' AS tech, 30 AS mins, 63.1 AS peak, 73 AS fan, TRUE AS paste_rep, 'Arctic MX-6' AS paste, FALSE AS ultra, 'Cinebench R23' AS tool, 27 AS days
  UNION ALL
  SELECT 'RC-7500F-7714' AS hash, 'marcus' AS tech, 30 AS mins, 65.8 AS peak, 53 AS fan, TRUE AS paste_rep, 'Thermal Grizzly Kryonaut' AS paste, FALSE AS ultra, 'Cinebench R23' AS tool, 10 AS days
  UNION ALL
  SELECT 'RC-5700X-2268' AS hash, 'aisha' AS tech, 30 AS mins, 63.5 AS peak, 71 AS fan, TRUE AS paste_rep, 'Arctic MX-6' AS paste, FALSE AS ultra, 'Prime95' AS tool, 17 AS days
  UNION ALL
  SELECT 'RC-7600-5830' AS hash, 'marcus' AS tech, 30 AS mins, 67.5 AS peak, 76 AS fan, TRUE AS paste_rep, 'Arctic MX-4' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 15 AS days
  UNION ALL
  SELECT 'RC-7600XT-9147' AS hash, 'marcus' AS tech, 30 AS mins, 64.2 AS peak, 65 AS fan, TRUE AS paste_rep, 'Honeywell PTM7950' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 8 AS days
  UNION ALL
  SELECT 'RC-B450M-1609' AS hash, 'aisha' AS tech, 30 AS mins, 55.3 AS peak, 40 AS fan, FALSE AS paste_rep, NULL AS paste, TRUE AS ultra, 'OCCT' AS tool, 38 AS days
  UNION ALL
  SELECT 'RC-B550M-4482' AS hash, 'marcus' AS tech, 30 AS mins, 56.0 AS peak, 47 AS fan, FALSE AS paste_rep, NULL AS paste, TRUE AS ultra, 'OCCT' AS tool, 20 AS days
  UNION ALL
  SELECT 'RC-7600-3075' AS hash, 'aisha' AS tech, 30 AS mins, 66.9 AS peak, 69 AS fan, TRUE AS paste_rep, 'Arctic MX-4' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 13 AS days
  UNION ALL
  SELECT 'RC-3060TI-8821' AS hash, 'marcus' AS tech, 30 AS mins, 64.5 AS peak, 69 AS fan, TRUE AS paste_rep, 'Arctic MX-4' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 36 AS days
  UNION ALL
  SELECT 'RC-4070SUPER-1953' AS hash, 'aisha' AS tech, 30 AS mins, 66.4 AS peak, 81 AS fan, TRUE AS paste_rep, 'Thermal Grizzly Kryonaut' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 6 AS days
  UNION ALL
  SELECT 'RC-32GB-6640' AS hash, 'aisha' AS tech, 60 AS mins, 46.3 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'MemTest86' AS tool, 10 AS days
  UNION ALL
  SELECT 'RC-16GB-2905' AS hash, 'marcus' AS tech, 60 AS mins, 46.8 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'MemTest86' AS tool, 25 AS days
  UNION ALL
  SELECT 'RC-A520M-7381' AS hash, 'marcus' AS tech, 30 AS mins, 58.5 AS peak, 43 AS fan, FALSE AS paste_rep, NULL AS paste, TRUE AS ultra, 'OCCT' AS tool, 28 AS days
  UNION ALL
  SELECT 'RC-B550M-5126' AS hash, 'aisha' AS tech, 30 AS mins, 60.6 AS peak, 55 AS fan, FALSE AS paste_rep, NULL AS paste, TRUE AS ultra, 'OCCT' AS tool, 18 AS days
  UNION ALL
  SELECT 'RC-1650-8034' AS hash, 'aisha' AS tech, 30 AS mins, 65.9 AS peak, 74 AS fan, TRUE AS paste_rep, 'Noctua NT-H2' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 43 AS days
  UNION ALL
  SELECT 'RC-4060-4708' AS hash, 'marcus' AS tech, 30 AS mins, 63.1 AS peak, 70 AS fan, TRUE AS paste_rep, 'Arctic MX-6' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 6 AS days
  UNION ALL
  SELECT 'RC-14400F-3359' AS hash, 'aisha' AS tech, 30 AS mins, 63.0 AS peak, 60 AS fan, TRUE AS paste_rep, 'Arctic MX-4' AS paste, FALSE AS ultra, 'Prime95' AS tool, 5 AS days
  UNION ALL
  SELECT 'RC-16GB-9512' AS hash, 'aisha' AS tech, 60 AS mins, 47.5 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'MemTest86' AS tool, 23 AS days
  UNION ALL
  SELECT 'RC-NM620-6273' AS hash, 'marcus' AS tech, 30 AS mins, 55.7 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'OCCT' AS tool, 19 AS days
  UNION ALL
  SELECT 'RC-B450M-2491' AS hash, 'marcus' AS tech, 30 AS mins, 55.4 AS peak, 52 AS fan, FALSE AS paste_rep, NULL AS paste, TRUE AS ultra, 'OCCT' AS tool, 26 AS days
  UNION ALL
  SELECT 'RC-3050-7166' AS hash, 'aisha' AS tech, 30 AS mins, 65.2 AS peak, 64 AS fan, TRUE AS paste_rep, 'Arctic MX-6' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 14 AS days
  UNION ALL
  SELECT 'RC-500GB-3847' AS hash, 'marcus' AS tech, 30 AS mins, 44.3 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'OCCT' AS tool, 32 AS days
  UNION ALL
  SELECT 'RC-990-5590' AS hash, 'aisha' AS tech, 30 AS mins, 55.9 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'OCCT' AS tool, 9 AS days
  UNION ALL
  SELECT 'RC-7800XT-2736' AS hash, 'marcus' AS tech, 30 AS mins, 63.3 AS peak, 60 AS fan, TRUE AS paste_rep, 'Thermal Grizzly Kryonaut' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 12 AS days
  UNION ALL
  SELECT 'RC-512GB-8259' AS hash, 'aisha' AS tech, 30 AS mins, 60.3 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'OCCT' AS tool, 34 AS days
  UNION ALL
  SELECT 'RC-16GB-1328' AS hash, 'marcus' AS tech, 60 AS mins, 47.3 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'MemTest86' AS tool, 15 AS days
  UNION ALL
  SELECT 'RC-16GB-6981' AS hash, 'aisha' AS tech, 60 AS mins, 45.5 AS peak, 0 AS fan, FALSE AS paste_rep, NULL AS paste, FALSE AS ultra, 'MemTest86' AS tool, 21 AS days
  UNION ALL
  SELECT 'RC-7800XT-4013' AS hash, 'marcus' AS tech, 30 AS mins, 67.7 AS peak, 76 AS fan, TRUE AS paste_rep, 'Noctua NT-H2' AS paste, TRUE AS ultra, 'FurMark 2' AS tool, 4 AS days
) t
JOIN components c ON c.serial_hash = t.hash
JOIN users u ON u.username = t.tech;
