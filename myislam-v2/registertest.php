<?php 
session_start();
// Make sure this file sets up a $pdo connection variable!
require_once '/config/database.php'; 

$username = '';
$password = '';
$confirm_password = '';
$success = '';
$error = '';

// 1. FIXED: Removed the trailing underscore from REQUEST_METHOD
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // 2. FIXED: Applied trim properly. 
    // Note: trim() will fail if the POST variable is missing completely, 
    // so we apply the ?? fallback first, then clean it with trim().
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // 3. FIXED: Corrected spelling to strlen()
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } 
    // FIXED: Changed to strict !== check for safety
    elseif ($password !== $confirm_password) { 
        $error = "Passwords do not match.";
    } 
    else {
        // 4. FIXED: Added missing semicolons and bound parameters safely
        // Check if username already exists in the database
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if ($stmt->fetch()) {
            $error = 'Username already exists.';
        } 
        else {
            // Hash the password so hackers can't read it if they steal your database!
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user into the database
            $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $hashed_password]);
            
            $success = 'Successfully registered!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset="UTF-8">
    <title>Register</title>
</head>
<body>

    <!-- Show error or success messages to the user -->
    <?php if ($error): ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color: green;"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>

    <!-- FIXED: Added a <form> tag so data actually submits, and a submit button -->
    <form action="" method="POST">
        <div>
            <label for='username'>Username</label>
            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
        </div>
        
        <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        
        <div>
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>

        <button type="submit">Register</button>
    </form>

</body>
</html>
