<?php
declare(strict_types=1);

return [
    '32-capture-mdadm.php' => [
        ['type' => 'command', 'value' => 'mdadm'],
    ],
    '24-fetch-ssh-keys.php' => [
        ['type' => 'env', 'value' => 'MCX_SSH_KEYS_URI'],
    ],
    // Opt-in: only the network install sets MCX_REMOVE_ENGINE (the task checks it again itself).
    '90-remove-engine.php' => [
        ['type' => 'env', 'value' => 'MCX_REMOVE_ENGINE'],
    ],
];
