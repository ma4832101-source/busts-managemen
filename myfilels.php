<?php
/**
 * advanced_store_single.php
 * Single-file starter advanced store system (demo)
 * Requirements: XAMPP (Apache + MySQL)
 * Place in htdocs and open in browser.
 *
 * Seeded credentials:
 *  - admin / admin123  (role: admin)
 *  - employee / employee123 (role: employee)
 *
 * NOTE: For production, split files, secure uploads, sanitize inputs, add CSRF, etc.
 */

// ---------- CONFIG ----------
$dbHost = '127.0.0.1';
$dbRootUser = 'root';
$dbRootPass = ''; // set if you use a MySQL root password
$dbName = 'advanced_store_single_db';
$uploadsDir = __DIR__ . '/uploads_single';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

// Start session with secure settings
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'domain' => '',
    'secure' => false, // set to true in production with HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['created'])) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// ---------- DB SETUP & PDO ----------
try {
    // Connect to MySQL server first (for DB creation)
    $pdoRoot = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbRootUser, $dbRootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    // Create DB if not exists
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Connect to DB
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbRootUser, $dbRootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (Exception $e) {
    die("DB connection error: " . htmlspecialchars($e->getMessage()));
}

// Create tables if missing (enhanced schema)
$pdo->exec("
CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  address TEXT,
  phone VARCHAR(50),
  email VARCHAR(150),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  fullname VARCHAR(200),
  role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  branch_id INT DEFAULT NULL,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  position VARCHAR(100),
  phone VARCHAR(50),
  email VARCHAR(150),
  address TEXT,
  salary DECIMAL(12,2) DEFAULT 0.00,
  hire_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  phone VARCHAR(50),
  email VARCHAR(150),
  address TEXT,
  loyalty_points INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  quantity INT DEFAULT 0,
  price DECIMAL(12,2) DEFAULT 0.00,
  cost DECIMAL(12,2) DEFAULT 0.00,
  category_id INT DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  branch_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  status ENUM('pending','ongoing','completed') DEFAULT 'pending',
  start_date DATE,
  end_date DATE,
  budget DECIMAL(14,2) DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('revenue','expense') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency VARCHAR(10) DEFAULT 'USD',
  description TEXT,
  reference VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(100) UNIQUE,
  customer_id INT,
  total DECIMAL(14,2),
  status ENUM('pending','paid','cancelled') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  message TEXT,
  type VARCHAR(50),
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255),
  filepath VARCHAR(255),
  uploaded_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS calendar_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255),
  description TEXT,
  event_date DATE,
  start_time TIME,
  end_time TIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT DEFAULT NULL,
  user_id INT NOT NULL,
  total_amount DECIMAL(14,2) DEFAULT 0.00,
  discount DECIMAL(14,2) DEFAULT 0.00,
  tax DECIMAL(14,2) DEFAULT 0.00,
  payment_method ENUM('cash','card','transfer') DEFAULT 'cash',
  status ENUM('completed','pending','cancelled') DEFAULT 'completed',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  total_price DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

