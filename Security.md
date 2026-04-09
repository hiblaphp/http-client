# Security Policy

Security is a primary concern for Hibla HTTP Client. As an asynchronous HTTP client, it handles sensitive data including authentication tokens, cookies, and private request payloads. This document outlines our security policy, how to report vulnerabilities, and the security measures implemented within the library.

## Reporting a Vulnerability

If you discover a security vulnerability within this project, please do not use the public issue tracker. Instead, send a private email to reymart.calicdan06@gmail.com.

Please include the following information in your report:
* A description of the vulnerability.
* Steps to reproduce the issue (a proof-of-concept script is highly appreciated).
* The potential impact of the vulnerability.

We aim to acknowledge receipt of your report within 48 hours and provide a timeline for a fix. We follow a coordinated disclosure process where we ask that you do not share information about the vulnerability publicly until a patch has been released.

## Security Features and Implementation

Hibla HTTP Client implements several low-level safeguards to protect against common web-based attacks:

### Header Injection Prevention
The library strictly validates header names and values. Any attempt to inject carriage returns (CR) or line feeds (LF) into headers—common in HTTP Request Smuggling or Header Injection attacks—is blocked at the object level before the request is dispatched to the transport layer.

### RFC 6265 Compliant Cookie Safety
The built-in CookieJar and Cookie value objects strictly follow RFC 6265. Security measures include:
* Strict domain and path scoping to prevent cookie leakage across domains.
* Enforcement of the Secure flag, ensuring sensitive cookies are only sent over HTTPS.
* Support for HttpOnly and SameSite attributes.
* Validation of cookie names and values to prevent malformed Cookie headers that could lead to server-side misinterpretation.

### Transport Security
The client relies on ext-curl for the transport layer. It inherits the underlying system's TLS/SSL capabilities. By default, peer certificate verification is enabled to prevent Man-in-the-Middle (MitM) attacks.

## Security Best Practices for Users

To maintain the security of your application while using this client, we recommend the following practices:

### Never Disable SSL Verification in Production
The `verifySSL(false)` method is provided strictly for local development or controlled testing environments. Disabling SSL verification in production makes your application vulnerable to MitM attacks.

### Use Dedicated Authentication Methods
While headers can be set manually, it is safer to use `withToken()`, `withBasicAuth()`, and `withDigestAuth()`. These methods ensure that credentials are formatted correctly and, in the case of `withToken()`, prevent accidental double-prefixing of the Authorization header.

### Keep PHP and cURL Updated
Since this library currently depends on `ext-curl`, the security of your connections (including supported TLS versions and ciphers) depends on the version of cURL and OpenSSL/LibreSSL installed on your system. Regularly update your environment to mitigate protocol-level vulnerabilities.

### Sanitize URI Template Parameters
When using `withUrlParameter()`, ensure that any user-provided data is intended for the specific URI segment. While the library performs standard percent-encoding for simple expansions, it cannot determine if a provided value is semantically valid for your specific API logic.

## Security Disclosures

Fixed security vulnerabilities will be documented in the repository's CHANGELOG.md and released as a new version. Where appropriate, we will issue a GitHub Security Advisory.