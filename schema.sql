-- Cashflow platform schema (MySQL 8 / InnoDB / utf8mb4)
-- Core principle: every operational/financial row belongs to a company,
-- and (where relevant) to a profit center. Access is re-verified against
-- these tables on every request server-side -- the frontend never decides
-- what a user can see.

CREATE TABLE IF NOT EXISTS cf_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    cui VARCHAR(32) NULL,
    reg_com VARCHAR(64) NULL,
    logo VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    email VARCHAR(191) NULL,
    phone VARCHAR(64) NULL,
    website VARCHAR(191) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Bucharest',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles are per-company scoped role *definitions* a user is assigned to.
-- 'owner' and 'admin' implicitly get full access to every profit center
-- of the company; other roles rely on cf_profit_center_access rows.
CREATE TABLE IF NOT EXISTS cf_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_company_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_company_user (company_id, user_id),
    CONSTRAINT fk_cu_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_user FOREIGN KEY (user_id) REFERENCES cf_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_role FOREIGN KEY (role_id) REFERENCES cf_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A "corporate" profit center (type='corporate') is auto-created per
-- company and holds general/shared costs that aren't tied to one activity
-- line. It is a normal profit center as far as the data model is
-- concerned, which keeps profit_center_id mandatory on every transaction.
CREATE TABLE IF NOT EXISTS cf_profit_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(32) NOT NULL,
    description VARCHAR(255) NULL,
    color VARCHAR(16) NOT NULL DEFAULT '#6366f1',
    icon VARCHAR(32) NOT NULL DEFAULT 'bi-briefcase',
    type VARCHAR(32) NOT NULL DEFAULT 'custom',
    budget_amount DECIMAL(18,2) NULL,
    budget_period ENUM('monthly','yearly') NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_company_code (company_id, code),
    CONSTRAINT fk_pc_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit per-user, per-profit-center access. Owners/admins bypass this
-- (see cf_roles) but every other role must have a row here to see or
-- write data in a given profit center.
CREATE TABLE IF NOT EXISTS cf_profit_center_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    access_level ENUM('none','read','read_write','full') NOT NULL DEFAULT 'read',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_pc (user_id, profit_center_id),
    CONSTRAINT fk_pca_user FOREIGN KEY (user_id) REFERENCES cf_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pca_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_pca_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bank/cash accounts belong to the company, not to one profit center --
-- multiple activity lines can post against the same account.
CREATE TABLE IF NOT EXISTS cf_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    bank VARCHAR(191) NULL,
    iban VARCHAR(64) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    type ENUM('bank','cash','card') NOT NULL DEFAULT 'bank',
    opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_acc_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    type ENUM('income','expense') NOT NULL,
    parent_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cat_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    type ENUM('client','supplier','both') NOT NULL DEFAULT 'both',
    cui VARCHAR(32) NULL,
    email VARCHAR(191) NULL,
    phone VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_partner_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The financial transaction. profit_center_id is NOT NULL by design
-- (section 11 of the spec): general/unassignable costs go to the
-- company's 'corporate' profit center rather than leaving this null.
CREATE TABLE IF NOT EXISTS cf_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    account_id INT NOT NULL,
    user_id INT NOT NULL,
    type ENUM('income','expense') NOT NULL,
    category_id INT NULL,
    partner_id INT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
    amount_ron DECIMAL(18,2) NOT NULL,
    transaction_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    invoice_number VARCHAR(64) NULL,
    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_tx_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id),
    CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES cf_accounts(id),
    CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES cf_users(id),
    KEY idx_tx_company_pc_date (company_id, profit_center_id, transaction_date),
    KEY idx_tx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Splitting a shared/corporate cost across several profit centers
-- (section 13/33). Sum of allocations must equal the transaction amount;
-- enforced in application code on write.
CREATE TABLE IF NOT EXISTS cf_transaction_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    percent DECIMAL(6,3) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alloc_tx FOREIGN KEY (transaction_id) REFERENCES cf_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    company_id INT NULL,
    profit_center_id INT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_company (company_id, created_at),
    KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cf_roles (code, name) VALUES
    ('owner', 'Administrator'),
    ('admin', 'Administrator'),
    ('manager', 'Manager Financiar'),
    ('operator', 'Operator'),
    ('read_only', 'Read Only');

-- =====================================================================
-- Business modules (section 46: extensions live on top of the core
-- financial model, not baked into it as `if center == transport` checks).
-- =====================================================================

