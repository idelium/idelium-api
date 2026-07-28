<?php

return [
    'token_ttl_seconds' => env('IDELIUM_OIDC_WORKLOAD_TOKEN_TTL_SECONDS', 300),
    'max_assertion_age_seconds' => env('IDELIUM_OIDC_MAX_ASSERTION_AGE_SECONDS', 300),
    'providers' => [
        'github-actions' => [
            'issuer' => 'https://token.actions.githubusercontent.com',
            'audience' => env('IDELIUM_OIDC_GITHUB_AUDIENCE', 'idelium-api'),
            'algorithms' => ['HS256', 'RS256'],
            'hmacSecret' => env('IDELIUM_OIDC_GITHUB_HMAC_SECRET'),
            'publicKeys' => [],
            'policies' => [],
        ],
        'gitlab-ci' => [
            'issuer' => env('IDELIUM_OIDC_GITLAB_ISSUER', 'https://gitlab.com'),
            'audience' => env('IDELIUM_OIDC_GITLAB_AUDIENCE', 'idelium-api'),
            'algorithms' => ['HS256', 'RS256'],
            'hmacSecret' => env('IDELIUM_OIDC_GITLAB_HMAC_SECRET'),
            'publicKeys' => [],
            'policies' => [],
        ],
        'jenkins' => [
            'issuer' => env('IDELIUM_OIDC_JENKINS_ISSUER', 'https://jenkins.example.invalid'),
            'audience' => env('IDELIUM_OIDC_JENKINS_AUDIENCE', 'idelium-api'),
            'algorithms' => ['HS256', 'RS256'],
            'hmacSecret' => env('IDELIUM_OIDC_JENKINS_HMAC_SECRET'),
            'publicKeys' => [],
            'policies' => [],
        ],
    ],
];
