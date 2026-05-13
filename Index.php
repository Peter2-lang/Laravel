<?php
session_start();

// --- DATABASE CONFIGURATION ---
$db_file = 'boarding_house.db';
try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- DATABASE INITIALIZATION (CREATE TABLES) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS property (id INTEGER PRIMARY KEY, name TEXT, address TEXT, manager TEXT, phone TEXT, notes TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS rooms (id INTEGER PRIMARY KEY, name TEXT, type TEXT, monthly_rate REAL, capacity INTEGER, status TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tenants (id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone TEXT, room_id INTEGER, start_date TEXT, monthly_rent REAL, account_id INTEGER)");
$pdo->exec("CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY, role TEXT, username TEXT, password TEXT, tenant_id INTEGER)");
$pdo->exec("CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY, tenant_id INTEGER, amount REAL, method TEXT, reference TEXT, date TEXT, status TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS reports (id INTEGER PRIMARY KEY, tenant_id INTEGER, category TEXT, title TEXT, details TEXT, date TEXT, status TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS schedules (id INTEGER PRIMARY KEY, title TEXT, date TEXT, time TEXT, category TEXT, details TEXT, visible_to_tenants INTEGER)");

// SEED INITIAL DATA (If empty)
$stmt = $pdo->query("SELECT COUNT(*) FROM accounts");
if ($stmt->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO property (name, address, manager, phone, notes) VALUES ('Madaje\'s Boarding House', 'San Pedro Street, Hinunangan Southern Leyte', 'Roberto Madaje Jr.', '09123456789', 'Quiet and clean.')");
    $pdo->exec("INSERT INTO accounts (role, username, password) VALUES ('admin', 'admin', 'admin123')");
    $pdo->exec("INSERT INTO rooms (name, type, monthly_rate, capacity, status) VALUES ('Room 101', 'Solo', 4500, 1, 'Available'), ('Room 102', 'Double', 3800, 2, 'Available')");
}

// --- HELPER FUNCTIONS ---
function formatMoney($val) { return "PHP " . number_format($val, 2); }
function formatDate($val) { return date("M d, Y", strtotime($val)); }

function getBillingMonths($startDate) {
    $start = new DateTime($startDate);
    $now = new DateTime();
    $diff = $start->diff($now);
    $months = ($diff->y * 12) + $diff->m;
    if ($now->format('d') >= $start->format('d')) $months++;
    return max(1, $months);
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                $stmt = $pdo->prepare("SELECT * FROM accounts WHERE username = ? AND password = ?");
                $stmt->execute([$_POST['username'], $_POST['password']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                } else {
                    $login_error = "Invalid credentials.";
                }
                break;

            case 'logout':
                session_destroy();
                header("Location: index.php");
                exit;

            case 'add_tenant':
                $pdo->prepare("INSERT INTO tenants (name, email, phone, room_id, start_date, monthly_rent) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_POST['name'], $_POST['email'], $_POST['phone'], $_POST['room_id'], $_POST['start_date'], $_POST['monthly_rent']]);
                $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?")->execute([$_POST['room_id']]);
                break;

            case 'add_payment':
                $pdo->prepare("INSERT INTO payments (tenant_id, amount, method, reference, date, status) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_POST['tenant_id'], $_POST['amount'], $_POST['method'], $_POST['reference'], date('Y-m-d'), 'Pending']);
                break;
            
            case 'verify_payment':
                $pdo->prepare("UPDATE payments SET status = 'Verified' WHERE id = ?")->execute([$_POST['id']]);
                break;

            case 'add_report':
                $pdo->prepare("INSERT INTO reports (tenant_id, category, title, details, date, status) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_POST['tenant_id'], $_POST['category'], $_POST['title'], $_POST['details'], date('Y-m-d'), 'Open']);
                break;
        }
        if (!isset($login_error)) { header("Location: index.php"); exit; }
    }
}

// --- DATA FETCHING ---
$property = $pdo->query("SELECT * FROM property LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = null;
$tenant = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user['role'] === 'tenant') {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$user['tenant_id']]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll(PDO::FETCH_ASSOC);
$tenants = $pdo->query("SELECT * FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
$payments = $pdo->query("SELECT p.*, t.name as tenant_name FROM payments p JOIN tenants t ON p.tenant_id = t.id ORDER BY date DESC")->fetchAll(PDO::FETCH_ASSOC);
$reports = $pdo->query("SELECT r.*, t.name as tenant_name FROM reports r JOIN tenants t ON r.tenant_id = t.id ORDER BY date DESC")->fetchAll(PDO::FETCH_ASSOC);

$page = $_GET['tab'] ?? 'Overview';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $property['name']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f7efe3; color: #1c1917; }
        .btn-primary { @apply bg-stone-950 text-white px-5 py-2.5 rounded-2xl font-semibold hover:bg-amber-800 transition; }
        .input { @apply w-full rounded-2xl border border-stone-200 bg-white px-4 py-2.5 outline-none focus:ring-4 focus:ring-amber-700/10; }
        @media print { .no-print { display: none; } .print-only { display: block !important; } }
    </style>
</head>
<body class="min-h-screen">

<?php if (!$user): ?>
    <!-- LOGIN VIEW -->
    <div class="flex items-center justify-center min-h-screen p-6">
        <div class="w-full max-w-md bg-white/80 backdrop-blur-xl p-8 rounded-[2.5rem] shadow-2xl border border-white">
            <h1 class="text-3xl font-black mb-2"><?php echo $property['name']; ?></h1>
            <p class="text-stone-500 mb-6">Secure Portal Access</p>
            
            <?php if (isset($login_error)): ?>
                <div class="bg-rose-50 text-rose-700 p-4 rounded-2xl mb-4 text-sm"><?php echo $login_error; ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="login">
                <div>
                    <label class="text-sm font-semibold block mb-1">Username</label>
                    <input type="text" name="username" class="input" required>
                </div>
                <div>
                    <label class="text-sm font-semibold block mb-1">Password</label>
                    <input type="password" name="password" class="input" required>
                </div>
                <button type="submit" class="btn-primary w-full py-4 text-lg">Sign In</button>
            </form>
            <div class="mt-6 text-xs text-stone-400 text-center">
                Default Admin: admin / admin123
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- APP SHELL -->
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-72 bg-white/50 backdrop-blur-xl border-r border-stone-200 p-6 no-print hidden lg:block">
            <div class="bg-stone-950 p-6 rounded-[2rem] text-white mb-8">
                <p class="text-[10px] uppercase tracking-widest opacity-50"><?php echo strtoupper($user['role']); ?> PORTAL</p>
                <h2 class="text-xl font-bold mt-2 leading-tight"><?php echo $property['name']; ?></h2>
            </div>
            
            <nav class="space-y-2">
                <?php 
                $tabs = $user['role'] === 'admin' 
                    ? ['Overview', 'Tenants', 'Rooms', 'Payments', 'Reports'] 
                    : ['Dashboard', 'Payments', 'Reports'];
                
                foreach ($tabs as $t): ?>
                    <a href="?tab=<?php echo $t; ?>" class="block px-5 py-3 rounded-2xl font-semibold <?php echo $page === $t ? 'bg-amber-800 text-white' : 'hover:bg-white text-stone-600'; ?>">
                        <?php echo $t; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="absolute bottom-8 left-6 right-6">
                <form method="POST">
                    <input type="hidden" name="action" value="logout">
                    <button class="w-full border border-stone-200 py-3 rounded-2xl font-semibold text-stone-600 hover:bg-white transition">Log Out</button>
                </form>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 lg:p-10">
            <header class="mb-10 no-print">
                <p class="text-amber-800 font-bold uppercase tracking-widest text-sm"><?php echo $page; ?></p>
                <h1 class="text-4xl font-black mt-2">Welcome, <?php echo $user['username']; ?></h1>
            </header>

            <?php if ($page === 'Overview' && $user['role'] === 'admin'): ?>
                <!-- ADMIN OVERVIEW -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                    <div class="bg-white p-8 rounded-[2rem] shadow-sm">
                        <p class="text-stone-500 font-semibold">Total Tenants</p>
                        <p class="text-4xl font-black mt-2"><?php echo count($tenants); ?></p>
                    </div>
                    <div class="bg-white p-8 rounded-[2rem] shadow-sm">
                        <p class="text-stone-500 font-semibold">Available Rooms</p>
                        <?php 
                        $avail = array_filter($rooms, fn($r) => $r['status'] === 'Available');
                        ?>
                        <p class="text-4xl font-black mt-2"><?php echo count($avail); ?></p>
                    </div>
                    <div class="bg-white p-8 rounded-[2rem] shadow-sm">
                        <p class="text-stone-500 font-semibold">Open Reports</p>
                        <?php 
                        $openR = array_filter($reports, fn($r) => $r['status'] === 'Open');
                        ?>
                        <p class="text-4xl font-black mt-2 text-rose-600"><?php echo count($openR); ?></p>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm">
                    <h3 class="text-xl font-bold mb-6">Recent Payments</h3>
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-stone-400 text-xs uppercase tracking-widest">
                                <th class="pb-4">Tenant</th>
                                <th class="pb-4">Amount</th>
                                <th class="pb-4">Status</th>
                                <th class="pb-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td class="py-4 font-semibold"><?php echo $p['tenant_name']; ?></td>
                                    <td class="py-4"><?php echo formatMoney($p['amount']); ?></td>
                                    <td class="py-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $p['status'] === 'Verified' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'; ?>">
                                            <?php echo $p['status']; ?>
                                        </span>
                                    </td>
                                    <td class="py-4">
                                        <?php if ($p['status'] === 'Pending'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="verify_payment">
                                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                                <button class="text-sm font-bold text-amber-800">Verify</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($page === 'Tenants' && $user['role'] === 'admin'): ?>
                <!-- TENANT MANAGEMENT -->
                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm mb-10">
                    <h3 class="text-xl font-bold mb-6">Register New Tenant</h3>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <input type="hidden" name="action" value="add_tenant">
                        <input type="text" name="name" placeholder="Full Name" class="input" required>
                        <input type="email" name="email" placeholder="Email" class="input" required>
                        <input type="text" name="phone" placeholder="Phone" class="input">
                        <select name="room_id" class="input">
                            <?php foreach ($rooms as $r): if ($r['status'] === 'Available'): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['name']; ?> (<?php echo formatMoney($r['monthly_rate']); ?>)</option>
                            <?php endif; endforeach; ?>
                        </select>
                        <input type="date" name="start_date" class="input" value="<?php echo date('Y-m-d'); ?>">
                        <input type="number" name="monthly_rent" placeholder="Agreed Rent" class="input" required>
                        <button type="submit" class="btn-primary md:col-span-3">Register Tenant</button>
                    </form>
                </div>

            <?php elseif ($page === 'Dashboard' && $user['role'] === 'tenant'): ?>
                <!-- TENANT DASHBOARD -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-stone-950 text-white p-8 rounded-[2.5rem]">
                        <p class="opacity-50 text-sm uppercase tracking-widest">Current Balance</p>
                        <?php 
                        $months = getBillingMonths($tenant['start_date']);
                        $total_due = $months * $tenant['monthly_rent'];
                        
                        $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE tenant_id = ? AND status = 'Verified'");
                        $stmt->execute([$tenant['id']]);
                        $paid = $stmt->fetchColumn() ?: 0;
                        
                        $balance = $total_due - $paid;
                        ?>
                        <h2 class="text-5xl font-black mt-4"><?php echo formatMoney($balance); ?></h2>
                        <p class="mt-4 opacity-70">Total billing for <?php echo $months; ?> month(s).</p>
                    </div>

                    <div class="bg-white p-8 rounded-[2.5rem]">
                        <h3 class="text-xl font-bold mb-4">Submit Payment Notice</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="add_payment">
                            <input type="hidden" name="tenant_id" value="<?php echo $tenant['id']; ?>">
                            <input type="number" name="amount" placeholder="Amount" class="input" required>
                            <select name="method" class="input">
                                <option>GCash</option>
                                <option>Cash</option>
                                <option>Bank Transfer</option>
                            </select>
                            <input type="text" name="reference" placeholder="Reference Number" class="input">
                            <button type="submit" class="btn-primary w-full">Submit Payment</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>

</body>
</html>