-- Vehicles are company-scoped (not tied to a single profit center): the
-- same truck can serve more than one activity line over time.
CREATE TABLE IF NOT EXISTS cf_vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    type ENUM('truck','trailer','car','other') NOT NULL DEFAULT 'truck',
    plate_number VARCHAR(32) NOT NULL,
    make VARCHAR(64) NULL,
    model VARCHAR(64) NULL,
    year INT NULL,
    mileage_km INT NOT NULL DEFAULT 0,
    fuel_consumption_per_100km DECIMAL(6,2) NULL,
    rca_expiry DATE NULL,
    casco_expiry DATE NULL,
    itp_expiry DATE NULL,
    leasing_monthly DECIMAL(12,2) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_veh_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_drivers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    phone VARCHAR(64) NULL,
    license_number VARCHAR(64) NULL,
    base_salary DECIMAL(12,2) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_drv_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A trip/cursă is profit-center scoped like a transaction, and can settle
-- into the ledger via income_transaction_id/expense_transaction_id so the
-- cashflow always reflects operational activity (spec section 60).
CREATE TABLE IF NOT EXISTS cf_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    trip_number VARCHAR(32) NULL,
    partner_id INT NULL,
    vehicle_id INT NULL,
    trailer_id INT NULL,
    driver_id INT NULL,
    origin VARCHAR(191) NULL,
    destination VARCHAR(191) NULL,
    km INT NULL,
    tariff DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
    fuel_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    road_taxes DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_costs DECIMAL(12,2) NOT NULL DEFAULT 0,
    trip_date DATE NOT NULL,
    status ENUM('planned','in_progress','completed','settled') NOT NULL DEFAULT 'planned',
    income_transaction_id INT NULL,
    expense_transaction_id INT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A work order (reparații / detailing / colantări) mirrors cf_trips for