// Seed initial data if users table empty
$uCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($uCount === 0) {
    // sample branch
    $pdo->prepare("INSERT INTO branches (name, address, phone, email) VALUES (?,?,?,?)")
        ->execute(['Head Office', 'Main branch', '123-456-7890', 'head@example.com']);
    $branchId = $pdo->lastInsertId();
    
    // seed categories
    $categories = ['Electronics', 'Clothing', 'Food', 'Books', 'Home & Garden'];
    foreach ($categories as $category) {
        $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$category]);
    }
    
    // seed admin and employee
    $pwdAdmin = password_hash('admin123', PASSWORD_DEFAULT);
    $pwdEmp = password_hash('employee123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username,password,fullname,role,branch_id) VALUES (?,?,?,?,?)");
    $stmt->execute(['admin',$pwdAdmin,'System Admin','admin',$branchId]);
    $stmt->execute(['employee',$pwdEmp,'Default Employee','employee',$branchId]);
    $empUserId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employees (user_id,position,phone,email,hire_date) VALUES (?,?,?,?,?)")
        ->execute([$empUserId,'Sales','000-000-000','employee@example.com', date('Y-m-d')]);
        
    // seed sample products
    $products = [
        ['SKU001', 'Laptop', 'High performance laptop', 10, 999.99, 700.00, 1, null, $branchId],
        ['SKU002', 'T-Shirt', 'Cotton t-shirt', 50, 19.99, 10.00, 2, null, $branchId],
        ['SKU003', 'Coffee', 'Premium coffee beans', 30, 12.50, 7.00, 3, date('Y-m-d', strtotime('+6 months')), $branchId]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO products (sku, name, description, quantity, price, cost, category_id, expiry_date, branch_id) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($products as $product) {
        $stmt->execute($product);
    }
}

// ---------- Helpers ----------
function is_logged_in(){ return isset($_SESSION['user']); }
function require_login(){ if(!is_logged_in()){ header('Location:?page=login'); exit; } }
function is_admin(){ return is_logged_in() && $_SESSION['user']['role']==='admin'; }
function is_employee(){ return is_logged_in() && $_SESSION['user']['role']==='employee'; }
function h($s){ return htmlspecialchars($s, ENT_QUOTES); }
function validate_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function validate_date($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Password strength validation
function is_password_strong($password) {
    // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
}

// Generate random string for references
function generate_reference($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Check if employee can access page
function check_employee_access($page) {
    $allowed_pages = ['dashboard', 'products', 'customers', 'sales', 'calendar', 'documents', 'notifications'];
    if (is_employee() && !in_array($page, $allowed_pages)) {
        $_SESSION['error'] = "Access denied. You don't have permission to view this page.";
        header('Location: ?page=dashboard');
        exit;
    }
}

// Simple router via GET 'page' and POST actions
$page = $_GET['page'] ?? 'home';
check_employee_access($page);

// ---------- AUTH: login / logout / add admin ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // LOGIN
    if ($action === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            unset($user['password']);
            $_SESSION['user'] = $user;
            
            // Update last login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            header('Location: ?page=dashboard'); exit;
        } else {
            $_SESSION['error'] = "Invalid credentials";
            header('Location: ?page=login'); exit;
        }
    }

    // LOGOUT
    if ($action === 'logout') {
        session_destroy();
        header('Location: ?page=login'); exit;
    }

    // ADD USER (admin only)
    if ($action === 'add_user' && is_admin()) {
        $username = trim($_POST['username']);
        $pwd = $_POST['password'];
        $fullname = $_POST['fullname'] ?: null;
        $role = in_array($_POST['role'], ['admin','employee']) ? $_POST['role'] : 'employee';
        $branch_id = $_POST['branch_id'] ?: null;
        
        // Validate password strength
        if (!is_password_strong($pwd)) {
            $_SESSION['error'] = "Password must be at least 8 characters with uppercase, lowercase and number";
            header('Location: ?page=users'); exit;
        }
        
        $hashed = password_hash($pwd, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username,password,fullname,role,branch_id) VALUES (?,?,?,?,?)");
            $stmt->execute([$username, $hashed, $fullname, $role, $branch_id]);
            $uid = $pdo->lastInsertId();
            if ($role === 'employee') {
                $pdo->prepare("INSERT INTO employees (user_id,position,hire_date) VALUES (?,?,?)")
                    ->execute([$uid, $_POST['position'] ?? '', date('Y-m-d')]);
            }
            $_SESSION['success'] = "User created";
        } catch (Exception $e) {
            $_SESSION['error'] = "Create user failed: " . $e->getMessage();
        }
        header('Location: ?page=users'); exit;
    }

    // ADD BRANCH
    if ($action === 'add_branch' && is_admin()) {
        $name = $_POST['name']; 
        $address = $_POST['address'] ?? null;
        $phone = $_POST['phone'] ?? null;
        $email = $_POST['email'] ?? null;
        
        if (!empty($email) && !validate_email($email)) {
            $_SESSION['error'] = "Invalid email format";
            header('Location: ?page=branches'); exit;
        }
        
        $pdo->prepare("INSERT INTO branches (name,address,phone,email) VALUES (?,?,?,?)")
            ->execute([$name,$address,$phone,$email]);
        $_SESSION['success'] = "Branch added";
        header('Location: ?page=branches'); exit;
    }

    // ADD PRODUCT
    if ($action === 'add_product' && is_logged_in()) {
        $sku = $_POST['sku'] ?: null;
        $name = $_POST['name'];
        $description = $_POST['description'] ?? null;
        $quantity = (int)($_POST['quantity'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $cost = (float)($_POST['cost'] ?? 0);
        $category_id = $_POST['category_id'] ?: null;
        $expiry = $_POST['expiry_date'] ?: null;
        $branch_id = $_POST['branch_id'] ?: null;
        
        if ($expiry && !validate_date($expiry)) {
            $_SESSION['error'] = "Invalid expiry date format";
            header('Location: ?page=products'); exit;
        }
        
        $pdo->prepare("INSERT INTO products (sku,name,description,quantity,price,cost,category_id,expiry_date,branch_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$sku,$name,$description,$quantity,$price,$cost,$category_id,$expiry,$branch_id]);
        $_SESSION['success'] = "Product added";
        header('Location: ?page=products'); exit;
    }

    // EDIT / DELETE similar approach for customers, projects, transactions, etc.
    if ($action === 'add_customer') {
        $name = $_POST['name'];
        $phone = $_POST['phone'] ?? null;
        $email = $_POST['email'] ?? null;
        $address = $_POST['address'] ?? null;
        
        if (!empty($email) && !validate_email($email)) {
            $_SESSION['error'] = "Invalid email format";
            header('Location: ?page=customers'); exit;
        }
        
        $pdo->prepare("INSERT INTO customers (name,phone,email,address) VALUES (?,?,?,?)")
            ->execute([$name, $phone, $email, $address]);
        $_SESSION['success'] = "Customer added";
        header('Location:?page=customers'); exit;
    }

    if ($action === 'add_project') {
        $title = $_POST['title'];
        $description = $_POST['description'] ?? null;
        $status = $_POST['status'] ?? 'pending';
        $start_date = $_POST['start_date'] ?: null;
        $end_date = $_POST['end_date'] ?: null;
        $budget = (float)($_POST['budget'] ?? 0);
        
        if (($start_date && !validate_date($start_date)) || ($end_date && !validate_date($end_date))) {
            $_SESSION['error'] = "Invalid date format";
            header('Location: ?page=projects'); exit;
        }
        
        $pdo->prepare("INSERT INTO projects (title,description,status,start_date,end_date,budget) VALUES (?,?,?,?,?,?)")
            ->execute([$title, $description, $status, $start_date, $end_date, $budget]);
        $_SESSION['success'] = "Project added";
        header('Location:?page=projects'); exit;
    }

    if ($action === 'add_transaction') {
        $type = $_POST['type'];
        $amount = (float)$_POST['amount'];
        $currency = $_POST['currency'] ?? 'USD';
        $description = $_POST['description'] ?? null;
        $reference = generate_reference();
        
        $pdo->prepare("INSERT INTO transactions (type,amount,currency,description,reference) VALUES (?,?,?,?,?)")
            ->execute([$type, $amount, $currency, $description, $reference]);
        $_SESSION['success'] = "Transaction added (Ref: $reference)";
        header('Location:?page=transactions'); exit;
    }

    if ($action === 'add_notification') {
        $user_id = $_POST['user_id'] ?: null;
        $message = $_POST['message'];
        $type = $_POST['type'] ?? 'info';
        
        $pdo->prepare("INSERT INTO notifications (user_id,message,type) VALUES (?,?,?)")
            ->execute([$user_id, $message, $type]);
        $_SESSION['success'] = "Notification added";
        header('Location:?page=notifications'); exit;
    }

    if ($action === 'upload_document' && is_logged_in()) {
        if (!empty($_FILES['doc']['name'])) {
            $maxSize = 5 * 1024 * 1024; // 5MB
            $allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            
            $fname = basename($_FILES['doc']['name']);
            $fileExt = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            
            // Validate file type
            if (!in_array($fileExt, $allowedTypes)) {
                $_SESSION['error'] = "File type not allowed";
                header('Location:?page=documents'); exit;
            }
            
            // Validate file size
            if ($_FILES['doc']['size'] > $maxSize) {
                $_SESSION['error'] = "File too large (max 5MB)";
                header('Location:?page=documents'); exit;
            }
            
            $target = $uploadsDir . '/' . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/','_',$fname);
            if (move_uploaded_file($_FILES['doc']['tmp_name'], $target)) {
                $pdo->prepare("INSERT INTO documents (filename,filepath,uploaded_by) VALUES (?,?,?)")
                    ->execute([$fname, $target, $_SESSION['user']['id']]);
                $_SESSION['success'] = "Uploaded successfully";
            } else {
                $_SESSION['error'] = "Upload failed";
            }
        } else {
            $_SESSION['error'] = "No file selected";
        }
        header('Location:?page=documents'); exit;
    }

    if ($action === 'add_event') {
        $title = $_POST['title'];
        $description = $_POST['description'] ?? null;
        $event_date = $_POST['event_date'] ?: null;
        $start_time = $_POST['start_time'] ?: null;
        $end_time = $_POST['end_time'] ?: null;
        
        if ($event_date && !validate_date($event_date)) {
            $_SESSION['error'] = "Invalid date format";
            header('Location: ?page=calendar'); exit;
        }
        
        $pdo->prepare("INSERT INTO calendar_events (title,description,event_date,start_time,end_time) VALUES (?,?,?,?,?)")
            ->execute([$title, $description, $event_date, $start_time, $end_time]);
        $_SESSION['success'] = "Event added";
        header('Location:?page=calendar'); exit;
    }

    // Mark notification read
    if ($action === 'mark_read' && isset($_POST['nid'])) {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=?")->execute([$_POST['nid']]);
        header('Location:?page=notifications'); exit;
    }

    // Export CSV for tables
    if ($action === 'export' && isset($_POST['table'])) {
        $table = preg_replace('/[^a-z_]/','',$_POST['table']);
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $table . '_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        if (count($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) fputcsv($out, $r);
        }
        fclose($out); exit;
    }

    // Delete handlers (simple)
    if ($action === 'delete' && isset($_POST['table'], $_POST['id']) && is_admin()) {
        $t = preg_replace('/[^a-z_]/','',$_POST['table']);
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM `$t` WHERE id=?")->execute([$id]);
        $_SESSION['success'] = "Deleted";
        header('Location:?page=' . ($_POST['back'] ?? 'dashboard')); exit;
    }

    // Process sale
    if ($action === 'process_sale') {
        $customer_id = $_POST['customer_id'] ?: null;
        $items = $_POST['items'] ?? [];
        $discount = (float)($_POST['discount'] ?? 0);
        $tax = (float)($_POST['tax'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $notes = $_POST['notes'] ?? '';
        
        if (empty($items)) {
            $_SESSION['error'] = "No items in the sale";
            header('Location: ?page=sales'); exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Calculate total
            $total_amount = 0;
            foreach ($items as $item) {
                $product_id = $item['product_id'];
                $quantity = (int)$item['quantity'];
                
                // Get product details
                $stmt = $pdo->prepare("SELECT price, quantity as stock FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    throw new Exception("Product not found");
                }
                
                if ($product['stock'] < $quantity) {
                    throw new Exception("Insufficient stock for product ID: $product_id");
                }
                
                $unit_price = (float)$product['price'];
                $item_total = $unit_price * $quantity;
                $total_amount += $item_total;
            }
            
            // Apply discount and tax
            $total_amount = $total_amount - $discount + $tax;
            
            // Create sale record
            $stmt = $pdo->prepare("INSERT INTO sales (customer_id, user_id, total_amount, discount, tax, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $_SESSION['user']['id'], $total_amount, $discount, $tax, $payment_method, $notes]);
            $sale_id = $pdo->lastInsertId();
            
            // Add sale items and update product quantities
            foreach ($items as $item) {
                $product_id = $item['product_id'];
                $quantity = (int)$item['quantity'];
                
                // Get product price
                $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                
                $unit_price = (float)$product['price'];
                $item_total = $unit_price * $quantity;
                
                // Add sale item
                $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$sale_id, $product_id, $quantity, $unit_price, $item_total]);
                
                // Update product quantity
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$quantity, $product_id]);
            }
            
            // Add transaction record
            $reference = generate_reference();
            $stmt = $pdo->prepare("INSERT INTO transactions (type, amount, currency, description, reference) VALUES ('revenue', ?, 'USD', 'Sale #$sale_id', ?)");
            $stmt->execute([$total_amount, $reference]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Sale completed successfully. Sale ID: $sale_id";
            header('Location: ?page=sales'); exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Sale failed: " . $e->getMessage();
            header('Location: ?page=sales'); exit;
        }
    }
}

// ---------- Small API endpoints (AJAX) ----------
if (isset($_GET['api'])) {
    if ($_GET['api'] === 'kpis') {
        // return simple KPIs JSON
        $revenue = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='revenue'")->fetchColumn() ?: 0;
        $expense = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='expense'")->fetchColumn() ?: 0;
        $profit = $revenue - $expense;
        $expiringCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)")->execute() ? $pdo->query("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)")->fetchColumn() : 0;
        
        // Additional KPIs
        $lowStockCount = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity < 10")->fetchColumn() ?: 0;
        $customerCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() ?: 0;
        $employeeCount = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() ?: 0;
        
        echo json_encode([
            'revenue'=> (float)$revenue, 
            'expense'=>(float)$expense, 
            'profit'=>(float)$profit, 
            'expiring' => (int)$expiringCount,
            'low_stock' => (int)$lowStockCount,
            'customers' => (int)$customerCount,
            'employees' => (int)$employeeCount
        ]);
        exit;
    }
    
    if ($_GET['api'] === 'product_search' && isset($_GET['q'])) {
        $search = '%' . $_GET['q'] . '%';
        $stmt = $pdo->prepare("SELECT id, name, price, quantity FROM products WHERE name LIKE ? OR sku LIKE ?");
        $stmt->execute([$search, $search]);
        $products = $stmt->fetchAll();
        
        echo json_encode($products);
        exit;
    }
}

