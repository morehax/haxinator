# Security Framework for MAC Address Manager

This directory contains security components that enhance the security of the MAC Address Manager web application. The framework provides protection against common web vulnerabilities including command injection, CSRF, XSS, and more.

## Components

### 1. SecureCommand.php

A secure layer for executing shell commands that prevents command injection vulnerabilities by properly escaping and validating all command parameters.

Key features:
- Proper escaping of all command arguments
- Command template system using placeholders
- Logging of all executed commands for auditing
- NetworkManager-specific command helpers

### 2. InputValidator.php 

A validation framework for user inputs to ensure data integrity and prevent injection attacks.

Validates various types of data:
- UUIDs
- MAC addresses
- SSIDs
- IP addresses
- Interface names
- Passwords
- File paths

### 3. CSRFProtection.php

Protection against Cross-Site Request Forgery (CSRF) attacks.

Features:
- Token generation and validation
- Form field auto-generation
- Request validation
- Enforcement capabilities

### 4. bootstrap.php

A central bootstrap file that loads all security components and applies security headers and session hardening.

Features:
- Secure session configuration
- Security headers
- Automatic CSRF protection for unsafe HTTP methods
- Exception handling for security components

## Usage

To use the security framework in your PHP files:

```php
// Include the security framework
require_once __DIR__ . '/security/bootstrap.php';

// Now you have access to all security components:
// - SecureCommand for executing commands safely
// - InputValidator for validating user input
// - CSRFProtection for CSRF protection
```

## Examples

### Secure Command Execution

```php
// Old insecure way:
// exec("nmcli connection modify '$uuid' 802-11-wireless.mac-address-randomization always");

// New secure way:
SecureCommand::execute(
    "nmcli connection modify %s 802-11-wireless.mac-address-randomization always", 
    [$uuid]
);
```

### Input Validation

```php
// Validate a MAC address
if (!InputValidator::mac($macAddress)) {
    $error = "Invalid MAC address format";
}

// Validate a UUID
if (!InputValidator::uuid($uuid)) {
    $error = "Invalid UUID format";
}
```

### CSRF Protection

```php
// In your form:
<form method="post">
    <?= CSRFProtection::tokenField() ?>
    <!-- Form fields -->
    <button type="submit">Submit</button>
</form>

// At the beginning of your POST handler:
CSRFProtection::enforceCheck();
```

## Security Best Practices

The framework implements several security best practices:

1. **Secure Command Execution**: All shell commands are executed through the SecureCommand class which properly escapes and validates all parameters.

2. **Input Validation**: All user input is validated before use in commands or queries.

3. **CSRF Protection**: All forms are protected against CSRF attacks.

4. **Secure Session Handling**: Session cookies are configured with HttpOnly and Secure flags.

5. **Security Headers**: The application sets appropriate security headers to protect against XSS, clickjacking, and other attacks.

6. **Error Handling**: Errors are handled gracefully without exposing sensitive information. 