-- the auto-service activity line.
CREATE TABLE IF NOT EXISTS cf_work_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    order_number VARCHAR(32) NULL,
    partner_id INT NULL,
    vehicle_plate VARCHAR(32) NULL,
    vehicle_make VARCHAR(64) NULL,
    vehicle_model VARCHAR(64) NULL,
    vehicle_vin VARCHAR(32) NULL,
    service_category VARCHAR(64) NULL,
    date_in DATE NOT NULL,
    date_estimated DATE NULL,
    date_done DATE NULL,
    materials_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    labor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    subcontractor_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    client_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
    status ENUM('in_progress','completed','delivered','settled') NOT NULL DEFAULT 'in_progress',
    income_transaction_id INT NULL,
    expense_transaction_id INT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wo_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_wo_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices track receivables (issued to clients) and payables (received
-- from suppliers) separately from the realized cashflow ledger. Marking
-- one paid creates the matching cf_transactions row (payment_transaction_id).
CREATE TABLE IF NOT EXISTS cf_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    partner_id INT NULL,
    direction ENUM('receivable','payable') NOT NULL,
    invoice_number VARCHAR(64) NULL,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RON',
    exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
    amount_ron DECIMAL(12,2) NOT NULL,
    paid_amount_ron DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    description VARCHAR(255) NULL,
    payment_transaction_id INT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id),
    KEY idx_inv_company_status (company_id, direction, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reusable shared-cost allocation rules (section 32/33): "Software X ->
-- Transport 50% / Detailing 30% / Colantări 20%". Applying a rule to a
-- transaction fills in cf_transaction_allocations.
CREATE TABLE IF NOT EXISTS cf_allocation_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    method ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rule_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_allocation_rule_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    profit_center_id INT NOT NULL,
    value DECIMAL(12,3) NOT NULL,
    CONSTRAINT fk_rline_rule FOREIGN KEY (rule_id) REFERENCES cf_allocation_rules(id) ON DELETE CASCADE,
    CONSTRAINT fk_rline_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- RBAC (section 25/44 formalized): cf_roles stay the coarse "job title"
-- shown in the UI, but the actual can-they-do-this check goes through a
-- role -> permission matrix so new capabilities don't need new roles or
-- scattered `if ($role === 'manager')` checks.
-- =====================================================================

CREATE TABLE IF NOT EXISTS cf_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(191) NOT NULL,
    permission_group VARCHAR(64) NOT NULL DEFAULT 'general'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    UNIQUE KEY uniq_role_permission (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES cf_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES cf_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cf_permissions (code, label, permission_group) VALUES
    ('transactions.write', 'Adaugă/anulează tranzacții', 'financiar'),
    ('invoices.write', 'Gestionează facturi (creanțe/datorii)', 'financiar'),
    ('operations.write', 'Gestionează curse/lucrări', 'operational'),
    ('allocations.manage', 'Alocă costuri pe centre de profit', 'financiar'),
    ('reports.view', 'Vede rapoarte', 'raportare'),
    ('documents.upload', 'Încarcă documente/facturi', 'documente'),
    ('integrations.manage', 'Configurează integrări (ANAF/SmartBill/Drive)', 'administrare'),
    ('company.manage', 'Administrează firma (centre, conturi, permisiuni)', 'administrare'),
    ('billing.view', 'Vede abonamentul și utilizarea', 'administrare');

-- =====================================================================
-- Platform / super-admin layer: a platform admin operates across every
-- company, outside the per-company role model above.
-- =====================================================================

-- cf_users.is_platform_admin is added by cashflow_migrate() in db.php via a
-- guarded ALTER TABLE (SHOW COLUMNS check), not here -- MySQL's `ADD COLUMN
-- IF NOT EXISTS` support is version-dependent, so we use the same
-- conditional-ALTER pattern already established in hr/modules/corectii.php.

CREATE TABLE IF NOT EXISTS cf_subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    price_month_ron DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_documents_month INT NOT NULL DEFAULT 0,
    max_anaf_lookups_month INT NOT NULL DEFAULT 0,
    max_users INT NOT NULL DEFAULT 0,
    max_profit_centers INT NOT NULL DEFAULT 0,
    features VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_company_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    plan_id INT NOT NULL,
    status ENUM('active','cancelled','past_due') NOT NULL DEFAULT 'active',
    current_period_start DATE NOT NULL,
    current_period_end DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_csub_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_csub_plan FOREIGN KEY (plan_id) REFERENCES cf_subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic per-company, per-period usage counters, checked/incremented
-- atomically against the active plan's limits before a metered action
-- (document upload, ANAF lookup) is allowed to proceed.
CREATE TABLE IF NOT EXISTS cf_usage_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    period_ym CHAR(7) NOT NULL,
    metric VARCHAR(32) NOT NULL,
    counter INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_usage (company_id, period_ym, metric),
    CONSTRAINT fk_usage_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uploaded documents (invoices etc). Counts against the plan's
-- max_documents_month. Files are stored outside the web-servable path;
-- this row is the only way to reach the bytes (see modules/documents.php).
CREATE TABLE IF NOT EXISTS cf_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    profit_center_id INT NULL,
    uploaded_by INT NOT NULL,
    type ENUM('invoice_in','invoice_out','other') NOT NULL DEFAULT 'other',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL,
    source ENUM('manual','anaf','smartbill','google_drive') NOT NULL DEFAULT 'manual',
    external_ref VARCHAR(191) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_pc FOREIGN KEY (profit_center_id) REFERENCES cf_profit_centers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-company third-party integration credentials/state. One row per
-- (company, provider). Tokens are stored as returned by the provider --
-- never rendered back into a form, only a masked "connected" indicator.
CREATE TABLE IF NOT EXISTS cf_company_integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    provider ENUM('anaf_efactura','smartbill','google_drive') NOT NULL,
    environment VARCHAR(16) NOT NULL DEFAULT 'test',
    config TEXT NULL,
    access_token TEXT NULL,
    refresh_token TEXT NULL,
    token_expires_at DATETIME NULL,
    status ENUM('disconnected','connected','error') NOT NULL DEFAULT 'disconnected',
    last_error VARCHAR(500) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_company_provider (company_id, provider),
    CONSTRAINT fk_ci_company FOREIGN KEY (company_id) REFERENCES cf_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login throttling: counts recent failed attempts per email to slow down
-- credential stuffing / brute force without needing an external service.
CREATE TABLE IF NOT EXISTS cf_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    ip_address VARCHAR(45) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cf_subscription_plans (code, name, price_month_ron, max_documents_month, max_anaf_lookups_month, max_users, max_profit_centers, features, status) VALUES
    ('starter', 'Starter', 0, 20, 50, 3, 3, 'excel_export', 'active'),
    ('business', 'Business', 249, 200, 500, 15, 10, 'excel_export,anaf_lookup,anaf_efactura', 'active'),
    ('enterprise', 'Enterprise', 799, 2000, 5000, 100, 100, 'excel_export,anaf_lookup,anaf_efactura,smartbill,google_drive', 'active');
