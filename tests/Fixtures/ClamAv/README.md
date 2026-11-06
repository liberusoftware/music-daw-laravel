This is ClamAV's empty signed test database, from Cisco Talos ClamAV 1.4.6:
https://github.com/Cisco-Talos/clamav/blob/clamav-1.4.6/unit_tests/input/freshclam_testfiles/test-1.cvd

SHA-256: a39c001ff5a02af09afc204f5710b58290dc68bdc76b6343011188179bf4d7cd

Upstream distributes it under GPL-2.0-or-later; the accompanying COPYING is also inside the database archive. No production malware signatures or engine code are vendored here.

Tests copy this fixture unchanged into a private temporary database directory and add a local EICAR test signature. A test-only process factory uses libfaketime to control only the ClamAV child process's clock, exercising fresh and stale states with the same scanner flags and signed database. Neither production timestamps nor signature validation are changed. This fixture must never be used as a production database.
