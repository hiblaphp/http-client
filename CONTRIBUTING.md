# Contributing to Hibla HTTP Client

Thank you for your interest in contributing to Hibla HTTP Client. To maintain the high performance, reliability, and developer experience of this library, we uphold strict standards regarding code quality, type safety, and architectural consistency.

## Architectural Principles

Before contributing code, please ensure your changes align with these core principles:

1. Immutable Builders: The HttpClient and Request objects must remain immutable. Every method that modifies request or transport configuration must return a new clone of the instance. Note that stateful dependencies, specifically the CookieJar, are shared mutable objects by design to facilitate session persistence across derived client instances.
2. Non-blocking Execution: Never use sleep() or blocking I/O functions. Always utilize the Hibla Event Loop and return Hibla Promise instances.
3. Developer Experience (DX): API methods should be expressive and intuitive. Complex tasks, such as Server-Sent Events (SSE) or sophisticated retry logic, should be made trivial for the end-user through the fluent API.

## Coding Standards

We adhere to strict PHP community standards to ensure the codebase remains clean, maintainable, and interoperable.

### PSR Compliance
* PSR-4 (Autoloading): All classes must follow PSR-4 mapping. Source code resides in the src/ directory under the Hibla\HttpClient namespace.
* PSR-12 (Coding Style): We strictly follow the PSR-12 extended coding style guide. We use Laravel Pint to enforce these rules.

### Static Analysis
Type safety is a non-negotiable requirement for this project.
* PHPStan Max Level: All code within the src/ directory must pass PHPStan analysis at the maximum level (Level 10).
* Avoid the "mixed" type: Specific types must be used whenever they can be inferred.
* Strict Typing: Use scalar type hints and return types for all methods.
* Generics: Utilize PHPDoc generic templates and shape definitions where applicable to improve IDE support and static analysis accuracy.

## Testing Requirements

We maintain a high-density test suite with a zero-regression policy.
* Coverage: New features must include comprehensive tests using Pest or PHPUnit.
* Regressions: Bug fixes must be accompanied by a regression test that demonstrates the failure before the fix and passes after the fix.
* Integration: Use the hiblaphp/http-client-testing plugin and the provided Docker containers (httpbin and squid) to verify behavior against real network conditions.

## Local Development Workflow

### 1. Setup
Clone the repository and install dependencies:
```bash
git clone https://github.com/hiblaphp/http-client.git
cd http-client
composer install
```

### 2. Environment
Bring up the Dockerized test environment to enable integration tests:
```bash
composer httpbin:up
composer proxy:up
```

### 3. Verification
Run these commands frequently during development. Your Pull Request will not be accepted if these checks do not pass.

Run the test suite:
```bash
composer test
```

Run static analysis (Max Level):
```bash
composer analyze
```

Format code according to PSR-12:
```bash
composer format
```

## Submitting a Pull Request

1. Fork the repository and create your feature branch from the main branch.
2. Implement your changes following the coding standards and architectural principles.
3. Ensure all tests, static analysis, and formatting checks pass locally.
4. Update the README.md if you are adding or changing user-facing functionality.
5. Submit the Pull Request with a clear description of the problem and the solution.

## License

By contributing to this project, you agree that your contributions will be licensed under the project's MIT License.