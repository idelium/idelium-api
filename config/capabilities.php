<?php

return [
    'version' => '2026-07-28',

    'roles' => [
        1 => [
            'tenant.switch',
            'accounts.manage',
            'customers.manage',
            'api_keys.manage',
            'agents.manage',
            'agents.read',
            'audit_events.read',
            'artifacts.read',
            'projects.manage',
            'resources.manage',
            'resources.read',
            'runs.launch',
            'profile.manage',
        ],
        2 => [
            'accounts.manage',
            'api_keys.manage',
            'agents.manage',
            'agents.read',
            'audit_events.read',
            'artifacts.read',
            'projects.manage',
            'resources.manage',
            'resources.read',
            'runs.launch',
            'profile.manage',
        ],
        3 => [
            'artifacts.read',
            'agents.read',
            'projects.read',
            'resources.read',
            'runs.launch',
            'profile.manage',
        ],
    ],
];
