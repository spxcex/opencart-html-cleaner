# Security Policy

## 🔒 Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |

## 🚨 Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via:
- Email: [sap.super.test@gmail.com]
- Or create a private security advisory on GitHub

Please include:
- Type of vulnerability
- Full paths of affected source files
- Steps to reproduce
- Proof of concept or exploit code (if possible)
- Impact of the issue

You should receive a response within 48 hours.

## ⚠️ Security Best Practices

When using this tool:

1. **Always backup your database** before running
2. **Test on development environment** first
3. **Delete the script** after use
4. **Restrict access** via .htaccess or firewall
5. **Use strong database passwords**
6. **Keep PHP and MySQL updated**

## 🛡️ Built-in Security Features

- PDO prepared statements (SQL injection protection)
- Input validation
- HTML escaping
- Transaction rollback on errors