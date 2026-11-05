# Liberu Music

> A modular Digital Audio Workstation project for recording, composition, production and live performance.

[Software](https://liberusoftware.com) · [Hosting](https://liberuhosting.com) · [Services](https://liberuservices.com) · [Liberu Group](https://liberugroup.com)

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/) [![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/) [![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)](https://filamentphp.com/) [![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)](https://livewire.laravel.com/)

## What it is

Liberu Music is the Laravel host skeleton for a proposed modular Digital Audio Workstation for songwriters, musicians, producers, composers and mix engineers. It starts from [Liberu Boilerplate](https://github.com/liberusoftware/boilerplate-laravel/tree/95f445a2901f2296959bec24229c6a1d5d5a6815), preserving its application structure and shared foundation modules and themes.

**Status: initial skeleton.** Music-specific capabilities are planned, not implemented. The 88 separate module repositories contain README placeholders only; they are not installable Composer packages and are not required by this host. No audio engine, recording, editing, endpoints or Music UI is provided yet.

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
git clone https://github.com/liberusoftware/music-laravel.git
cd music-laravel
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
database/    # foundation migrations and seeders
modules/     # inherited shared foundation packages only
resources/   # inherited foundation views and assets
routes/      # foundation routes
tests/       # inherited application and architecture checks
themes/      # inherited shared themes
```

Each Music capability has separate core, API, Livewire and Filament repository placeholders. Core repositories use `module-music-<capability>`; adapters append `-api`, `-livewire` or `-filament`. See the [complete repository catalogue](MUSIC-MODULES.md).

Laravel owns project metadata, authorization, revision manifests, asset policy and asynchronous jobs. A separately designed native/local engine owns audio graph execution, DSP, sample scheduling, recording buffers and devices. PHP, database access and network requests must never execute in the audio callback. Filament supports operations and Livewire supports project management/review; neither is the real-time engine.

Foundation identity, teams, permissions, files, settings, audit, notifications and queues remain shared capabilities. Follow the [foundation adoption plan](https://github.com/liberusoftware/documentation/blob/main/projects/music/FOUNDATION.md) instead of duplicating them in Music modules.

## Development and verification

```bash
composer test
npm run build
```

These are inherited foundation checks, not evidence of DAW support. No Music implementation or audio correctness tests are included at this stage. Dependencies and lockfiles retain the boilerplate versions.

Inherited GitHub Actions definitions are preserved as `.github/workflow-templates/*.yml.example` templates because the provisioning token lacks the `workflow` scope. Move them into `.github/workflows/` and rename them to `.yml` when publishing with credentials that have that scope. The package publishing workflow also requires organization secrets; review it before enabling publication from this host. Music placeholders have no release tags or Packagist submissions.

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
| Accounting | [liberusoftware/accounting-erp-laravel](https://github.com/liberusoftware/accounting-erp-laravel) | Ledgers, banking, tax, expenses, close, and reporting |
| Automation | [liberusoftware/automation-laravel](https://github.com/liberusoftware/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |
| Billing | [liberusoftware/billing-laravel](https://github.com/liberusoftware/billing-laravel) | Billing, subscriptions, payments, invoices, and revenue operations |
| Boilerplate | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Modular Laravel foundation and reference implementation |
| Browser game | [liberusoftware/browser-game-laravel](https://github.com/liberusoftware/browser-game-laravel) | Browser-based game platform and domain capabilities |
| CMS | [liberusoftware/cms-laravel](https://github.com/liberusoftware/cms-laravel) | Content, publishing, pages, media, search, and delivery |
| Control panel | [liberusoftware/control-panel-laravel](https://github.com/liberusoftware/control-panel-laravel) | Hosting, infrastructure, DNS, mail, backups, and operations |
| CRM | [liberusoftware/crm-laravel](https://github.com/liberusoftware/crm-laravel) | Customers, leads, opportunities, sales, and service |
| Ecommerce | [liberusoftware/ecommerce-laravel](https://github.com/liberusoftware/ecommerce-laravel) | Catalogues, checkout, orders, fulfilment, and returns |
| Genealogy | [liberusoftware/genealogy-laravel](https://github.com/liberusoftware/genealogy-laravel) | Genealogy records, relationships, sources, and research |
| Maintenance | [liberusoftware/maintenance-laravel](https://github.com/liberusoftware/maintenance-laravel) | Maintenance planning, assets, work orders, and operations |
| Real estate | [liberusoftware/real-estate-laravel](https://github.com/liberusoftware/real-estate-laravel) | Property, listing, tenancy, and transaction workflows |
| Social network | [liberusoftware/social-network-laravel](https://github.com/liberusoftware/social-network-laravel) | Social profiles, groups, content, messaging, and discovery |

## Security

Do not report security vulnerabilities through public GitHub issues. Email `security@liberusoftware.com` with reproduction details and the affected version so the report can be handled privately.

## License

This project is open-source software available under the [MIT License](LICENSE.md). The linked licence text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. Report reproducible bugs, propose focused enhancements, improve documentation or translations, and submit tested changes. Search existing issues first. Pull requests should explain the problem and approach, remain focused, pass the required checks, and document user-visible or breaking changes. Security reports must follow the private route above.

## Contributors

Thank you to everyone who helps improve Liberu. [View the contributors graph](https://github.com/liberusoftware/music-laravel/graphs/contributors).
