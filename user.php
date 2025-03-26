<?php 
require 'db.php';
session_start();
date_default_timezone_set('Asia/Manila'); // Ensure correct timezone

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$tellers = ['Teller1', 'Teller2', 'Teller3']; // Teller round-robin setup

// Define prefixes
$prefixes = [
    'ADMIN' => 'A-',
    'ACCOUNTS' => 'B-',
    'DOCUMENTATION' => 'C-',
    'CREWING' => 'D-',
    'TECHOPS' => 'E-',
    'SOURCING' => 'F-',
    'TANKER' => 'G-',
    'WELFARE' => 'H-'
];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['services'], $_POST['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    $services = $_POST['services'];
    $prefix = $prefixes[$services] ?? 'X-';

    if (!empty($services)) {
        // 🚀 Lock the table to prevent race conditions
        $conn->query("LOCK TABLES queue WRITE");

        // ✅ Get the last queue number safely
        $stmt = $conn->prepare("SELECT queue_number FROM queue WHERE services = ? AND DATE(date_generated) = CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $services);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row && isset($row['queue_number'])) {
            preg_match('/\d+/', $row['queue_number'], $matches);
            $last_queue_number = isset($matches[0]) ? intval($matches[0]) : 0;
        } else {
            $last_queue_number = 0;
        }

        // ✅ Properly increment queue number
        $new_queue_number = $last_queue_number + 1;
        $formatted_queue_number = $prefix . $new_queue_number;

        // Assign a teller in round-robin manner
        $teller_index = ($new_queue_number - 1) % count($tellers);
        $assigned_teller = $tellers[$teller_index];

        // 🚀 Insert into database
        $stmt = $conn->prepare("INSERT INTO queue (queue_number, services, status, teller, date_generated) 
                                VALUES (?, ?, 'Waiting', ?, CURDATE())");
        $stmt->bind_param("sss", $formatted_queue_number, $services, $assigned_teller);
        $stmt->execute();
        $stmt->close();

        // 🚀 Unlock tables after insertion
        $conn->query("UNLOCK TABLES");

        $_SESSION['queue_number'] = $formatted_queue_number;

        // Redirect to refresh session
        header("Location: user.php");
        exit;
    }
}

// Retrieve latest queue number for the user
$user_queue = null;
if (isset($_SESSION['queue_number'])) {
    $sql = "SELECT queue_number, status, teller FROM queue WHERE queue_number = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $_SESSION['queue_number']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_queue = $result->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
            <a href="index.php" class="btn btn-primary ">Back</a>
                <div class="card shadow-lg mt-4">
                    <div class="card-header text-center">
                        <h5>Generate Queue Number</h5>
                    </div>
                    <div class="card-body text-center">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php foreach ($prefixes as $key => $prefix) : ?>
                                <button type="submit" name="services" value="<?php echo $key; ?>" class="btn btn-primary m-1">
                                    <?php echo ucfirst(strtolower($key)); ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                </div>

                <div class="card shadow-lg mt-4">
                    <div class="card-header">
                        <h5>Your Queue Status</h5>
                        <?php if ($user_queue): ?>
                        <button class="btn btn-secondary float-end" onclick="printQueue()">🖨 Print Number</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($user_queue): ?>
                            <p>Your Queue Number:</p>
                            <h1 id="queueNumberPrint" style="font-size: 8rem; font-weight: bold;"> <?php echo $user_queue['queue_number']; ?> </h1>
                            <p>Status: <strong><?php echo ucfirst($user_queue['status']); ?></strong></p>
                            <p>Teller: <strong><?php echo $user_queue['teller']; ?></strong></p>
                        <?php else: ?>
                            <p class="alert alert-secondary">You have not joined the queue yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function printQueue() {
        var queueNumber = document.getElementById("queueNumberPrint").innerText;
        var tellerName = "<?php echo $user_queue ? $user_queue['teller'] : ''; ?>"; // Get teller name from PHP
        
        var printWindow = window.open('', '', 'width=400,height=600');
        printWindow.document.write('<html><head><title>Print Queue Number</title></head><body>');
        printWindow.document.write('<h2 style="text-align: center;">Your Queue Number</h2>');
        printWindow.document.write('<h1 style="text-align: center; font-size: 8rem;">' + queueNumber + '</h1>');
        printWindow.document.write('<p style="text-align: center; font-size: 1.5rem;">Teller: <strong>' + tellerName + '</strong></p>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.print();
    }
</script>

</body>
</html>
