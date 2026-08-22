# Contributing to RMC Events

Thank you for your interest in contributing to RMC Events! This document provides guidelines and instructions for contributing.

## How to Contribute

### Reporting Bugs

1. Check existing [issues](https://github.com/JhonCarlBaculinao/campus-event-system-/issues) to avoid duplicates.
2. Open a new issue with a clear title and description.
3. Include steps to reproduce, expected behavior, and actual behavior.
4. Mention your PHP version, PostgreSQL version, and browser.

### Suggesting Features

1. Open an issue with the label `enhancement`.
2. Describe the feature, its use case, and why it benefits the project.

### Submitting Changes

1. **Fork** the repository.
2. **Create a branch** for your feature or fix:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes** following the code style guidelines below.
4. **Test** your changes thoroughly.
5. **Commit** with a clear message:
   ```bash
   git commit -m "Add: brief description of change"
   ```
6. **Push** to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```
7. **Open a Pull Request** against the `main` branch.

## Code Style Guidelines

### PHP
- Use `snake_case` for functions and variables.
- Use `PascalCase` for class names (if applicable).
- Always use `pg_query_params()` for database queries — never concatenate user input into SQL.
- Escape all output with `htmlspecialchars()`.
- Include CSRF tokens on all POST forms.
- Follow the existing file naming conventions (lowercase, underscores).

### HTML / CSS
- Use Tailwind CSS utility classes for styling.
- Follow the existing RMC maroon design system (`#7a0c0c` primary).
- Ensure all pages are mobile-responsive.
- Use semantic HTML where possible.

### JavaScript
- Use vanilla JavaScript — no jQuery or frameworks.
- Keep scripts in the `js/` directory or inline within pages.
- Handle errors gracefully.

## Commit Message Convention

Use the following prefixes:

| Prefix   | Purpose                        |
|----------|--------------------------------|
| `Add:`   | New feature or file            |
| `Fix:`   | Bug fix                        |
| `Update:`| Improvement to existing feature|
| `Remove:`| Removing code or files         |
| `Refactor:`| Code restructuring           |
| `Docs:`  | Documentation changes          |

Examples:
```
Add: email notification for event approval
Fix: QR code scan timeout issue
Update: sidebar navigation styling
```

## Pull Request Checklist

- [ ] Code follows the project's style guidelines
- [ ] Changes have been tested locally
- [ ] No SQL injection vulnerabilities (use prepared statements)
- [ ] All user output is escaped (XSS prevention)
- [ ] CSRF tokens are included on new forms
- [ ] Database changes are reflected in `campus_event_db.sql`
- [ ] Commit messages follow the convention above
- [ ] PR description explains what and why

## Code of Conduct

- Be respectful and constructive.
- Focus on the code, not the person.
- Accept feedback gracefully.
- Help maintain a welcoming environment for all contributors.

## Questions?

Open an issue or reach out to the maintainer directly.
