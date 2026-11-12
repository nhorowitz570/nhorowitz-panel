<?php

return [
    'install_ready' => (bool) env('DAEMON_INSTALL_READY', false),
    'ca_cert' => env('DAEMON_CA_CERT', storage_path('app/private/daemon-ca.crt')),
    'ca_key' => env('DAEMON_CA_KEY', storage_path('app/private/daemon-ca.key')),
    'gateway_host' => env('DAEMON_GATEWAY_HOST', parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),
    'gateway_secret' => env('DAEMON_GATEWAY_SECRET'),
    'gateway_internal_url' => env('DAEMON_GATEWAY_INTERNAL_URL'),
    'commands_enabled' => (bool) env('DAEMON_COMMANDS_ENABLED', false),
    'package_install_since' => env('DAEMON_PACKAGE_INSTALL_SINCE', '0.1.1-pre.1'),
    'repo_token_since' => env('DAEMON_REPO_TOKEN_SINCE', '0.1.1-pre.10'),
    'inspect_since' => env('DAEMON_INSPECT_SINCE', '0.1.1-pre.16'),
    'systemd_remove_since' => env('DAEMON_SYSTEMD_REMOVE_SINCE', '0.1.1-pre.17'),
    'log_ip_masking_since' => env('DAEMON_LOG_IP_MASKING_SINCE', '0.1.1-pre.21'),

    'project_data_since' => env('DAEMON_PROJECT_DATA_SINCE', '0.1.1-pre.35'),
    'exec_env_since' => env('DAEMON_EXEC_ENV_SINCE', '0.1.1-pre.34'),

    'log_skip_since' => env('DAEMON_LOG_SKIP_SINCE', '0.1.1-pre.22'),
    'disk_usage_since' => env('DAEMON_DISK_USAGE_SINCE', '0.1.1-pre.19'),
    'clone_since' => env('DAEMON_CLONE_SINCE', '0.1.1-pre.23'),
    'wildcard_domains_since' => env('DAEMON_WILDCARD_DOMAINS_SINCE', '0.1.1-pre.34'),
    'policy_since' => env('DAEMON_POLICY_SINCE', '0.1.1-pre.24'),
    'package_versions_since' => env('DAEMON_PACKAGE_VERSIONS_SINCE', '0.1.1-pre.26'),

    'package_add_since' => env('DAEMON_PACKAGE_ADD_SINCE', '0.1.1-pre.35'),
    'tunnel_since' => env('DAEMON_TUNNEL_SINCE', '0.1.1-pre.37'),
    'install_url' => env('DAEMON_INSTALL_URL', rtrim((string) env('APP_URL', ''), '/').'/install.sh'),
    'install_script' => null,
    'releases_url' => env('DAEMON_RELEASES_URL', 'https://releases.serveros.com'),
    'release_pubkey' => env('DAEMON_RELEASE_PUBKEY'),
    'release_feed_url' => env('DAEMON_RELEASE_FEED_URL', 'https://serveros.com/api/daemon/releases/latest'),
    'releases_path' => env('DAEMON_RELEASES_PATH', public_path('releases/daemon')),
    'deploy_token' => env('SERVEROS_DEPLOY_TOKEN'),
    'local_node_port' => env('DAEMON_LOCAL_NODE_PORT'),
    'local_node_ports' => env('DAEMON_LOCAL_NODE_PORTS', ''),
    'panel_url' => env('DAEMON_PANEL_URL', rtrim((string) env('APP_URL', ''), '/')),
];
