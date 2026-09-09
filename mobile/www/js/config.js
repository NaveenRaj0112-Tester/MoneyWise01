/**
 * Money Wise — app configuration.
 *
 * MW_API_BASE must point at the MoneyWise PHP REST API (HTTPS in production).
 * The Capacitor WebView never talks to MySQL directly — only this API.
 *
 * Override per build:
 *   $env:MW_API_BASE="https://moneywise.example.com/api"; npm run build:www
 *
 * Default keeps local development working against a XAMPP install.
 */
var MW_API_BASE = 'http://localhost/MoneyManagement1/api';
