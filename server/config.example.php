<?php
// Copy OUTSIDE public_html to ~/domains/YOUR_DOMAIN/khushrang-private/config.php.
// Never commit the real configuration or credentials.
// A private .env can provide DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/STORAGE_PATH
// instead; those values override these defaults and preserve integration settings.
return [
 'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
 'user' => 'YOUR_DATABASE_USER',
 'password' => 'SET_SECURELY_ON_HOST',
 'storage_path' => __DIR__ . '/media',
 // Distinct server-to-server tokens per source. Store only SHA-256 hashes here.
 // Generate a random 32-byte hex token securely; give its raw value ONLY to the trusted sender.
 'inbound_connectors' => [
   // ['source'=>'website','token_sha256'=>'SHA256_OF_RANDOM_TOKEN','scopes'=>['clients']],
   // ['source'=>'email-platform','token_sha256'=>'DIFFERENT_TOKEN_SHA256','scopes'=>['outreach']],
 ],
 // Generic JSON webhook adapter. Configure the receiving automation in your email system.
 'email_destination' => [
   'url' => '', // e.g. https://automation.example.com/hooks/khushrang
   'allowed_hosts' => [], // exact hostnames, e.g. ['automation.example.com']
   'bearer_token' => '', // optional receiver credential; private configuration only
 ],
];
