# Security Policy - Liberu Music

## Reporting a Vulnerability

Private vulnerability reporting is enabled for [liberusoftware/project-music-daw-laravel-filament](https://github.com/liberusoftware/project-music-daw-laravel-filament).

[**Report a vulnerability privately**](https://github.com/liberusoftware/project-music-daw-laravel-filament/security/advisories/new).

Sign in to GitHub and use the link above, or open **Security → Advisories → Report a vulnerability** in this repository. Reports are handled privately through GitHub Security Advisories; maintainers can invite collaborators when needed to investigate and prepare a fix.

**Do not disclose suspected vulnerabilities in public issues, pull requests, or discussions.**

Include the affected release or commit, package versions, relevant deployment configuration, reproduction steps or a minimal proof of concept, and the security impact. Redact credentials, tokens, personal data, and other sensitive information. Test only systems you own or have permission to assess.

## What to Expect

- **Volunteer-maintained**: Liberu Music is maintained by volunteers. There is no guaranteed response time or SLA for security reports.
- **Disclosure**: We follow responsible disclosure practices. Once a fix is released, we will credit reporters unless they prefer to remain anonymous.
- **Scope of response**: We will investigate all legitimate reports and release patches for confirmed vulnerabilities in a timely manner.

## Scope

The following components are in scope for security reports:

- `app/` - Application code (Models, Controllers, Services, Jobs, Events, Listeners)
- `routes/` - Route definitions and middleware
- `database/` - Migrations, seeders, and factories
- `config/` - Configuration files
- `resources/` - Views, frontend assets, and language files
- `tests/` - Test infrastructure
- Publicly accessible API endpoints
- Module registration and discovery logic
- Service provider architecture
- Authentication and authorization scaffolding
- Project and media access controls, uploaded audio, and audio-engine integrations

First-party modules shipped with this application, including code under `modules/`, are also in scope.

## Out of Scope

The following are generally outside this project's security reporting scope:

- **Third-party dependencies**: report vulnerabilities confined to upstream code to its maintainers. First-party integration flaws and application-specific impact remain in scope here.
- **Production misconfigurations**: Issues caused by running the application with `APP_DEBUG=true` in production, or other insecure configurations that are documented as such.
- **Known issues**: check previously documented findings before submitting a duplicate; report additional impact or affected versions privately.
- **Theoretical issues**: Issues that cannot be demonstrated to have a real-world security impact.
- **Social engineering**: Attacks targeting users through phishing, credential theft, or other social engineering vectors.
- **Denial of service**: Volumetric DDoS attacks or other resource exhaustion attacks against the hosting infrastructure.

## Supported Versions

Security fixes target the default branch (`main`) and the latest release, when a release is available. Older versions may require an upgrade; backports are not guaranteed. Include the affected version or commit in your report even if you are not running the latest release.