// ---------- Views: login / dashboard / lists ----------
function flash(){
    if(!empty($_SESSION['success'])){ echo '<div class="flash success">'.h($_SESSION['success']).'</div>'; unset($_SESSION['success']); }
    if(!empty($_SESSION['error'])){ echo '<div class="flash error">'.h($_SESSION['error']).'</div>'; unset($_SESSION['error']); }
    if(!empty($_SESSION['info'])){ echo '<div class="flash info">'.h($_SESSION['info']).'</div>'; unset($_SESSION['info']); }
}

// HTML header + CSS + sidebar style
function render_header($title='Advanced Store'){
    $user = $_SESSION['user'] ?? null;
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title><?=h($title)?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
      :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --success: #4cc9f0;
        --info: #4895ef;
        --warning: #f72585;
        --danger: #e63946;
        --light: #f8f9fa;
        --dark: #212529;
        --muted: #6c757d;
        --accent: #2b6cb0;
        --sidebar: #0f1724;
        --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      }
      
      * { box-sizing: border-box; }
      
      body {
        font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
        margin: 0;
        background: #f5f7fb;
        color: var(--dark);
        line-height: 1.6;
      }
      
      .top {
        background: #fff;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 4px rgba(0,0,0,.1);
        position: sticky;
        top: 0;
        z-index: 100;
      }
      
      .brand {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
      }
      
      .brand i {
        font-size: 1.8rem;
      }
      
      .container {
        display: flex;
        min-height: calc(100vh - 60px);
      }
      
      .sidebar {
        width: 250px;
        background: var(--sidebar);
        color: #fff;
        padding: 20px;
        box-shadow: var(--card-shadow);
        transition: all 0.3s ease;
      }
      
      .sidebar a {
        color: #cfe3ff;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 10px;
        text-decoration: none;
        border-radius: 6px;
        margin-bottom: 5px;
        transition: all 0.2s;
      }
      
      .sidebar a:hover {
        background: rgba(255,255,255,.1);
        transform: translateX(5px);
      }
      
      .sidebar a i {
        width: 20px;
        text-align: center;
      }
      
      .main {
        flex: 1;
        padding: 20px;
        overflow-x: auto;
      }
      
      .card {
        background: #fff;
        padding: 20px;
        border-radius: 10px;
        box-shadow: var(--card-shadow);
        margin-bottom: 20px;
      }
      
      .card h3, .card h4 {
        margin-top: 0;
        color: var(--primary);
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
      }
      
      .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
      }
      
      .stat-card {
        text-align: center;
        padding: 20px;
        border-radius: 10px;
        color: white;
      }
      
      .stat-card i {
        font-size: 2.5rem;
        margin-bottom: 10px;
      }
      
      .stat-card .value {
        font-size: 1.8rem;
        font-weight: bold;
        margin: 5px 0;
      }
      
      .stat-card .label {
        font-size: 0.9rem;
        opacity: 0.9;
      }
      
      .revenue { background: linear-gradient(45deg, #4361ee, #3a0ca3); }
      .expense { background: linear-gradient(45deg, #f72585, #b5179e); }
      .profit { background: linear-gradient(45deg, #4cc9f0, #4895ef); }
      .customers { background: linear-gradient(45deg, #560bad, #7209b7); }
      
      input, select, textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #dde4f0;
        border-radius: 6px;
        margin-bottom: 10px;
        font-family: inherit;
      }
      
      button, .btn {
        background: var(--primary);
        color: #fff;
        border: 0;
        padding: 10px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.2s;
      }
      
      button:hover, .btn:hover {
        background: var(--secondary);
        transform: translateY(-2px);
      }
      
      .btn-danger { background: var(--danger); }
      .btn-danger:hover { background: #c1121f; }
      
      .btn-success { background: var(--success); }
      .btn-success:hover { background: #3a86ff; }
      
      .muted {
        color: var(--muted);
        font-size: 0.9rem;
      }
      
      .flash {
        padding: 12px;
        margin: 15px 0;
        border-radius: 6px;
        font-weight: 500;
      }
      
      .flash.success {
        background: #d1e7dd;
        color: #0f5132;
        border-left: 4px solid #0f5132;
      }
      
      .flash.error {
        background: #f8d7da;
        color: #842029;
        border-left: 4px solid #842029;
      }
      
      .flash.info {
        background: #cff4fc;
        color: #055160;
        border-left: 4px solid #055160;
      }
      
      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
      }
      
      th, td {
        padding: 12px;
        border-bottom: 1px solid #eef2f7;
        text-align: left;
      }
      
      th {
        background: #f8f9fa;
        font-weight: 600;
      }
      
      tr:hover {
        background: #f8f9fa;
      }
      
      .badge {
        padding: 5px 10px;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
      }
      
      .badge-success { background: #d1e7dd; color: #0f5132; }
      .badge-warning { background: #fff3cd; color: #664d03; }
      .badge-danger { background: #f8d7da; color: #842029; }
      .badge-info { background: #cff4fc; color: #055160; }
      
      .expiring {
        background: #fff7ed;
        color: #92400e;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.8rem;
      }
      
      .top-actions {
        display: flex;
        gap: 10px;
        align-items: center;
      }
      
      .form-row {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
      }
      
      .form-group {
        flex: 1;
      }
      
      .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
      }
      
      .dashboard-section {
        margin-bottom: 30px;
      }
      
      .dashboard-section h3 {
        border-bottom: 2px solid var(--primary);
        padding-bottom: 10px;
        margin-bottom: 20px;
      }
      
      /* Sales specific styles */
      .sale-item {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
      }
      
      .sale-summary {
        background: #e9ecef;
        padding: 20px;
        border-radius: 8px;
        margin-top: 20px;
      }
      
      .sale-total {
        font-size: 1.5rem;
        font-weight: bold;
        color: var(--primary);
      }
      
      /* Login page enhancements */
      .login-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 20px;
      }
      
      .login-card {
        background: #fff;
        padding: 40px 30px;
        border-radius: 15px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        width: 100%;
        max-width: 450px;
        position: relative;
        overflow: hidden;
      }
      
      .login-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 5px;
        background: linear-gradient(90deg, #4361ee, #3a0ca3);
      }
      
      .login-brand {
        text-align: center;
        margin-bottom: 30px;
        color: var(--primary);
      }
      
      .login-brand i {
        font-size: 3rem;
        margin-bottom: 15px;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
      }
      
      .login-brand h2 {
        margin: 0;
        font-weight: 700;
      }
      
      .login-form input {
        padding: 15px;
        font-size: 1rem;
        border: 2px solid #e2e8f0;
        transition: all 0.3s;
      }
      
      .login-form input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
      }
      
      .login-btn {
        width: 100%;
        padding: 15px;
        font-size: 1.1rem;
        font-weight: 600;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        border: none;
        border-radius: 8px;
        color: white;
        cursor: pointer;
        transition: all 0.3s;
      }
      
      .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
      }
      
      .login-links {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
        font-size: 0.9rem;
      }
      
      .login-footer {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
        color: var(--muted);
      }
      
      /* Role-based styling */
      .admin-only {
        border-left: 4px solid var(--primary);
      }
      
      .employee-view {
        border-left: 4px solid var(--success);
      }
      
      @media (max-width: 992px) {
        .grid {
          grid-template-columns: 1fr;
        }
        
        .sidebar {
          width: 70px;
          padding: 15px 10px;
        }
        
        .sidebar a span {
          display: none;
        }
        
        .sidebar a i {
          font-size: 1.2rem;
        }
      }
      
      @media (max-width: 768px) {
        .container {
          flex-direction: column;
        }
        
        .sidebar {
          width: 100%;
          display: flex;
          overflow-x: auto;
          padding: 10px;
        }
        
        .sidebar a {
          flex-direction: column;
          padding: 10px;
          font-size: 0.8rem;
          text-align: center;
        }
        
        .form-row {
          flex-direction: column;
          gap: 10px;
        }
        
        .login-card {
          padding: 30px 20px;
        }
      }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    </head><body>
    <div class="top">
      <div class="brand">
        <i class="fas fa-store"></i>
        <span>Advanced Store</span>
      </div>
      <div class="top-actions">
        <?php if($user): ?>
          <div class="muted">Hi, <?=h($user['fullname'] ?: $user['username'])?> (<?=h($user['role'])?>)</div>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="logout">
            <button type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
          </form>
        <?php else: ?>
          <a href="?page=login" class="btn"><i class="fas fa-sign-in-alt"></i> Login</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="container">
      <?php if($user): ?>
      <div class="sidebar">
        <h3 style="color:#fff; display: flex; align-items: center; gap: 10px;">
          <i class="fas fa-bars"></i>
          <span>Menu</span>
        </h3>
        <a href="?page=dashboard"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
        <a href="?page=products"><i class="fas fa-box"></i> <span>Inventory</span></a>
        <a href="?page=sales"><i class="fas fa-shopping-cart"></i> <span>Sales</span></a>
        <a href="?page=customers"><i class="fas fa-address-book"></i> <span>Customers</span></a>
        <?php if(is_admin()): ?>
        <a href="?page=employees"><i class="fas fa-users"></i> <span>Employees</span></a>
        <?php endif; ?>
        <a href="?page=calendar"><i class="fas fa-calendar"></i> <span>Calendar</span></a>
        <a href="?page=documents"><i class="fas fa-file"></i> <span>Documents</span></a>
        <a href="?page=notifications">
          <i class="fas fa-bell"></i> 
          <span>Notifications</span> 
          <?php
            $pdoLocal = $GLOBALS['pdo'];
            $cnt = $pdoLocal->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
            if($cnt>0) echo '<span class="badge badge-danger">'.(int)$cnt.'</span>';
          ?>
        </a>
        <?php if(is_admin()): ?>
        <hr style="border:none;border-top:1px solid rgba(255,255,255,.1);margin:15px 0">
        <a href="?page=users"><i class="fas fa-user-cog"></i> <span>Users</span></a>
        <a href="?page=branches"><i class="fas fa-code-branch"></i> <span>Branches</span></a>
        <a href="?page=projects"><i class="fas fa-tasks"></i> <span>Projects</span></a>
        <a href="?page=transactions"><i class="fas fa-money-bill-wave"></i> <span>Transactions</span></a>
        <a href="?page=reports"><i class="fas fa-chart-bar"></i> <span>Reports</span></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="main">
    <?php
}

// Footer
function render_footer(){
    echo '</div></div></body></html>';
}

// ---------- Render: Login ----------
if ($page === 'login') {
    if (is_logged_in()) { header('Location:?page=dashboard'); exit; }
    ?>
    <div class="login-container">
      <div class="login-card">
        <div class="login-brand">
          <i class="fas fa-store"></i>
          <h2>Advanced Store</h2>
        </div>
        <?php flash(); ?>
        <form method="post" class="login-form">
          <div class="form-group">
            <input name="username" placeholder="Username" required>
          </div>
          <div class="form-group">
            <input name="password" type="password" placeholder="Password" required>
          </div>
          <button type="submit" name="action" value="login" class="login-btn">
            <i class="fas fa-sign-in-alt"></i> Login
          </button>
        </form>
        
        <div class="login-links">
          <a href="?page=help" class="muted">Need Help?</a>
          <span class="muted">v1.0</span>
        </div>
        
        <div class="login-footer">
          <p class="muted">
            Seeded credentials:<br>
            <strong>admin / admin123</strong> (Admin)<br>
            <strong>employee / employee123</strong> (Employee)
          </p>
        </div>
      </div>
    </div>
    <?php exit;
}

// ---------- Dashboard ----------
if ($page === 'dashboard') {
    require_login();
    render_header('Dashboard');
    flash();
    // fetch quick numbers
    $revenue = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='revenue'")->fetchColumn() ?: 0;
    $expense = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='expense'")->fetchColumn() ?: 0;
    $profit = $revenue - $expense;
    $customerCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() ?: 0;
    $lowStockCount = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity < 10")->fetchColumn() ?: 0;
    $recentNot = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 6")->fetchAll();
    
    // Recent sales for dashboard
    $recentSales = $pdo->query("
      SELECT s.*, c.name as customer_name, u.username 
      FROM sales s 
      LEFT JOIN customers c ON c.id = s.customer_id 
      LEFT JOIN users u ON u.id = s.user_id 
      ORDER BY s.created_at DESC 
      LIMIT 5
    ")->fetchAll();
    ?>
    <div class="dashboard-section">
      <h3>Key Performance Indicators</h3>
      <div class="grid">
        <div class="stat-card revenue">
          <i class="fas fa-dollar-sign"></i>
          <div class="value">$<?=number_format($revenue,2)?></div>
          <div class="label">Total Revenue</div>
        </div>
        <div class="stat-card expense">
          <i class="fas fa-money-bill-wave"></i>
          <div class="value">$<?=number_format($expense,2)?></div>
          <div class="label">Total Expenses</div>
        </div>
        <div class="stat-card profit">
          <i class="fas fa-chart-line"></i>
          <div class="value">$<?=number_format($profit,2)?></div>
          <div class="label">Net Profit</div>
        </div>
        <div class="stat-card customers">
          <i class="fas fa-users"></i>
          <div class="value"><?=number_format($customerCount)?></div>
          <div class="label">Total Customers</div>
        </div>
      </div>
    </div>

    <div class="dashboard-section">
      <div class="grid">
        <div class="card">
          <h3>Revenue / Expense Chart</h3>
          <div class="chart-container">
            <canvas id="kpiChart"></canvas>
          </div>
        </div>
        
        <div class="card">
          <h3>Inventory Status</h3>
          <div class="chart-container">
            <canvas id="inventoryChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="dashboard-section">
      <div class="grid">
        <div class="card">
          <h4>Recent Notifications</h4>
          <?php foreach($recentNot as $n): ?>
            <div style="padding:10px;border-bottom:1px solid #f2f6fb">
              <div style="display:flex;justify-content:space-between">
                <div class="badge badge-<?= 
                  $n['type'] === 'alert' ? 'danger' : 
                  ($n['type'] === 'warning' ? 'warning' : 'info')
                ?>"><?=h($n['type'])?></div>
                <div class="muted"><?=h($n['created_at'])?></div>
              </div>
              <div><?=h($n['message'])?></div>
              <?php if(!$n['is_read']): ?>
                <form method="post" style="margin-top:10px">
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="nid" value="<?=h($n['id'])?>">
                  <button type="submit" class="btn-success">
                    <i class="fas fa-check"></i> Mark as read
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <div style="text-align:center;margin-top:15px">
            <a href="?page=notifications" class="btn">View All Notifications</a>
          </div>
        </div>

        <div class="card">
          <h4>Recent Sales</h4>
          <?php if (!$recentSales): ?>
            <div class="muted">No recent sales</div>
          <?php else: ?>
            <?php foreach($recentSales as $s): ?>
              <div style="padding:10px;border-bottom:1px solid #f2f6fb">
                <div style="display:flex;justify-content:space-between">
                  <strong>Sale #<?=h($s['id'])?></strong>
                  <span class="muted"><?=h($s['created_at'])?></span>
                </div>
                <div>Customer: <?=h($s['customer_name'] ?? 'Walk-in')?></div>
                <div>Amount: $<?=number_format($s['total_amount'], 2)?></div>
                <div>By: <?=h($s['username'])?></div>
              </div>
            <?php endforeach; ?>
            <div style="text-align:center;margin-top:15px">
              <a href="?page=sales" class="btn">View All Sales</a>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h4>Expiring Products (14 days)</h4>
          <?php
          $exp = $pdo->prepare("SELECT * FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) ORDER BY expiry_date ASC");
          $exp->execute();
          $exps = $exp->fetchAll();
          if (!$exps) echo "<div class='muted'>No products nearing expiry.</div>";
          foreach($exps as $p){
              $days = (int)ceil((strtotime($p['expiry_date']) - time())/86400);
              echo "<div style='padding:10px;border-bottom:1px solid #f2f6fb'>
                      <strong>".h($p['name'])."</strong> 
                      <div class='muted'>SKU: ".h($p['sku'])."</div>
                      <div>Exp: ".h($p['expiry_date'])." <span class='expiring'>in {$days} days</span></div>
                    </div>";
          }
          ?>
        </div>

        <div class="card">
          <h4>Quick Actions</h4>
          <div style="display:flex;flex-direction:column;gap:10px">
            <a href="?page=sales" class="btn"><i class="fas fa-shopping-cart"></i> New Sale</a>
            <a href="?page=products" class="btn"><i class="fas fa-box"></i> Manage Inventory</a>
            <a href="?page=customers" class="btn"><i class="fas fa-user-plus"></i> Add Customer</a>
            <?php if(is_admin()): ?>
              <a href="?page=users" class="btn"><i class="fas fa-user-cog"></i> Manage Users</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <script>
    // fetch kpis for chart
    fetch('?api=kpis').then(r=>r.json()).then(d=>{
        // Revenue/Expense chart
        const ctx = document.getElementById('kpiChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Revenue', 'Expense', 'Profit'],
                datasets: [{
                    label: 'Amount ($)',
                    data: [d.revenue, d.expense, d.profit],
                    backgroundColor: [
                        'rgba(67, 97, 238, 0.7)',
                        'rgba(247, 37, 133, 0.7)',
                        'rgba(76, 201, 240, 0.7)'
                    ],
                    borderColor: [
                        'rgba(67, 97, 238, 1)',
                        'rgba(247, 37, 133, 1)',
                        'rgba(76, 201, 240, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Inventory chart
        const invCtx = document.getElementById('inventoryChart').getContext('2d');
        new Chart(invCtx, {
            type: 'doughnut',
            data: {
                labels: ['Expiring Soon', 'Low Stock', 'In Stock'],
                datasets: [{
                    data: [d.expiring, d.low_stock, 100 - d.expiring - d.low_stock],
                    backgroundColor: [
                        'rgba(255, 159, 64, 0.7)',
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(75, 192, 192, 0.7)'
                    ],
                    borderColor: [
                        'rgba(255, 159, 64, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(75, 192, 192, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    });
    </script>
    <?php
    render_footer();
    exit;
}

// ---------- Products page ----------
if ($page === 'products') {
    require_login();
    render_header('Products');
    flash();
    
    // Get filter parameters
    $category_filter = $_GET['category'] ?? '';
    $low_stock = isset($_GET['low_stock']) ? true : false;
    
    // Build query with filters
    $query = "SELECT p.*, b.name as branch, c.name as category_name 
              FROM products p 
              LEFT JOIN branches b ON b.id = p.branch_id 
              LEFT JOIN categories c ON c.id = p.category_id 
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($category_filter)) {
        $query .= " AND p.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($low_stock) {
        $query .= " AND p.quantity < 10";
    }
    
    $query .= " ORDER BY p.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
    $categories = $pdo->query("SELECT * FROM categories")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Product</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <input name="sku" placeholder="SKU (optional)">
          </div>
          <div class="form-group">
            <input name="name" placeholder="Product Name" required>
          </div>
        </div>
        
        <textarea name="description" placeholder="Description (optional)"></textarea>
        
        <div class="form-row">
          <div class="form-group">
            <input name="quantity" type="number" placeholder="Quantity" min="0">
          </div>
          <div class="form-group">
            <input name="price" type="number" step="0.01" placeholder="Price">
          </div>
          <div class="form-group">
            <input name="cost" type="number" step="0.01" placeholder="Cost (optional)">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <select name="category_id">
              <option value="">Select Category</option>
              <?php foreach($categories as $c) echo "<option value='".h($c['id'])."'>".h($c['name'])."</option>"; ?>
            </select>
          </div>
          <div class="form-group">
            <input name="expiry_date" type="date" placeholder="Expiry Date (optional)">
          </div>
          <div class="form-group">
            <select name="branch_id">
              <option value="">Branch (optional)</option>
              <?php foreach($branches as $b) echo "<option value='".h($b['id'])."'>".h($b['name'])."</option>"; ?>
            </select>
          </div>
        </div>
        
        <input type="hidden" name="action" value="add_product">
        <button type="submit"><i class="fas fa-plus"></i> Add Product</button>
      </form>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:20px">
        <h3 style="margin:0">Products</h3>
        <div style="display:flex;gap:10px;align-items:center">
          <form method="get" style="display:flex;gap:10px;align-items:center">
            <input type="hidden" name="page" value="products">
            <select name="category" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php foreach($categories as $c): ?>
                <option value="<?=h($c['id'])?>" <?= $category_filter == $c['id'] ? 'selected' : '' ?>>
                  <?=h($c['name'])?>
                </option>
              <?php endforeach; ?>
            </select>
            <label>
              <input type="checkbox" name="low_stock" onchange="this.form.submit()" <?= $low_stock ? 'checked' : '' ?>> 
              Low Stock
            </label>
          </form>
          <?php if(is_admin()): ?>
          <form method="post">
            <input type="hidden" name="action" value="export">
            <input type="hidden" name="table" value="products">
            <button type="submit" class="btn-success">
              <i class="fas fa-file-export"></i> Export CSV
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Name</th>
              <th>Category</th>
              <th>Qty</th>
              <th>Price</th>
              <th>Cost</th>
              <th>Expiry</th>
              <th>Branch</th>
              <?php if(is_admin()): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach($products as $p): ?>
            <tr>
              <td><?=h($p['sku'])?></td>
              <td><?=h($p['name'])?></td>
              <td><?=h($p['category_name'])?></td>
              <td>
                <?=h($p['quantity'])?>
                <?php if ($p['quantity'] < 10): ?>
                  <span class="badge badge-danger">Low</span>
                <?php endif; ?>
              </td>
              <td>$<?=number_format($p['price'],2)?></td>
              <td>$<?=number_format($p['cost'] ?? 0,2)?></td>
              <td>
                <?=h($p['expiry_date'])?> 
                <?php
                  if ($p['expiry_date']) {
                      $days = (int)ceil((strtotime($p['expiry_date']) - time())/86400);
                      if ($days <= 14) echo "<span class='expiring'>in {$days}d</span>";
                  }
                ?>
              </td>
              <td><?=h($p['branch'])?></td>
              <?php if(is_admin()): ?>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="table" value="products">
                  <input type="hidden" name="id" value="<?=h($p['id'])?>">
                  <input type="hidden" name="back" value="products">
                  <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
      <?php if (empty($products)): ?>
        <div style="text-align:center;padding:20px;color:var(--muted)">
          <i class="fas fa-box-open" style="font-size:3rem;"></i>
          <p>No products found</p>
        </div>
      <?php endif; ?>
    </div>
    <?php render_footer(); exit;
}

// ---------- Sales Management ----------
if ($page === 'sales') {
    require_login();
    render_header('Sales');
    flash();
    
    // Get sales list
    $sales = $pdo->query("
      SELECT s.*, c.name as customer_name, u.username 
      FROM sales s 
      LEFT JOIN customers c ON c.id = s.customer_id 
      LEFT JOIN users u ON u.id = s.user_id 
      ORDER BY s.created_at DESC
    ")->fetchAll();
    
    // Get customers for dropdown
    $customers = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();
    ?>
    <div class="card">
      <h3>New Sale</h3>
      <form id="saleForm" method="post">
        <div class="form-row">
          <div class="form-group">
            <select name="customer_id" id="customer_id">
              <option value="">Select Customer (optional)</option>
              <?php foreach($customers as $c): ?>
                <option value="<?=h($c['id'])?>"><?=h($c['name'])?> - <?=h($c['phone'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <input type="text" id="product_search" placeholder="Search products..." autocomplete="off">
            <div id="product_results" style="display:none; position:absolute; background:white; border:1px solid #ddd; max-height:200px; overflow-y:auto; width:100%; z-index:1000;"></div>
          </div>
        </div>
        
        <div id="sale_items">
          <!-- Sale items will be added here dynamically -->
        </div>
        
        <div class="sale-summary">
          <div class="form-row">
            <div class="form-group">
              <label>Discount ($)</label>
              <input type="number" step="0.01" name="discount" id="discount" value="0.00">
            </div>
            <div class="form-group">
              <label>Tax ($)</label>
              <input type="number" step="0.01" name="tax" id="tax" value="0.00">
            </div>
            <div class="form-group">
              <label>Payment Method</label>
              <select name="payment_method">
                <option value="cash">Cash</option>
                <option value="card">Card</option>
                <option value="transfer">Transfer</option>
              </select>
            </div>
          </div>
          
          <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" placeholder="Sale notes..."></textarea>
          </div>
          
          <div style="text-align:right; margin-top:20px;">
            <h3>Total: $<span id="sale_total">0.00</span></h3>
          </div>
        </div>
        
        <input type="hidden" name="action" value="process_sale">
        <button type="submit" class="btn-success" style="margin-top:20px;">
          <i class="fas fa-check"></i> Complete Sale
        </button>
      </form>
    </div>
    
    <div class="card">
      <h3>Sales History</h3>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Amount</th>
              <th>Payment</th>
              <th>Salesperson</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($sales as $s): ?>
            <tr>
              <td><?=h($s['id'])?></td>
              <td><?=h($s['created_at'])?></td>
              <td><?=h($s['customer_name'] ?? 'Walk-in')?></td>
              <td>$<?=number_format($s['total_amount'], 2)?></td>
              <td><?=h($s['payment_method'])?></td>
              <td><?=h($s['username'])?></td>
              <td>
                <a href="?page=sale_details&id=<?=h($s['id'])?>" class="btn">
                  <i class="fas fa-eye"></i> View
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const productSearch = document.getElementById('product_search');
      const productResults = document.getElementById('product_results');
      const saleItems = document.getElementById('sale_items');
      const saleTotal = document.getElementById('sale_total');
      const discount = document.getElementById('discount');
      const tax = document.getElementById('tax');
      
      let items = [];
      
      // Product search functionality
      productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length < 2) {
          productResults.style.display = 'none';
          return;
        }
        
        fetch(`?api=product_search&q=${encodeURIComponent(query)}`)
          .then(response => response.json())
          .then(products => {
            if (products.length === 0) {
              productResults.innerHTML = '<div class="muted" style="padding:10px;">No products found</div>';
              productResults.style.display = 'block';
              return;
            }
            
            productResults.innerHTML = products.map(product => `
              <div style="padding:10px; border-bottom:1px solid #eee; cursor:pointer;" 
                   onclick="addProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', ${product.price}, ${product.quantity})">
                <strong>${product.name}</strong> - $${product.price.toFixed(2)}
                <div class="muted">Stock: ${product.quantity}</div>
              </div>
            `).join('');
            productResults.style.display = 'block';
          });
      });
      
      // Close search results when clicking outside
      document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !productResults.contains(e.target)) {
          productResults.style.display = 'none';
        }
      });
      
      // Add product to sale
      window.addProduct = function(id, name, price, stock) {
        // Check if product already exists in items
        const existingItem = items.find(item => item.product_id === id);
        if (existingItem) {
          if (existingItem.quantity >= stock) {
            alert('Cannot add more than available stock');
            return;
          }
          existingItem.quantity += 1;
          existingItem.total = existingItem.quantity * existingItem.unit_price;
          renderItems();
          return;
        }
        
        items.push({
          product_id: id,
          name: name,
          quantity: 1,
          unit_price: price,
          total: price,
          stock: stock
        });
        
        productSearch.value = '';
        productResults.style.display = 'none';
        renderItems();
      };
      
      // Remove product from sale
      window.removeItem = function(index) {
        items.splice(index, 1);
        renderItems();
      };
      
      // Update item quantity
      window.updateQuantity = function(index, change) {
        const item = items[index];
        const newQuantity = item.quantity + change;
        
        if (newQuantity < 1) return;
        if (newQuantity > item.stock) {
          alert('Cannot add more than available stock');
          return;
        }
        
        item.quantity = newQuantity;
        item.total = item.quantity * item.unit_price;
        renderItems();
      };
      
      // Render sale items
      function renderItems() {
        if (items.length === 0) {
          saleItems.innerHTML = '<div class="muted" style="text-align:center;padding:20px;">No items added to sale</div>';
          saleTotal.textContent = '0.00';
          return;
        }
        
        saleItems.innerHTML = items.map((item, index) => `
          <div class="sale-item">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <strong>${item.name}</strong>
                <div class="muted">$${item.unit_price.toFixed(2)} each</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;">
                <button type="button" onclick="updateQuantity(${index}, -1)" class="btn-danger">-</button>
                <span>${item.quantity}</span>
                <button type="button" onclick="updateQuantity(${index}, 1)" class="btn-success">+</button>
                <span style="font-weight:bold;">$${item.total.toFixed(2)}</span>
                <button type="button" onclick="removeItem(${index})" class="btn-danger">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          </div>
        `).join('');
        
        calculateTotal();
      }
      
      // Calculate sale total
      function calculateTotal() {
        const subtotal = items.reduce((sum, item) => sum + item.total, 0);
        const discountVal = parseFloat(discount.value) || 0;
        const taxVal = parseFloat(tax.value) || 0;
        const total = subtotal - discountVal + taxVal;
        
        saleTotal.textContent = total.toFixed(2);
      }
      
      // Update total when discount or tax changes
      discount.addEventListener('input', calculateTotal);
      tax.addEventListener('input', calculateTotal);
      
      // Form submission
      document.getElementById('saleForm').addEventListener('submit', function(e) {
        if (items.length === 0) {
          e.preventDefault();
          alert('Please add at least one item to the sale');
          return;
        }
        
        // Add hidden inputs for each item
        items.forEach((item, index) => {
          const productInput = document.createElement('input');
          productInput.type = 'hidden';
          productInput.name = `items[${index}][product_id]`;
          productInput.value = item.product_id;
          
          const quantityInput = document.createElement('input');
          quantityInput.type = 'hidden';
          quantityInput.name = `items[${index}][quantity]`;
          quantityInput.value = item.quantity;
          
          this.appendChild(productInput);
          this.appendChild(quantityInput);
        });
      });
      
      // Initial render
      renderItems();
    });
    </script>
    <?php render_footer(); exit;
}

// ---------- Sale Details ----------
if ($page === 'sale_details' && isset($_GET['id'])) {
    require_login();
    
    $sale_id = (int)$_GET['id'];
    $sale = $pdo->prepare("
      SELECT s.*, c.name as customer_name, c.phone as customer_phone, u.username 
      FROM sales s 
      LEFT JOIN customers c ON c.id = s.customer_id 
      LEFT JOIN users u ON u.id = s.user_id 
      WHERE s.id = ?
    ");
    $sale->execute([$sale_id]);
    $sale = $sale->fetch();
    
    if (!$sale) {
        $_SESSION['error'] = "Sale not found";
        header('Location: ?page=sales');
        exit;
    }
    
    $items = $pdo->prepare("
      SELECT si.*, p.name as product_name, p.sku 
      FROM sale_items si 
      JOIN products p ON p.id = si.product_id 
      WHERE si.sale_id = ?
    ");
    $items->execute([$sale_id]);
    $items = $items->fetchAll();
    
    render_header('Sale Details');
    flash();
    ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
          <h3>Sale #<?=h($sale['id'])?></h3>
          <p class="muted">Date: <?=h($sale['created_at'])?></p>
        </div>
        <div style="text-align:right;">
          <div class="badge badge-<?= $sale['status'] === 'completed' ? 'success' : ($sale['status'] === 'pending' ? 'warning' : 'danger') ?>">
            <?=h($sale['status'])?>
          </div>
          <div class="sale-total">$<?=number_format($sale['total_amount'], 2)?></div>
        </div>
      </div>
      
      <div class="form-row" style="margin-top:20px;">
        <div class="form-group">
          <label>Customer</label>
          <div><?=h($sale['customer_name'] ?? 'Walk-in Customer')?></div>
          <?php if($sale['customer_phone']): ?>
            <div class="muted"><?=h($sale['customer_phone'])?></div>
          <?php endif; ?>
        </div>
        
        <div class="form-group">
          <label>Salesperson</label>
          <div><?=h($sale['username'])?></div>
        </div>
        
        <div class="form-group">
          <label>Payment Method</label>
          <div><?=h($sale['payment_method'])?></div>
        </div>
      </div>
      
      <?php if($sale['notes']): ?>
        <div class="form-group">
          <label>Notes</label>
          <div><?=h($sale['notes'])?></div>
        </div>
      <?php endif; ?>
    </div>
    
    <div class="card">
      <h4>Sale Items</h4>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU</th>
              <th>Quantity</th>
              <th>Unit Price</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($items as $item): ?>
            <tr>
              <td><?=h($item['product_name'])?></td>
              <td><?=h($item['sku'])?></td>
              <td><?=h($item['quantity'])?></td>
              <td>$<?=number_format($item['unit_price'], 2)?></td>
              <td>$<?=number_format($item['total_price'], 2)?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" style="text-align:right; font-weight:bold;">Subtotal:</td>
              <td>$<?=number_format($sale['total_amount'] + $sale['discount'] - $sale['tax'], 2)?></td>
            </tr>
            <?php if($sale['discount'] > 0): ?>
            <tr>
              <td colspan="4" style="text-align:right; font-weight:bold;">Discount:</td>
              <td>-$<?=number_format($sale['discount'], 2)?></td>
            </tr>
            <?php endif; ?>
            <?php if($sale['tax'] > 0): ?>
            <tr>
              <td colspan="4" style="text-align:right; font-weight:bold;">Tax:</td>
              <td>$<?=number_format($sale['tax'], 2)?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <td colspan="4" style="text-align:right; font-weight:bold;">Total:</td>
              <td>$<?=number_format($sale['total_amount'], 2)?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    
    <div style="text-align:center; margin-top:20px;">
      <button onclick="window.print()" class="btn">
        <i class="fas fa-print"></i> Print Receipt
      </button>
      <a href="?page=sales" class="btn">
        <i class="fas fa-arrow-left"></i> Back to Sales
      </a>
    </div>
    <?php render_footer(); exit;
}

// ---------- Employees ----------
if ($page === 'employees') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('Employees');
    flash();
    
    $rows = $pdo->query("
      SELECT e.*, u.username, u.fullname, u.role, b.name as branch_name 
      FROM employees e 
      JOIN users u ON u.id=e.user_id 
      LEFT JOIN branches b ON b.id = u.branch_id 
      ORDER BY e.created_at DESC
    ")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Employee</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <input name="username" placeholder="Username" required>
          </div>
          <div class="form-group">
            <input name="password" type="password" placeholder="Password" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <input name="fullname" placeholder="Full Name">
          </div>
          <div class="form-group">
            <input name="position" placeholder="Position">
          </div>
        </div>
        
        <input type="hidden" name="role" value="employee">
        <input type="hidden" name="action" value="add_user">
        <button type="submit"><i class="fas fa-user-plus"></i> Create Employee</button>
      </form>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:20px">
        <h3 style="margin:0">Employee List</h3>
        <form method="post">
          <input type="hidden" name="action" value="export">
          <input type="hidden" name="table" value="employees">
          <button type="submit" class="btn-success">
            <i class="fas fa-file-export"></i> Export CSV
          </button>
        </form>
      </div>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Username</th>
              <th>Full Name</th>
              <th>Position</th>
              <th>Role</th>
              <th>Branch</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['username'])?></td>
              <td><?=h($r['fullname'])?></td>
              <td><?=h($r['position'])?></td>
              <td>
                <span class="badge <?= $r['role'] === 'admin' ? 'badge-info' : 'badge-success' ?>">
                  <?=h($r['role'])?>
                </span>
              </td>
              <td><?=h($r['branch_name'])?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="table" value="employees">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="back" value="employees">
                  <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}


// ---------- Customers ----------
if ($page === 'customers') {
    require_login();
    render_header('Customers');
    flash();
    
    $rows = $pdo->query("SELECT * FROM customers ORDER BY created_at DESC")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Customer</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <input name="name" placeholder="Name" required>
          </div>
          <div class="form-group">
            <input name="phone" placeholder="Phone">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <input name="email" type="email" placeholder="Email">
          </div>
          <div class="form-group">
            <textarea name="address" placeholder="Address"></textarea>
          </div>
        </div>
        
        <input type="hidden" name="action" value="add_customer">
        <button type="submit"><i class="fas fa-user-plus"></i> Add Customer</button>
      </form>
    </div>
    
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:20px">
        <h3 style="margin:0">Customers</h3>
        <?php if(is_admin()): ?>
        <form method="post">
          <input type="hidden" name="action" value="export">
          <input type="hidden" name="table" value="customers">
          <button type="submit" class="btn-success">
            <i class="fas fa-file-export"></i> Export CSV
          </button>
        </form>
        <?php endif; ?>
      </div>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Loyalty Points</th>
              <?php if(is_admin()): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['phone'])?></td>
              <td><?=h($r['email'])?></td>
              <td>
                <span class="badge badge-info"><?=h($r['loyalty_points'])?> pts</span>
              </td>
              <?php if(is_admin()): ?>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="table" value="customers">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="back" value="customers">
                  <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Projects ----------
if ($page === 'projects') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('Projects');
    flash();
    
    $rows = $pdo->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Project</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <input name="title" placeholder="Title" required>
          </div>
          <div class="form-group">
            <input name="budget" type="number" step="0.01" placeholder="Budget">
          </div>
        </div>
        
        <textarea name="description" placeholder="Description"></textarea>
        
        <div class="form-row">
          <div class="form-group">
            <select name="status">
              <option value="pending">Pending</option>
              <option value="ongoing">Ongoing</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="form-group">
            <input type="date" name="start_date" placeholder="Start Date">
          </div>
          <div class="form-group">
            <input type="date" name="end_date" placeholder="End Date">
          </div>
        </div>
        
        <input type="hidden" name="action" value="add_project">
        <button type="submit"><i class="fas fa-plus"></i> Add Project</button>
      </form>
    </div>
    
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:20px">
        <h3 style="margin:0">Projects</h3>
        <form method="post">
          <input type="hidden" name="action" value="export">
          <input type="hidden" name="table" value="projects">
          <button type="submit" class="btn-success">
            <i class="fas fa-file-export"></i> Export CSV
          </button>
        </form>
      </div>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Title</th>
              <th>Status</th>
              <th>Budget</th>
              <th>Dates</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['title'])?></td>
              <td>
                <span class="badge 
                  <?= $r['status'] === 'completed' ? 'badge-success' : 
                    ($r['status'] === 'ongoing' ? 'badge-info' : 'badge-warning') ?>">
                  <?=h($r['status'])?>
                </span>
              </td>
              <td>$<?=number_format($r['budget'], 2)?></td>
              <td>
                <?=h($r['start_date'])?> to <?=h($r['end_date'])?>
              </td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="table" value="projects">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="back" value="projects">
                  <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Transactions ----------
if ($page === 'transactions') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('Transactions');
    flash();
    
    $rows = $pdo->query("SELECT * FROM transactions ORDER BY created_at DESC")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Transaction</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <select name="type">
              <option value="revenue">Revenue</option>
              <option value="expense">Expense</option>
            </select>
          </div>
          <div class="form-group">
            <input name="amount" type="number" step="0.01" placeholder="Amount" required>
          </div>
          <div class="form-group">
            <select name="currency">
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
              <option value="GBP">GBP</option>
              <option value="SLSH">SLSH</option>
            </select>
          </div>
        </div>
        
        <textarea name="description" placeholder="Description"></textarea>
        
        <input type="hidden" name="action" value="add_transaction">
        <button type="submit"><i class="fas fa-plus"></i> Add Transaction</button>
      </form>
    </div>
    
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:20px">
        <h3 style="margin:0">Transactions</h3>
        <form method="post">
          <input type="hidden" name="action" value="export">
          <input type="hidden" name="table" value="transactions">
          <button type="submit" class="btn-success">
            <i class="fas fa-file-export"></i> Export CSV
          </button>
        </form>
      </div>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Type</th>
              <th>Amount</th>
              <th>Currency</th>
              <th>Reference</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td>
                <span class="badge <?= $r['type'] === 'revenue' ? 'badge-success' : 'badge-danger' ?>">
                  <?=h($r['type'])?>
                </span>
              </td>
              <td>$<?=number_format($r['amount'],2)?></td>
              <td><?=h($r['currency'])?></td>
              <td><?=h($r['reference'])?></td>
              <td><?=h($r['created_at'])?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Notifications ----------
if ($page === 'notifications') {
    require_login();
    render_header('Notifications');
    flash();
    
    $rows = $pdo->query("SELECT n.*, u.username FROM notifications n LEFT JOIN users u ON u.id=n.user_id ORDER BY created_at DESC")->fetchAll();
    ?>
    <div class="card">
      <h3>Send Notification</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <select name="user_id">
              <option value="">All Users</option>
              <?php foreach($pdo->query("SELECT id,username FROM users")->fetchAll() as $u) echo "<option value='".h($u['id'])."'>".h($u['username'])."</option>"; ?>
            </select>
          </div>
          <div class="form-group">
            <select name="type">
              <option value="info">Info</option>
              <option value="alert">Alert</option>
              <option value="warning">Warning</option>
            </select>
          </div>
        </div>
        
        <textarea name="message" placeholder="Message" required></textarea>
        
        <input type="hidden" name="action" value="add_notification">
        <button type="submit"><i class="fas fa-paper-plane"></i> Send Notification</button>
      </form>
    </div>
    
    <div class="card">
      <h3>Notifications</h3>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Message</th>
              <th>Type</th>
              <th>Read</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['username'] ?? 'All')?></td>
              <td><?=h($r['message'])?></td>
              <td>
                <span class="badge 
                  <?= $r['type'] === 'alert' ? 'badge-danger' : 
                    ($r['type'] === 'warning' ? 'badge-warning' : 'badge-info') ?>">
                  <?=h($r['type'])?>
                </span>
              </td>
              <td>
                <?= $r['is_read'] ? 
                  '<span class="badge badge-success">Yes</span>' : 
                  '<span class="badge badge-warning">No</span>' ?>
              </td>
              <td><?=h($r['created_at'])?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Documents ----------
if ($page === 'documents') {
    require_login();
    render_header('Documents');
    flash();
    
    $rows = $pdo->query("SELECT d.*, u.username 
                         FROM documents d 
                         LEFT JOIN users u ON u.id = d.uploaded_by 
                         ORDER BY d.created_at DESC")->fetchAll();
    ?>
    <div class="card">
      <h3>Upload Document</h3>
      <form method="post" enctype="multipart/form-data">
        <div class="form-group">
          <input type="file" name="doc" required>
          <div class="muted">Max file size: 5MB. Allowed types: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG</div>
        </div>
        
        <input type="hidden" name="action" value="upload_document">
        <button type="submit"><i class="fas fa-upload"></i> Upload</button>
      </form>
    </div>
    
    <div class="card">
      <h3>Files</h3>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Filename</th>
              <th>Uploaded By</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['id'])?></td>
              <td><?=h($r['filename'])?></td>
              <td><?=h($r['username'])?></td>
              <td><?=h($r['created_at'])?></td>
              <td>
                <a href="<?=h($r['filepath'])?>" download class="btn btn-success">
                  <i class="fas fa-download"></i>
                </a>
                <?php if(is_admin()): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="table" value="documents">
                    <input type="hidden" name="id" value="<?=h($r['id'])?>">
                    <input type="hidden" name="back" value="documents">
                    <button type="submit" class="btn-danger">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Calendar ----------
if ($page === 'calendar') {
    require_login();
    render_header('Calendar');
    flash();
    
    $rows = $pdo->query("SELECT * FROM calendar_events ORDER BY event_date ASC")->fetchAll();
    ?>
    <div class="card">
      <h3>Add Event</h3>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <input name="title" placeholder="Title" required>
          </div>
          <div class="form-group">
            <input type="date" name="event_date" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <input type="time" name="start_time" placeholder="Start Time">
          </div>
          <div class="form-group">
            <input type="time" name="end_time" placeholder="End Time">
          </div>
        </div>
        
        <textarea name="description" placeholder="Description"></textarea>
        
        <input type="hidden" name="action" value="add_event">
        <button type="submit"><i class="fas fa-plus"></i> Add Event</button>
      </form>
    </div>
    
    <div class="card">
      <h3>Upcoming Events</h3>
      
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Time</th>
              <th>Title</th>
              <?php if(is_admin()): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['event_date'])?></td>
              <td>
                <?= $r['start_time'] ? h($r['start_time']) : 'All day' ?>
                <?= $r['end_time'] ? ' - ' . h($r['end_time']) : '' ?>
              </td>
              <td><?=h($r['title'])?></td>
              <?php if(is_admin()): ?>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="table" value="calendar_events">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="back" value="calendar">
                                    <button type="submit" class="btn-danger">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Users (Admin Only) ----------
if ($page === 'users') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('User Management');
    flash();
    
    $rows = $pdo->query("
        SELECT u.*, b.name as branch_name 
        FROM users u 
        LEFT JOIN branches b ON b.id = u.branch_id 
        ORDER BY u.created_at DESC
    ")->fetchAll();
    
    $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
    ?>
    <div class="card">
        <h3>Add User</h3>
        <form method="post">
            <div class="form-row">
                <div class="form-group">
                    <input name="username" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <input name="password" type="password" placeholder="Password" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <input name="fullname" placeholder="Full Name">
                </div>
                <div class="form-group">
                    <select name="role">
                        <option value="employee">Employee</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="branch_id">
                        <option value="">Select Branch</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?=h($b['id'])?>"><?=h($b['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <input type="hidden" name="action" value="add_user">
            <button type="submit"><i class="fas fa-user-plus"></i> Add User</button>
        </form>
    </div>

    <div class="card">
        <h3>User List</h3>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?=h($r['id'])?></td>
                        <td><?=h($r['username'])?></td>
                        <td><?=h($r['fullname'])?></td>
                        <td>
                            <span class="badge <?= $r['role'] === 'admin' ? 'badge-info' : 'badge-success' ?>">
                                <?=h($r['role'])?>
                            </span>
                        </td>
                        <td><?=h($r['branch_name'])?></td>
                        <td><?=h($r['last_login'])?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="table" value="users">
                                <input type="hidden" name="id" value="<?=h($r['id'])?>">
                                <input type="hidden" name="back" value="users">
                                <button type="submit" class="btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Branches (Admin Only) ----------
if ($page === 'branches') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('Branch Management');
    flash();
    
    $rows = $pdo->query("SELECT * FROM branches ORDER BY created_at DESC")->fetchAll();
    ?>
    <div class="card">
        <h3>Add Branch</h3>
        <form method="post">
            <div class="form-row">
                <div class="form-group">
                    <input name="name" placeholder="Branch Name" required>
                </div>
                <div class="form-group">
                    <input name="phone" placeholder="Phone">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <input name="email" type="email" placeholder="Email">
                </div>
            </div>
            
            <div class="form-group">
                <textarea name="address" placeholder="Address"></textarea>
            </div>
            
            <input type="hidden" name="action" value="add_branch">
            <button type="submit"><i class="fas fa-plus"></i> Add Branch</button>
        </form>
    </div>

    <div class="card">
        <h3>Branch List</h3>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?=h($r['id'])?></td>
                        <td><?=h($r['name'])?></td>
                        <td><?=h($r['phone'])?></td>
                        <td><?=h($r['email'])?></td>
                        <td><?=h($r['address'])?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="table" value="branches">
                                <input type="hidden" name="id" value="<?=h($r['id'])?>">
                                <input type="hidden" name="back" value="branches">
                                <button type="submit" class="btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Reports (Admin Only) ----------
if ($page === 'reports') {
    require_login();
    if (!is_admin()) {
        $_SESSION['error'] = "Access denied. Admin only.";
        header('Location: ?page=dashboard');
        exit;
    }
    
    render_header('Reports');
    flash();
    
    // Get report data
    $topProducts = $pdo->query("
        SELECT p.name, SUM(si.quantity) as total_sold, SUM(si.total_price) as total_revenue
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        GROUP BY si.product_id
        ORDER BY total_sold DESC
        LIMIT 10
    ")->fetchAll();
    
    $salesByMonth = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
               COUNT(*) as sales_count, 
               SUM(total_amount) as total_revenue
        FROM sales
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ")->fetchAll();
    ?>
    <div class="card">
        <h3>Sales Reports</h3>
        
        <div class="grid">
            <div class="card">
                <h4>Top Selling Products</h4>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Units Sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($topProducts as $p): ?>
                            <tr>
                                <td><?=h($p['name'])?></td>
                                <td><?=h($p['total_sold'])?></td>
                                <td>$<?=number_format($p['total_revenue'], 2)?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <h4>Monthly Sales</h4>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Sales Count</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($salesByMonth as $s): ?>
                            <tr>
                                <td><?=h($s['month'])?></td>
                                <td><?=h($s['sales_count'])?></td>
                                <td>$<?=number_format($s['total_revenue'], 2)?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 20px;">
            <h4>Export Reports</h4>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <form method="post">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="table" value="sales">
                    <button type="submit" class="btn">
                        <i class="fas fa-file-export"></i> Export Sales
                    </button>
                </form>
                
                <form method="post">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="table" value="products">
                    <button type="submit" class="btn">
                        <i class="fas fa-file-export"></i> Export Products
                    </button>
                </form>
                
                <form method="post">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="table" value="customers">
                    <button type="submit" class="btn">
                        <i class="fas fa-file-export"></i> Export Customers
                    </button>
                </form>
                
                <form method="post">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="table" value="transactions">
                    <button type="submit" class="btn">
                        <i class="fas fa-file-export"></i> Export Transactions
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php render_footer(); exit;
}

// ---------- Default: Home ----------
if ($page === 'home') {
    if (is_logged_in()) {
        header('Location: ?page=dashboard');
        exit;
    }
    header('Location: ?page=login');
    exit;
}

// ---------- 404: Page Not Found ----------
render_header('Page Not Found');
?>
<div class="card" style="text-align:center; padding:40px 20px;">
    <i class="fas fa-exclamation-triangle" style="font-size:4rem;color:#ffc107;"></i>
    <h2>Page Not Found</h2>
    <p>The requested page could not be found.</p>
    <a href="?page=dashboard" class="btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>
<?php
render_footer();
exit;