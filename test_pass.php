<?php
$hash = '$2y$10$05YsJXrRv.0Cs5LXV.vqU.ZO19JXh2XjI/CnnIzuAoVqmQML9eCV2';
$tests = ['password', 'admin123', 'password123', '123456', 'admin', 'hinaplanon', 'iligan123', 'secret'];
foreach ($tests as $t) {
    if (password_verify($t, $hash)) {
        echo "Found: $t\n";
        exit;
    }
}
echo "Not in simple list\n";
