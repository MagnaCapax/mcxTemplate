<?php
declare(strict_types=1);

return [
    '32-capture-mdadm.php' => [
        ['type' => 'command', 'value' => 'mdadm'],
    ],
    '24-fetch-ssh-keys.php' => [
        ['type' => 'env', 'value' => 'MCX_SSH_KEYS_URI'],
    ],
];
