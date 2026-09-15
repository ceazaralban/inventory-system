<?php
    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    function redirectIfNotLoggedIn() {
        if (!isLoggedIn()) {
            header("Location: ../index.php");
            exit();
        }
    }

function loadRolePermissions(PDO $db, string $role): array {
    // Admin always allowed (no need DB permissions)
    if ($role === 'admin') return ['*' => 1];

    $stmt = $db->prepare("SELECT page_key, is_enabled FROM page_permissions WHERE role = ?");
    $stmt->execute([$role]);

    $perms = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $perms[$r['page_key']] = (int)$r['is_enabled'];
    }

    // If nothing exists yet, default allow all
    if (!$perms) return ['*' => 1];

    return $perms;
}

function login($username, $password) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id, username, password_hash, role, is_active
              FROM users
              WHERE username = :username
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() == 1) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Block disabled accounts
        if ((int)($row['is_active'] ?? 0) !== 1) {
            return false;
        }

        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['user_id']  = (int)$row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role']     = $row['role'] ?? 'staff';

            // ✅ Load permissions into session
            $_SESSION['page_perms'] = loadRolePermissions($db, $_SESSION['role']);

            return true;
        }
    }
    return false;
}

    function requireRole(array $roles): void {
        $role = $_SESSION['role'] ?? '';
            if (!in_array($role, $roles, true)) {
                http_response_code(403);
                die('Access denied');
            }
    }

    function canViewPage(string $page_key): bool {
        $role = $_SESSION['role'] ?? '';

        if ($role === 'admin') return true;

        $perms = $_SESSION['page_perms'] ?? null;
        if (is_array($perms)) {
            if (isset($perms['*']) && (int)$perms['*'] === 1) return true;
            return isset($perms[$page_key]) && (int)$perms[$page_key] === 1;
        }

        // Fallback: query DB if perms not loaded (rare)
        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare("SELECT is_enabled FROM page_permissions WHERE page_key = ? AND role = ? LIMIT 1");
        $stmt->execute([$page_key, $role]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return true; // default allow if missing
        return ((int)$row['is_enabled'] === 1);
    }

    function requirePageAccess(string $page_key): void {
        if (!canViewPage($page_key)) {
            http_response_code(403);
            die('Access denied');
        }
    }

?>