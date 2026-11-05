# Liberu Music

> A modular Digital Audio Workstation project for recording, composition, production and live performance.

[Software](https://liberusoftware.com) · [Hosting](https://liberuhosting.com) · [Services](https://liberuservices.com) · [Liberu Group](https://liberugroup.com)

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/) [![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/) [![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)](https://filamentphp.com/) [![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)](https://livewire.laravel.com/)

## What it is

Liberu Music is the Laravel host skeleton for a proposed modular Digital Audio Workstation for songwriters, musicians, producers, composers and mix engineers. It starts from [Liberu Boilerplate](https://github.com/liberusoftware/project-boilerplate-laravel-filament/tree/95f445a2901f2296959bec24229c6a1d5d5a6815), preserving its application structure and shared foundation modules and themes.

**Status: under development.** Projects core, Media Library core and their optional API adapters are installed from `modules/`, with independently tested implementations synchronized to separate repositories. APIs require explicit enablement. Projects provides checkpoint/recovery/import commands and scoped revision polling; Media Library provides authorized metadata, keyset browsing, upload reservation views and cancellable waveform jobs. Media transfer/edit/pack/lifecycle HTTP operations, remaining Music packages and the complete DAW are unfinished. No native audio engine, physical recording support or Music UI is provided yet. See the [delivery roadmap](https://github.com/liberusoftware/project-music-daw-laravel-filament/issues/94) for acceptance tracking.

## Planned product capabilities

The [product scope](https://github.com/liberusoftware/documentation/blob/main/projects/music/MUSIC.md) defines 22 capabilities:

- **Projects and Sessions:** Project documents, tracks, folders, templates, revision history, autosave and portable session manifests.
- **Audio Engine and Transport:** Native audio graph, sample clock, play/stop/seek, buffers, latency compensation and offline graph rendering.
- **Audio Devices and Monitoring:** Device discovery, input/output channel mapping, sample rates, buffer sizes and direct/software monitoring.
- **Recording and Take Comping:** Track arming, count-in, punch and loop recording, take lanes, comp segments and recoverable disk writes.
- **Arrangement and Audio Editing:** Timeline clips, fades, splits, slip edits, crossfades, markers, warp anchors and nondestructive pitch/time changes.
- **Clip Launcher and Scenes:** Quantized clip launch, scenes, follow actions and recording performances into the arrangement.
- **MIDI and Expressive Editing:** Piano roll, drum editor, step entry, controller lanes, note expression, MPE and MIDI import/export.
- **Tempo, Meter and Groove:** Tempo maps, meter changes, groove templates, swing, metronome and musical/sample-time conversion.
- **Instruments and Samplers:** Built-in synth, drum machine, sampler, multisamples, voice allocation and portable instrument presets.
- **Audio Effects and Amp Processing:** EQ, dynamics, delay, reverb, modulation effects and independently designed amp/cabinet processing.
- **Plugin Hosting and Isolation:** Plugin discovery, scanning, process isolation, state snapshots, compatibility and missing-plugin recovery.
- **Mixer and Routing:** Channels, buses, sends, sidechains, pan, gain, groups, solo/mute and routing snapshots.
- **Automation and Modulation:** Parameter automation lanes, read/write/touch/latch modes, envelopes, LFOs and modulation mappings.
- **Composition, Chords and Score:** Chord tracks, scales, voicings, articulation maps and notation views with printable parts.
- **Media Library and Content:** Content-addressed audio assets, waveform peaks, metadata, tags, search, packs and rights provenance.
- **Presets, Racks and Templates:** Versioned instrument/effect presets, device racks, macros and reusable session templates.
- **Controllers and Hardware Integration:** MIDI port mapping, controller learn, control surfaces, feedback and external clock synchronization.
- **Collaboration and Review:** Project membership integration, revision sharing, comments, review markers, locks and conflict branches.
- **Offline Storage and Synchronization:** Local journals, pinned assets, resumable transfer, revision reconciliation and storage quotas.
- **Export, Mastering and Delivery:** Offline bounce, stems, freeze/flatten, dithering, loudness/true-peak analysis and delivery manifests.
- **Video and Timecode:** Reference video, frame-rate-aware timeline display, timecode offsets and audio-to-picture synchronization.
- **Live Performance and Setlists:** Setlists, cue preparation, preloaded projects, scene transitions and performance recovery.

## Stack

| Dependency | Version |
|---|---|
| PHP | 8.5 |
| Laravel | 13.x |
| Filament | 5.x |
| Livewire | 4.x |
| Composer | 2.x |
| Node.js | Supported release compatible with the Vite toolchain |
| Database | A Laravel-supported SQL database |

## Quick start

These commands set up the inherited Laravel foundation only.

```bash
git clone https://github.com/liberusoftware/project-music-daw-laravel-filament.git
cd music-daw-laravel
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan migrate
php artisan serve
```

Configure the database, application URL, mail and external services in `.env` before migrations. The inherited `install.sh` is also available for interactive foundation setup.

## How the modules fit together

```text
app/         # host composition and integration
bootstrap/   # Laravel bootstrap
config/      # foundation configuration and application policy
database/    # host integration migrations and seeders
modules/     # canonical shared foundation and Music packages
resources/   # inherited foundation views and assets
routes/      # foundation routes
tests/       # host composition, Music integration and architecture checks
themes/      # inherited shared themes
```

Each Music capability has separate core, API, Livewire and Filament repositories; implementation status is tracked on GitHub. Core repositories use `module-music-<capability>`; adapters append `-api`, `-livewire` or `-filament`. See the [complete repository catalogue](MUSIC-MODULES.md).

Laravel owns project metadata, authorization, revision manifests, asset policy and asynchronous jobs. A separately designed native/local engine owns audio graph execution, DSP, sample scheduling, recording buffers and devices. PHP, database access and network requests must never execute in the audio callback. Filament supports operations and Livewire supports project management/review; neither is the real-time engine.

Foundation identity, teams, permissions, files, settings, audit, notifications and queues remain shared capabilities. Follow the [foundation adoption plan](https://github.com/liberusoftware/documentation/blob/main/projects/music/FOUNDATION.md) instead of duplicating them in Music modules.

## Development and verification

```bash
composer test
npm run build
```

The host suite includes Music composition tests. Each implemented Music package also runs independently through the Liberu package testbench and enforces 100% source line coverage. Host coverage includes `modules/*/src` and currently remains below its required 100% gate. These tests do not establish native engine or physical-device support.

Active install and coverage checks are published under `.github/workflows/` in the host and implemented Music repositories. Projects core has passed its standalone install/test workflows. Host installation has passed, while the host-wide 100% coverage gate still fails; passing package checks does not satisfy that gate. The optional [Projects API](modules/music-projects-api/README.md) and [Media Library API](modules/music-media-library-api/README.md) also validate their OpenAPI fragments. Inherited publishing templates require organization secrets and review before activation. No complete Music module release is claimed.

## Documentation and roadmap

- [Product scope](https://github.com/liberusoftware/documentation/blob/main/projects/music/MUSIC.md)
- [Runtime architecture](https://github.com/liberusoftware/documentation/blob/main/projects/music/ARCHITECTURE.md)
- [Presentation support](https://github.com/liberusoftware/documentation/blob/main/projects/music/PRESENTATION.md)
- [Foundation adoption](https://github.com/liberusoftware/documentation/blob/main/projects/music/FOUNDATION.md)
- [Delivery sequence and acceptance gates](https://github.com/liberusoftware/documentation/blob/main/projects/music/DEVELOPMENT.md)
- [Module repository catalogue](MUSIC-MODULES.md)

Implementation starts with architecture decisions and a measured local recording/recovery prototype, then proceeds through creative production, professional workflows and connected workspaces. Repository creation does not establish feature support.

## Related Liberu projects

| Project | Repository | Scope |
|---|---|---|
| Accounting | [liberusoftware/accounting-erp-laravel](https://github.com/liberusoftware/project-accounting-erp-laravel-filament) | Ledgers, banking, tax, expenses, close, and reporting |
| Automation | [liberusoftware/automation-laravel](https://github.com/liberusoftware/project-automation-laravel-filament) | Governed workflows, provider-neutral AI, approvals, and connectors |
| Billing | [liberusoftware/billing-laravel](https://github.com/liberusoftware/project-billing-laravel-filament) | Billing, subscriptions, payments, invoices, and revenue operations |
| Boilerplate | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/project-boilerplate-laravel-filament) | Modular Laravel foundation and reference implementation |
| Browser game | [liberusoftware/browser-game-laravel](https://github.com/liberusoftware/project-browser-game-laravel-filament) | Browser-based game platform and domain capabilities |
| CMS | [liberusoftware/cms-laravel](https://github.com/liberusoftware/project-cms-laravel-filament) | Content, publishing, pages, media, search, and delivery |
| Control panel | [liberusoftware/control-panel-laravel](https://github.com/liberusoftware/project-control-panel-laravel-filament) | Hosting, infrastructure, DNS, mail, backups, and operations |
| CRM | [liberusoftware/crm-laravel](https://github.com/liberusoftware/project-crm-laravel-filament) | Customers, leads, opportunities, sales, and service |
| Ecommerce | [liberusoftware/ecommerce-laravel](https://github.com/liberusoftware/project-ecommerce-laravel-filament) | Catalogues, checkout, orders, fulfilment, and returns |
| Genealogy | [liberusoftware/genealogy-laravel](https://github.com/liberusoftware/project-genealogy-laravel-filament) | Genealogy records, relationships, sources, and research |
| Maintenance | [liberusoftware/maintenance-laravel](https://github.com/liberusoftware/project-maintenance-laravel-filament) | Maintenance planning, assets, work orders, and operations |
| Real estate | [liberusoftware/real-estate-laravel](https://github.com/liberusoftware/project-real-estate-laravel-filament) | Property, listing, tenancy, and transaction workflows |
| Social network | [liberusoftware/social-network-laravel](https://github.com/liberusoftware/project-social-network-laravel-filament) | Social profiles, groups, content, messaging, and discovery |

## Security

Do not report security vulnerabilities through public GitHub issues. Email `security@liberusoftware.com` with reproduction details and the affected version so the report can be handled privately.

## License

This project is open-source software available under the [MIT License](LICENSE.md). The linked licence text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. Report reproducible bugs, propose focused enhancements, improve documentation or translations, and submit tested changes. Search existing issues first. Pull requests should explain the problem and approach, remain focused, pass the required checks, and document user-visible or breaking changes. Security reports must follow the private route above.

## Contributors

Thank you to everyone who helps improve Liberu. [View the contributors graph](https://github.com/liberusoftware/project-music-daw-laravel-filament/graphs/contributors).

<!-- presentation-family:begin -->
## Project family and presentation options

This repository hosts **Music DAW with Laravel and Filament**. Composition, recording, sequencing and audio production. Its existing installation instructions and manifests govern this application. The other presentations are independent skeletons derived from their matching Boilerplate foundation; repository availability does not establish product feature parity.

### Other Laravel and Filament projects

| Project | Scope |
| --- | --- |
| [Boilerplate](https://github.com/liberusoftware/project-boilerplate-laravel-filament) | Shared application foundation and module composition |
| [Accounting ERP](https://github.com/liberusoftware/project-accounting-erp-laravel-filament) | Ledgers, tax, reporting and finance workflows |
| [Automation](https://github.com/liberusoftware/project-automation-laravel-filament) | AI gateways, approvals, rules and integrations |
| [Billing](https://github.com/liberusoftware/project-billing-laravel-filament) | Subscriptions, pricing, payments and provisioning |
| [Browser Game](https://github.com/liberusoftware/project-browser-game-laravel-filament) | Game worlds, players and community workflows |
| [CMS](https://github.com/liberusoftware/project-cms-laravel-filament) | Content authoring, publishing and delivery |
| [Control Panel](https://github.com/liberusoftware/project-control-panel-laravel-filament) | Infrastructure and hosting operations |
| [CRM](https://github.com/liberusoftware/project-crm-laravel-filament) | Relationships, sales pipelines and customer service |
| [Ecommerce](https://github.com/liberusoftware/project-ecommerce-laravel-filament) | Catalogs, checkout, orders and fulfillment |
| [Enterprise SAP](https://github.com/liberusoftware/project-enterprise-sap-laravel-filament) | Enterprise workspace and cross-product orchestration |
| [Genealogy](https://github.com/liberusoftware/project-genealogy-laravel-filament) | Family trees, research, evidence and collaboration |
| [Liberu Platform](https://github.com/liberusoftware/project-liberu-official-laravel-filament) | Official sites, staff applications and platform composition |
| [Maintenance](https://github.com/liberusoftware/project-maintenance-laravel-filament) | Assets, work orders, schedules and field operations |
| [Real Estate](https://github.com/liberusoftware/project-real-estate-laravel-filament) | Listings, sales, lettings and agency operations |
| [Social Network](https://github.com/liberusoftware/project-social-network-laravel-filament) | Profiles, feeds, communities and moderation |

### Other presentations for Music DAW

- [Laravel · Inertia · Vue](https://github.com/liberusoftware/project-music-daw-laravel-inertia-vue): Laravel routes and sessions with Vue pages over the Inertia protocol.
- [Laravel · Inertia · React](https://github.com/liberusoftware/project-music-daw-laravel-inertia-react): Laravel routes and sessions with React pages over the Inertia protocol.
- [Nuxt · Vue](https://github.com/liberusoftware/project-music-daw-nuxt-vue): Independent Vue application with Nuxt server rendering and reviewed API integration.
- [Next.js · React](https://github.com/liberusoftware/project-music-daw-next-react): Independent React application with explicit Next.js server/client boundaries.
- [React Native · Expo](https://github.com/liberusoftware/project-music-daw-mobile-react): Native iOS/Android application using React Native and Expo.
- [Flutter · Mobile](https://github.com/liberusoftware/project-music-daw-mobile-flutter): Flutter iOS/Android application using Dart and mobile widgets.
- [Dart · Desktop](https://github.com/liberusoftware/project-music-daw-dart-desktop): Dedicated Dart desktop application with a Flutter host for Linux, macOS and Windows.
- [Dart · Mobile](https://github.com/liberusoftware/project-music-daw-dart-mobile): Separate Dart mobile application with a Flutter host for iOS and Android.

Use the [presentation implementation guides](https://github.com/liberusoftware/documentation/blob/main/guides/presentation/README.md) and [product documentation](https://github.com/liberusoftware/documentation/blob/main/projects/music/README.md) to select compatible modules, reviewed contracts and the correct deployment boundary. Laravel remains authoritative for domain rules and tenant authorization across all clients.
<!-- presentation-family:end -->
