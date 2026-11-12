<?php
function logActivity($pdo, $user_id, $activity) {
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, activity) VALUES (?, ?)");
    $stmt->execute([$user_id, $activity]);
}
?>